<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * A fill-in-the-blank sermon note sheet: the paper handed out at the door, as a
 * page.
 *
 * An editor writes the outline as plain text, with three or more underscores
 * marking each gap. Plain text rather than a form builder, because this is
 * typed on a Saturday night by whoever is preparing the talk, usually pasted
 * from the document the paper copy is printed from.
 *
 * # A GAP IS ITS POSITION
 *
 * Answers are stored as a list: the third answer belongs to the third gap.
 * That is the only identity a gap has — there is nothing else in "___" to key
 * on — so an outline edited after somebody filled it in can leave an answer
 * against a gap it was never written for. Rather than guess which gap moved,
 * the answers are stored with the VERSION of the outline they were written
 * under, and the sheet says when that is no longer the current one.
 *
 * The version is bumped by a change in WORDING, not in layout: see
 * fingerprint(). A sheet that announced it had changed every time somebody
 * rewrapped a line would be a notice people learn to ignore, and then it would
 * be ignored on the day a gap really moved.
 *
 * Pure: no database, no request. NoteSheetRepository stores it.
 */
final class NoteSheet
{
    /** Three or more, so a single underscore in a name or an address is text. */
    public const GAP = '/_{3,}/';

    /** Long enough for any printed sheet; short enough that nobody pastes a book. */
    public const MAX_OUTLINE = 20000;

    /** A gap holds a word or a phrase, not a paragraph — that is what notes are for. */
    public const MAX_ANSWER = 200;

    /**
     * The outline as text and gaps, in order, for a template to render.
     *
     * @return list<array{type: 'text', text: string}|array{type: 'gap', index: int}>
     */
    public static function segments(string $outline): array
    {
        $parts = preg_split(self::GAP, $outline);
        $out = [];

        foreach ($parts === false ? [$outline] : $parts as $i => $text) {
            if ($i > 0) {
                $out[] = ['type' => 'gap', 'index' => $i - 1];
            }
            if ($text !== '') {
                $out[] = ['type' => 'text', 'text' => $text];
            }
        }

        return $out;
    }

    public static function gapCount(string $outline): int
    {
        return (int) preg_match_all(self::GAP, $outline);
    }

    /**
     * What decides whether an edit is a new version.
     *
     * Whitespace collapsed, so rewrapping, indenting or a trailing newline is
     * the same sheet. Everything else counts — including a change to the words
     * AROUND a gap, because "the ___ of God" becoming "the ___ of man" changes
     * what an answer already written there means, even though every gap is
     * where it was.
     */
    public static function fingerprint(string $outline): string
    {
        return sha1((string) preg_replace('/\s+/u', ' ', trim($outline)));
    }

    /**
     * The outline an editor submitted, ready to store. Empty means "no sheet".
     */
    public static function cleanOutline(string $outline): string
    {
        $outline = str_replace(["\r\n", "\r"], "\n", $outline);

        return mb_substr(trim($outline), 0, self::MAX_OUTLINE);
    }

    /**
     * Answers from a request, shaped to the sheet.
     *
     * Exactly one entry per gap, in gap order: missing ones are empty and extra
     * ones are dropped, so what is stored can never claim more gaps than the
     * sheet had. Each is one line — a gap is inline in a sentence, and a
     * newline in it would break the sheet when printed — and capped.
     *
     * Anything that is not a string becomes empty rather than being refused:
     * this is saved while somebody types, and losing a whole sheet because one
     * field arrived oddly would be the worst outcome available.
     *
     * @return list<string>
     */
    public static function cleanAnswers(mixed $input, int $gapCount): array
    {
        $input = is_array($input) ? array_values($input) : [];
        $out = [];

        for ($i = 0; $i < $gapCount; $i++) {
            $value = $input[$i] ?? '';
            $value = is_string($value) ? $value : '';
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));

            $out[] = mb_substr($value, 0, self::MAX_ANSWER);
        }

        return $out;
    }

    /**
     * Were these answers written under an older outline?
     *
     * Only when there ARE answers: somebody opening a sheet for the first time
     * has nothing that could be out of line, and telling them it changed would
     * be about an edit they never saw.
     *
     * @param list<string> $answers
     */
    public static function changedSince(?int $savedVersion, int $currentVersion, array $answers): bool
    {
        if ($savedVersion === null) {
            return false;
        }

        if (implode('', $answers) === '') {
            return false;
        }

        return $savedVersion !== $currentVersion;
    }
}
