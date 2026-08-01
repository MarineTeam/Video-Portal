<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Mail\MailProvider;
use Portal\Mail\SendResult;
use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Sharing\BundleRepository;
use Portal\Sharing\Share;
use Portal\Sharing\ShareMailer;
use Portal\Sharing\ShareRepository;

/**
 * Share notifications.
 *
 * Two properties matter more than the wording:
 *
 *   A FAILED SEND MUST NOT LOSE THE LINK. The share exists whether or not the
 *   message got out, the provider's own error is kept verbatim, and resending
 *   is one click away. Otherwise a wrong SMTP password silently discards work
 *   an admin already did.
 *
 *   EVERY URL COMES FROM CONFIG, never the request. Host is attacker-controlled
 *   on most shared hosts, and an emailed link built from it is phishing wearing
 *   the site's own domain. This was a real, fixed bug in a predecessor app.
 */
final class ShareMailerTest extends DatabaseTestCase
{
    private ShareRepository $shares;
    private BundleRepository $bundles;
    private VideoRepository $videos;
    private RecordingMailProvider $provider;
    private ShareMailer $mailer;
    private int $videoId;

    protected function setUp(): void
    {
        $this->truncate([
            'bundle_items', 'bundles', 'shares', 'video_categories',
            'videos', 'categories', 'settings',
        ]);

        $config = new Config('/nonexistent/none.php');
        $config->overlay(['base_url' => 'https://portal.example']);
        $config->setSetting('site_name', 'Test Portal');

        $categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $categories);
        $this->shares = new ShareRepository($this->db(), $this->videos);
        $this->bundles = new BundleRepository($this->db(), $this->shares);

        $this->provider = new RecordingMailProvider([]);
        $this->mailer = new ShareMailer($config, $this->provider, $this->shares, $this->bundles);

        $this->videoId = $this->makeVideo('A Sermon');
    }

    private function makeVideo(string $title): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider'     => 'bunny',
            'provider_id'  => bin2hex(random_bytes(8)),
            'slug'         => $this->videos->uniqueSlug($title),
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    // ------------------------------------------------------------ single link

    public function testASharedVideoIsAnnounced(): void
    {
        $share = $this->shares->create($this->videoId, 'recipient@example.test');

        $result = $this->mailer->sendShare($share);

        self::assertTrue($result->sent);
        self::assertCount(1, $this->provider->sent);

        $message = $this->provider->sent[0];
        self::assertSame('recipient@example.test', $message['to']);
        self::assertStringContainsString('A Sermon', $message['subject']);
        self::assertStringContainsString('Test Portal', $message['subject']);
    }

    /** Host is attacker-controlled; config is not. */
    public function testLinksAreBuiltFromConfigNotTheRequest(): void
    {
        $share = $this->shares->create($this->videoId, 'recipient@example.test');
        $this->mailer->sendShare($share);

        $html = $this->provider->sent[0]['html'];

        self::assertStringContainsString('https://portal.example/s/' . $share->id, $html);
        self::assertStringNotContainsString('localhost', $html);
    }

    /**
     * Corporate gateways rewrite or strip hrefs often enough that a message
     * offering only a button is one some recipients cannot act on.
     */
    public function testTheUrlAppearsAsCopyableTextToo(): void
    {
        $share = $this->shares->create($this->videoId, 'recipient@example.test');
        $this->mailer->sendShare($share);

        $message = $this->provider->sent[0];
        $url = 'https://portal.example/s/' . $share->id;

        self::assertStringContainsString($url, $message['text'], 'Plain text alternative should carry the URL.');
        self::assertGreaterThanOrEqual(
            2,
            substr_count($message['html'], $url),
            'The URL should appear as both a button and copyable text.'
        );
    }

    public function testAccountModeNamesTheAddressToSignInAs(): void
    {
        $share = $this->shares->create($this->videoId, 'recipient@example.test', [
            'accessMode' => Share::MODE_ACCOUNT,
        ]);

        $this->mailer->sendShare($share);

        self::assertStringContainsString(
            'recipient@example.test',
            $this->provider->sent[0]['html'],
            'A link opened while signed in as someone else refuses; naming the address is the fix.'
        );
    }

    public function testGateModeDoesNotDemandAnAccount(): void
    {
        $share = $this->shares->create($this->videoId, 'recipient@example.test', [
            'accessMode' => Share::MODE_GATE,
        ]);

        $this->mailer->sendShare($share);

        $html = $this->provider->sent[0]['html'];

        self::assertStringContainsString('confirm your email', $html);
        self::assertStringNotContainsString('signed in as', $html);
    }

    public function testSuccessIsRecordedOnTheShare(): void
    {
        $share = $this->shares->create($this->videoId, 'recipient@example.test');
        $this->mailer->sendShare($share);

        $updated = $this->shares->find($share->id);

        self::assertNotNull($updated?->emailedAt);
        self::assertNull($updated->emailError);
    }

    // ------------------------------------------------------- failure handling

    /** The property that matters most. */
    public function testAFailedSendKeepsTheLinkAndTheReason(): void
    {
        $this->provider->failWith('550 Mailbox unavailable');

        $share = $this->shares->create($this->videoId, 'recipient@example.test');
        $result = $this->mailer->sendShare($share);

        self::assertFalse($result->sent);

        $updated = $this->shares->find($share->id);

        self::assertNotNull($updated, 'The share must survive a failed send.');
        self::assertTrue($updated->isLive(), 'And must still work.');
        self::assertSame('550 Mailbox unavailable', $updated->emailError, 'Verbatim, so it can be acted on.');
        self::assertNull($updated->emailedAt);
    }

    public function testAProviderThatThrowsIsContained(): void
    {
        $this->provider->throwOnSend = true;

        $share = $this->shares->create($this->videoId, 'recipient@example.test');
        $result = $this->mailer->sendShare($share);

        self::assertFalse($result->sent);
        self::assertNotNull($this->shares->find($share->id), 'A throwing provider must not lose the share.');
    }

    public function testAnUnconfiguredProviderSaysSoWithoutLosingTheShare(): void
    {
        $this->provider->configured = false;

        $share = $this->shares->create($this->videoId, 'recipient@example.test');
        $result = $this->mailer->sendShare($share);

        self::assertFalse($result->sent);
        self::assertSame([], $this->provider->sent);
        self::assertNotNull($this->shares->find($share->id));
    }

    /** Resending after fixing the config must clear the error. */
    public function testResendingAfterAFailureClearsTheError(): void
    {
        $this->provider->failWith('Connection refused');

        $share = $this->shares->create($this->videoId, 'recipient@example.test');
        $this->mailer->sendShare($share);

        self::assertNotNull($this->shares->find($share->id)?->emailError);

        $this->provider->succeed();
        $this->mailer->sendShare($this->shares->find($share->id));

        $updated = $this->shares->find($share->id);
        self::assertNull($updated?->emailError);
        self::assertNotNull($updated->emailedAt);
    }

    // ---------------------------------------------------------------- bundles

    /**
     * Eight separate emails because eight videos were shared at once would
     * reasonably read as a broken site.
     */
    public function testABundleIsOneEmailNotOnePerVideo(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->shares->create($this->makeVideo("Video {$i}"), 'recipient@example.test');
        }

        $bundle = $this->bundles->ensureFor('recipient@example.test');
        self::assertNotNull($bundle);

        $result = $this->mailer->sendBundle($bundle);

        self::assertTrue($result->sent);
        self::assertCount(1, $this->provider->sent);
        self::assertStringContainsString('4 videos', $this->provider->sent[0]['subject']);
    }

    public function testTheBundleEmailListsEveryLiveVideo(): void
    {
        foreach (['First', 'Second', 'Third'] as $title) {
            $this->shares->create($this->makeVideo($title), 'recipient@example.test');
        }

        $bundle = $this->bundles->ensureFor('recipient@example.test');
        self::assertNotNull($bundle);

        $this->mailer->sendBundle($bundle);
        $html = $this->provider->sent[0]['html'];

        foreach (['First', 'Second', 'Third'] as $title) {
            self::assertStringContainsString($title, $html);
        }
    }

    public function testARevokedVideoIsNotListed(): void
    {
        $first = $this->shares->create($this->makeVideo('Kept'), 'recipient@example.test');
        $second = $this->shares->create($this->makeVideo('Revoked'), 'recipient@example.test');
        $this->shares->create($this->makeVideo('Also kept'), 'recipient@example.test');

        $bundle = $this->bundles->ensureFor('recipient@example.test');
        self::assertNotNull($bundle);

        $this->shares->revoke($second->id);
        $this->mailer->sendBundle($bundle);

        $html = $this->provider->sent[0]['html'];

        self::assertStringContainsString('Kept', $html);
        self::assertStringNotContainsString('Revoked', $html);
    }

    public function testSendingABundleStampsEveryMember(): void
    {
        $shares = [];
        foreach (['One', 'Two'] as $title) {
            $shares[] = $this->shares->create($this->makeVideo($title), 'recipient@example.test');
        }

        $bundle = $this->bundles->ensureFor('recipient@example.test');
        self::assertNotNull($bundle);

        $this->mailer->sendBundle($bundle);

        foreach ($shares as $share) {
            self::assertNotNull(
                $this->shares->find($share->id)?->emailedAt,
                'The admin table should show every bundled link as notified.'
            );
        }
    }

    public function testAnEmptyBundleIsNotSent(): void
    {
        $shares = [];
        foreach (['One', 'Two'] as $title) {
            $shares[] = $this->shares->create($this->makeVideo($title), 'recipient@example.test');
        }

        $bundle = $this->bundles->ensureFor('recipient@example.test');
        self::assertNotNull($bundle);

        foreach ($shares as $share) {
            $this->shares->revoke($share->id);
        }

        $result = $this->mailer->sendBundle($bundle);

        self::assertFalse($result->sent);
        self::assertSame([], $this->provider->sent);
    }

    // ---------------------------------------------------------------- notify

    /** One live share is better served by the link than by an index page. */
    public function testNotifyUsesADirectLinkForASingleShare(): void
    {
        $this->shares->create($this->videoId, 'recipient@example.test');

        $result = $this->mailer->notify('recipient@example.test');

        self::assertTrue($result->sent);
        self::assertStringContainsString('A Sermon', $this->provider->sent[0]['subject']);
    }

    public function testNotifyUsesTheBundleOnceThereAreSeveral(): void
    {
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->shares->create($this->makeVideo($title), 'recipient@example.test');
        }
        $this->bundles->ensureFor('recipient@example.test');

        $result = $this->mailer->notify('recipient@example.test');

        self::assertTrue($result->sent);
        self::assertStringContainsString('3 videos', $this->provider->sent[0]['subject']);
    }

    public function testNotifyingSomeoneWithNothingLiveDoesNotSend(): void
    {
        $result = $this->mailer->notify('nobody@example.test');

        self::assertFalse($result->sent);
        self::assertSame([], $this->provider->sent);
    }

    // ------------------------------------------------------------- gate link

    /**
     * Sent in response to an unauthenticated request, so it must not confirm
     * anything to someone who merely guessed an address.
     */
    public function testTheGateLinkRevealsNothingAboutWhatWasShared(): void
    {
        $this->mailer->sendGateLink('someone@example.test', 'https://portal.example/s/abc?key=xyz', 'A Sermon');

        $message = $this->provider->sent[0];

        self::assertStringNotContainsString('A Sermon', $message['subject']);
        self::assertStringContainsString('sign-in link', $message['subject']);
        self::assertStringContainsString('expires in an hour', $message['html']);
    }

    // -------------------------------------------------------------- escaping

    /** Titles are editor-supplied, and an editor account is what an attacker targets. */
    public function testTitlesAreEscaped(): void
    {
        $videoId = $this->makeVideo('<script>alert(1)</script>');
        $share = $this->shares->create($videoId, 'recipient@example.test');

        $this->mailer->sendShare($share);

        $html = $this->provider->sent[0]['html'];

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}

/**
 * A mail provider that records instead of sending.
 */
final class RecordingMailProvider implements MailProvider
{
    /** @var list<array{to: string, subject: string, html: string, text: string}> */
    public array $sent = [];

    public bool $configured = true;
    public bool $throwOnSend = false;
    private ?string $failure = null;

    /** @param array<string, string> $credentials */
    public function __construct(private readonly array $credentials)
    {
    }

    public function failWith(string $error): void
    {
        $this->failure = $error;
    }

    public function succeed(): void
    {
        $this->failure = null;
    }

    public static function slug(): string
    {
        return 'recording';
    }

    public static function label(): string
    {
        return 'Recording';
    }

    public static function description(): string
    {
        return 'Captures messages for tests.';
    }

    public static function requiredExtensions(): array
    {
        return [];
    }

    /** @return list<SettingField> */
    public static function fields(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function fromAddress(): string
    {
        return 'Test <test@example.test>';
    }

    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $options = []
    ): SendResult {
        if ($this->throwOnSend) {
            throw new \RuntimeException('The provider exploded.');
        }

        if ($this->failure !== null) {
            return SendResult::failure($this->failure);
        }

        $this->sent[] = [
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
            'text'    => (string) $text,
        ];

        return SendResult::success('test-message-id');
    }

    public function test(): TestResult
    {
        return TestResult::pass('Recording provider.');
    }
}
