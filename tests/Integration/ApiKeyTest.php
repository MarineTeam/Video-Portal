<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Api\ApiKeys;
use Portal\Api\Scope;
use Portal\Http\HttpException;
use Portal\Support\SecretGuard;

/**
 * Keys for the read API.
 *
 * A key is a password a machine keeps in a config file, and every rule here
 * follows: only the hash is stored, it is shown once, a prefix is kept so the
 * list means something, and the row outlives the key so the history survives a
 * revocation.
 */
final class ApiKeyTest extends DatabaseTestCase
{
    private ApiKeys $keys;

    protected function setUp(): void
    {
        $this->truncate(['api_keys', 'rate_limits']);

        $this->keys = new ApiKeys($this->db());
    }

    // ------------------------------------------------- only the hash is kept

    /**
     * THE RULE. A key that can be read back out of the database is one that
     * leaks with a backup, a support session, or a careless export.
     */
    public function testThePlaintextKeyIsNowhereInTheDatabase(): void
    {
        $made = $this->keys->issue('Nightly sync', [Scope::CONTENT], 'admin@example.test');

        $everything = (string) json_encode($this->db()->all('SELECT * FROM {api_keys}'));

        self::assertStringNotContainsString(
            $made['key'],
            $everything,
            'THE KEY ITSELF IS STORED — it leaks with any backup'
        );

        // And the hash that IS stored is not the key.
        self::assertNotSame(
            $made['key'],
            (string) $this->db()->value('SELECT key_hash FROM {api_keys}')
        );
    }

    /** It still authenticates, which is the whole point of the hash. */
    public function testAKeyAuthenticatesAgainstItsHash(): void
    {
        $made = $this->keys->issue('Nightly sync', [Scope::CONTENT]);

        $found = $this->keys->authenticate($made['key']);

        self::assertNotNull($found);
        self::assertSame($made['id'], (int) $found['id']);
    }

    public function testSomethingThatIsNotAKeyAuthenticatesNothing(): void
    {
        $this->keys->issue('Nightly sync', [Scope::CONTENT]);

        foreach (['', 'nope', 'vpk_', 'vpk_' . str_repeat('0', 64), 'Bearer x'] as $notAKey) {
            self::assertNull($this->keys->authenticate($notAKey), $notAKey);
        }
    }

    /**
     * A PREFIX IS KEPT, or a screen of keys is a screen of identical rows and
     * somebody revoking one has to guess.
     */
    public function testAPrefixIsKeptSoTheListMeansSomething(): void
    {
        $made = $this->keys->issue('Nightly sync', [Scope::CONTENT]);

        $prefix = (string) $this->keys->all()[0]['key_prefix'];

        self::assertNotSame('', $prefix);
        self::assertTrue(str_starts_with($made['key'], $prefix));
        // Long enough to recognise, short enough to be useless.
        self::assertLessThan(20, strlen($prefix));
    }

    // ------------------------------------------ the row outlives the key

    /**
     * THE RULE. "Who made this, when, and when was it last used" is the entire
     * value of an audit trail after an incident — and deleting the row destroys
     * it at precisely the moment somebody needs it.
     */
    public function testRevokingKeepsTheHistoryAndStopsTheKey(): void
    {
        $made = $this->keys->issue('Nightly sync', [Scope::CONTENT], 'admin@example.test');
        $this->keys->touch($made['id']);

        $this->keys->revoke($made['id']);

        self::assertNull(
            $this->keys->authenticate($made['key']),
            'A REVOKED KEY STILL WORKS'
        );

        $row = $this->keys->all()[0];

        self::assertSame('Nightly sync', (string) $row['name']);
        self::assertSame('admin@example.test', (string) $row['created_by']);
        self::assertNotNull($row['revoked_at']);
        self::assertSame(1, (int) $row['uses'], 'the usage history went with the key');
    }

    /** Revoking twice is not an error — somebody handling an incident presses it twice. */
    public function testRevokingTwiceIsNotAnError(): void
    {
        $made = $this->keys->issue('Nightly sync', [Scope::CONTENT]);

        $this->keys->revoke($made['id']);
        $first = (string) $this->keys->all()[0]['revoked_at'];

        $this->keys->revoke($made['id']);

        self::assertSame(
            $first,
            (string) $this->keys->all()[0]['revoked_at'],
            'the second revoke moved the timestamp'
        );
    }

    // ----------------------------------------------------------- the scopes

    /** A key with no scopes could read nothing, so it is refused rather than made. */
    public function testAKeyWithNothingItMayReadIsRefused(): void
    {
        $this->expectException(HttpException::class);

        $this->keys->issue('Useless', []);
    }

    /** An invented scope is dropped, and a key left with none is then refused. */
    public function testAKeyOfOnlyInventedScopesIsRefused(): void
    {
        $this->expectException(HttpException::class);

        $this->keys->issue('Everything', ['groups:address', 'admin', '*']);
    }

    public function testTheScopesComeBackAsTheyWereGranted(): void
    {
        $made = $this->keys->issue('Sync', [Scope::EVENTS, Scope::CONTENT, 'invented']);

        $scopes = ApiKeys::scopesOf((array) $this->keys->authenticate($made['key']));

        self::assertSame([Scope::EVENTS, Scope::CONTENT], $scopes);
    }

    /**
     * And the no-hierarchy rule survives the round trip through the database.
     *
     * Tested here as well as in ApiScopeTest, because a key is stored as JSON
     * and read back — a decode that lost the exact strings would let the pure
     * rule pass while the real one failed.
     */
    public function testAStoredKeyStillGetsNoHierarchy(): void
    {
        $made = $this->keys->issue('Counts only', [Scope::EVENTS]);
        $scopes = ApiKeys::scopesOf((array) $this->keys->authenticate($made['key']));

        self::assertTrue(Scope::allows($scopes, Scope::EVENTS));
        self::assertFalse(
            Scope::allows($scopes, Scope::REGISTRATIONS),
            'A STORED KEY GAINED A SCOPE IT WAS NOT GRANTED'
        );
    }

    // --------------------------------------------------- and it cannot leak

    /**
     * The listing carries no hash, so putting it in a payload is safe.
     *
     * The guard is the backstop rather than the reason: all() names its columns
     * and key_hash is not among them.
     */
    public function testTheListingIsSafeToHandToAScreen(): void
    {
        $this->keys->issue('Nightly sync', [Scope::CONTENT]);

        self::assertTrue(
            SecretGuard::isClean($this->keys->all()),
            'the key listing carries something the guard forbids'
        );
    }

    /** And a raw row is not, which is what the guard is for. */
    public function testARawRowIsRefusedByTheGuard(): void
    {
        $this->keys->issue('Nightly sync', [Scope::CONTENT]);

        self::assertFalse(
            SecretGuard::isClean($this->db()->all('SELECT * FROM {api_keys}')),
            'A KEY HASH COULD BE HANDED OUT BY ANY EXPORT — the guard does not know its name'
        );
    }
}
