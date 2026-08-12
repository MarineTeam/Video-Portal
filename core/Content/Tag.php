<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * One free-form label.
 *
 * The slug is the identity, not the name. Tags are typed rather than chosen, so
 * "Prayer", "prayer" and " prayer " are one tag arrived at three ways, and the
 * name is only whichever spelling was seen first. Comparing on the name would
 * make the same idea into several tags, each linking to a page with a fraction
 * of the content on it.
 */
final class Tag
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:   (int) $row['id'],
            slug: (string) $row['slug'],
            name: (string) $row['name'],
        );
    }

    public function url(): string
    {
        return '/tag/' . rawurlencode($this->slug);
    }
}
