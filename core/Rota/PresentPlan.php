<?php

declare(strict_types=1);

namespace Portal\Rota;

/**
 * A service's running order, ready for the screen at the front.
 *
 * # IT IS ALL IN THE PAGE, AND IT ADVANCES IN THE BROWSER
 *
 * Two decisions, and the second follows from the first.
 *
 * Every slide is rendered into the page that loads. A projector in a church
 * hall is on the wifi that drops during the second hymn, and a present mode
 * that fetched the next slide would go blank in front of everybody — at the one
 * moment nobody can be debugging it. There is nothing to fetch.
 *
 * And the position is NOT stored. Advancing writes nothing and asks nothing, so
 * two people opening present mode do not fight over whose screen is where, and
 * a service does not leave a half-finished "we got to item 4" row behind. The
 * cost is real and is the obvious next feature: a congregation's phones cannot
 * follow along. That is a different thing — a shared position everybody polls —
 * and it should not be built by accident into the page that drives the wall.
 */
final class PresentPlan
{
    /** @param list<Slide> $slides */
    private function __construct(public readonly array $slides)
    {
    }

    /**
     * From the rows RotaRepository::plan() returns.
     *
     * Items with no title are dropped. An untitled line is a blank screen with
     * "Hymn" over it, which reads as the system having failed rather than as
     * somebody having left a row half-written — and the person who could fix it
     * is at the front of a church.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function from(array $rows): self
    {
        $slides = [];

        foreach ($rows as $row) {
            $slide = Slide::from($row);

            if ($slide->title === '') {
                continue;
            }

            $slides[] = $slide;
        }

        return new self($slides);
    }

    public function total(): int
    {
        return count($this->slides);
    }

    public function isEmpty(): bool
    {
        return $this->slides === [];
    }

    /**
     * The slide at a position, or null.
     *
     * Not clamped, and deliberately not. Clamping would mean asking for slide
     * 99 of 6 silently shows the last one, so a bad link or a stale remote
     * press would present something plausible — and "plausible" on the screen
     * at the front is worse than nothing, because nobody in the room can tell
     * it is wrong.
     */
    public function at(int $index): ?Slide
    {
        return $this->slides[$index] ?? null;
    }

    /**
     * Where to go from here, or null at the end.
     *
     * Returns an INDEX rather than a Slide so the caller can tell "there is
     * nothing after this" from "the thing after this is empty" — and so a
     * template can disable an arrow rather than draw one that does nothing.
     */
    public function next(int $index): ?int
    {
        return isset($this->slides[$index + 1]) ? $index + 1 : null;
    }

    public function previous(int $index): ?int
    {
        return $index > 0 && isset($this->slides[$index - 1]) ? $index - 1 : null;
    }

    /**
     * A position somebody typed or a remote sent, made safe.
     *
     * Out of range becomes the FIRST slide, not the last and not an error. A
     * link to slide 40 of a six-item service is a stale bookmark or a typo, and
     * starting at the beginning is the one answer that is obviously a start
     * rather than an ending somebody has to work out.
     */
    public function resolve(mixed $index): int
    {
        $index = is_numeric($index) ? (int) $index : 0;

        return isset($this->slides[$index]) ? $index : 0;
    }
}
