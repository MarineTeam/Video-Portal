<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\Session;
use Portal\Http\Request;
use Portal\Http\Response;

/**
 * Session survival across requests.
 *
 * A live install kept losing its sign-in. The cause: session data loads
 * lazily, and commit() serialised whatever was in memory. A request that never
 * touched the session — a 404, or the /theme-asset/theme.css fetch that every
 * single page load makes — still had the empty array it was constructed with,
 * and wrote that back over the row.
 *
 * So a signed-in person was logged out by their own stylesheet request,
 * roughly whenever it happened to finish after the page request. That produced
 * every symptom reported: intermittent "the site forgets it is logged in", a
 * 404 causing a sign-out, and the OIDC pending-state map vanishing so the next
 * callback claimed the sign-in link had already been used.
 *
 * These run against a real database, because the bug lives in what gets
 * written to it.
 */
final class SessionPersistenceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        $this->truncate(['sessions']);
    }

    /**
     * One request: boot from a cookie, do something, commit, hand back the
     * cookie the browser would now hold.
     *
     * @param callable(Session): void $work
     */
    private function request(?string $cookie, callable $work): ?string
    {
        $request = new Request(
            'GET',
            '/',
            cookies: $cookie === null ? [] : ['portal_session' => $cookie],
        );

        $session = new Session($this->db());
        $session->boot($request);

        $work($session);

        $response = new Response();
        $session->commit($response, $request);

        return $this->cookieFrom($response) ?? $cookie;
    }

    private function cookieFrom(Response $response): ?string
    {
        // Response holds queued cookies privately; read them the same way the
        // browser effectively would, via the send path's own data.
        $reflection = new \ReflectionProperty(Response::class, 'cookies');
        /** @var list<array{name: string, value: string, options: array<string, mixed>}> $cookies */
        $cookies = $reflection->getValue($response);

        foreach ($cookies as $cookie) {
            if ($cookie['name'] === 'portal_session') {
                return $cookie['value'] !== '' ? $cookie['value'] : null;
            }
        }

        return null;
    }

    public function testASignedInSessionPersistsAcrossRequests(): void
    {
        $cookie = $this->request(null, static function (Session $session): void {
            $session->login(42);
        });

        self::assertNotNull($cookie);

        $this->request($cookie, function (Session $session): void {
            self::assertSame(42, $session->userId());
        });
    }

    /**
     * The exact bug. A request that reads nothing from the session must leave
     * it alone.
     */
    public function testARequestThatNeverTouchesTheSessionDoesNotEraseIt(): void
    {
        $cookie = $this->request(null, static function (Session $session): void {
            $session->login(7);
            $session->put('oidc.pending', ['abc' => ['nonce' => 'n', 'at' => time()]]);
        });

        self::assertNotNull($cookie);

        // A 404, or an asset fetch: boots the session and never reads it.
        $this->request($cookie, static function (Session $session): void {
            // deliberately nothing
        });

        $this->request($cookie, function (Session $session): void {
            self::assertSame(7, $session->userId(), 'The sign-in was erased by an unrelated request.');
            self::assertSame(
                ['abc' => ['nonce' => 'n', 'at' => $session->get('oidc.pending')['abc']['at']]],
                $session->get('oidc.pending'),
                'Pending sign-in state was erased by an unrelated request.'
            );
        });
    }

    /**
     * The stylesheet request specifically: every page load makes one, so this
     * is the path that made the failure look random.
     */
    public function testTenUntouchedRequestsInARowLeaveTheSessionIntact(): void
    {
        $cookie = $this->request(null, static function (Session $session): void {
            $session->login(99);
        });

        for ($i = 0; $i < 10; $i++) {
            $this->request($cookie, static function (Session $session): void {
                // an asset, a 404, a favicon
            });
        }

        $this->request($cookie, function (Session $session): void {
            self::assertSame(99, $session->userId(), 'Repeated untouched requests eroded the session.');
        });
    }

    public function testWritesStillPersist(): void
    {
        $cookie = $this->request(null, static function (Session $session): void {
            $session->login(5);
            $session->put('colour', 'blue');
        });

        $this->request($cookie, static function (Session $session): void {
            $session->put('colour', 'green');
        });

        $this->request($cookie, function (Session $session): void {
            self::assertSame('green', $session->get('colour'));
            self::assertSame(5, $session->userId());
        });
    }

    public function testForgettingAValuePersists(): void
    {
        $cookie = $this->request(null, static function (Session $session): void {
            $session->login(5);
            $session->put('temp', 'value');
        });

        $this->request($cookie, static function (Session $session): void {
            $session->forget('temp');
        });

        $this->request($cookie, function (Session $session): void {
            self::assertNull($session->get('temp'));
            self::assertSame(5, $session->userId(), 'Removing one key must not drop the sign-in.');
        });
    }

    public function testLogoutClearsTheSessionAndTheCookie(): void
    {
        $cookie = $this->request(null, static function (Session $session): void {
            $session->login(11);
        });

        $request = new Request('GET', '/auth/logout', cookies: ['portal_session' => (string) $cookie]);
        $session = new Session($this->db());
        $session->boot($request);
        $session->logout();

        $response = new Response();
        $session->commit($response, $request);

        // Row gone.
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {sessions}'),
            'Signing out should remove the session row.'
        );

        // And the browser is told to drop the cookie.
        self::assertNull($this->cookieFrom($response));

        // Presenting the old cookie afterwards is not signed in.
        $this->request($cookie, function (Session $session): void {
            self::assertNull($session->userId());
        });
    }

    /** An anonymous visitor should not get a session row at all. */
    public function testAnonymousBrowsingCreatesNoSession(): void
    {
        $this->request(null, static function (Session $session): void {
            // just looking
        });

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {sessions}'));
    }

    /** Signing in rotates the id, so a fixated cookie cannot be inherited. */
    public function testSigningInRotatesTheSessionId(): void
    {
        $before = $this->request(null, static function (Session $session): void {
            $session->put('pre', 'login');
        });

        self::assertNotNull($before);

        $after = $this->request($before, static function (Session $session): void {
            $session->login(3);
        });

        self::assertNotNull($after);
        self::assertNotSame($before, $after, 'The session id must change on sign-in.');

        // The old cookie no longer works.
        $this->request($before, function (Session $session): void {
            self::assertNull($session->userId());
        });
    }

    public function testLastActiveIsRefreshedByAnUntouchedRequest(): void
    {
        $cookie = $this->request(null, static function (Session $session): void {
            $session->login(21);
        });

        $this->db()->execute(
            'UPDATE {sessions} SET last_active_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
        );

        $this->request($cookie, static function (Session $session): void {
            // an asset request
        });

        $age = (int) $this->db()->value(
            'SELECT TIMESTAMPDIFF(SECOND, last_active_at, NOW()) FROM {sessions}'
        );

        self::assertLessThan(60, $age, 'An untouched request should still count as activity.');
    }
}
