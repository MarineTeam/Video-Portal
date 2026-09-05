<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Finding the word somebody meant.
 *
 * Pure: no database, no settings, no request. Given the words in a query and
 * the words that exist in this library, it answers "did you mean these
 * instead" — and nothing else. Whether the answer is worth showing is decided
 * in SearchSuggester, which is where the guard lives.
 *
 * # The platform difference, stated rather than papered over
 *
 * Postgres has `pg_trgm`: a trigram index that makes "how similar are these two
 * strings" an indexed operation, so a fuzzy search is a query. MySQL has no
 * equivalent and there is no extension a shared-hosting customer can install.
 * There is no way to write the Postgres query here, so this is not a port —
 * it is a different mechanism reaching a similar place, with different limits,
 * and the settings screen says so in words rather than leaving somebody to
 * discover them.
 *
 * FULLTEXT is the usual MySQL answer and it is the wrong one here for reasons
 * SearchQuery already sets out at length: InnoDB drops tokens under three
 * characters and applies a stopword list, which on a sermon archive silently
 * loses "joy", "sin", "Job" and "war", and the fix is a `my.cnf` change no
 * customer on DreamHost can make. It is also not typo-tolerant — it matches
 * words, and a misspelled word is a different word. Using it here would add the
 * stopword problem and solve nothing.
 *
 * What is left is edit distance and phonetics, both computed in PHP over the
 * library's own vocabulary. That is genuinely less than a trigram index: it
 * cannot rank by similarity, and it corrects a word rather than a phrase.
 * Within its range it is exact, which a similarity threshold is not.
 */
final class TypoTolerance
{
    /**
     * Shorter than this is never corrected.
     *
     * At three letters almost every word in English is one edit from several
     * others — "God" to "good", "gods", "cod", "God" to "Job" in two. A
     * correction there is a guess dressed as help, and the words that suffer
     * most are exactly the ones a sermon archive is full of.
     */
    public const MIN_LENGTH = 4;

    /**
     * levenshtein() counts BYTES, not characters.
     *
     * On a UTF-8 term that inflates every distance, so a word with an accent
     * compares as further away than it is and no correction is offered. That is
     * the safe direction — a missing suggestion is a search that behaves as it
     * did before, where a wrong one sends somebody to the wrong sermon — so
     * non-ASCII terms are skipped outright rather than compared badly.
     */
    private const ASCII_ONLY = '/^[\x20-\x7E]+$/';

    /** A guard on levenshtein()'s cost, which is the product of the lengths. */
    private const MAX_BYTES = 96;

    /**
     * How many edits are forgiven at each length.
     *
     * Scaled, because one edit in a four-letter word is a quarter of it and one
     * edit in "ecclesiastes" is nothing. A flat threshold either refuses real
     * typos in long words or invents corrections for short ones.
     */
    public static function threshold(string $term): int
    {
        $length = strlen($term);

        if ($length < self::MIN_LENGTH) {
            return 0;
        }

        return $length <= 6 ? 1 : 2;
    }

    /**
     * The closest word in the vocabulary, or null if nothing is close enough.
     *
     * A term that is ALREADY in the vocabulary is never corrected, and that is
     * the most important rule here. A search for "grace romans" finding nothing
     * usually means no video has both words, not that either is misspelled —
     * and "correcting" a word the library actually contains would move the
     * results away from what was asked for rather than towards it.
     *
     * @param list<string> $vocabulary lowercased words from the library
     */
    public static function nearest(string $term, array $vocabulary): ?string
    {
        $term = mb_strtolower(trim($term));

        if (!self::comparable($term)) {
            return null;
        }

        /*
         * Length is asked here and nowhere else.
         *
         * This started as two checks — a MIN_LENGTH test and then the
         * threshold — and the first could never fire on its own, because
         * threshold() already answers 0 below MIN_LENGTH. A guard whose
         * removal no test can notice is a comment that looks like one, and
         * this project has recorded that shape twice before. One check, and
         * deleting it fails a test.
         */
        $threshold = self::threshold($term);
        if ($threshold < 1) {
            return null;
        }

        $sound = metaphone($term);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $bestPrefix = -1;

        foreach ($vocabulary as $word) {
            if ($word === $term) {
                // It is a real word in this library. See above.
                return null;
            }

            if (!self::comparable($word)) {
                continue;
            }

            // Cheap rejects before the expensive comparison: a word that
            // differs in length by more than the threshold cannot be within it.
            if (abs(strlen($word) - strlen($term)) > $threshold + 1) {
                continue;
            }

            $distance = levenshtein($term, $word);

            /*
             * Phonetics buy one extra edit, and only one.
             *
             * "filip" reaches "philip" in two edits, which a five-letter word
             * would otherwise refuse; they are obviously the same name to
             * anybody reading them aloud. Without the extra edit the phonetic
             * rule would add nothing, and without the cap it would pair words
             * that merely rhyme.
             */
            $allowed = $threshold;
            if ($sound !== '' && $sound === metaphone($word)) {
                $allowed = $threshold + 1;
            }

            if ($distance > $allowed) {
                continue;
            }

            /*
             * Ties are broken deterministically, so the same query always
             * suggests the same word. Left to array order it would depend on
             * how the vocabulary happened to come back from the database,
             * which changes when somebody adds a video.
             */
            $prefix = self::commonPrefix($term, $word);

            if (
                $distance < $bestDistance
                || ($distance === $bestDistance && $prefix > $bestPrefix)
                || ($distance === $bestDistance && $prefix === $bestPrefix && $best !== null && $word < $best)
            ) {
                $best = $word;
                $bestDistance = $distance;
                $bestPrefix = $prefix;
            }
        }

        return $best;
    }

    /**
     * Correct a whole query, or answer null if nothing needed correcting.
     *
     * Null rather than the original terms, so a caller cannot accidentally
     * treat "no change" as a suggestion and offer somebody their own spelling
     * back as an alternative to itself.
     *
     * @param list<string> $terms      from SearchQuery::terms()
     * @param list<string> $vocabulary lowercased words from the library
     * @return list<string>|null
     */
    public static function correct(array $terms, array $vocabulary): ?array
    {
        if ($terms === [] || $vocabulary === []) {
            return null;
        }

        $corrected = [];
        $changed = false;

        foreach ($terms as $term) {
            /*
             * A quoted phrase is left alone. Somebody who typed quotation marks
             * asked for those exact words in that order, and the honest answer
             * to "no video says that" is that no video says that.
             */
            if (str_contains($term, ' ')) {
                $corrected[] = $term;
                continue;
            }

            $near = self::nearest($term, $vocabulary);

            if ($near === null) {
                $corrected[] = $term;
                continue;
            }

            $corrected[] = $near;
            $changed = true;
        }

        return $changed ? $corrected : null;
    }

    /** ASCII, and short enough that the comparison is cheap. */
    private static function comparable(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::MAX_BYTES
            && preg_match(self::ASCII_ONLY, $value) === 1;
    }

    private static function commonPrefix(string $a, string $b): int
    {
        $limit = min(strlen($a), strlen($b));
        $i = 0;

        while ($i < $limit && $a[$i] === $b[$i]) {
            $i++;
        }

        return $i;
    }
}
