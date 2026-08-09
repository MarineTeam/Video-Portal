<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * The canon, and every way people write its book names.
 *
 * Pure data and lookups. It exists as its own class because the alias table is
 * the whole feature: a reference parser that only understands "1 Corinthians"
 * and not "1 Cor", "1Co", "I Corinthians" or "First Corinthians" will miss most
 * of what is actually typed into a sermon description.
 *
 * The deuterocanonical books are included. Leaving them out would make this
 * unusable for a whole class of site — a Catholic or Orthodox parish is exactly
 * the kind of place that runs a sermon archive — and the cost of including them
 * is a longer array and nothing else. A site that does not use them simply
 * never has a video that matches one.
 *
 * Chapter counts are here for one reason: they let an obviously impossible
 * reference be refused. "John 99" is not a scripture reference, and indexing it
 * puts a sermon in a chapter nobody can browse to.
 */
final class ScriptureBooks
{
    /**
     * Every book: slug => [name, testament, chapters, aliases].
     *
     * The slug is what goes in the database and in URLs, so it is stable and
     * lowercase-ascii. The aliases are only ever matched after normalisation —
     * see normalise() — so this list carries the SHAPES people write rather than
     * every combination of dots, spaces and capitalisation.
     *
     * @return array<string, array{name: string, testament: string, chapters: int, aliases: list<string>}>
     */
    public static function all(): array
    {
        static $books = null;

        if ($books !== null) {
            return $books;
        }

        return $books = [
            // ------------------------------------------------- Old Testament
            'genesis'         => ['Genesis', 'ot', 50, ['gen', 'ge', 'gn']],
            'exodus'          => ['Exodus', 'ot', 40, ['exo', 'ex', 'exod']],
            'leviticus'       => ['Leviticus', 'ot', 27, ['lev', 'le', 'lv']],
            'numbers'         => ['Numbers', 'ot', 36, ['num', 'nu', 'nm', 'nb']],
            'deuteronomy'     => ['Deuteronomy', 'ot', 34, ['deut', 'dt', 'de']],
            'joshua'          => ['Joshua', 'ot', 24, ['josh', 'jos', 'jsh']],
            // 'jud' is listed for BOTH Judges and Jude on purpose. People
            // genuinely write it for each, neither has a better claim, and the
            // index turns a spelling two books answer to into a refusal.
            'judges'          => ['Judges', 'ot', 21, ['judg', 'jdg', 'jg', 'jud']],
            'ruth'            => ['Ruth', 'ot', 4, ['rth', 'ru']],
            '1-samuel'        => ['1 Samuel', 'ot', 31, ['1sam', '1sa', '1sm', '1s']],
            '2-samuel'        => ['2 Samuel', 'ot', 24, ['2sam', '2sa', '2sm', '2s']],
            '1-kings'         => ['1 Kings', 'ot', 22, ['1kgs', '1ki', '1kin', '1k']],
            '2-kings'         => ['2 Kings', 'ot', 25, ['2kgs', '2ki', '2kin', '2k']],
            '1-chronicles'    => ['1 Chronicles', 'ot', 29, ['1chron', '1chr', '1ch']],
            '2-chronicles'    => ['2 Chronicles', 'ot', 36, ['2chron', '2chr', '2ch']],
            'ezra'            => ['Ezra', 'ot', 10, ['ezr', 'ez']],
            'nehemiah'        => ['Nehemiah', 'ot', 13, ['neh', 'ne']],
            'esther'          => ['Esther', 'ot', 10, ['esth', 'est', 'es']],
            'job'             => ['Job', 'ot', 42, ['jb']],
            'psalms'          => ['Psalms', 'ot', 150, ['psalm', 'pslm', 'ps', 'psa', 'psm', 'pss']],
            'proverbs'        => ['Proverbs', 'ot', 31, ['prov', 'pro', 'prv', 'pr']],
            'ecclesiastes'    => ['Ecclesiastes', 'ot', 12, ['eccles', 'eccle', 'eccl', 'ecc', 'qoh']],
            'song-of-songs'   => ['Song of Songs', 'ot', 8, [
                'song', 'songofsolomon', 'songofsongs', 'sos', 'sng', 'canticles', 'cant',
            ]],
            'isaiah'          => ['Isaiah', 'ot', 66, ['isa', 'is']],
            'jeremiah'        => ['Jeremiah', 'ot', 52, ['jer', 'je', 'jr']],
            'lamentations'    => ['Lamentations', 'ot', 5, ['lam', 'la']],
            // 'ez' likewise: written for Ezra and for Ezekiel about equally.
            'ezekiel'         => ['Ezekiel', 'ot', 48, ['ezek', 'eze', 'ezk', 'ez']],
            'daniel'          => ['Daniel', 'ot', 12, ['dan', 'da', 'dn']],
            'hosea'           => ['Hosea', 'ot', 14, ['hos', 'ho']],
            'joel'            => ['Joel', 'ot', 3, ['jl']],
            'amos'            => ['Amos', 'ot', 9, ['am']],
            'obadiah'         => ['Obadiah', 'ot', 1, ['obad', 'oba', 'ob']],
            'jonah'           => ['Jonah', 'ot', 4, ['jnh', 'jon']],
            'micah'           => ['Micah', 'ot', 7, ['mic', 'mc']],
            'nahum'           => ['Nahum', 'ot', 3, ['nah', 'na']],
            'habakkuk'        => ['Habakkuk', 'ot', 3, ['hab', 'hb']],
            'zephaniah'       => ['Zephaniah', 'ot', 3, ['zeph', 'zep', 'zp']],
            'haggai'          => ['Haggai', 'ot', 2, ['hag', 'hg']],
            'zechariah'       => ['Zechariah', 'ot', 14, ['zech', 'zec', 'zc']],
            'malachi'         => ['Malachi', 'ot', 4, ['mal', 'ml']],

            // --------------------------------------------- Deuterocanonical
            'tobit'           => ['Tobit', 'dc', 14, ['tob', 'tb']],
            'judith'          => ['Judith', 'dc', 16, ['jdt', 'jth']],
            'wisdom'          => ['Wisdom', 'dc', 19, ['wis', 'ws', 'wisdomofsolomon']],
            'sirach'          => ['Sirach', 'dc', 51, ['sir', 'ecclesiasticus', 'ecclus']],
            'baruch'          => ['Baruch', 'dc', 6, ['bar']],
            '1-maccabees'     => ['1 Maccabees', 'dc', 16, ['1macc', '1mac', '1ma', '1m']],
            '2-maccabees'     => ['2 Maccabees', 'dc', 15, ['2macc', '2mac', '2ma', '2m']],

            // ------------------------------------------------- New Testament
            'matthew'         => ['Matthew', 'nt', 28, ['matt', 'mat', 'mt']],
            'mark'            => ['Mark', 'nt', 16, ['mrk', 'mk', 'mr']],
            'luke'            => ['Luke', 'nt', 24, ['luk', 'lk']],
            'john'            => ['John', 'nt', 21, ['jhn', 'jn']],
            'acts'            => ['Acts', 'nt', 28, ['act', 'ac', 'actsoftheapostles']],
            'romans'          => ['Romans', 'nt', 16, ['rom', 'ro', 'rm']],
            '1-corinthians'   => ['1 Corinthians', 'nt', 16, ['1cor', '1co', '1c']],
            '2-corinthians'   => ['2 Corinthians', 'nt', 13, ['2cor', '2co', '2c']],
            'galatians'       => ['Galatians', 'nt', 6, ['gal', 'ga']],
            'ephesians'       => ['Ephesians', 'nt', 6, ['ephes', 'eph']],
            'philippians'     => ['Philippians', 'nt', 4, ['phil', 'php', 'pp']],
            'colossians'      => ['Colossians', 'nt', 4, ['col', 'co']],
            '1-thessalonians' => ['1 Thessalonians', 'nt', 5, ['1thess', '1thes', '1th']],
            '2-thessalonians' => ['2 Thessalonians', 'nt', 3, ['2thess', '2thes', '2th']],
            '1-timothy'       => ['1 Timothy', 'nt', 6, ['1tim', '1ti', '1tm']],
            '2-timothy'       => ['2 Timothy', 'nt', 4, ['2tim', '2ti', '2tm']],
            'titus'           => ['Titus', 'nt', 3, ['tit', 'ti']],
            'philemon'        => ['Philemon', 'nt', 1, ['philem', 'phlm', 'phm', 'pm']],
            'hebrews'         => ['Hebrews', 'nt', 13, ['heb', 'hb']],
            'james'           => ['James', 'nt', 5, ['jas', 'jm']],
            '1-peter'         => ['1 Peter', 'nt', 5, ['1pet', '1pe', '1pt', '1p']],
            '2-peter'         => ['2 Peter', 'nt', 3, ['2pet', '2pe', '2pt', '2p']],
            '1-john'          => ['1 John', 'nt', 5, ['1jhn', '1jn', '1joh', '1j']],
            '2-john'          => ['2 John', 'nt', 1, ['2jhn', '2jn', '2joh', '2j']],
            '3-john'          => ['3 John', 'nt', 1, ['3jhn', '3jn', '3joh', '3j']],
            'jude'            => ['Jude', 'nt', 1, ['jud', 'jd']],
            'revelation'      => ['Revelation', 'nt', 22, ['rev', 'rv', 'apocalypse', 'apoc']],
        ];
    }

    /** @return array<string, string> slug => display name, in canonical order */
    public static function names(): array
    {
        $names = [];
        foreach (self::all() as $slug => $book) {
            $names[$slug] = $book[0];
        }

        return $names;
    }

    public static function name(string $slug): ?string
    {
        return self::all()[$slug][0] ?? null;
    }

    public static function testament(string $slug): ?string
    {
        return self::all()[$slug][1] ?? null;
    }

    public static function chapters(string $slug): int
    {
        return self::all()[$slug][2] ?? 0;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    /**
     * Resolve however somebody wrote a book name.
     *
     * Returns null for anything ambiguous as well as for anything unknown, and
     * that is the important half. "Jud" is a real abbreviation for both Jude and
     * Judges, and "Phil" is written for both Philippians and Philemon. Guessing
     * puts a sermon under the wrong book, which is worse than not indexing it:
     * a missing entry is invisible, a wrong one is confidently misleading and
     * nobody goes looking for the cause.
     *
     * (In practice "Phil" resolves, because Philemon's aliases deliberately do
     * not include it — the shorter book yields the commoner abbreviation. "Jud"
     * genuinely does not resolve, because both books really are written that
     * way and neither has a claim.)
     */
    public static function resolve(string $written): ?string
    {
        $index = self::index();
        $key = self::normalise($written);

        if ($key === '') {
            return null;
        }

        // AMBIGUOUS is stored in place of a slug, so a name that two books
        // answer to fails here rather than picking whichever was declared last.
        $found = $index[$key] ?? null;

        return $found === self::AMBIGUOUS ? null : $found;
    }

    /** Marker for an alias more than one book answers to. */
    private const AMBIGUOUS = "\0ambiguous";

    /**
     * Every recognised spelling, normalised, mapped to a slug.
     *
     * Built once. The canonical name and the slug are always included, so
     * "1 Corinthians" and "1-corinthians" both resolve without being listed.
     *
     * @return array<string, string>
     */
    private static function index(): array
    {
        static $index = null;

        if ($index !== null) {
            return $index;
        }

        $index = [];

        foreach (self::all() as $slug => [$name, , , $aliases]) {
            foreach ([$slug, $name, ...$aliases] as $spelling) {
                $key = self::normalise($spelling);

                if ($key === '') {
                    continue;
                }

                if (isset($index[$key]) && $index[$key] !== $slug) {
                    $index[$key] = self::AMBIGUOUS;
                    continue;
                }

                $index[$key] = $slug;
            }
        }

        return $index;
    }

    /**
     * One spelling reduced to something comparable.
     *
     * Lowercased, with dots, spaces and hyphens removed, and every way of
     * writing an ordinal turned into a digit. That collapses "I Cor.",
     * "1 Corinthians", "First Corinthians" and "1st Cor" onto one key, so the
     * alias list above can carry shapes rather than permutations.
     */
    public static function normalise(string $written): string
    {
        $key = strtolower(trim($written));

        // Ordinals first, while the word boundary is still there to match on.
        $key = (string) preg_replace(
            ['/^(first|1st|i)\s+/', '/^(second|2nd|ii)\s+/', '/^(third|3rd|iii)\s+/'],
            ['1 ', '2 ', '3 '],
            $key
        );

        /*
         * Roman numerals written without a space — "iijohn" — after the spaced
         * forms above.
         *
         * Only ii and iii. A rule for a bare leading "i" would turn "isaiah"
         * into "1saiah", and there is no way to tell "ijohn" from the start of
         * a book name without already knowing the answer. No book begins with
         * "ii", so these two are unambiguous; the cost is that somebody writing
         * "IJohn" with no space goes unrecognised, which is rare and fails by
         * not indexing rather than by indexing the wrong book.
         */
        $key = (string) preg_replace(
            ['/^iii(?=[a-z])/', '/^ii(?=[a-z])/'],
            ['3', '2'],
            $key
        );

        return (string) preg_replace('/[^a-z0-9]/', '', $key);
    }
}
