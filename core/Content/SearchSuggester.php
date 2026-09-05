<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Throwable;

/**
 * "Did you mean" — and the guard that decides whether to say it.
 *
 * TypoTolerance finds the nearest word. This decides whether offering it is
 * safe and worth doing, which is a different question and the one with the
 * rule attached.
 *
 * # THE RULE: a suggestion is verified before it is shown
 *
 * The vocabulary is built from every title in the library, INCLUDING the
 * unpublished, the hidden and the members-only. That is deliberate, and it is
 * only safe because of the check at the end of suggest(): the corrected query
 * is run through the ordinary listing, with the asking viewer's own visibility
 * filters, and is discarded unless it finds something THEY can already see.
 *
 * The reason for building it that way rather than filtering the vocabulary is
 * that filtering it means writing the visibility rules a second time, in a
 * second place, against a different set of tables. This project has said
 * before why that is worse: two implementations of a visibility rule
 * eventually disagree, and the failure is a members-only title on a public
 * page. One implementation, asked at the end, cannot disagree with itself.
 *
 * So the check is not a nicety about usefulness. Remove it and a stranger who
 * types "marrige" is told "did you mean marriage" because one members-only
 * sermon is called that — which is the leak, in the form the spec names
 * specifically: the title is a leak too.
 */
final class SearchSuggester
{
    /**
     * How many words are held in mind at once.
     *
     * Every word is compared against every term, so this bounds the work of a
     * failed search. Four thousand distinct words is a library of a few
     * thousand videos; beyond that the extra words are the long tail, and the
     * long tail is not where a common misspelling lands.
     */
    public const MAX_WORDS = 4000;

    /** Rows read from each source table. A cap, not a page — nothing follows. */
    private const MAX_ROWS = 5000;

    /** @var list<string>|null */
    private ?array $vocabulary = null;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The corrected query, or null.
     *
     * @param callable(string): int $countVisible runs the corrected query
     *                                            through the ordinary listing
     *                                            as the asking viewer and
     *                                            returns how many it found
     */
    public function suggest(string $raw, callable $countVisible): ?string
    {
        $terms = SearchQuery::terms($raw);

        $corrected = TypoTolerance::correct($terms, $this->vocabulary());
        if ($corrected === null) {
            return null;
        }

        $candidate = implode(' ', $corrected);
        if ($candidate === '' || mb_strtolower(trim($raw)) === $candidate) {
            return null;
        }

        /*
         * THE GUARD. Nothing is suggested that does not answer this viewer's
         * search with results this viewer can open. It is both halves at once:
         * a suggestion that finds nothing is useless, and a suggestion drawn
         * from a title they are not allowed to see is a leak.
         */
        if ($countVisible($candidate) < 1) {
            return null;
        }

        return $candidate;
    }

    /**
     * Every distinct word in the library worth correcting towards.
     *
     * Titles, speakers, series, categories and tags — the things people search
     * by name. Not descriptions or transcripts: those are tens of thousands of
     * words each, most of them ordinary English, and a vocabulary full of
     * ordinary English corrects a typo towards whichever common word happens to
     * be one letter away rather than towards the sermon somebody wanted.
     *
     * Memoised per request. A failed search asks once; the suggestion check
     * that follows does not ask again.
     *
     * @return list<string>
     */
    public function vocabulary(): array
    {
        if ($this->vocabulary !== null) {
            return $this->vocabulary;
        }

        $words = [];

        foreach ($this->sources() as $sql) {
            try {
                $rows = $this->db->column($sql);
            } catch (Throwable) {
                /*
                 * One source failing is not a reason to lose the rest, and a
                 * search must not 500 because a suggestion could not be built.
                 * This is a convenience layered on a result page that is
                 * already correct without it.
                 */
                continue;
            }

            foreach ($rows as $text) {
                foreach (self::words((string) $text) as $word) {
                    $words[$word] = true;

                    if (count($words) >= self::MAX_WORDS) {
                        break 3;
                    }
                }
            }
        }

        return $this->vocabulary = array_keys($words);
    }

    /**
     * Split a title into the words worth holding.
     *
     * Anything shorter than TypoTolerance::MIN_LENGTH is dropped here rather
     * than compared and rejected later — it can never be a correction, so
     * keeping it only spends the word budget.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text);

        // Apostrophes are kept inside a word so "god's" does not become "god"
        // plus a fragment; everything else is a separator.
        $parts = preg_split("/[^\p{L}\p{N}']+/u", $text) ?: [];

        $out = [];
        foreach ($parts as $word) {
            $word = trim($word, "'");

            if (strlen($word) >= TypoTolerance::MIN_LENGTH) {
                $out[] = $word;
            }
        }

        return $out;
    }

    /**
     * Where the words come from.
     *
     * Trashed rows are excluded — a deleted video's words are noise, and
     * suggesting one would send somebody towards a page that 404s. Nothing
     * else is filtered here; see the class comment for why the visibility
     * question is answered at the end instead.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $limit = self::MAX_ROWS;

        return [
            "SELECT title FROM {videos} WHERE deleted_at IS NULL ORDER BY id DESC LIMIT {$limit}",
            "SELECT name FROM {speakers} LIMIT {$limit}",
            "SELECT title FROM {series} LIMIT {$limit}",
            "SELECT name FROM {categories} WHERE deleted_at IS NULL LIMIT {$limit}",
            "SELECT name FROM {tags} LIMIT {$limit}",
        ];
    }
}
