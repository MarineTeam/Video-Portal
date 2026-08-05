<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\SearchQuery;

/**
 * Parsing what somebody typed, and deciding what it is worth.
 *
 * The parser matters more than it looks. The version this replaced put the raw
 * string into one LIKE, which meant a two-word query found nothing unless those
 * two words appeared adjacent in one field — a search box that works for
 * exactly the queries nobody needs help with.
 */
final class SearchQueryTest extends TestCase
{
    // ----------------------------------------------------------------- terms

    public function testWordsBecomeSeparateTerms(): void
    {
        self::assertSame(['grace', 'romans'], SearchQuery::terms('grace romans'));
    }

    public function testTermsAreLowercased(): void
    {
        self::assertSame(['romans'], SearchQuery::terms('ROMANS'));
    }

    public function testExtraWhitespaceIsIgnored(): void
    {
        self::assertSame(['a', 'b'], SearchQuery::terms("  a \n\t b  "));
    }

    public function testAnEmptyQueryHasNoTerms(): void
    {
        self::assertSame([], SearchQuery::terms(''));
        self::assertSame([], SearchQuery::terms('   '));
    }

    /** Typing a word twice must not double its weight. */
    public function testRepeatedWordsAreCountedOnce(): void
    {
        self::assertSame(['faith'], SearchQuery::terms('faith faith FAITH'));
    }

    public function testAQuotedPhraseStaysWhole(): void
    {
        self::assertSame(
            ['sermon on the mount'],
            SearchQuery::terms('"sermon on the mount"')
        );
    }

    public function testAQuotedPhraseCanSitBesideLooseWords(): void
    {
        self::assertSame(
            ['sermon on the mount', 'matthew'],
            SearchQuery::terms('"sermon on the mount" matthew')
        );
    }

    /**
     * A half-typed quote is common and must not swallow the rest of the query
     * or refuse it.
     */
    public function testAnUnbalancedQuoteFallsBackToWords(): void
    {
        self::assertSame(['sermon', 'on', 'the'], SearchQuery::terms('"sermon on the'));
    }

    /** Punctuation alone is not a search; the caller needs to know that. */
    public function testPunctuationOnlyProducesNoTerms(): void
    {
        self::assertSame([], SearchQuery::terms('""'));
        self::assertSame([], SearchQuery::terms('" "'));
    }

    /**
     * Each term becomes several LIKE comparisons, so an unbounded query is a
     * cheap way for anybody to make the database work hard.
     */
    public function testTheTermCountIsCapped(): void
    {
        $terms = SearchQuery::terms(implode(' ', range(1, 50)));

        self::assertCount(SearchQuery::MAX_TERMS, $terms);
    }

    public function testAnAbsurdlyLongTermIsDropped(): void
    {
        $long = str_repeat('x', SearchQuery::MAX_TERM_LENGTH + 1);

        self::assertSame(['keep'], SearchQuery::terms($long . ' keep'));
    }

    // --------------------------------------------------------------- matching

    /**
     * The defect this whole class exists to fix.
     *
     * Two words that appear in two different fields must still match. The
     * previous implementation required them adjacent in one.
     */
    public function testTermsMayMatchInDifferentFields(): void
    {
        $fields = ['title' => 'Romans 8', 'series' => 'Grace Abounding'];

        self::assertTrue(SearchQuery::matches(['grace', 'romans'], $fields));
    }

    public function testEveryTermMustMatchSomething(): void
    {
        $fields = ['title' => 'Romans 8', 'series' => 'Grace Abounding'];

        self::assertFalse(SearchQuery::matches(['grace', 'romans', 'leviticus'], $fields));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        self::assertTrue(SearchQuery::matches(['romans'], ['title' => 'ROMANS 8']));
    }

    /** No query matches everything, which is what an empty search box means. */
    public function testNoTermsMatchesAnything(): void
    {
        self::assertTrue(SearchQuery::matches([], ['title' => 'Anything at all']));
    }

    public function testAbsentFieldsDoNotMatch(): void
    {
        self::assertFalse(SearchQuery::matches(['smith'], ['title' => 'Romans 8']));
    }

    // ---------------------------------------------------------------- scoring

    /**
     * The ordering claim, stated as an ordering rather than as numbers, so the
     * weights can be retuned without rewriting the test that protects them.
     */
    public function testATitleMatchOutranksADescriptionMatch(): void
    {
        $terms = ['grace'];

        self::assertGreaterThan(
            SearchQuery::score($terms, ['title' => 'Romans 8', 'description' => 'about grace']),
            SearchQuery::score($terms, ['title' => 'Grace', 'description' => ''])
        );
    }

    public function testATitlePrefixOutranksATitleMatchInTheMiddle(): void
    {
        $terms = ['grace'];

        self::assertGreaterThan(
            SearchQuery::score($terms, ['title' => 'Abounding grace']),
            SearchQuery::score($terms, ['title' => 'Grace abounding'])
        );
    }

    /**
     * Somebody who typed a whole title is unambiguously looking for that video,
     * and no accumulation of partial matches elsewhere should displace it.
     */
    public function testAnExactTitleWinsOutright(): void
    {
        $terms = SearchQuery::terms('grace abounding');

        $exact = SearchQuery::score($terms, ['title' => 'Grace Abounding']);

        $stuffed = SearchQuery::score($terms, [
            'title'       => 'Abounding thoughts on nothing much',
            'description' => 'grace abounding grace abounding',
            'speaker'     => 'Grace Abounding',
            'series'      => 'Grace Abounding',
            'categories'  => 'Grace Abounding',
        ]);

        self::assertGreaterThan($stuffed, $exact);
    }

    public function testASpeakerMatchOutranksASeriesMatch(): void
    {
        $terms = ['smith'];

        self::assertGreaterThan(
            SearchQuery::score($terms, ['series' => 'Smith Lectures']),
            SearchQuery::score($terms, ['speaker' => 'John Smith'])
        );
    }

    public function testMoreTermsMatchedScoresHigher(): void
    {
        $terms = ['grace', 'romans'];

        self::assertGreaterThan(
            SearchQuery::score($terms, ['title' => 'Romans 8']),
            SearchQuery::score($terms, ['title' => 'Romans 8', 'series' => 'Grace Abounding'])
        );
    }

    public function testNothingMatchedScoresZero(): void
    {
        self::assertSame(0, SearchQuery::score(['leviticus'], ['title' => 'Romans 8']));
    }

    public function testNoTermsScoresZero(): void
    {
        self::assertSame(0, SearchQuery::score([], ['title' => 'Romans 8']));
    }
}
