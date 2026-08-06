<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Content\CategoryRepository;
use Portal\Content\Notifier;
use Portal\Content\SeriesRepository;
use Portal\Content\SpeakerRepository;
use Portal\Content\SubscriptionRepository;
use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Mail\MailProvider;
use Portal\Mail\SendResult;
use Portal\Providers\TestResult;

/**
 * Subscriptions, and announcing new content.
 *
 * Three claims carry this, and each one is a way to get badly embarrassed:
 * subscribing twice must not mean two emails, a video must be announced exactly
 * once however many times the job runs, and a members-only video must never be
 * announced to a list anybody can join.
 */
final class SubscriptionTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private CategoryRepository $categories;
    private VideoRepository $videos;
    private SeriesRepository $series;
    private SpeakerRepository $speakers;
    private RecordingMailer $mail;

    protected function setUp(): void
    {
        $this->truncate([
            'subscriptions', 'announced_videos', 'video_categories',
            'categories', 'videos', 'series', 'speakers', 'users',
        ]);

        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
        $this->series = new SeriesRepository($this->db());
        $this->speakers = new SpeakerRepository($this->db());
        $this->subscriptions = new SubscriptionRepository($this->db(), $this->categories);
        $this->mail = new RecordingMailer();
    }

    // ----------------------------------------------------------- subscribing

    public function testSubscribingStoresARowAndAToken(): void
    {
        $result = $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);

        self::assertTrue($result['new']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,64}$/', $result['token']);
        self::assertTrue($this->subscriptions->has('a@example.com', SubscriptionRepository::SITE, null));
    }

    /**
     * A double-submitted form is the ordinary way this happens, and the
     * consequence of getting it wrong is two copies of every email.
     */
    public function testSubscribingTwiceStoresOneRow(): void
    {
        $first = $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $second = $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);

        self::assertTrue($first['new']);
        self::assertFalse($second['new']);
        self::assertSame($first['token'], $second['token'], 'The second call must hand back the same link.');
        self::assertSame(1, $this->subscriptions->count());
    }

    /**
     * The unique key has to hold for a site-wide subscription too. MySQL treats
     * NULLs in a unique key as distinct, so without the generated column this
     * is exactly the case that would slip through.
     */
    public function testTheSiteScopeIsAlsoDeduplicated(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);

        self::assertSame(1, $this->subscriptions->count());
    }

    public function testTheSameAddressCanSubscribeToDifferentThings(): void
    {
        $seriesId = $this->series->create(['title' => 'Advent'])->id;

        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SERIES, $seriesId);

        self::assertSame(2, $this->subscriptions->count());
        self::assertCount(2, $this->subscriptions->forEmail('a@example.com'));
    }

    public function testAddressesAreNormalised(): void
    {
        $this->subscriptions->subscribe('Mixed@Example.COM', SubscriptionRepository::SITE, null);

        self::assertTrue($this->subscriptions->has('mixed@example.com', SubscriptionRepository::SITE, null));
        self::assertSame(1, $this->subscriptions->count());
    }

    /** Quietly widening one series into the whole library is the wrong default. */
    public function testAnUnknownScopeIsRefused(): void
    {
        self::assertNull(SubscriptionRepository::sanitizeScope('everything-forever'));
        self::assertNull(SubscriptionRepository::sanitizeScope(''));
        self::assertNull(SubscriptionRepository::sanitizeScope(null));
        self::assertSame('series', SubscriptionRepository::sanitizeScope(' series '));
    }

    // --------------------------------------------------------- unsubscribing

    public function testUnsubscribingByTokenRemovesTheRow(): void
    {
        $result = $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);

        self::assertTrue($this->subscriptions->unsubscribe($result['token']));
        self::assertSame(0, $this->subscriptions->count());
    }

    /** The link stops working, which an HMAC over the address would not. */
    public function testATokenIsSingleUse(): void
    {
        $result = $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);

        self::assertTrue($this->subscriptions->unsubscribe($result['token']));
        self::assertFalse($this->subscriptions->unsubscribe($result['token']));
        self::assertNull($this->subscriptions->findByToken($result['token']));
    }

    public function testAMalformedTokenIsRefusedWithoutAQuery(): void
    {
        self::assertFalse($this->subscriptions->unsubscribe('../../etc/passwd'));
        self::assertFalse($this->subscriptions->unsubscribe(''));
        self::assertFalse($this->subscriptions->unsubscribe('short'));
        self::assertNull($this->subscriptions->findByToken('nope'));
    }

    /**
     * The button that stops people reaching for the spam button instead.
     */
    public function testUnsubscribingFromEverythingClearsEveryScope(): void
    {
        $seriesId = $this->series->create(['title' => 'Advent'])->id;
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SERIES, $seriesId);
        $this->subscriptions->subscribe('b@example.com', SubscriptionRepository::SITE, null);

        self::assertSame(2, $this->subscriptions->unsubscribeAll('a@example.com'));
        self::assertSame(1, $this->subscriptions->count());
    }

    // ------------------------------------------------------------ recipients

    public function testASiteSubscriberHearsAboutEverything(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $video = $this->videos->find($this->video('Anything'));

        self::assertSame(['a@example.com'], $this->emails($video));
    }

    public function testASeriesSubscriberOnlyHearsAboutThatSeries(): void
    {
        $seriesId = $this->series->create(['title' => 'Advent'])->id;
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SERIES, $seriesId);

        $inSeries = $this->videos->find($this->video('Episode', seriesId: $seriesId));
        $elsewhere = $this->videos->find($this->video('Unrelated'));

        self::assertSame(['a@example.com'], $this->emails($inSeries));
        self::assertSame([], $this->emails($elsewhere));
    }

    public function testASpeakerSubscriberHearsAboutTheirVideos(): void
    {
        $speakerId = $this->speakers->create(['name' => 'Jane'])->id;
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SPEAKER, $speakerId);

        $video = $this->videos->find($this->video('A talk', speakerId: $speakerId));

        self::assertSame(['a@example.com'], $this->emails($video));
    }

    /**
     * Subscribing to a parent category covers what is filed beneath it, which
     * is what anybody means by "tell me about Sermons".
     */
    public function testACategorySubscriptionCoversChildCategories(): void
    {
        $parent = $this->categories->create(['name' => 'Sermons'])->id;
        $child = $this->categories->create(['name' => '2026', 'parent_id' => $parent])->id;

        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::CATEGORY, $parent);

        $videoId = $this->video('Filed deep');
        $this->videos->setCategories($videoId, [$child]);

        self::assertSame(['a@example.com'], $this->emails($this->videos->find($videoId)));
    }

    /** One person, one email, however many ways they subscribed. */
    public function testSomebodySubscribedTwiceOverGetsOneEmail(): void
    {
        $seriesId = $this->series->create(['title' => 'Advent'])->id;
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SERIES, $seriesId);

        $video = $this->videos->find($this->video('Episode', seriesId: $seriesId));

        self::assertSame(['a@example.com'], $this->emails($video));
    }

    // ------------------------------------------------------------ announcing

    public function testANewVideoIsAnnouncedOnce(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $this->video('Brand new');

        $this->notifier()->run();
        self::assertCount(1, $this->mail->sent);

        // The claim that matters: running again sends nothing.
        $this->notifier()->run();
        self::assertCount(1, $this->mail->sent, 'The same video was announced twice.');
    }

    public function testTheEmailCarriesAWorkingUnsubscribeLink(): void
    {
        $result = $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $this->video('Brand new');

        $this->notifier()->run();

        self::assertStringContainsString('/unsubscribe/' . $result['token'], $this->mail->sent[0]['html']);
        self::assertStringContainsString('/unsubscribe/' . $result['token'], $this->mail->sent[0]['text']);
        self::assertArrayHasKey('List-Unsubscribe', $this->mail->sent[0]['options']['headers'] ?? []);
    }

    /**
     * The failure that would be hardest to take back: a members-only video in
     * an email to a mailing list anybody can join.
     */
    public function testAMemberOnlyVideoIsNeverAnnounced(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $id = $this->video('Members only');
        $this->db()->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$id]);

        $this->notifier()->run();

        self::assertSame([], $this->mail->sent);
    }

    public function testADraftIsNotAnnounced(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $id = $this->video('Draft');
        $this->db()->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$id]);

        $this->notifier()->run();

        self::assertSame([], $this->mail->sent);
    }

    public function testAHiddenVideoIsNotAnnounced(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);
        $id = $this->video('Hidden');
        $this->db()->execute('UPDATE {videos} SET hidden = 1 WHERE id = ?', [$id]);

        $this->notifier()->run();

        self::assertSame([], $this->mail->sent);
    }

    /**
     * A scheduled video is announced when it arrives, not when it was created —
     * which is the whole reason the job asks "what is visible now" rather than
     * being triggered by an edit.
     */
    public function testAScheduledVideoIsAnnouncedOnlyOnceItIsVisible(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);

        $id = $this->video('Later', publishedAt: date('Y-m-d H:i:s', time() + 86400));

        $this->notifier()->run();
        self::assertSame([], $this->mail->sent, 'A scheduled video was announced early.');

        $this->db()->execute(
            'UPDATE {videos} SET published_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s', time() - 60), $id]
        );

        $this->notifier()->run();
        self::assertCount(1, $this->mail->sent);
    }

    /**
     * A site whose cron stopped for six weeks must not, on the day it is fixed,
     * send six weeks of announcements at once.
     */
    public function testAnOldVideoIsMarkedRatherThanMailed(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);

        $old = date('Y-m-d H:i:s', time() - (Notifier::CATCH_UP_DAYS + 3) * 86400);
        $id = $this->video('Ancient', publishedAt: $old);

        $this->notifier()->run();

        self::assertSame([], $this->mail->sent);
        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {announced_videos} WHERE video_id = ?', [$id]),
            'It must be recorded, or it will be reconsidered on every future run.'
        );
    }

    /**
     * The race guard, tested directly because it cannot be seen from run().
     *
     * A single-threaded pass never watches a claim fail: unannounced() has
     * already excluded anything claimed. So the only way to pin the property is
     * to claim the same video twice. It matters because pseudo-cron fires from
     * ordinary web requests, and two of those can arrive at the same moment on
     * a busy site — one of them has to lose, silently and without a lock.
     */
    public function testAVideoCanOnlyBeClaimedOnce(): void
    {
        $id = $this->video('Contested');
        $notifier = $this->notifier();

        self::assertTrue($notifier->claim($id));
        self::assertFalse($notifier->claim($id), 'Two overlapping runs would both announce it.');
    }

    public function testNothingIsSentWhenNobodyHasSubscribed(): void
    {
        $this->video('Brand new');

        $this->notifier()->run();

        self::assertSame([], $this->mail->sent);
    }

    // -------------------------------------------------------------- orphans

    public function testASubscriptionToADeletedSeriesIsPruned(): void
    {
        $seriesId = $this->series->create(['title' => 'Advent'])->id;
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SERIES, $seriesId);
        $this->subscriptions->subscribe('b@example.com', SubscriptionRepository::SITE, null);

        $this->series->delete($seriesId);

        self::assertSame(1, $this->subscriptions->pruneOrphans());
        self::assertSame(1, $this->subscriptions->count());
    }

    /** Pruning must never touch a site-wide subscription, which names nothing. */
    public function testPruningLeavesSiteSubscriptionsAlone(): void
    {
        $this->subscriptions->subscribe('a@example.com', SubscriptionRepository::SITE, null);

        self::assertSame(0, $this->subscriptions->pruneOrphans());
        self::assertSame(1, $this->subscriptions->count());
    }

    // --------------------------------------------------------------- fixtures

    private function notifier(): Notifier
    {
        // A base URL is mandatory rather than inferred from the request — an
        // emailed link built from HTTP_HOST is a host-header-poisoning bug that
        // this project already fixed once. So the test has to supply one, which
        // is the point.
        $config = new Config('/nonexistent-config.php');
        $config->overlay(['base_url' => 'https://portal.example']);

        return new Notifier(
            $this->db(),
            $config,
            $this->subscriptions,
            $this->videos,
            $this->mail,
        );
    }

    /** @return list<string> */
    private function emails(?Video $video): array
    {
        self::assertNotNull($video);

        return array_map(
            static fn (array $r): string => $r['email'],
            $this->subscriptions->recipientsFor($video)
        );
    }

    private function video(
        string $title,
        ?int $seriesId = null,
        ?int $speakerId = null,
        ?string $publishedAt = null,
    ): int {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'series_id'    => $seriesId,
            'speaker_id'   => $speakerId,
            'published_at' => $publishedAt ?? $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}

/**
 * A mail provider that remembers instead of sending.
 *
 * Counting messages is the point: asserting that a second run "does nothing"
 * is much weaker than asserting that the list of sent mail did not grow.
 */
final class RecordingMailer implements MailProvider
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public static function slug(): string
    {
        return 'recording';
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
        $this->sent[] = [
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
            'text'    => (string) $text,
            'options' => $options,
        ];

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
