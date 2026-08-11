<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\Relatedness;

/**
 * What goes in "More like this".
 *
 * The weights are a claim about what somebody wants next, which is exactly the
 * kind of thing that looks obviously right and is off by one somewhere. These
 * pin the claims rather than the numbers: which signal beats which, and what
 * happens when several weak ones pile up against a strong one.
 */
final class RelatednessTest extends TestCase
{
    /** @param array<string, mixed> $signal */
    private function only(array $signal): array
    {
        return $signal + ['series' => false, 'speaker' => false, 'categories' => 0, 'scriptures' => 0];
    }

    // ------------------------------------------------------------- the order

    /**
     * The claim the whole feature rests on: somebody who just finished part
     * three wants part four, and nothing outranks that.
     */
    public function testTheSameSeriesBeatsEverythingElseCombined(): void
    {
        $sibling = $this->only(['series' => true]);
        $everythingElse = $this->only(['speaker' => true, 'categories' => 9, 'scriptures' => 9]);

        self::assertGreaterThan(
            Relatedness::score($everythingElse),
            Relatedness::score($sibling)
        );
    }

    public function testTheSameSpeakerBeatsASharedCategory(): void
    {
        self::assertGreaterThan(
            Relatedness::score($this->only(['categories' => 1])),
            Relatedness::score($this->only(['speaker' => true]))
        );
    }

    /**
     * Scripture sits above category and below series: two talks on the same
     * chapter are genuinely related in a way this product knows about, but a
     * passing citation is weaker evidence than being in the same series.
     */
    public function testScriptureSitsBetweenSeriesAndCategory(): void
    {
        $scripture = Relatedness::score($this->only(['scriptures' => 1]));

        self::assertGreaterThan(Relatedness::score($this->only(['categories' => 1])), $scripture);
        self::assertLessThan(Relatedness::score($this->only(['series' => true])), $scripture);
    }

    // ---------------------------------------------------------------- capping

    /**
     * A video filed under everything must not outrank the next episode.
     *
     * On this kind of site one category often holds most of the library, so
     * uncapped overlap counting would let breadth beat relevance — the video
     * tagged into nine categories would win every time.
     */
    public function testOverlapIsCappedSoBreadthCannotBeatRelevance(): void
    {
        $filedUnderEverything = $this->only(['categories' => 20, 'scriptures' => 20]);

        self::assertLessThan(
            Relatedness::score($this->only(['series' => true])),
            Relatedness::score($filedUnderEverything)
        );
    }

    public function testTwoSharedCategoriesBeatOne(): void
    {
        self::assertGreaterThan(
            Relatedness::score($this->only(['categories' => 1])),
            Relatedness::score($this->only(['categories' => 2]))
        );
    }

    public function testNegativeCountsCannotSubtract(): void
    {
        self::assertSame(
            Relatedness::score($this->only(['speaker' => true])),
            Relatedness::score($this->only(['speaker' => true, 'categories' => -5]))
        );
    }

    // --------------------------------------------------------------- ranking

    public function testRankingIsBestFirst(): void
    {
        $ranked = Relatedness::rank([
            7 => $this->only(['categories' => 1]),
            8 => $this->only(['series' => true]),
            9 => $this->only(['speaker' => true]),
        ]);

        self::assertSame([8, 9, 7], $ranked);
    }

    /**
     * A candidate matching on nothing is not a candidate. The query gathers
     * generously, so this is the filter that keeps an unrelated video out.
     */
    public function testACandidateMatchingNothingIsDropped(): void
    {
        self::assertSame([8], Relatedness::rank([
            8 => $this->only(['series' => true]),
            9 => $this->only([]),
        ]));
    }

    public function testTheListIsCapped(): void
    {
        $signals = [];
        for ($id = 1; $id <= 20; $id++) {
            $signals[$id] = $this->only(['speaker' => true]);
        }

        self::assertCount(Relatedness::LIMIT, Relatedness::rank($signals));
    }

    /**
     * Equal scores fall back to an order somebody chose, not to whatever the
     * database happened to return.
     */
    public function testTiesFollowTheSuppliedOrder(): void
    {
        $signals = [
            5 => $this->only(['speaker' => true]),
            6 => $this->only(['speaker' => true]),
            7 => $this->only(['speaker' => true]),
        ];

        self::assertSame([7, 5, 6], Relatedness::rank($signals, [7, 5, 6], 3));
        self::assertSame([6, 7, 5], Relatedness::rank($signals, [6, 7, 5], 3));
    }

    /**
     * And a tie with no guidance is still stable. An unstable "more like this"
     * reshuffles under somebody halfway through reading it.
     */
    public function testAnUnguidedTieIsStable(): void
    {
        $signals = [9 => $this->only(['speaker' => true]), 3 => $this->only(['speaker' => true])];

        self::assertSame([3, 9], Relatedness::rank($signals, [], 2));
        self::assertSame(Relatedness::rank($signals), Relatedness::rank($signals));
    }

    /**
     * The tiebreak must not promote a weaker candidate. It orders equals; it
     * does not reorder the ranking.
     */
    public function testTheTiebreakNeverOutranksTheScore(): void
    {
        $ranked = Relatedness::rank(
            [4 => $this->only(['categories' => 1]), 5 => $this->only(['series' => true])],
            [4, 5],
            2
        );

        self::assertSame([5, 4], $ranked);
    }

    public function testNoSignalsMeansNoSection(): void
    {
        self::assertSame([], Relatedness::rank([]));
    }
}
