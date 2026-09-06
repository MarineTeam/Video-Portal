<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Broadcast\BroadcastRepository;
use Portal\Broadcast\Consent;
use Portal\Broadcast\Sender;
use Portal\Config;
use Portal\Mail\MailProvider;
use Portal\Mail\SendResult;
use Portal\Providers\TestResult;
use Portal\Sms\SmsProvider;
use Portal\Sms\SmsResult;

/**
 * Broadcasts: who is resolved, and what a resumed run cannot do.
 *
 * Against a real database because the guarantee is a UNIQUE KEY — "a resumed
 * run cannot send twice" is not a property of any code path, it is a property
 * of the index, and a mock would agree with either answer.
 */
final class BroadcastTest extends DatabaseTestCase
{
    private BroadcastRepository $broadcasts;
    private BroadcastMailer $mail;
    private BroadcastTexter $sms;
    private int $broadcastId;

    protected function setUp(): void
    {
        $this->truncate([
            'broadcast_recipients', 'broadcasts', 'broadcast_prefs',
            'notifications', 'group_members', 'permission_groups', 'users',
        ]);

        $this->broadcasts = new BroadcastRepository($this->db(), '44');
        $this->mail = new BroadcastMailer();
        $this->sms = new BroadcastTexter();
        $this->broadcastId = $this->broadcasts->create('Church weekend', 'admin@example.test');

        $this->broadcasts->update($this->broadcastId, [
            'body'          => 'It is on the 14th.',
            'audience_type' => BroadcastRepository::EVERYONE,
        ]);
    }

    private function person(string $name, bool $optOutEmail = false, ?string $phone = null, bool $smsOptIn = false): int
    {
        $now = date('Y-m-d H:i:s');

        $id = (int) $this->db()->insert('users', [
            'email'      => strtolower($name) . '@example.test',
            'name'       => $name,
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($optOutEmail || $phone !== null || $smsOptIn) {
            $this->broadcasts->savePrefs($id, $optOutEmail, $smsOptIn, (string) $phone);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function broadcast(): array
    {
        return (array) $this->broadcasts->find($this->broadcastId);
    }

    private function channels(bool $email, bool $sms = false, bool $push = false): void
    {
        $this->broadcasts->update($this->broadcastId, [
            '_whole_form' => true,
            'by_email'    => $email,
            'by_sms'      => $sms,
            'by_push'     => $push,
        ]);
    }

    private function sender(): Sender
    {
        $config = new Config('/nonexistent-config.php');
        $config->overlay(['base_url' => 'https://portal.example']);

        return new Sender($this->db(), $config, $this->broadcasts, $this->mail, $this->sms);
    }

    // ------------------------------------------------- honest counts first

    /**
     * The preview says how many will be reached AND why the rest will not.
     *
     * "300 recipients" is a number that gets believed and then quietly is not
     * true; "214 of 300, and here is why" is one somebody can act on.
     */
    public function testThePreviewCountsWhoIsActuallyReachable(): void
    {
        $this->person('Ada');
        $this->person('Bea', optOutEmail: true);
        $this->person('Cy');

        $this->channels(email: true);

        $preview = $this->broadcasts->preview($this->broadcast());

        self::assertSame(2, $preview[Consent::EMAIL]['reach']);
        self::assertSame(['turned announcements off' => 1], $preview[Consent::EMAIL]['skipped']);
    }

    /**
     * THE THREE RULES, on one audience: the same people give three different
     * numbers, which a single "wants announcements" flag could not produce.
     */
    public function testTheThreeChannelsReachDifferentNumbersOfTheSamePeople(): void
    {
        $this->person('Ada');
        $this->person('Bea', phone: '07700 900123', smsOptIn: true);
        $this->person('Cy', phone: '07700 900124');

        $this->channels(email: true, sms: true, push: true);

        $preview = $this->broadcasts->preview($this->broadcast());

        self::assertSame(3, $preview[Consent::EMAIL]['reach'], 'email is opt-out');
        self::assertSame(1, $preview[Consent::SMS]['reach'], 'a number is not consent to text it');
        self::assertSame(0, $preview[Consent::PUSH]['reach'], 'nobody has a device');

        self::assertSame(
            ['has not opted in to texts' => 2],
            $preview[Consent::SMS]['skipped']
        );
    }

    /** A push table that does not exist is "no devices", not a fatal. */
    public function testASiteWithoutThePushPluginStillWorks(): void
    {
        $this->person('Ada');
        $this->channels(email: true, push: true);

        $preview = $this->broadcasts->preview($this->broadcast());

        self::assertSame(0, $preview[Consent::PUSH]['reach']);
        self::assertSame(1, $preview[Consent::EMAIL]['reach']);
    }

    // ---------------------------------------------- resolved before sending

    public function testResolvingWritesOneRowPerPersonPerChannel(): void
    {
        $this->person('Ada', phone: '07700 900123', smsOptIn: true);
        $this->person('Bea');

        $this->channels(email: true, sms: true);

        self::assertSame(3, $this->broadcasts->resolve($this->broadcast()));
        self::assertSame(
            BroadcastRepository::SENDING,
            (string) $this->broadcast()['state'],
            'the broadcast still looks like a draft somebody could edit and send again'
        );
    }

    /**
     * THE GUARANTEE, and it is the index rather than any code path: resolving
     * twice — which an interrupted run will do — cannot produce a second row
     * for anybody.
     *
     * Without it, an interruption between "resolved" and "finished" sends the
     * whole audience a second copy, which is the failure people remember.
     */
    public function testResolvingTwiceCannotDuplicateAnybody(): void
    {
        $this->person('Ada');
        $this->person('Bea');
        $this->channels(email: true);

        $this->broadcasts->resolve($this->broadcast());
        $this->broadcasts->resolve($this->broadcast());
        $this->broadcasts->resolve($this->broadcast());

        self::assertSame(
            2,
            (int) $this->db()->value('SELECT COUNT(*) FROM {broadcast_recipients}'),
            'A RESUMED RUN WOULD SEND EVERYBODY A SECOND COPY'
        );
    }

    /** And the unique key really is what stops it. */
    public function testTheDatabaseRefusesASecondRowForTheSameRecipient(): void
    {
        $this->person('Ada');
        $this->channels(email: true);
        $this->broadcasts->resolve($this->broadcast());

        $this->expectException(\Throwable::class);

        $this->db()->execute(
            'INSERT INTO {broadcast_recipients}
                (broadcast_id, channel, address, state, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$this->broadcastId, Consent::EMAIL, 'ada@example.test', 'pending']
        );
    }

    /** Somebody who cannot be reached gets no row at all, rather than a failed one. */
    public function testSomebodyWhoOptedOutIsNotResolvedIntoARow(): void
    {
        $this->person('Ada', optOutEmail: true);
        $this->person('Bea');
        $this->channels(email: true);

        $this->broadcasts->resolve($this->broadcast());

        self::assertSame(
            ['bea@example.test'],
            array_column(
                $this->db()->all('SELECT address FROM {broadcast_recipients}'),
                'address'
            )
        );
    }

    /** A number is stored as E.164, so the gateway is handed something dialable. */
    public function testAPhoneNumberIsResolvedIntoSomethingDialable(): void
    {
        $this->person('Ada', phone: '07700 900123', smsOptIn: true);
        $this->channels(email: false, sms: true);

        $this->broadcasts->resolve($this->broadcast());

        self::assertSame(
            '+447700900123',
            (string) $this->db()->value(
                'SELECT address FROM {broadcast_recipients} WHERE channel = ?',
                [Consent::SMS]
            )
        );
    }

    // ------------------------------------------------------ the send itself

    public function testSendingWorksThroughTheRows(): void
    {
        foreach (['Ada', 'Bea', 'Cy'] as $name) {
            $this->person($name);
        }

        $this->channels(email: true);
        $this->broadcasts->resolve($this->broadcast());

        $result = $this->sender()->run($this->broadcast());

        self::assertSame(3, $result['sent']);
        self::assertSame(0, $result['remaining']);
        self::assertCount(3, $this->mail->sent);
        self::assertSame(BroadcastRepository::SENT, (string) $this->broadcast()['state']);
    }

    /**
     * THE RULE. One bad address fails one row and the loop carries on.
     *
     * Without it, one dead mailbox ends the broadcast at whoever is
     * alphabetically unlucky and everybody after them is never told anything —
     * silently, because the run looks like it finished.
     */
    public function testOneBadAddressDoesNotStopTheRest(): void
    {
        foreach (['Ada', 'Bea', 'Cy'] as $name) {
            $this->person($name);
        }

        $this->channels(email: true);
        $this->broadcasts->resolve($this->broadcast());

        $this->mail->refuse('bea@example.test', 'Mailbox does not exist');

        $result = $this->sender()->run($this->broadcast());

        self::assertSame(2, $result['sent'], 'ONE BAD ADDRESS STOPPED THE REST');
        self::assertSame(1, $result['failed']);
        self::assertSame(0, $result['remaining']);
    }

    /** And a provider that THROWS fails one row rather than the run. */
    public function testAProviderThatThrowsFailsOneRow(): void
    {
        foreach (['Ada', 'Bea', 'Cy'] as $name) {
            $this->person($name);
        }

        $this->channels(email: true);
        $this->broadcasts->resolve($this->broadcast());

        $this->mail->explodeOn('bea@example.test');

        $result = $this->sender()->run($this->broadcast());

        self::assertSame(2, $result['sent']);
        self::assertSame(1, $result['failed']);
    }

    /** The reason is kept in the provider's own words. */
    public function testTheReasonIsKeptInTheProvidersOwnWords(): void
    {
        $this->person('Ada');
        $this->channels(email: true);
        $this->broadcasts->resolve($this->broadcast());
        $this->mail->refuse('ada@example.test', 'Mailbox does not exist');

        $this->sender()->run($this->broadcast());

        self::assertSame(
            'Mailbox does not exist',
            (string) $this->db()->value('SELECT error FROM {broadcast_recipients}')
        );
    }

    /**
     * A run is bounded, and the next one picks up where it stopped.
     *
     * Shared hosting kills long requests and pseudo-cron runs inside somebody's
     * page view, so a send of four hundred WILL be interrupted.
     */
    public function testARunIsBoundedAndResumes(): void
    {
        for ($n = 0; $n < Sender::PER_RUN + 5; $n++) {
            $this->person('Person' . $n);
        }

        $this->channels(email: true);
        $total = $this->broadcasts->resolve($this->broadcast());

        self::assertSame(Sender::PER_RUN + 5, $total);

        $first = $this->sender()->run($this->broadcast());

        self::assertSame(Sender::PER_RUN, $first['sent'], 'the run was not bounded');
        self::assertSame(5, $first['remaining']);

        $second = $this->sender()->run($this->broadcast());

        self::assertSame(5, $second['sent']);
        self::assertSame(0, $second['remaining']);

        // And nobody was sent to twice across the two runs.
        self::assertCount(Sender::PER_RUN + 5, array_unique($this->mail->addresses()));
    }

    /**
     * And by the CLOCK as well as the count.
     *
     * Fifty emails through a fast provider is a second; fifty texts through a
     * slow gateway is well past what a shared host allows — and this runs
     * inside a visitor's page view.
     */
    public function testARunAlsoStopsWhenTheClockRunsOut(): void
    {
        for ($n = 0; $n < 10; $n++) {
            $this->person('Person' . $n);
        }

        $this->channels(email: true);
        $this->broadcasts->resolve($this->broadcast());

        // Started long enough ago that the very first check is already past the
        // limit, so nothing goes.
        $result = $this->sender()->run($this->broadcast(), time() - Sender::SECONDS_PER_RUN - 1);

        self::assertSame(0, $result['sent'], 'THE CLOCK WAS IGNORED');
        self::assertTrue($result['ranOut']);
        self::assertSame(10, $result['remaining'], 'rows were consumed without being sent');
    }

    /**
     * THE FIRE-ONCE GUARD, tested directly.
     *
     * A single-threaded run never watches a claim fail, because nextBatch()
     * has already excluded anything claimed. The second call in one process is
     * the only way to stage the condition it exists for.
     */
    public function testTheSecondClaimOnTheSameRowFails(): void
    {
        $this->person('Ada');
        $this->channels(email: true);
        $this->broadcasts->resolve($this->broadcast());

        $rowId = (int) $this->db()->value('SELECT id FROM {broadcast_recipients} LIMIT 1');

        self::assertTrue($this->broadcasts->claim($rowId));
        self::assertFalse(
            $this->broadcasts->claim($rowId),
            'TWO OVERLAPPING RUNS WOULD BOTH SEND TO THE SAME PERSON'
        );
    }

    // ------------------------------------------------------- the audience

    /**
     * An event's sign-ups INCLUDING the ones with no account — which is most of
     * the point of having events at all.
     */
    public function testAnEventsAudienceIncludesPeopleWithNoAccount(): void
    {
        $this->truncate(['event_signups', 'events']);

        $now = date('Y-m-d H:i:s');
        $eventId = (int) $this->db()->insert('events', [
            'slug'       => 'quiz-night',
            'title'      => 'Quiz night',
            'starts_at'  => date('Y-m-d H:i:s', strtotime('+7 days')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([['Ada', null], ['Stranger', null]] as [$name, $userId]) {
            $this->db()->insert('event_signups', [
                'event_id'   => $eventId,
                'name'       => $name,
                'email'      => strtolower($name) . '@example.test',
                'user_id'    => $userId,
                'state'      => 'going',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->broadcasts->update($this->broadcastId, [
            'audience_type' => BroadcastRepository::EVENT,
            'audience_id'   => $eventId,
        ]);
        $this->channels(email: true);

        self::assertSame(2, $this->broadcasts->preview($this->broadcast())[Consent::EMAIL]['reach']);
    }

    // ------------------------------------------------------- preferences

    /**
     * A missing row means EMAIL YES, SMS NO — the defaults the three rules
     * describe. Nobody has to visit a settings page to be emailed, and nobody
     * is texted because they never visited one.
     */
    public function testNeverHavingChosenMeansEmailYesAndTextNo(): void
    {
        $id = $this->person('Ada');
        $prefs = $this->broadcasts->prefs($id);

        self::assertFalse($prefs['email_opt_out']);
        self::assertFalse($prefs['sms_opt_in']);
    }

    /**
     * The number is stored as typed rather than as E.164, so somebody can see
     * what they wrote and correct it — and a number that stops being readable
     * stops being sent to rather than being repaired into a different phone.
     */
    public function testThePhoneNumberIsKeptAsItWasTyped(): void
    {
        $id = $this->person('Ada');
        $this->broadcasts->savePrefs($id, false, true, '07700 900123');

        self::assertSame('07700 900123', $this->broadcasts->prefs($id)['phone']);
    }

    /** A broadcast that has started cannot be edited under its recipients. */
    public function testAStartedBroadcastCannotBeRewritten(): void
    {
        $this->person('Ada');
        $this->channels(email: true);
        $this->broadcasts->resolve($this->broadcast());

        $this->expectExceptionMessage('already started');

        $this->broadcasts->update($this->broadcastId, ['body' => 'Actually it is the 21st.']);
    }
}

/**
 * A mail provider that remembers, and can be told to refuse or to throw.
 *
 * Refusing and throwing are DIFFERENT failures and both have to be survivable:
 * one is a provider behaving as documented, the other is a provider behaving as
 * providers eventually do.
 */
final class BroadcastMailer implements MailProvider
{
    /** @var list<array<string, string>> */
    public array $sent = [];

    /** @var array<string, string> */
    private array $refuse = [];

    private ?string $explode = null;

    public function refuse(string $address, string $why): void
    {
        $this->refuse[$address] = $why;
    }

    public function explodeOn(string $address): void
    {
        $this->explode = $address;
    }

    /** @return list<string> */
    public function addresses(): array
    {
        return array_column($this->sent, 'to');
    }

    public static function slug(): string
    {
        return 'broadcast-recorder';
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
        if ($this->explode === $to) {
            throw new \RuntimeException('The provider fell over.');
        }

        if (isset($this->refuse[$to])) {
            return SendResult::failure($this->refuse[$to]);
        }

        $this->sent[] = ['to' => $to, 'subject' => $subject];

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

/** An SMS provider that remembers rather than spending money. */
final class BroadcastTexter implements SmsProvider
{
    /** @var list<array<string, string>> */
    public array $sent = [];

    public static function slug(): string
    {
        return 'broadcast-texter';
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

    public function send(string $to, string $message): SmsResult
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return SmsResult::success('recorded');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function from(): string
    {
        return '+447700900000';
    }
}
