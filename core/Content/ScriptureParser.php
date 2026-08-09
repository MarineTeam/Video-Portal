<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Finding scripture references in ordinary prose.
 *
 * Pure: text in, references out.
 *
 * The governing decision is that this is a SEARCH over text nobody wrote for
 * it, so it is tuned to be quiet rather than thorough. A missed reference costs
 * one sermon not appearing under one chapter, and somebody can add it by hand.
 * A false one files a sermon under a passage it never mentions, which is
 * confidently wrong, invisible to whoever caused it, and turns the browse pages
 * into something nobody trusts. Every ambiguity below is therefore resolved by
 * refusing.
 */
final class ScriptureParser
{
    /**
     * A ceiling on references from one description.
     *
     * A sermon has a handful. Anything past this is a document that happens to
     * be full of numbers, and the point of the limit is that a paste into a
     * textarea must not turn one save into a thousand inserts.
     */
    public const MAX_REFERENCES = 50;

    /**
     * Find every reference in a piece of text.
     *
     * @return list<array{book: string, chapter: int, verse: ?int, endChapter: int, endVerse: ?int, raw: string}>
     */
    public static function parse(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        /*
         * The book name must start with a capital.
         *
         * This is the single most effective false-positive guard there is. "he
         * acts 2 ways" and "in job 3 he" are ordinary English; "Acts 2" and
         * "Job 3" are references, and the only thing separating them in running
         * prose is the capital letter. Descriptions are written by people who
         * capitalise book names, so the cost is close to nothing.
         */
        $ordinal = '(?:[123]|I{1,3}|First|Second|Third|1st|2nd|3rd)';

        /*
         * The book name may be "X of Y" or "X of the Y".
         *
         * Four real names need it — Song of Songs, Song of Solomon, Wisdom of
         * Solomon, Acts of the Apostles — and without it they are simply never
         * found, silently, while every other book works. Deliberately narrow
         * rather than "any run of capitalised words": the wide version walks
         * off the end of one reference and into the next sentence, and a
         * bounded pattern that misses an invented name is the right way round
         * for a parser tuned to be quiet.
         */
        $name = '[A-Z][A-Za-z]+(?:\s+of(?:\s+the)?\s+[A-Z][A-Za-z]+)?';

        $pattern = '/\b(' . $ordinal . '\s*\.?\s*)?'      // 1  optional ordinal
                 . '(' . $name . ')\.?'                   // 2  the book name
                 . '\s+(\d{1,3})'                         // 3  chapter
                 . '(?::(\d{1,3}))?'                      // 4  verse
                 . '(?:\s*[-–—]\s*'                       // range, any dash
                 . '(?:(\d{1,3}):)?'                      // 5  end chapter
                 . '(\d{1,3}))?'                          // 6  end verse
                 . '/u';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $found = [];

        foreach ($matches as $m) {
            $reference = self::interpret($m);

            if ($reference === null) {
                continue;
            }

            /*
             * Keyed, so the same passage written twice in one description —
             * once in the summary and once in a list of readings — is one
             * reference. Deduplicated on the RESOLVED value rather than the raw
             * text, so "Jn 3:16" and "John 3:16" collapse too.
             */
            $key = $reference['book'] . ':' . $reference['chapter'] . ':' . ($reference['verse'] ?? '')
                 . '-' . $reference['endChapter'] . ':' . ($reference['endVerse'] ?? '');

            $found[$key] ??= $reference;

            if (count($found) >= self::MAX_REFERENCES) {
                break;
            }
        }

        return array_values($found);
    }

    /**
     * One regex match turned into a reference, or null if it is not one.
     *
     * @param  list<string> $m
     * @return array{book: string, chapter: int, verse: ?int, endChapter: int, endVerse: ?int, raw: string}|null
     */
    private static function interpret(array $m): ?array
    {
        $written = trim(($m[1] ?? '') . ' ' . $m[2]);
        $book = ScriptureBooks::resolve($written);

        if ($book === null) {
            return null;
        }

        $chapter = (int) $m[3];
        $verse = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null;

        if ($verse !== null && $verse < 1) {
            return null;
        }

        $endChapter = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : $chapter;
        $endVerse = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : null;

        /*
         * "John 3-4" is a chapter range; "John 3:16-18" is a verse range. The
         * regex cannot tell them apart, because both end in a bare number — the
         * difference is whether a verse was given at the start.
         */
        if ($verse === null && $endVerse !== null) {
            $endChapter = $endVerse;
            $endVerse = null;
        }

        /*
         * Both ends checked against the book's real length, in one place.
         *
         * This is what stops a date — "Exodus 2026" — and a mistyped number
         * from creating a browse page nobody can reach, and it is the reason
         * the chapter counts are carried at all.
         *
         * Written once rather than twice on purpose. An earlier version also
         * checked the start chapter before the range was worked out, which read
         * as belt and braces and was in fact unreachable: end_chapter defaults
         * to the start, so the later check already covered every case. A guard
         * that can be deleted without any test noticing is not a guard, it is a
         * comment that looks like one.
         */
        if ($chapter < 1
            || $chapter > ScriptureBooks::chapters($book)
            || $endChapter > ScriptureBooks::chapters($book)) {
            return null;
        }

        /*
         * A range that runs backwards is a misread rather than a reference.
         * Refused rather than swapped: "John 3:16-2" is far more likely to be
         * "3:16" followed by something else than a range somebody meant.
         */
        if ($endChapter < $chapter
            || ($endChapter === $chapter && $endVerse !== null && $verse !== null && $endVerse < $verse)) {
            return null;
        }

        return [
            'book'       => $book,
            'chapter'    => $chapter,
            'verse'      => $verse,
            'endChapter' => $endChapter,
            'endVerse'   => $endVerse,
            'raw'        => trim($m[0]),
        ];
    }

    /**
     * A reference written out the way people expect to read it.
     *
     * Built from the resolved parts rather than echoing what was typed, so a
     * page listing references written five different ways reads as one list.
     *
     * @param array{book: string, chapter: int, verse: ?int, endChapter: int, endVerse: ?int} $reference
     */
    public static function format(array $reference): string
    {
        $name = ScriptureBooks::name($reference['book']) ?? $reference['book'];

        $out = $name . ' ' . $reference['chapter'];

        if ($reference['verse'] !== null) {
            $out .= ':' . $reference['verse'];
        }

        if ($reference['endChapter'] !== $reference['chapter']) {
            $out .= '–' . $reference['endChapter']
                  . ($reference['endVerse'] !== null ? ':' . $reference['endVerse'] : '');
        } elseif ($reference['endVerse'] !== null && $reference['endVerse'] !== $reference['verse']) {
            $out .= '–' . $reference['endVerse'];
        }

        return $out;
    }
}
