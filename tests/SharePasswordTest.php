<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Sharing\SharePassword;

/**
 * The passphrase rules for a share link.
 *
 * Deliberately looser than PasswordPolicy, which governs the credential
 * somebody signs in with. These protect one video for a few days and are
 * dictated over the phone; holding them to an account password's standard
 * would mean nobody could use the feature.
 */
final class SharePasswordTest extends TestCase
{
    public function testItAcceptsSomethingDictatable(): void
    {
        self::assertTrue(SharePassword::isAcceptable('sixchr'));
        self::assertTrue(SharePassword::isAcceptable('the blue door'));
    }

    public function testItRefusesTooShort(): void
    {
        self::assertFalse(SharePassword::isAcceptable('five5'));
        self::assertFalse(SharePassword::isAcceptable(''));
    }

    /**
     * Whitespace-only is refused rather than counted.
     *
     * Six spaces satisfies a naive length check, cannot be dictated, cannot be
     * typed reliably, and looks to whoever set it exactly like an empty field.
     */
    public function testItRefusesWhitespaceOnly(): void
    {
        self::assertFalse(SharePassword::isAcceptable('      '));
        self::assertFalse(SharePassword::isAcceptable("\t\n  \t\n"));
    }

    /**
     * Length is counted in characters, not bytes. A six-character passphrase
     * written in Japanese is eighteen bytes and must not be refused, and a
     * three-character one must not be accepted for the same reason.
     */
    public function testLengthIsCountedInCharacters(): void
    {
        self::assertTrue(SharePassword::isAcceptable('あおいドアです'));
        self::assertFalse(SharePassword::isAcceptable('あおい'));
    }

    public function testItRefusesSomethingAbsurdlyLong(): void
    {
        self::assertFalse(SharePassword::isAcceptable(str_repeat('x', SharePassword::MAXIMUM + 1)));
    }

    // ---------------------------------------------------------------- hashing

    public function testHashingAndVerifying(): void
    {
        $hash = SharePassword::hash('the blue door');

        self::assertIsString($hash);
        self::assertNotSame('the blue door', $hash, 'the passphrase was stored in the clear');
        self::assertTrue(SharePassword::matches($hash, 'the blue door'));
        self::assertFalse(SharePassword::matches($hash, 'the red door'));
    }

    /** Two links with the same passphrase must not share a hash. */
    public function testTheHashIsSalted(): void
    {
        self::assertNotSame(
            SharePassword::hash('the blue door'),
            SharePassword::hash('the blue door')
        );
    }

    /** Nothing to hash means no passphrase, not a hash of nothing. */
    public function testAnUnusablePassphraseHashesToNull(): void
    {
        self::assertNull(SharePassword::hash(null));
        self::assertNull(SharePassword::hash(''));
        self::assertNull(SharePassword::hash('short'));
        self::assertNull(SharePassword::hash('      '));
    }

    /**
     * The direction that matters most.
     *
     * A link with no passphrase must not be openable by supplying nothing —
     * getting this backwards would make every unprotected link demand a
     * passphrase, or worse, let an empty string open a protected one.
     */
    public function testNoStoredHashMatchesNothingAtAll(): void
    {
        self::assertFalse(SharePassword::matches(null, ''));
        self::assertFalse(SharePassword::matches(null, 'anything'));
        self::assertFalse(SharePassword::matches('', 'anything'));
    }

    public function testAnEmptyAttemptNeverOpensAProtectedLink(): void
    {
        $hash = SharePassword::hash('the blue door');

        self::assertFalse(SharePassword::matches($hash, ''));
        self::assertFalse(SharePassword::matches($hash, ' '));
    }

    /**
     * The bucket is per link, so one person mistyping cannot lock anybody out
     * of a different share.
     */
    public function testTheRateLimitBucketIsPerLink(): void
    {
        self::assertNotSame(SharePassword::bucket('aaa'), SharePassword::bucket('bbb'));
        self::assertSame(SharePassword::bucket('aaa'), SharePassword::bucket('aaa'));
    }
}
