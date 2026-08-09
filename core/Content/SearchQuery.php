<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * What somebody typed into the search box, and what it is worth.
 *
 * Pure: no database. Two jobs, both of which need to be testable on their own —
 * turning a raw string into terms, and scoring a candidate against them.
 *
 * # Why not MySQL FULLTEXT
 *
 * It is the obvious answer and it is the wrong one for a product that installs
 * on shared hosting. InnoDB's full-text index ignores tokens shorter than
 * `innodb_ft_min_token_size` (three characters by default) and drops a built-in
 * stopword list. On a sermon archive that silently loses "joy", "sin", "Job",
 * and "war" — and the fix is a `my.cnf` change plus an index rebuild, neither
 * of which a customer on DreamHost can do. A search that returns nothing for a
 * three-letter word, with no error and no way to fix it, is worse than a
 * slower one that works.
 *
 * The cost is honest: this scans rather than seeks, so it is linear in the
 * library. On the libraries this product is for — hundreds to low thousands of
 * videos — that is milliseconds. A site large enough for it to matter is a site
 * large enough to want a search plugin backed by something real, which the
 * plugin API can provide.
 */
final class SearchQuery
{
    /**
     * How many terms are honoured.
     *
     * Every term becomes several LIKE comparisons, so an unbounded query is a
     * free way for anybody to make the database work hard. Eight is more than
     * anyone types deliberately and cheap enough not to care about.
     */
    public const MAX_TERMS = 8;

    /** Beyond this a "term" is a paste, not a word. */
    public const MAX_TERM_LENGTH = 64;

    // Weights. Relative order matters much more than the absolute numbers: a
    // title match must beat any number of description matches, or searching a
    // common word ranks whichever video happens to mention it most.
    public const WEIGHT_TITLE_EXACT  = 100;
    public const WEIGHT_TITLE_PREFIX = 25;
    public const WEIGHT_TITLE        = 15;
    public const WEIGHT_SPEAKER      = 10;
    public const WEIGHT_SERIES       = 8;
    public const WEIGHT_CATEGORY     = 5;
    public const WEIGHT_DESCRIPTION  = 3;

    /**
     * Below description, on purpose.
     *
     * A transcript is tens of thousands of words, so almost every common word
     * appears in almost every one. Weighting it near a title would make the
     * ranking meaningless — every search would return the whole library in
     * arbitrary order. Low weight means transcripts BREAK TIES and surface
     * videos nothing else matched, which is what they are for: finding the
     * sermon where somebody said a particular phrase.
     */
    public const WEIGHT_TRANSCRIPT   = 2;

    /**
     * Split a raw query into terms.
     *
     * A double-quoted run is kept whole, so `"sermon on the mount"` is one term
     * rather than four — the one piece of syntax worth supporting, because
     * without it a multi-word title cannot be searched precisely at all.
     *
     * Everything else is split on whitespace. Terms are lowercased here so the
     * caller never has to remember to, and deduplicated so typing a word twice
     * does not double its score.
     *
     * @return list<string>
     */
    public static function terms(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $terms = [];

        // Quoted phrases first, removed as they are taken, so the leftover text
        // can be split naively without having to think about quotes again.
        if (preg_match_all('/"([^"]+)"/u', $raw, $matches) > 0) {
            foreach ($matches[1] as $phrase) {
                $terms[] = $phrase;
            }
            $raw = (string) preg_replace('/"[^"]*"/u', ' ', $raw);
        }

        // An unbalanced quote is a half-typed one. Its contents are still words
        // somebody meant, so the quote character is dropped and they are split
        // normally rather than the query being refused.
        $raw = str_replace('"', ' ', $raw);

        foreach (preg_split('/\s+/u', $raw) ?: [] as $word) {
            if ($word !== '') {
                $terms[] = $word;
            }
        }

        $out = [];
        foreach ($terms as $term) {
            $term = mb_strtolower(trim($term));

            if ($term === '' || mb_strlen($term) > self::MAX_TERM_LENGTH) {
                continue;
            }

            $out[$term] = true;

            if (count($out) >= self::MAX_TERMS) {
                break;
            }
        }

        return array_keys($out);
    }

    /**
     * Score one candidate against a parsed query.
     *
     * The twin of the SQL expression in VideoRepository::search(). It exists
     * because ordering has to happen in SQL — before the LIMIT, or the top
     * result is whichever of the first page happened to match best — and a
     * scoring rule written only in SQL is a scoring rule nothing can test.
     * A test asserts the two agree; without it they would drift on the first
     * change and nobody would notice which one was wrong.
     *
     * @param list<string>         $terms  from terms()
     * @param array<string,string> $fields title, description, speaker, series, categories
     */
    public static function score(array $terms, array $fields): int
    {
        if ($terms === []) {
            return 0;
        }

        $title = mb_strtolower((string) ($fields['title'] ?? ''));
        $description = mb_strtolower((string) ($fields['description'] ?? ''));
        $speaker = mb_strtolower((string) ($fields['speaker'] ?? ''));
        $series = mb_strtolower((string) ($fields['series'] ?? ''));
        $categories = mb_strtolower((string) ($fields['categories'] ?? ''));
        $transcript = mb_strtolower((string) ($fields['transcript'] ?? ''));

        $score = 0;

        // The whole query, as typed, being the entire title. Scored once rather
        // than per term: it is a single fact about the candidate, and the thing
        // somebody who typed a full title is unambiguously looking for.
        if ($title !== '' && $title === mb_strtolower(trim(implode(' ', $terms)))) {
            $score += self::WEIGHT_TITLE_EXACT;
        }

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            if (str_starts_with($title, $term)) {
                // A prefix match is worth more than one buried mid-string:
                // titles lead with what they are about.
                $score += self::WEIGHT_TITLE_PREFIX;
            } elseif (str_contains($title, $term)) {
                $score += self::WEIGHT_TITLE;
            }

            if (str_contains($speaker, $term)) {
                $score += self::WEIGHT_SPEAKER;
            }
            if (str_contains($series, $term)) {
                $score += self::WEIGHT_SERIES;
            }
            if (str_contains($categories, $term)) {
                $score += self::WEIGHT_CATEGORY;
            }
            if (str_contains($description, $term)) {
                $score += self::WEIGHT_DESCRIPTION;
            }
            if ($transcript !== '' && str_contains($transcript, $term)) {
                $score += self::WEIGHT_TRANSCRIPT;
            }
        }

        return $score;
    }

    /**
     * Did this candidate match at all?
     *
     * Every term has to match something, though not the same something: "grace
     * romans" should find a video called "Romans 8" by a speaker whose series
     * is "Grace Abounding". Requiring all terms in one field is what makes a
     * naive LIKE search useless the moment anybody types two words.
     *
     * @param list<string>         $terms
     * @param array<string,string> $fields
     */
    public static function matches(array $terms, array $fields): bool
    {
        if ($terms === []) {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', [
            (string) ($fields['title'] ?? ''),
            (string) ($fields['description'] ?? ''),
            (string) ($fields['speaker'] ?? ''),
            (string) ($fields['series'] ?? ''),
            (string) ($fields['categories'] ?? ''),
            (string) ($fields['transcript'] ?? ''),
        ]));

        foreach ($terms as $term) {
            if (!str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }
}
