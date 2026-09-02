<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;
use Throwable;

/**
 * The category tree.
 *
 * Categories are stored as an adjacency list (parent_id) with a materialized
 * `path` column caching the ancestor chain as "/1/7/22/". The path is
 * redundant, and keeping it correct is this class's main responsibility — but
 * it turns "every ancestor of this node" and "every descendant of this node"
 * into single indexed queries, which the permission resolver and the plugin
 * override resolver both perform on nearly every request.
 *
 * The rule that keeps it honest: `path` is only ever written here, and any
 * operation that changes a node's parent rewrites the subtree in one
 * transaction.
 */
final class CategoryRepository
{
    /** Depth ceiling. Deeper than this is almost always a mistake, and it
     *  bounds the cost of a subtree rewrite. */
    private const MAX_DEPTH = 10;

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    public function find(int $id): ?Category
    {
        $row = $this->db->first('SELECT * FROM {categories} WHERE id = ?', [$id]);
        return $row === null ? null : Category::fromRow($row);
    }

    public function findBySlug(string $slug): ?Category
    {
        $row = $this->db->first('SELECT * FROM {categories} WHERE slug = ?', [$slug]);
        return $row === null ? null : Category::fromRow($row);
    }

    /**
     * Every category, ordered for display: siblings by position, depth-first.
     *
     * @return list<Category>
     */
    public function all(bool $includeUnpublished = false): array
    {
        $where = $includeUnpublished ? '' : ' WHERE is_published = 1';

        $rows = $this->db->all(
            "SELECT * FROM {categories}{$where} ORDER BY path, position, name"
        );

        return array_map(static fn (array $row): Category => Category::fromRow($row), $rows);
    }

    /**
     * Top-level categories only.
     *
     * @return list<Category>
     */
    public function roots(bool $includeUnpublished = false): array
    {
        $where = $includeUnpublished ? '' : ' AND is_published = 1';

        $rows = $this->db->all(
            "SELECT * FROM {categories} WHERE parent_id IS NULL{$where} ORDER BY position, name"
        );

        return array_map(static fn (array $row): Category => Category::fromRow($row), $rows);
    }

    /**
     * Direct children of a category.
     *
     * @return list<Category>
     */
    public function children(int $parentId, bool $includeUnpublished = false): array
    {
        $where = $includeUnpublished ? '' : ' AND is_published = 1';

        $rows = $this->db->all(
            "SELECT * FROM {categories} WHERE parent_id = ?{$where} ORDER BY position, name",
            [$parentId]
        );

        return array_map(static fn (array $row): Category => Category::fromRow($row), $rows);
    }

    /**
     * A category and everything beneath it, in one query.
     *
     * This is what the materialized path buys: a LIKE on an indexed prefix
     * instead of a recursive walk. Used whenever a listing should include
     * subcategories, which is the behaviour people expect from "show me
     * everything in Sermons".
     *
     * @return list<int>
     */
    public function descendantIds(int $categoryId, bool $includeSelf = true): array
    {
        $category = $this->find($categoryId);
        if ($category === null) {
            return [];
        }

        // path is "/1/7/22/", so descendants all start with that exact prefix.
        // The trailing slash prevents /1/2/ matching /1/20/.
        $ids = $this->db->column(
            'SELECT id FROM {categories} WHERE path LIKE ?',
            [$this->db->escapeLike($category->path) . '%']
        );

        $ids = array_map('intval', $ids);

        if (!$includeSelf) {
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $categoryId));
        }

        return $ids;
    }

    /**
     * Ancestors from root down to the parent — the breadcrumb trail.
     *
     * @return list<Category>
     */
    public function ancestors(int $categoryId): array
    {
        $category = $this->find($categoryId);
        if ($category === null) {
            return [];
        }

        $ids = array_values(array_filter(
            array_map('intval', explode('/', trim($category->path, '/'))),
            static fn (int $id): bool => $id > 0 && $id !== $categoryId
        ));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->all(
            "SELECT * FROM {categories} WHERE id IN ({$placeholders}) ORDER BY depth",
            $ids
        );

        return array_map(static fn (array $row): Category => Category::fromRow($row), $rows);
    }

    /**
     * The whole tree as nested arrays, for the admin tree view and menus.
     *
     * @return list<array{category: Category, children: list<mixed>}>
     */
    public function tree(bool $includeUnpublished = false): array
    {
        $all = $this->all($includeUnpublished);

        /** @var array<int, list<Category>> $byParent */
        $byParent = [];
        foreach ($all as $category) {
            $byParent[$category->parentId ?? 0][] = $category;
        }

        $build = static function (int $parentId) use (&$build, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $category) {
                $nodes[] = [
                    'category' => $category,
                    'children' => $build($category->id),
                ];
            }
            return $nodes;
        };

        return $build(0);
    }

    // ----------------------------------------------------------------- writes

    /**
     * Create a category, computing its path from its parent.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Category
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw HttpException::badRequest('A category needs a name.');
        }

        $parentId = isset($attributes['parent_id']) && $attributes['parent_id'] !== null
            ? (int) $attributes['parent_id']
            : null;

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->find($parentId);
            if ($parent === null) {
                throw HttpException::badRequest('That parent category does not exist.');
            }
            if ($parent->depth + 1 > self::MAX_DEPTH) {
                throw HttpException::badRequest(
                    sprintf('Categories cannot nest more than %d levels deep.', self::MAX_DEPTH)
                );
            }
        }

        $slug = $this->uniqueSlug((string) ($attributes['slug'] ?? $name));
        $now = date('Y-m-d H:i:s');

        return $this->db->transaction(function () use ($attributes, $name, $slug, $parent, $parentId, $now): Category {
            $id = $this->db->insert('categories', [
                'parent_id'    => $parentId,
                'slug'         => $slug,
                'name'         => $name,
                'description'  => $attributes['description'] ?? null,
                'image_url'    => $attributes['image_url'] ?? null,
                // Placeholder: the real path needs the id we just generated.
                'path'         => '/',
                'depth'        => $parent === null ? 0 : $parent->depth + 1,
                'position'     => isset($attributes['position'])
                    ? (int) $attributes['position']
                    : $this->nextPosition($parentId),
                'provider_collection_id' => $attributes['provider_collection_id'] ?? null,
                'is_published' => isset($attributes['is_published']) ? (int) (bool) $attributes['is_published'] : 1,
                'member_only'  => isset($attributes['member_only']) ? (int) (bool) $attributes['member_only'] : 0,
                'hidden'       => isset($attributes['hidden']) ? (int) (bool) $attributes['hidden'] : 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            $path = ($parent === null ? '/' : $parent->path) . $id . '/';
            $this->db->execute('UPDATE {categories} SET path = ? WHERE id = ?', [$path, $id]);

            $category = $this->find($id);
            if ($category === null) {
                throw new \RuntimeException('The category was created but could not be read back.');
            }

            return $category;
        });
    }

    /**
     * Update a category. Moving it to a new parent rewrites the subtree.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(int $id, array $attributes): Category
    {
        $category = $this->find($id);
        if ($category === null) {
            throw HttpException::notFound('That category does not exist.');
        }

        return $this->db->transaction(function () use ($category, $attributes, $id): Category {
            $fields = [];

            if (isset($attributes['name'])) {
                $name = trim((string) $attributes['name']);
                if ($name === '') {
                    throw HttpException::badRequest('A category needs a name.');
                }
                $fields['name'] = $name;
            }

            if (isset($attributes['slug'])) {
                $slug = $this->uniqueSlug((string) $attributes['slug'], $id);
                if ($slug !== $category->slug) {
                    // Keep the old slug working — a link in a printed bulletin
                    // should not break because someone fixed a typo.
                    $this->recordAlias($id, $category->slug);
                    $fields['slug'] = $slug;
                }
            }

            foreach (['description', 'image_url', 'provider_collection_id'] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $fields[$key] = $attributes[$key];
                }
            }

            foreach (['is_published', 'member_only', 'hidden'] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $fields[$key] = (int) (bool) $attributes[$key];
                }
            }

            if (isset($attributes['thumbnail_mode'])) {
                $fields['thumbnail_mode'] = ThumbnailPolicy::sanitize($attributes['thumbnail_mode']);
            }

            if (isset($attributes['download_mode'])) {
                $fields['download_mode'] = DownloadPolicy::sanitize($attributes['download_mode']);
            }

            if (array_key_exists('position', $attributes)) {
                $fields['position'] = (int) $attributes['position'];
            }

            $movingParent = array_key_exists('parent_id', $attributes);
            $newParentId = $movingParent && $attributes['parent_id'] !== null
                ? (int) $attributes['parent_id']
                : null;

            if ($movingParent && $newParentId !== $category->parentId) {
                $this->assertMoveIsLegal($category, $newParentId);
                $fields['parent_id'] = $newParentId;
            }

            if ($fields !== []) {
                $fields['updated_at'] = date('Y-m-d H:i:s');
                $this->db->update('categories', $fields, ['id' => $id]);
            }

            if ($movingParent && $newParentId !== $category->parentId) {
                $this->rebuildSubtree($id);
            }

            $updated = $this->find($id);
            if ($updated === null) {
                throw new \RuntimeException('The category vanished mid-update.');
            }

            return $updated;
        });
    }

    /**
     * Refuse moves that would corrupt the tree.
     *
     * Making a node a descendant of itself produces a cycle, and every
     * ancestor walk after that runs forever. The materialized path makes this
     * cheap to detect: a cycle is exactly "the new parent's path contains my
     * id".
     */
    private function assertMoveIsLegal(Category $category, ?int $newParentId): void
    {
        if ($newParentId === null) {
            return;
        }

        if ($newParentId === $category->id) {
            throw HttpException::badRequest('A category cannot be its own parent.');
        }

        $newParent = $this->find($newParentId);
        if ($newParent === null) {
            throw HttpException::badRequest('That parent category does not exist.');
        }

        if (str_contains($newParent->path, '/' . $category->id . '/')) {
            throw HttpException::badRequest(
                'A category cannot be moved inside one of its own subcategories.'
            );
        }

        $subtreeDepth = (int) $this->db->value(
            'SELECT COALESCE(MAX(depth), ?) FROM {categories} WHERE path LIKE ?',
            [$category->depth, $this->db->escapeLike($category->path) . '%']
        );

        $relativeDepth = $subtreeDepth - $category->depth;
        if ($newParent->depth + 1 + $relativeDepth > self::MAX_DEPTH) {
            throw HttpException::badRequest(
                sprintf('That move would nest categories more than %d levels deep.', self::MAX_DEPTH)
            );
        }
    }

    /**
     * Recompute path and depth for a node and everything beneath it.
     *
     * Called after a move. Every descendant's path contains the moved node's
     * old path as a prefix, so the rewrite is a string substitution rather
     * than a tree walk.
     */
    private function rebuildSubtree(int $id): void
    {
        $category = $this->find($id);
        if ($category === null) {
            return;
        }

        $parent = $category->parentId === null ? null : $this->find($category->parentId);
        $newPath = ($parent === null ? '/' : $parent->path) . $id . '/';
        $newDepth = $parent === null ? 0 : $parent->depth + 1;

        $oldPath = $category->path;

        if ($oldPath === $newPath) {
            return;
        }

        $this->db->execute(
            'UPDATE {categories} SET path = ?, depth = ? WHERE id = ?',
            [$newPath, $newDepth, $id]
        );

        // Descendants: swap the old prefix for the new one and shift depth by
        // the same delta. One statement regardless of subtree size.
        $this->db->execute(
            'UPDATE {categories}
                SET path = CONCAT(?, SUBSTRING(path, ?)),
                    depth = depth + ?
              WHERE path LIKE ? AND id <> ?',
            [
                $newPath,
                strlen($oldPath) + 1,
                $newDepth - $category->depth,
                $this->db->escapeLike($oldPath) . '%',
                $id,
            ]
        );
    }

    /**
     * Delete a category.
     *
     * The schema cascades to descendants. Videos are not deleted — they lose
     * the association and become uncategorised, because destroying content as
     * a side effect of tidying the menu is never what anyone meant.
     */
    public function delete(int $id): void
    {
        // {taggables} is polymorphic, so it carries no foreign key and no
        // cascade. See VideoRepository::forceDelete().
        $this->db->execute(
            'DELETE FROM {taggables} WHERE taggable_type = ? AND taggable_id = ?',
            ['category', $id]
        );
        $this->db->execute('DELETE FROM {categories} WHERE id = ?', [$id]);
    }

    /**
     * Reorder siblings.
     *
     * @param list<int> $orderedIds
     */
    /**
     * Move one category up or down among its siblings.
     *
     * reorder() has existed since Phase 1 and had no caller, so the `position`
     * column the schema describes as "for manual ordering" has never been
     * orderable — every category tree on every install has sat in whatever
     * order it happened to be created in. This is what calls it.
     *
     * Sibling-scoped on purpose. A category's order only means anything against
     * the ones beside it: moving "Sermons" up past "Sermons / 2019" would be
     * asking a tree to behave like a list, and the answer would be different
     * depending on which rows happened to be expanded.
     *
     * @param int $direction -1 for earlier, 1 for later
     * @return bool whether anything moved — false at either end, which the
     *              screen reports rather than claiming a change that did not
     *              happen
     */
    public function move(int $id, int $direction): bool
    {
        $category = $this->find($id);

        if ($category === null || ($direction !== -1 && $direction !== 1)) {
            return false;
        }

        $siblings = $this->db->column(
            'SELECT id FROM {categories}
              WHERE ' . ($category->parentId === null ? 'parent_id IS NULL' : 'parent_id = ?') . '
              ORDER BY position ASC, name ASC',
            $category->parentId === null ? [] : [$category->parentId]
        );

        $siblings = array_values(array_map('intval', $siblings));
        $index = array_search($id, $siblings, true);

        if ($index === false) {
            return false;
        }

        $target = $index + $direction;

        // Already first or last. Not an error — somebody pressed the button at
        // the end of the list, which is a normal thing to do.
        if ($target < 0 || $target >= count($siblings)) {
            return false;
        }

        [$siblings[$index], $siblings[$target]] = [$siblings[$target], $siblings[$index]];

        $this->reorder($category->parentId, $siblings);

        return true;
    }

    public function reorder(?int $parentId, array $orderedIds): void
    {
        $this->db->transaction(function () use ($parentId, $orderedIds): void {
            $position = 0;
            foreach ($orderedIds as $id) {
                $position += 10;
                $this->db->execute(
                    'UPDATE {categories} SET position = ?, updated_at = NOW()
                      WHERE id = ? AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?'),
                    $parentId === null ? [$position, (int) $id] : [$position, (int) $id, $parentId]
                );
            }
        });
    }

    // ------------------------------------------------------------- provider

    /**
     * Import provider collections as categories.
     *
     * The contract that makes local taxonomy authoritative: a collection is
     * matched by provider_collection_id, and an existing category is NEVER
     * renamed by a re-import. Someone who renamed "Untitled Collection" to
     * "Sunday Mornings" must not have that undone by a routine sync.
     *
     * @param list<array{id: string, name: string, videoCount: int}> $collections
     * @return array{created: int, skipped: int}
     */
    public function importCollections(array $collections): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($collections as $collection) {
            $providerId = trim((string) ($collection['id'] ?? ''));
            $name = trim((string) ($collection['name'] ?? ''));

            if ($providerId === '' || $name === '') {
                continue;
            }

            $existing = $this->db->value(
                'SELECT id FROM {categories} WHERE provider_collection_id = ?',
                [$providerId]
            );

            if ($existing !== null) {
                $skipped++;
                continue;
            }

            $this->create([
                'name'                   => $name,
                'provider_collection_id' => $providerId,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------- internals

    /**
     * A slug unique across all categories, suffixing on collision.
     *
     * Global rather than per-parent: /category/{slug} has to be unambiguous,
     * and a nullable parent_id would not enforce per-parent uniqueness at the
     * database level anyway.
     */
    public function uniqueSlug(string $desired, ?int $ignoreId = null): string
    {
        $base = Str::slug($desired);
        $slug = $base;
        $suffix = 1;

        while (true) {
            $sql = 'SELECT id FROM {categories} WHERE slug = ?';
            $params = [$slug];

            if ($ignoreId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }

            if ($this->db->value($sql, $params) === null) {
                return $slug;
            }

            $suffix++;
            $slug = $base . '-' . $suffix;
        }
    }

    private function nextPosition(?int $parentId): int
    {
        $max = $this->db->value(
            'SELECT MAX(position) FROM {categories} WHERE '
            . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?'),
            $parentId === null ? [] : [$parentId]
        );

        return ((int) $max) + 10;
    }

    private function recordAlias(int $categoryId, string $oldSlug): void
    {
        try {
            $this->db->execute(
                'INSERT IGNORE INTO {slug_aliases} (target_type, target_id, slug, created_at)
                 VALUES ("category", ?, ?, NOW())',
                [$categoryId, $oldSlug]
            );
        } catch (Throwable $e) {
            // A missing alias costs a 404 on an old link; a failed rename
            // costs the edit. The rename wins.
            error_log('Portal: could not record category slug alias: ' . $e->getMessage());
        }
    }
}
