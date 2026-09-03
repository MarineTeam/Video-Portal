<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Auth\SignInAllowlist;

/**
 * Who may sign in, decided without a database.
 *
 * The exemptions are the part worth pinning hardest. Deployment here is a pull
 * on a host with no shell, so a gate that can refuse the last administrator has
 * permanently closed the only screen that could switch it off — and a local
 * password is the documented way back in when a sign-in provider is
 * misconfigured, which is the failure this feature is most likely to cause.
 */
final class SignInAllowlistTest extends TestCase
{
    // ------------------------------------------------------------ exemptions

    /**
     * An administrator is never refused, whatever the list says.
     *
     * Deleting this rule fails here, and it must: it is the difference between
     * a setting somebody can undo and a site nobody can recover.
     */
    public function testAnAdministratorIsNeverRefused(): void
    {
        self::assertNull(SignInAllowlist::decide(true, true, false, null));
        self::assertNull(SignInAllowlist::decide(true, true, false, SignInAllowlist::SUSPENDED));
    }

    /** Neither is an account with a password here — the way back in. */
    public function testALocalPasswordAccountIsNeverRefused(): void
    {
        self::assertNull(SignInAllowlist::decide(true, false, true, null));
        self::assertNull(SignInAllowlist::decide(true, false, true, SignInAllowlist::SUSPENDED));
    }

    /** With the feature off, nobody is refused and the list is inert. */
    public function testNothingIsRefusedWhileTheFeatureIsOff(): void
    {
        self::assertNull(SignInAllowlist::decide(false, false, false, null));
        self::assertNull(SignInAllowlist::decide(false, false, false, SignInAllowlist::SUSPENDED));
    }

    // ---------------------------------------------------------------- refusals

    public function testAnAddressNobodyAddedIsRefused(): void
    {
        self::assertSame(
            SignInAllowlist::NOT_LISTED,
            SignInAllowlist::decide(true, false, false, null)
        );
    }

    /**
     * Suspended is its own answer, not the same as absent.
     *
     * Two different situations needing two different actions: one address was
     * never expected, the other was expected and then stopped. Collapsing them
     * sends an administrator to the wrong screen.
     */
    public function testASuspendedAddressIsRefusedForItsOwnReason(): void
    {
        self::assertSame(
            SignInAllowlist::SUSPENDED_ENTRY,
            SignInAllowlist::decide(true, false, false, SignInAllowlist::SUSPENDED)
        );
    }

    public function testAnActiveAddressPasses(): void
    {
        self::assertNull(SignInAllowlist::decide(true, false, false, SignInAllowlist::ACTIVE));
    }

    /** Each reason says something different and something actionable. */
    public function testEachReasonExplainsItselfDifferently(): void
    {
        $texts = array_map(
            static fn (string $r): string => SignInAllowlist::explain($r),
            [SignInAllowlist::NOT_LISTED, SignInAllowlist::SUSPENDED_ENTRY, SignInAllowlist::NOT_APPROVED]
        );

        foreach ($texts as $text) {
            self::assertNotSame('', $text);
        }

        self::assertSame(count($texts), count(array_unique($texts)));
        self::assertNotSame('', SignInAllowlist::explain('something-else'));
    }

    // ----------------------------------------------------------------- parsing

    /**
     * Pasted text, in the shapes it actually arrives in.
     *
     * The point of the feature is adding two hundred addresses at once, and
     * they come out of a spreadsheet column, a mail client's recipient row, or
     * a wrapped paragraph. An administrator with a list should not have to
     * reformat it before this will take it.
     */
    public function testItAcceptsTheSeparatorsPeopleActuallyPaste(): void
    {
        $parsed = SignInAllowlist::parse("a@example.com, b@example.com;c@example.com\n d@example.com");

        self::assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'],
            $parsed
        );
    }

    public function testItUnwrapsAddressesPastedFromAMailClient(): void
    {
        self::assertSame(
            ['sam@example.com'],
            SignInAllowlist::parse('Sam Smith <Sam@Example.com>')
        );
    }

    /**
     * The same address written two ways is one address.
     *
     * Otherwise the list grows a second row that somebody later suspends,
     * believing they closed the door, while the other row holds it open.
     */
    public function testCasingAndWhitespaceCannotProduceTwoEntries(): void
    {
        self::assertSame(
            ['sam@example.com'],
            SignInAllowlist::parse("  SAM@example.com \n sam@EXAMPLE.com  ")
        );
    }

    public function testEmptyTextParsesToNothing(): void
    {
        self::assertSame([], SignInAllowlist::parse('   '));
        self::assertSame([], SignInAllowlist::parse(''));
    }
}
