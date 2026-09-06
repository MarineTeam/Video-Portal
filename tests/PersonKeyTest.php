<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Schedules\PersonKey;

/**
 * Matching two spellings of one name.
 *
 * This side of the product is fed from a spreadsheet, and a spreadsheet is
 * typed by people. Every assertion here is about a pair of strings one person
 * would call the same name and a computer would not.
 */
final class PersonKeyTest extends TestCase
{
    // ---------------------------------------------------------- one person

    /** Case, spacing and accents are all the same person. */
    public function testTheSameNameWrittenFourWaysIsOneKey(): void
    {
        $expected = 'jose angel';

        foreach (['José Ángel', 'JOSE ANGEL', ' jose  angel ', 'Jose Angel'] as $spelling) {
            self::assertSame($expected, PersonKey::for($spelling), $spelling);
        }
    }

    /**
     * Both apostrophes, which is the one nobody thinks of.
     *
     * A keyboard and a word processor produce different bytes for it, and a
     * spreadsheet contains both — often in the same column, because somebody
     * pasted half the names from an email.
     */
    public function testAStraightAndACurlyApostropheAgree(): void
    {
        self::assertSame(
            PersonKey::for("O'Brien"),
            PersonKey::for("O\u{2019}Brien"),
            'two ways of typing the same name got different keys'
        );
    }

    public function testGermanAndNordicLettersFold(): void
    {
        self::assertSame('muller', PersonKey::for('Müller'));
        self::assertSame('strasse', PersonKey::for('Straße'));
        self::assertSame('aebleskiver', PersonKey::for('Æbleskiver'));
        self::assertSame('sorensen', PersonKey::for('Sørensen'));
    }

    public function testEasternEuropeanLettersFold(): void
    {
        self::assertSame('dvorak', PersonKey::for('Dvořák'));
        self::assertSame('lukasz', PersonKey::for('Łukasz'));
        self::assertSame('szoke', PersonKey::for('Szőke'));
    }

    /**
     * A script the fold does not cover keeps its own letters.
     *
     * Mangling it would be worse than leaving it: the key only has to match a
     * name against ITSELF, and a Greek name that folded to nothing would match
     * every other Greek name that also folded to nothing.
     */
    public function testAScriptOutsideTheMapIsLeftAloneRatherThanMangled(): void
    {
        $key = PersonKey::for('Ελένη');

        self::assertNotSame('', $key, 'the name folded away to nothing');
        self::assertSame($key, PersonKey::for(' ελένη '), 'it does not even match itself');
        self::assertNotSame($key, PersonKey::for('Δημήτρης'), 'two different names got one key');
    }

    public function testAnEmptyNameHasNoKey(): void
    {
        self::assertSame('', PersonKey::for(''));
        self::assertSame('', PersonKey::for('   '));
        self::assertSame('', PersonKey::for('!!!'));
    }

    // ------------------------------------------------ two different people

    /**
     * THE RULE: names are not reordered.
     *
     * "Smith, John" and "John Smith" get different keys, and that is correct: a
     * rule clever enough to match those is clever enough to match "James,
     * Robert" with "Robert James", who are two different people. The suggestion
     * list is where a human resolves it.
     */
    public function testNamesAreNotReordered(): void
    {
        self::assertNotSame(PersonKey::for('Smith, John'), PersonKey::for('John Smith'));
    }

    public function testDifferentPeopleGetDifferentKeys(): void
    {
        self::assertNotSame(PersonKey::for('John Smith'), PersonKey::for('Jane Smith'));
        self::assertNotSame(PersonKey::for('Müller'), PersonKey::for('Mueller'));
    }

    // -------------------------------------------------------- suggestions

    /**
     * A near-duplicate is suggested; an identical one is not.
     *
     * 100 means the keys already matched and the sync treated them as one
     * person before anybody looked, so offering it as a "suggestion" would be
     * offering to merge somebody with themselves.
     */
    public function testANearDuplicateIsSuggestedAndAnExactMatchIsNot(): void
    {
        self::assertTrue(PersonKey::looksLikeDuplicate('Dave Smith', 'David Smith'));
        self::assertFalse(PersonKey::looksLikeDuplicate('José Ángel', 'Jose Angel'));
        self::assertSame(100, PersonKey::similarity('José Ángel', 'Jose Angel'));
    }

    /**
     * Two different people are not suggested.
     *
     * The bar is deliberately not low: a list that offers every pair of names
     * beginning with the same letter is a list nobody reads, and the merges
     * that then do not happen are the ones it exists for.
     */
    public function testTwoDifferentPeopleAreNotOffered(): void
    {
        self::assertFalse(PersonKey::looksLikeDuplicate('John Smith', 'Jane Smith'));
        self::assertFalse(PersonKey::looksLikeDuplicate('Robert James', 'James Robert'));
        self::assertFalse(PersonKey::looksLikeDuplicate('Anne', 'Andrew'));
    }

    /** Similarity is measured on the keys, so an accent is not a difference. */
    public function testAnAccentDoesNotLowerTheScore(): void
    {
        self::assertSame(
            PersonKey::similarity('Dave Smith', 'David Smith'),
            PersonKey::similarity('Davé Smith', 'David Smith'),
            'an accent counted as a difference and buried a real near-duplicate'
        );
    }

    public function testAnEmptyNameIsNotLikeAnything(): void
    {
        self::assertSame(0, PersonKey::similarity('', 'Anybody'));
        self::assertFalse(PersonKey::looksLikeDuplicate('', ''));
    }
}
