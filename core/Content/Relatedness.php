<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * What makes one video worth watching after another.
 *
 * The theme has rendered a "More like this" section since Phase 1 and the watch
 * controller has passed it an empty array since Phase 1, so the section has
 * never appeared on any page. This decides what goes in it.
 *
 * Scoring is here, away from the query, for two reasons. It is the part with
 * opinions in it — the ordering of these weights is a claim about what a person
 * wants next, and a claim is worth being able to test. And the query it feeds
 * is a single statement gathering signals for every candidate at once; folding
 * the arithmetic into that SQL would make both harder to read and neither
 * easier to check.
 */
final class Relatedness
{
    /**
     * The weights, and the reasoning for their order.
     *
     * SERIES dominates everything. Somebody who just finished part three of a
     * series wants part four, and no amount of shared category or speaker
     * outranks that. It is worth more than the next two combined so that a
     * sibling episode cannot be pushed down by a video matching on several
     * weaker signals at once.
     *
     * SPEAKER above CATEGORY because a category on this kind of site is often
     * near-universal — "Sermons" may hold ninety per cent of the library, which
     * makes it nearly no information at all. A speaker is a real narrowing.
     *
     * SCRIPTURE sits between them: two talks on the same chapter are genuinely
     * related in a way this product knows about and a generic video library
     * cannot. It is deliberately not the top signal, because a passing citation
     * of a well-known verse is weaker evidence than being in the same series.
     */
    public const SERIES = 100;
    public const SPEAKER = 30;
    public const SCRIPTURE = 20;
    public const CATEGORY = 10;

    /**
     * How many to offer.
     *
     * Four fills one row of the grid at most widths. A longer list is not more
     * helpful — past the first few the scores are close enough that the order
     * is arbitrary, and presenting arbitrary as ranked is a small lie.
     */
    public const LIMIT = 4;

    /**
     * Rank candidates by how related they are.
     *
     * @param array<int, array{series?: bool, speaker?: bool, categories?: int, scriptures?: int}> $signals
     *        keyed by video id
     * @param list<int> $tieBreak video ids in the order to prefer when scores
     *        are equal — pass the ordering a listing would use, so an
     *        unresolvable tie falls back to something a person chose rather
     *        than to whatever the database returned first
     *
     * @return list<int> video ids, best first, at most $limit of them
     */
    public static function rank(array $signals, array $tieBreak = [], int $limit = self::LIMIT): array
    {
        $scored = [];

        foreach ($signals as $id => $signal) {
            $score = self::score($signal);

            // A candidate matching on nothing is not a candidate. It can appear
            // in the input because the query gathers generously.
            if ($score > 0) {
                $scored[(int) $id] = $score;
            }
        }

        $order = array_flip(array_values($tieBreak));

        uksort($scored, static function (int $a, int $b) use ($scored, $order): int {
            if ($scored[$a] !== $scored[$b]) {
                return $scored[$b] <=> $scored[$a];
            }

            // Both known to the tiebreak, or neither: fall back to id so the
            // result is at least stable between requests. An unstable "more
            // like this" reshuffles under somebody halfway through reading it.
            $aRank = $order[$a] ?? PHP_INT_MAX;
            $bRank = $order[$b] ?? PHP_INT_MAX;

            return $aRank === $bRank ? $a <=> $b : $aRank <=> $bRank;
        });

        return array_slice(array_keys($scored), 0, max(0, $limit));
    }

    /**
     * One candidate's score.
     *
     * Overlap counts are capped rather than summed without limit. A video
     * sharing five categories with this one is not five times as related as one
     * sharing a single distinctive category — and left uncapped, a video filed
     * under everything would outrank the next episode of the series being
     * watched.
     *
     * @param array{series?: bool, speaker?: bool, categories?: int, scriptures?: int} $signal
     */
    public static function score(array $signal): int
    {
        $score = 0;

        if (!empty($signal['series'])) {
            $score += self::SERIES;
        }

        if (!empty($signal['speaker'])) {
            $score += self::SPEAKER;
        }

        $score += min(2, max(0, (int) ($signal['scriptures'] ?? 0))) * self::SCRIPTURE;
        $score += min(2, max(0, (int) ($signal['categories'] ?? 0))) * self::CATEGORY;

        return $score;
    }
}
