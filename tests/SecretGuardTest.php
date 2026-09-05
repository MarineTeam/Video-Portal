<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\SecretGuard;
use RuntimeException;

/**
 * The one list of things that must never leave.
 *
 * The rule under test is not "secrets are removed" — it is that a forbidden key
 * reaching an exit is an ERROR. Stripping is the instinct and it is wrong: a
 * key arriving there means a query started selecting something it should not,
 * and quietly removing it hides that until a column lands which is not on the
 * list.
 */
final class SecretGuardTest extends TestCase
{
    // ------------------------------------------------------ it throws

    public function testAForbiddenKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);

        SecretGuard::assertClean(['email' => 'a@example.test', 'password_hash' => '$2y$...']);
    }

    /**
     * IT THROWS RATHER THAN STRIPPING, and this is the test that says so.
     *
     * A guard that returned a cleaned copy would satisfy every other test in
     * this file. This one fails if somebody ever "improves" it that way.
     */
    public function testItDoesNotOfferACleanedCopy(): void
    {
        self::assertFalse(
            method_exists(SecretGuard::class, 'strip'),
            'a strip() would make the throw optional, and the optional one always wins'
        );
        self::assertFalse(method_exists(SecretGuard::class, 'clean'));
        self::assertFalse(method_exists(SecretGuard::class, 'filter'));
        self::assertFalse(method_exists(SecretGuard::class, 'sanitize'));
    }

    /** The message names the key and the path, so the query can be found. */
    public function testTheMessageNamesTheKeyAndWhereItWas(): void
    {
        try {
            SecretGuard::assertClean(['rows' => [['token' => 'abc']]], 'feed');
            self::fail('it let a token through');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('token', $e->getMessage());
            self::assertStringContainsString('feed.rows', $e->getMessage());
        }
    }

    /**
     * And never the value. An exception message reaches a log, and a log is one
     * of the places a leaked secret ends up.
     */
    public function testTheMessageNeverCarriesTheSecretItself(): void
    {
        try {
            SecretGuard::assertClean(['password_hash' => 'THE-ACTUAL-HASH-VALUE']);
            self::fail('it let a hash through');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('THE-ACTUAL-HASH-VALUE', $e->getMessage());
        }
    }

    // --------------------------------------------------- it walks properly

    /**
     * Arrays at any depth, because nearly everything this app hands out is a
     * list of rows — a guard that only checked the top level would pass every
     * export it was written for.
     */
    public function testItFindsAKeyBuriedInAListOfRows(): void
    {
        $payload = [
            'account' => ['email' => 'a@example.test'],
            'devices' => [
                ['name' => 'Phone'],
                ['name' => 'Laptop', 'auth_secret' => 'xxx'],
            ],
        ];

        $this->expectException(RuntimeException::class);
        SecretGuard::assertClean($payload);
    }

    public function testItWalksObjectsAsJsonEncodeWould(): void
    {
        $row = new \stdClass();
        $row->title = 'A sermon';
        $row->token = 'unsubscribe-me';

        $this->expectException(RuntimeException::class);
        SecretGuard::assertClean(['rows' => [$row]]);
    }

    public function testKeysAreMatchedWithoutRegardToCase(): void
    {
        $this->expectException(RuntimeException::class);
        SecretGuard::assertClean(['Password_Hash' => 'x']);
    }

    /** A cycle is stopped rather than taking the site down inside the guard. */
    public function testAnAbsurdlyNestedPayloadIsRefusedRatherThanRecursedForever(): void
    {
        $deep = ['x' => 'end'];
        for ($i = 0; $i < 40; $i++) {
            $deep = ['level' => $deep];
        }

        $this->expectException(RuntimeException::class);
        SecretGuard::assertClean($deep);
    }

    // ------------------------------------------------- it lets through

    /** An ordinary payload passes untouched. */
    public function testAnOrdinaryPayloadIsFine(): void
    {
        SecretGuard::assertClean([
            'account'  => ['email' => 'me@example.test', 'name' => 'Me'],
            'history'  => [['title' => 'A sermon', 'position_seconds' => 120]],
            'settings' => ['theme' => 'dark'],
        ]);

        self::assertTrue(true, 'reached without throwing');
    }

    /**
     * The member's OWN address is not a secret. Getting this wrong would make
     * the export refuse to describe the person who asked for it.
     */
    public function testAMembersOwnAddressIsNotForbidden(): void
    {
        self::assertTrue(SecretGuard::isClean(['email' => 'me@example.test']));
    }

    /** Scalars, nulls and empty structures are all nothing to worry about. */
    public function testNonArrayPayloadsAreAccepted(): void
    {
        SecretGuard::assertClean('a string');
        SecretGuard::assertClean(42);
        SecretGuard::assertClean(null);
        SecretGuard::assertClean([]);

        self::assertTrue(true, 'reached without throwing');
    }

    // ------------------------------------------------------- the list

    /**
     * Every name on the list is a real column or setting in this application.
     *
     * A guard listing keys nothing produces gives false confidence: it looks
     * thorough and guards nothing. These were read off the schema.
     */
    public function testTheListCoversTheThingsThisAppActuallyStores(): void
    {
        foreach ([
            'password_hash',    // {users}
            'credentials',      // {providers}
            'auth_secret',      // {push_subscriptions}
            'p256dh',           // {push_subscriptions}
            'endpoint',         // {push_subscriptions} — a capability, not a name
            'token',            // {subscriptions}, and the feed token to come
            'added_by',         // {signin_allowlist}, {guest_exemptions}
            'actor_email',      // {audit_log}
        ] as $key) {
            self::assertContains($key, SecretGuard::FORBIDDEN, "{$key} is stored here and is not on the list");
        }
    }

    /**
     * Staff names are on it, and that is deliberate rather than an overreach.
     *
     * They record which member of staff made a decision ABOUT a person. A
     * member-facing export carrying one turns an administrative record into a
     * personal one about somebody who never asked to be named in it.
     */
    public function testStaffNamesCannotLeaveInAMemberFacingPayload(): void
    {
        $this->expectException(RuntimeException::class);

        SecretGuard::assertClean([
            'my_access' => [['email' => 'me@example.test', 'added_by' => 'admin@example.test']],
        ]);
    }
}
