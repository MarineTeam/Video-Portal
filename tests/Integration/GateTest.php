<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Sharing\Gate;
use Portal\Sharing\Share;
use Portal\Sharing\ShareRepository;

/**
 * The account-free access gate.
 *
 * The security property under test is anti-enumeration. A share link plus an
 * email box is enough to ask "does this address have access?", so wrong
 * address, unknown link, revoked, expired, and throttled must all be
 * indistinguishable. Anything else turns the page into an oracle for probing
 * who was sent what.
 */
final class GateTest extends DatabaseTestCase
{
    private Gate $gate;
    private ShareRepository $shares;
    private VideoRepository $videos;
    private int $videoId;

    /** @var list<array{email: string, url: string, title: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->truncate([
            'gate_grants', 'rate_limits', 'bundle_items', 'bundles',
            'shares', 'video_categories', 'videos', 'categories',
        ]);

        $config = new Config('/nonexistent/none.php');
        $config->overlay([
            'base_url'    => 'https://portal.example',
            'gate_secret' => 'a-test-gate-secret-of-reasonable-length',
        ]);

        $this->gate = new Gate($this->db(), $config);

        $categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $categories);
        $this->shares = new ShareRepository($this->db(), $this->videos);

        $now = date('Y-m-d H:i:s');
        $this->videoId = $this->db()->insert('videos', [
            'provider'     => 'bunny',
            'provider_id'  => bin2hex(random_bytes(8)),
            'slug'         => 'a-video',
            'title'        => 'A Video',
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->sent = [];
    }

    /** Captures what would have been emailed. */
    private function sender(): callable
    {
        return function (string $email, string $url, string $title): void {
            $this->sent[] = ['email' => $email, 'url' => $url, 'title' => $title];
        };
    }

    private function makeShare(string $email = 'recipient@example.test'): Share
    {
        return $this->shares->create($this->videoId, $email, ['accessMode' => Share::MODE_GATE]);
    }

    /** Clears the per-target throttle so a test can make a second request. */
    private function clearThrottle(): void
    {
        $this->db()->execute('DELETE FROM {rate_limits}');
    }

    // ------------------------------------------------------------- happy path

    public function testTheRightAddressGetsALink(): void
    {
        $share = $this->makeShare();

        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());

        self::assertCount(1, $this->sent);
        self::assertSame('recipient@example.test', $this->sent[0]['email']);
        self::assertStringContainsString('/s/' . $share->id, $this->sent[0]['url']);
        self::assertStringContainsString('key=', $this->sent[0]['url']);
    }

    public function testTheLinkRedeemsIntoAGrant(): void
    {
        $share = $this->makeShare();
        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());

        $grant = $this->gate->redeem('share', $share->id, $this->tokenFromUrl($this->sent[0]['url']));

        self::assertNotNull($grant);
        self::assertSame('recipient@example.test', $this->gate->verify('share', $share->id, $grant));
    }

    public function testAddressMatchingIgnoresCaseAndSpacing(): void
    {
        $share = $this->makeShare('Person@Example.TEST');

        $this->gate->request('share', $share->id, '  PERSON@example.test ', $this->sender());

        self::assertCount(1, $this->sent);
    }

    // ------------------------------------------------- anti-enumeration

    /**
     * The central property. Every one of these must be indistinguishable from
     * the others, and from a correct request that simply has not arrived yet.
     */
    public function testEveryRefusalLooksIdenticalFromOutside(): void
    {
        $live = $this->makeShare();

        $revoked = $this->makeShare('revoked@example.test');
        $this->shares->revoke($revoked->id);

        $expired = $this->makeShare('expired@example.test');
        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?',
            [$expired->id]
        );

        $cases = [
            'wrong address'   => [$live->id, 'someone.else@example.test'],
            'unknown link'    => ['aaaaaaaaaaaaaaaaaaaa', 'recipient@example.test'],
            'malformed link'  => ['../../etc/passwd', 'recipient@example.test'],
            'revoked link'    => [$revoked->id, 'revoked@example.test'],
            'expired link'    => [$expired->id, 'expired@example.test'],
            'invalid address' => [$live->id, 'not-an-email'],
        ];

        foreach ($cases as $label => [$id, $email]) {
            $this->clearThrottle();
            $this->sent = [];

            $this->gate->request('share', (string) $id, (string) $email, $this->sender());

            self::assertSame([], $this->sent, "{$label} should send nothing and say nothing");
        }
    }

    /**
     * Throttling is per target, not per address. Per address would itself be
     * an oracle: throttled for the right one, immediate for a wrong one.
     */
    public function testASecondRequestIsThrottledEvenWithADifferentAddress(): void
    {
        $share = $this->makeShare();

        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());
        self::assertCount(1, $this->sent);

        // A different address, immediately after: still throttled.
        $this->gate->request('share', $share->id, 'someone.else@example.test', $this->sender());
        self::assertCount(1, $this->sent);

        // And the correct address is throttled too, identically.
        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());
        self::assertCount(1, $this->sent);
    }

    public function testThrottlingIsPerTargetNotGlobal(): void
    {
        $first = $this->makeShare('a@example.test');
        $second = $this->makeShare('b@example.test');

        $this->gate->request('share', $first->id, 'a@example.test', $this->sender());
        $this->gate->request('share', $second->id, 'b@example.test', $this->sender());

        self::assertCount(2, $this->sent, 'One link being asked about must not block another.');
    }

    // ----------------------------------------------------------- single use

    public function testATokenCannotBeRedeemedTwice(): void
    {
        $share = $this->makeShare();
        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());

        $token = $this->tokenFromUrl($this->sent[0]['url']);

        self::assertNotNull($this->gate->redeem('share', $share->id, $token));
        self::assertNull(
            $this->gate->redeem('share', $share->id, $token),
            'A forwarded or recovered link must not open anything a second time.'
        );
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $share = $this->makeShare();
        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());

        $this->db()->execute('UPDATE {gate_grants} SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)');

        self::assertNull($this->gate->redeem('share', $share->id, $this->tokenFromUrl($this->sent[0]['url'])));
    }

    public function testATokenIsBoundToItsTarget(): void
    {
        $first = $this->makeShare('a@example.test');
        $second = $this->makeShare('b@example.test');

        $this->gate->request('share', $first->id, 'a@example.test', $this->sender());
        $token = $this->tokenFromUrl($this->sent[0]['url']);

        self::assertNull(
            $this->gate->redeem('share', $second->id, $token),
            'A token for one share must not open another.'
        );
    }

    public function testOnlyTheHashIsStored(): void
    {
        $share = $this->makeShare();
        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());

        $token = $this->tokenFromUrl($this->sent[0]['url']);
        $stored = (string) $this->db()->value('SELECT token_hash FROM {gate_grants} LIMIT 1');

        self::assertNotSame($token, $stored, 'A database dump must not yield working links.');
        self::assertSame(hash('sha256', $token), $stored);
    }

    // --------------------------------------------------------------- grants

    public function testATamperedGrantIsRefused(): void
    {
        $share = $this->makeShare();
        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());
        $grant = (string) $this->gate->redeem('share', $share->id, $this->tokenFromUrl($this->sent[0]['url']));

        $parts = explode('|', $grant);

        // Swap in a different address, keeping the original signature.
        $forged = implode('|', [$parts[0], $parts[1], 'attacker@example.test', $parts[3], $parts[4]]);

        self::assertNull($this->gate->verify('share', $share->id, $forged));
    }

    public function testAGrantForAnotherShareIsRefused(): void
    {
        $first = $this->makeShare('a@example.test');
        $second = $this->makeShare('b@example.test');

        $this->gate->request('share', $first->id, 'a@example.test', $this->sender());
        $grant = (string) $this->gate->redeem('share', $first->id, $this->tokenFromUrl($this->sent[0]['url']));

        self::assertNotNull($this->gate->verify('share', $first->id, $grant));
        self::assertNull(
            $this->gate->verify('share', $second->id, $grant),
            'A grant is bound to one target.'
        );
    }

    public function testAnExpiredGrantIsRefused(): void
    {
        $share = $this->makeShare();
        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());
        $grant = (string) $this->gate->redeem('share', $share->id, $this->tokenFromUrl($this->sent[0]['url']));

        $parts = explode('|', $grant);
        $parts[3] = (string) (time() - 60);
        // Signature no longer matches the altered expiry, which is the point.

        self::assertNull($this->gate->verify('share', $share->id, implode('|', $parts)));
    }

    public function testMalformedGrantsAreRefused(): void
    {
        $share = $this->makeShare();

        foreach (['', 'nonsense', 'a|b|c', 'a|b|c|d|e|f'] as $bad) {
            self::assertNull($this->gate->verify('share', $share->id, $bad));
        }
    }

    /**
     * An empty secret makes every grant forgeable, and the failure is
     * otherwise silent: the HMAC still computes and still verifies.
     */
    public function testAMissingSecretFailsLoudly(): void
    {
        $config = new Config('/nonexistent/none.php');
        $config->overlay(['base_url' => 'https://portal.example']);

        $gate = new Gate($this->db(), $config);
        $share = $this->makeShare();

        // verify() swallows it and refuses, which is the safe direction.
        self::assertNull($gate->verify('share', $share->id, 'a|b|c|d|e'));

        // And a request cannot silently succeed either.
        $gate->request('share', $share->id, 'recipient@example.test', $this->sender());
        self::assertSame([], $this->sent);
    }

    // --------------------------------------------------------------- cookies

    /**
     * Per-target cookies scoped to that target's path, so a browser only ever
     * sends the grant it needs rather than broadcasting every share someone
     * has access to.
     */
    public function testCookiesAreScopedToOneTarget(): void
    {
        $first = $this->makeShare('a@example.test');
        $second = $this->makeShare('b@example.test');

        self::assertNotSame(
            $this->gate->cookieName('share', $first->id),
            $this->gate->cookieName('share', $second->id)
        );

        self::assertSame('/s/' . $first->id, $this->gate->cookiePath('share', $first->id));
        self::assertSame('/b/' . $first->id, $this->gate->cookiePath('bundle', $first->id));
    }

    // -------------------------------------------------------------- cleanup

    public function testConsumedAndExpiredGrantsArePurged(): void
    {
        $share = $this->makeShare();
        $this->gate->request('share', $share->id, 'recipient@example.test', $this->sender());

        $this->db()->execute('UPDATE {gate_grants} SET expires_at = DATE_SUB(NOW(), INTERVAL 30 DAY)');

        self::assertSame(1, $this->gate->purge());
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {gate_grants}'));
    }

    private function tokenFromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return (string) ($query['key'] ?? '');
    }
}
