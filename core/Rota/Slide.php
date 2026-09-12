<?php

declare(strict_types=1);

namespace Portal\Rota;

/**
 * One thing on the screen at the front of the building.
 *
 * # THERE IS NO NOTE ON THIS OBJECT, AND THAT IS THE POINT
 *
 * A plan item carries a `note` for whoever is leading — "check the microphone",
 * "Margaret is reading, give her a minute to get up" — and the migration that
 * created the column says plainly that it is not printed on the congregation's
 * copy. Present mode is the most public surface this product has: a screen
 * three hundred people are looking at, photographed by some of them.
 *
 * So the note is not omitted by the template. It is ABSENT FROM THE TYPE. A
 * Slide has nowhere to put one, so no template change, no theme, no later
 * refactor that adds `<?= $slide->note ?>` because the row had one, can put a
 * leader's private aside on the wall. This is the same shape as GroupAddress,
 * whose return type has no unconditional address, and for the same reason:
 * where the cost of a leak is high, the leak should be impossible to express
 * rather than merely avoided.
 *
 * Readonly for the same reason — nothing can attach a note to one after the
 * fact either.
 */
final class Slide
{
    private function __construct(
        /** hymn | reading | item — what sort of thing this is. */
        public readonly string $kind,

        /** Its own name: the hymn's title, the passage, what the item is. */
        public readonly string $title,

        /**
         * What the congregation reads off the board.
         *
         * Free text, deliberately: "245", "H&M 245", "Romans 8:1-11" and
         * "insert" are all things people write, and this is the one field on
         * the screen that has to match what is in their hands.
         */
        public readonly string $reference,

        /**
         * The book and number, when the hymn is one this site holds.
         *
         * Not shown. It is what lets the presenter jump into the reader at the
         * right page — the words themselves are the reader's job, and
         * duplicating that here would be a second renderer to keep in step
         * with the first.
         */
        public readonly ?int $bookId,
        public readonly ?int $songNumber,
    ) {
    }

    /**
     * From a {service_plan_items} row.
     *
     * The only way to make one, so there is no path that starts from something
     * other than a real row — and no constructor a caller could hand a note to.
     *
     * @param array<string, mixed> $row
     */
    public static function from(array $row): self
    {
        $bookId = isset($row['book_id']) ? (int) $row['book_id'] : 0;
        $songNumber = isset($row['song_number']) ? (int) $row['song_number'] : 0;

        return new self(
            RotaRepository::planKind((string) ($row['kind'] ?? 'item')),
            trim((string) ($row['title'] ?? '')),
            trim((string) ($row['reference'] ?? '')),
            $bookId > 0 ? $bookId : null,
            $songNumber > 0 ? $songNumber : null,
        );
    }

    /*
     * The kind is normalised by RotaRepository::planKind(), which is what
     * writes it, rather than by a copy here.
     *
     * A second list of valid kinds is a second list to forget: the day somebody
     * adds "prayer", the repository would store it and this class would present
     * it as a plain item, which is the failure mode nobody notices because it
     * still looks fine.
     */

    /** Whether this hymn can be opened in the reader at its own page. */
    public function isInABook(): bool
    {
        return $this->bookId !== null && $this->songNumber !== null;
    }

    /**
     * What to call this on the screen — "Hymn", "Reading", or nothing.
     *
     * Empty for a plain item, because a label saying "Item" above the word
     * "Notices" is a line of text doing no work on a screen where everything
     * has to be readable from the back.
     */
    public function label(): string
    {
        return match ($this->kind) {
            'hymn'    => 'Hymn',
            'reading' => 'Reading',
            default   => '',
        };
    }
}
