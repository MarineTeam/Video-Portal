<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Feeds\CalendarFeedRepository;
use Portal\Support\SecretGuard;

/**
 * A personal calendar feed, where the token is the whole of the authentication.
 *
 * A calendar application cannot log in: it fetches a URL on a timer with no
 * session and nobody watching. So that one string decides who may read
 * somebody's rota and whereabouts for the next six months, and these are the
 * rules that follow from it.
 */
final class CalendarFeedTest extends DatabaseTestCase
{
    private CalendarFeedRepository $feeds;

    protected function setUp(): void
    {
        $this->truncate(['calendar_feeds', 'users']);

        $this->feeds = new CalendarFeedRepository($this->db());
    }

    private function member(): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('users', [
            'email'      => 'm-' . bin2hex(random_bytes(4)) . '@example.test',
            'name'       => 'A member',
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ---------------------------------------------- nobody has one until they ask

    /**
     * THE RULE. No row until the button is pressed, so a URL cannot be guessed
     * for an account that never wanted a feed — and a token minted for
     * everybody at install would be a capability handed to anybody who ever
     * reads the database.
     */
    public function testAnAccountHasNoFeedUntilItAsks(): void
    {
        $userId = $this->member();

        self::assertNull($this->feeds->forUser($userId), 'a feed existed before anybody asked');

        $this->feeds->issue($userId);

        self::assertNotNull($this->feeds->forUser($userId));
    }

    // --------------------------------------- replacing stops every subscriber

    /**
     * THE RULE. Replacing the token ends every subscription at once, which is
     * the point rather than a side effect: a feed has no idea who is reading
     * it, so there is no list to revoke one subscriber from.
     */
    public function testReplacingTheTokenStopsTheOldAddressEverywhere(): void
    {
        $userId = $this->member();
        $first = $this->feeds->issue($userId);

        self::assertSame($userId, $this->feeds->userFor($first));

        $second = $this->feeds->issue($userId);

        self::assertNotSame($first, $second);
        self::assertNull(
            $this->feeds->userFor($first),
            'THE OLD ADDRESS STILL WORKS — replacing it did not answer a leak'
        );
        self::assertSame($userId, $this->feeds->userFor($second));
    }

    /** And there is still only one feed, not two. */
    public function testReplacingDoesNotLeaveTwoFeeds(): void
    {
        $userId = $this->member();
        $this->feeds->issue($userId);
        $this->feeds->issue($userId);

        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {calendar_feeds}'));
    }

    /**
     * The counters reset with the token.
     *
     * A "last fetched" carried over from the old address would have a member
     * believing the new one was working before anything had ever asked for it —
     * and that figure is the only evidence they have either way.
     */
    public function testReplacingResetsWhatTheOldSubscriptionDid(): void
    {
        $userId = $this->member();
        $this->feeds->issue($userId);
        $this->feeds->touch($userId);
        $this->feeds->touch($userId);

        self::assertSame(2, (int) ((array) $this->feeds->forUser($userId))['fetches']);

        $this->feeds->issue($userId);

        $feed = (array) $this->feeds->forUser($userId);

        self::assertSame(0, (int) $feed['fetches']);
        self::assertNull($feed['last_used_at']);
    }

    public function testStoppingItRemovesTheFeedEntirely(): void
    {
        $userId = $this->member();
        $token = $this->feeds->issue($userId);

        $this->feeds->revoke($userId);

        self::assertNull($this->feeds->forUser($userId));
        self::assertNull($this->feeds->userFor($token));
    }

    // ------------------------------------------------------- the lookup

    /**
     * A token of the wrong shape never reaches a query.
     *
     * A feed URL is crawled and guessed at constantly, and none of that should
     * become database work.
     */
    public function testSomethingThatIsNotATokenIsRefusedOnShape(): void
    {
        $this->feeds->issue($this->member());

        foreach (['', 'x', 'nope', str_repeat('z', 64), str_repeat('a', 63)] as $notAToken) {
            self::assertNull($this->feeds->userFor($notAToken), $notAToken);
        }
    }

    /** Two members never share a token. */
    public function testTwoMembersGetDifferentTokens(): void
    {
        $one = $this->feeds->issue($this->member());
        $two = $this->feeds->issue($this->member());

        self::assertNotSame($one, $two);
    }

    // ----------------------------------------------- and it cannot leak out

    /**
     * The column is named so the secret guard catches it.
     *
     * Every other exit in this application — the data export, the read API —
     * runs its finished payload past SecretGuard, which forbids `feed_token` by
     * name. So a query that starts selecting it cannot quietly hand it out: the
     * guard THROWS. The only place the token is ever rendered is the member's
     * own settings page, which prints it directly.
     */
    public function testTheTokenCannotLeaveThroughAGuardedPayload(): void
    {
        $userId = $this->member();
        $this->feeds->issue($userId);

        $row = (array) $this->feeds->forUser($userId);

        self::assertArrayHasKey('feed_token', $row);
        self::assertFalse(
            SecretGuard::isClean($row),
            'A FEED TOKEN COULD BE HANDED OUT BY ANY EXPORT — the guard does not know its name'
        );
    }
}
