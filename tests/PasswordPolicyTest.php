<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Auth\PasswordPolicy;

/**
 * What counts as an acceptable password.
 *
 * The rule existed since Phase 1 and was called from nowhere, so none of this
 * was ever true in practice — the one password this product asks a person to
 * choose went in unexamined. These tests describe what it does now that
 * something enforces it.
 */
final class PasswordPolicyTest extends TestCase
{
    public function testALongEnoughPassphraseIsAccepted(): void
    {
        self::assertSame([], PasswordPolicy::problems('correct horse battery staple'));
        self::assertTrue(PasswordPolicy::isAcceptable('correct horse battery staple'));
    }

    public function testShortIsRefusedWithTheLengthItNeeds(): void
    {
        $problems = PasswordPolicy::problems('short');

        self::assertNotSame([], $problems);
        self::assertStringContainsString('12 characters', $problems[0]);
    }

    /**
     * Length alone does not save a password everybody tries first.
     */
    public function testACommonPasswordIsRefusedEvenAtLength(): void
    {
        self::assertNotSame([], PasswordPolicy::problems('administrator'));
        self::assertNotSame([], PasswordPolicy::problems('videoportal'));
    }

    public function testTheBlocklistIgnoresCase(): void
    {
        self::assertNotSame([], PasswordPolicy::problems('AdMiNiStRaToR'));
    }

    /**
     * Every reason at once, not the first one. Being told about the length,
     * fixing it, and then being told about the blocklist is being made to
     * guess twice.
     */
    public function testEveryProblemIsReportedTogether(): void
    {
        $problems = PasswordPolicy::problems(' password ');

        self::assertGreaterThan(1, count($problems));
    }

    /**
     * Whitespace at either end is invisible in a password field and survives
     * into the hash, so somebody who cannot sign in tomorrow has no way to see
     * why.
     */
    public function testSurroundingWhitespaceIsRefused(): void
    {
        $problems = PasswordPolicy::problems('a fine long passphrase ');

        self::assertNotSame([], $problems);
        self::assertStringContainsString('space', $problems[0]);
    }

    // ------------------------------------------------------------- the minimum

    public function testAConfiguredMinimumIsHonoured(): void
    {
        self::assertSame([], PasswordPolicy::problems('sixteen chars ok', 16));
        self::assertNotSame([], PasswordPolicy::problems('twelve chars', 16));
    }

    /**
     * A site that configures four has not turned the rule off, it has
     * misunderstood it. The floor is the point below which the answer is no
     * regardless of configuration.
     */
    public function testAMinimumBelowTheFloorIsRaisedToIt(): void
    {
        self::assertSame(PasswordPolicy::FLOOR, PasswordPolicy::minimum(4));
        self::assertSame(PasswordPolicy::FLOOR, PasswordPolicy::minimum(0));
        self::assertSame(PasswordPolicy::FLOOR, PasswordPolicy::minimum(-100));

        self::assertNotSame([], PasswordPolicy::problems('sevench', 1), 'the floor still applies');
    }

    public function testNonsenseConfigurationFallsBackToTheDefault(): void
    {
        self::assertSame(PasswordPolicy::DEFAULT_MINIMUM, PasswordPolicy::minimum(null));
        self::assertSame(PasswordPolicy::DEFAULT_MINIMUM, PasswordPolicy::minimum('not a number'));
        self::assertSame(PasswordPolicy::DEFAULT_MINIMUM, PasswordPolicy::minimum([]));
    }

    public function testAHigherMinimumIsKept(): void
    {
        self::assertSame(32, PasswordPolicy::minimum(32));
    }

    // ---------------------------------------------------------------- unicode

    /**
     * Counted in characters, not bytes.
     *
     * strlen would measure a passphrase in any non-Latin script as far longer
     * than it is — passing a four-character password written in Japanese while
     * refusing an eleven-character one written in English.
     */
    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        // Eight characters, twenty-four bytes in UTF-8.
        $eight = str_repeat('あ', 8);

        self::assertSame(8, mb_strlen($eight));
        self::assertNotSame([], PasswordPolicy::problems($eight), 'eight characters is under twelve');
        self::assertSame([], PasswordPolicy::problems(str_repeat('あ', 12)));
    }

    public function testAnEmptyPasswordIsRefused(): void
    {
        self::assertNotSame([], PasswordPolicy::problems(''));
    }
}
