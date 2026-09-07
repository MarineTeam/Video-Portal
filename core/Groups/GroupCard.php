<?php

declare(strict_types=1);

namespace Portal\Groups;

/**
 * A small group, as any listing or public page may see it.
 *
 * # THERE IS NO ADDRESS ON THIS TYPE, AND THAT IS THE POINT
 *
 * A group that meets in somebody's living room must never publish where they
 * live. The `area` is here because that is what a directory is for — "Northside",
 * "near the station" — and the address is not, because it is a private home
 * belonging to whoever opens their door on a Tuesday.
 *
 * A template that forgets to check therefore has NOTHING TO PRINT rather than
 * printing a house. The address reaches exactly one place: a variable the
 * controller computed by calling GroupAddress, which is the one function
 * allowed to decide.
 *
 * The same shape as Portal\Prayer\PrayerRequest, and for the same reason:
 * where the cost of forgetting is somebody's safety, the type is the guard.
 */
final class GroupCard
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        /** Roughly where. Never the address. */
        public readonly ?string $area,
        public readonly ?string $meets,
        public readonly ?int $capacity,
        public readonly bool $isPublished,
        /** Places taken — members AND unanswered requests. See GroupRepository. */
        public readonly int $taken,
        /** @var list<string> The leaders' names. A group with none is flagged. */
        public readonly array $leaders,
        /** What the person looking at this is to this group, if anything. */
        public readonly ?string $myState,
        public readonly bool $iLeadThis,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $leaders
     */
    public static function from(
        array $row,
        int $taken = 0,
        array $leaders = [],
        ?string $myState = null,
        bool $iLeadThis = false
    ): self {
        return new self(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            $row['description'] === null ? null : (string) $row['description'],
            $row['area'] === null ? null : (string) $row['area'],
            $row['meets'] === null ? null : (string) $row['meets'],
            $row['capacity'] === null ? null : (int) $row['capacity'],
            (bool) $row['is_published'],
            $taken,
            $leaders,
            $myState,
            $iLeadThis,
        );
    }

    public function hasRoom(): bool
    {
        return $this->capacity === null || $this->taken < $this->capacity;
    }

    /** How many places are left, or null when there is no limit. */
    public function placesLeft(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->taken);
    }

    public function needsALeader(): bool
    {
        return $this->leaders === [];
    }
}
