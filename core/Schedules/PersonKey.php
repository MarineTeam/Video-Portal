<?php

declare(strict_types=1);

namespace Portal\Schedules;

/**
 * Turning a name into something two spellings of it can be matched on.
 *
 * Pure: a string in, a string out. No database, no locale.
 *
 * # WHY A KEY AT ALL
 *
 * This side of the product is fed from a spreadsheet, and a spreadsheet is
 * typed by people. "José", "Jose", "JOSE" and " jose " are one person on four
 * rows, and a sync that treated them as four would produce a calendar where
 * somebody is on the rota four times and recognises none of the entries as
 * theirs.
 *
 * So every person carries a normalised key, and matching is on the key.
 *
 * # ACCENT FOLDING WITHOUT intl
 *
 * `iconv('UTF-8', 'ASCII//TRANSLIT')` is the obvious answer and it is not
 * usable here: its output depends on the server's locale, so the same name
 * normalises differently on two hosts — "ö" becomes "o" on one, `"o` on
 * another, and `?` on a third. On a product that installs on whatever shared
 * hosting somebody already has, that is a matching key that changes when the
 * site moves.
 *
 * The map below is explicit for that reason. It is longer than a call to iconv
 * and it answers the same on every host, which is the only property that
 * matters for something two rows are compared on.
 *
 * # WHAT IT DELIBERATELY DOES NOT DO
 *
 * It does not reorder names. "Smith, John" and "John Smith" get different keys,
 * and that is correct: this key decides IDENTITY, and a rule clever enough to
 * match those is clever enough to match "James, Robert" with "Robert James",
 * who are two different people. Near-duplicates are SUGGESTED for merging by
 * `similarity()` and merged by a person, never automatically — which is the
 * rule for this section.
 */
final class PersonKey
{
    /**
     * Latin letters with marks, and the plain letters they fold to.
     *
     * Covers Latin-1 Supplement and Latin Extended-A, which is every accented
     * letter a European name is written with. Anything outside it is left
     * alone rather than mangled: a name in Greek or Arabic keeps its own
     * letters and still matches itself, which is what the key is for.
     */
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
        'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e',
        'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i',
        'į' => 'i', 'ı' => 'i', 'ĳ' => 'ij',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n', 'ŋ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ſ' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u',
        'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    /**
     * The key two spellings of one name share.
     *
     * Lower-cased first, so the map only needs the lower-case forms; then
     * folded; then everything that is not a letter, a digit or a space is
     * removed, and runs of space collapse to one.
     *
     * Punctuation goes because a spreadsheet is full of it — "O'Brien",
     * "O’Brien" with a typographic apostrophe, and "OBrien" are one person, and
     * the two apostrophes are different bytes that no amount of care at the
     * keyboard makes consistent.
     */
    public static function for(string $name): string
    {
        $name = mb_strtolower(trim($name));

        if ($name === '') {
            return '';
        }

        $name = strtr($name, self::FOLD);

        // Unicode-aware: \p{L} keeps Greek, Cyrillic and the rest, which the
        // fold map deliberately leaves alone.
        $name = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $name) ?? $name;

        return trim(preg_replace('~\s+~u', ' ', $name) ?? $name);
    }

    /**
     * How alike two names are, for SUGGESTING a merge.
     *
     * 0 to 100. Never used to merge anything: the rule for this section is that
     * near-duplicates are suggested and a person decides. "Dave Smith" and
     * "David Smith" are usually the same person and occasionally a father and
     * son, and the site is not the thing that knows which.
     *
     * Compared on the KEYS, so an accent or a stray apostrophe does not count
     * as a difference — those are already handled, and letting them lower the
     * score would bury a real near-duplicate under a pile of trivial ones.
     */
    public static function similarity(string $a, string $b): int
    {
        $a = self::for($a);
        $b = self::for($b);

        if ($a === '' || $b === '') {
            return 0;
        }

        if ($a === $b) {
            return 100;
        }

        /*
         * similar_text is byte-based, which is fine here: both sides have been
         * through the fold, so what reaches it is overwhelmingly ASCII. A name
         * in another script compares against itself correctly and against a
         * Latin one at a low score, which is the right answer either way.
         */
        similar_text($a, $b, $percent);

        return (int) round($percent);
    }

    /**
     * Worth putting in front of somebody as a possible duplicate.
     *
     * Deliberately not a low bar. A suggestion list that offers every pair of
     * names beginning with the same letter is a list nobody reads, and the
     * merges that then do not happen are the ones this exists for.
     */
    public const SUGGEST_ABOVE = 82;

    public static function looksLikeDuplicate(string $a, string $b): bool
    {
        $score = self::similarity($a, $b);

        // 100 means the keys are identical, which is not a SUGGESTION — the
        // sync matched them as one person before anybody looked.
        return $score >= self::SUGGEST_ABOVE && $score < 100;
    }
}
