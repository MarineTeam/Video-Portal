<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Mail\MailProvider;
use Portal\Mail\SendResult;
use Portal\Providers\TestResult;
use Portal\Schedules\ReminderSchedule;
use Portal\Schedules\Reminders;
use Portal\Schedules\ScheduleRepository;

/**
 * Who gets a rota reminder, and how many.
 *
 * Against a real database because both rules are about rows: the fire-once
 * guard is a PRIMARY KEY, and "somebody with no account gets none" is an INNER
 * JOIN. A mock would agree with either while doing neither.
 */
final class RotaReminderTest extends DatabaseTestCase
{
    private ScheduleRepository $schedules;
    private Reminders $reminders;
    private ReminderMailer $mail;
    private int $scheduleId;

    protected function setUp(): void
    {
        $this->truncate([
            'schedule_reminders_sent', 'schedule_reminder_prefs', 'schedule_entries',
            'schedule_person_aliases', 'schedule_people', 'schedule_sources', 'schedules',
            'notifications', 'users',
        ]);

        // A base URL is supplied rather than inferred, because an emailed link
        // built from the request host is a poisoning bug this project has
        // already fixed once — and this runs from cron, where there is no
        // request to take a host from at all.
        $config = new Config('/nonexistent-config.php');
        $config->overlay(['base_url' => 'https://portal.example']);

        $this->schedules = new ScheduleRepository($this->db());
        $this->mail = new ReminderMailer();
        $this->reminders = new Reminders($this->db(), $config, $this->mail);
        $this->scheduleId = $this->schedules->createSchedule('Welcome');
    }

    private function account(string $email): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('users', [
            'email'      => $email,
            'name'       => 'Test Person',
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function onTomorrow(string $name, ?int $userId = null): int
    {
        $person = $this->schedules->personFor($name);

        if ($userId !== null) {
            $this->schedules->linkToAccount($person, $userId);
        }

        $this->schedules->put($this->scheduleId, $person, date('Y-m-d', strtotime('+1 day')), 'Coffee');

        return $person;
    }

    // ----------------------------------------------------- who is even asked

    /**
     * THE LIMITATION, enforced by the join rather than by a check somebody
     * could forget. A name on a spreadsheet has no address, so there is no
     * path here that can try to email one.
     */
    public function testSomebodyWithNoAccountIsNotEvenACandidate(): void
    {
        $this->onTomorrow('Jane Cole');

        self::assertSame([], $this->reminders->candidates());
    }

    public function testSomebodyLinkedToAnAccountIs(): void
    {
        $this->onTomorrow('Jane Cole', $this->account('jane@example.test'));

        $candidates = $this->reminders->candidates();

        self::assertCount(1, $candidates);
        self::assertSame('jane@example.test', $candidates[0]['email']);
    }

    /**
     * A schedule off the calendar reminds nobody.
     *
     * Its dates are hidden, so telling somebody they are on for a rota that is
     * not running is worse than telling them nothing at all.
     */
    public function testAWithdrawnScheduleRemindsNobody(): void
    {
        $this->onTomorrow('Jane Cole', $this->account('jane@example.test'));
        $this->schedules->enableSchedule($this->scheduleId, false);

        self::assertSame([], $this->reminders->candidates());
    }

    /**
     * Having never opened the settings screen means the DEFAULT, not silence.
     *
     * Linking a name to an account is what turns reminders on. If an absent
     * row meant off, linking would only turn on the possibility of somebody
     * finding a form, and nobody would ever be reminded of anything.
     */
    public function testNeverHavingChosenMeansTheDefaultAndNotSilence(): void
    {
        $userId = $this->account('jane@example.test');
        $this->onTomorrow('Jane Cole', $userId);

        $candidate = $this->reminders->candidates()[0];

        self::assertSame(1, (int) $candidate['day_before'], 'linking an account turned nothing on');
        self::assertSame(0, (int) $candidate['day_of']);
        self::assertSame(18, (int) $candidate['send_hour']);

        // And the repository agrees, so the screen shows what will happen.
        self::assertTrue($this->schedules->reminderPrefs($userId)['day_before']);
    }

    public function testSomebodyCanTurnThemOff(): void
    {
        $userId = $this->account('jane@example.test');
        $this->onTomorrow('Jane Cole', $userId);

        $this->schedules->saveReminderPrefs($userId, false, false, 7, 'Pacific/Auckland');

        $candidate = $this->reminders->candidates()[0];

        self::assertSame(0, (int) $candidate['day_before']);
        self::assertSame(7, (int) $candidate['send_hour']);
        self::assertSame('Pacific/Auckland', $candidate['timezone']);
    }

    /**
     * A zone nobody could have meant becomes nothing, and the sender falls
     * back to the site's — a bad string stored here would make every later
     * conversion guess.
     */
    public function testAnImpossibleTimezoneIsNotStored(): void
    {
        $userId = $this->account('jane@example.test');

        $this->schedules->saveReminderPrefs($userId, true, false, 9, 'Mars/Olympus_Mons');

        self::assertNull($this->schedules->reminderPrefs($userId)['timezone']);
    }

    // ------------------------------------------------------------ fire once

    /**
     * THE FIRE-ONCE GUARD, tested directly.
     *
     * A single-threaded run never watches a claim fail — the query has already
     * excluded anything claimed — so the second call in one process is the only
     * way to stage the condition this exists for. This project has twice been
     * caught by a concurrency guard no test could reach.
     */
    public function testTheSecondClaimOnTheSameReminderFails(): void
    {
        $person = $this->onTomorrow('Jane Cole', $this->account('jane@example.test'));
        $entryId = (int) $this->db()->value(
            'SELECT id FROM {schedule_entries} WHERE person_id = ?',
            [$person]
        );

        self::assertTrue($this->reminders->claim($entryId, ReminderSchedule::BEFORE));
        self::assertFalse(
            $this->reminders->claim($entryId, ReminderSchedule::BEFORE),
            'TWO OVERLAPPING CRON RUNS WOULD BOTH SEND'
        );

        // The other slot is a different reminder and is still available.
        self::assertTrue($this->reminders->claim($entryId, ReminderSchedule::DAY_OF));
    }

    /** And a run does not send the same person the same thing twice. */
    public function testARunTwiceOverSendsOneReminder(): void
    {
        $this->onTomorrow('Jane Cole', $this->account('jane@example.test'));

        // Now, in whatever zone this host keeps, well past any sending hour.
        $now = strtotime(date('Y-m-d') . ' 23:59:00');

        $first = $this->reminders->run($now);
        $second = $this->reminders->run($now);

        self::assertCount(1, $this->mail->sent, 'IT SENT THE SAME REMINDER TWICE');
        self::assertStringContainsString('1 reminder(s) sent', $first);
        self::assertStringContainsString('Nothing was due', $second, 'IT SENT THE SAME REMINDER TWICE');

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_reminders_sent}')
        );
    }

    /**
     * And what was sent is written down, because an email is in a mailbox this
     * site cannot read.
     */
    public function testWhatWasSentIsRecorded(): void
    {
        $this->onTomorrow('Jane Cole', $this->account('jane@example.test'));

        $this->reminders->run(strtotime(date('Y-m-d') . ' 23:59:00'));

        $recorded = $this->db()->first('SELECT * FROM {notifications}');

        self::assertIsArray($recorded);
        self::assertSame('jane@example.test', $recorded['recipient_email']);
        self::assertStringContainsString('you are on', (string) $recorded['title']);
    }

    /**
     * The message says when it ACTUALLY is, not which slot produced it.
     *
     * The day-before reminder for tomorrow says tomorrow. The same reminder,
     * running late on the morning itself — which is normal here, because
     * pseudo-cron only fires when somebody visits — has to say today, or it
     * tells somebody the wrong day at the moment it matters most.
     */
    public function testALateReminderSaysTodayRatherThanTomorrow(): void
    {
        $userId = $this->account('jane@example.test');
        $person = $this->schedules->personFor('Jane Cole');
        $this->schedules->linkToAccount($person, $userId);
        $this->schedules->put($this->scheduleId, $person, date('Y-m-d'), 'Coffee');

        // The day-before slot for today's date came due yesterday evening; the
        // job is only running now.
        $this->reminders->run(strtotime(date('Y-m-d') . ' 08:00:00'));

        self::assertCount(1, $this->mail->sent);
        self::assertStringContainsString('you are on today', $this->mail->sent[0]['subject']);
        self::assertStringNotContainsString('tomorrow', $this->mail->sent[0]['subject']);
    }

    /** And tomorrow's really does say tomorrow. */
    public function testTomorrowsReminderSaysTomorrow(): void
    {
        $this->onTomorrow('Jane Cole', $this->account('jane@example.test'));

        $this->reminders->run(strtotime(date('Y-m-d') . ' 23:59:00'));

        self::assertCount(1, $this->mail->sent);
        self::assertStringContainsString('you are on tomorrow', $this->mail->sent[0]['subject']);
    }

    /** A date already gone is never mentioned, however late the job runs. */
    public function testAPastDateIsNotRemindedAbout(): void
    {
        $person = $this->schedules->personFor('Jane Cole');
        $this->schedules->linkToAccount($person, $this->account('jane@example.test'));
        $this->schedules->put(
            $this->scheduleId,
            $person,
            date('Y-m-d', strtotime('-1 day')),
            'Coffee'
        );

        $message = $this->reminders->run(strtotime(date('Y-m-d') . ' 23:59:00'));

        self::assertStringContainsString('Nothing was due', $message);
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_reminders_sent}'),
            'it reminded somebody about yesterday'
        );
    }
}

/**
 * A mail provider that remembers instead of sending.
 *
 * Counting is the point: "the second run did nothing" is a much weaker claim
 * than "the list of sent mail did not grow".
 */
final class ReminderMailer implements MailProvider
{
    /** @var list<array<string, string>> */
    public array $sent = [];

    public static function slug(): string
    {
        return 'reminder-recorder';
    }

    public static function label(): string
    {
        return 'Recording (test double)';
    }

    public static function description(): string
    {
        return 'Remembers what it was asked to send. Never registered.';
    }

    /** @return list<\Portal\Providers\SettingField> */
    public static function fields(): array
    {
        return [];
    }

    /** @return list<string> */
    public static function requiredExtensions(): array
    {
        return [];
    }

    public function test(): TestResult
    {
        return TestResult::pass();
    }

    /** @param array<string, mixed> $options */
    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $options = []
    ): SendResult {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html];

        return SendResult::success('recorded');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function fromAddress(): string
    {
        return 'noreply@example.test';
    }
}
