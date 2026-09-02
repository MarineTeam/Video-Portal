<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * A category.
 *
 * `path` is the materialized ancestor chain, "/1/7/22/", ending with this
 * node's own id. It is derived data maintained exclusively by
 * CategoryRepository — nothing else should ever write it.
 */
final class Category
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly string $path = '/',
        public readonly int $depth = 0,
        public readonly int $position = 0,
        public readonly ?string $providerCollectionId = null,
        public readonly bool $isPublished = true,
        public readonly bool $memberOnly = false,
        public readonly bool $hidden = false,
        public readonly string $thumbnailMode = 'default',
        /** Tri-state download rule for this category and everything beneath it. */
        public readonly string $downloadMode = DownloadPolicy::INHERIT,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:          (int) $row['id'],
            parentId:    isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            slug:        (string) $row['slug'],
            name:        (string) $row['name'],
            description: isset($row['description']) && $row['description'] !== null ? (string) $row['description'] : null,
            imageUrl:    isset($row['image_url']) && $row['image_url'] !== null ? (string) $row['image_url'] : null,
            path:        (string) ($row['path'] ?? '/'),
            depth:       (int) ($row['depth'] ?? 0),
            position:    (int) ($row['position'] ?? 0),
            providerCollectionId: isset($row['provider_collection_id']) && $row['provider_collection_id'] !== null
                ? (string) $row['provider_collection_id']
                : null,
            isPublished: (bool) ($row['is_published'] ?? true),
            memberOnly:  (bool) ($row['member_only'] ?? false),
            hidden:      (bool) ($row['hidden'] ?? false),
            thumbnailMode: (string) ($row['thumbnail_mode'] ?? 'default'),
            downloadMode:  (string) ($row['download_mode'] ?? DownloadPolicy::INHERIT),
        );
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    /** Was this created by importing a provider collection? */
    public function isImported(): bool
    {
        return $this->providerCollectionId !== null;
    }

    public function url(): string
    {
        return '/category/' . $this->slug;
    }

    /**
     * Ancestor ids, root first, excluding this node.
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        return array_values(array_filter(
            array_map('intval', explode('/', trim($this->path, '/'))),
            fn (int $id): bool => $id > 0 && $id !== $this->id
        ));
    }

    /** Visible to someone who is not signed in? */
    public function isPublic(): bool
    {
        return $this->isPublished && !$this->memberOnly && !$this->hidden;
    }
}
