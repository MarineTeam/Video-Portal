<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;
use Portal\Video\VideoMeta;
use Throwable;

/**
 * Videos, their taxonomy, and the sync with the video provider.
 *
 * The listing methods all funnel through query(), so visibility rules are
 * expressed once. Getting that wrong in one place — a category page that
 * forgets to exclude unpublished videos, say — is how private content leaks.
 */
final class VideoRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly CategoryRepository $categories,
    ) {
    }

    // ------------------------------------------------------------------ reads

    public function find(int $id): ?Video
    {
        $row = $this->db->first('SELECT * FROM {videos} WHERE id = ? AND deleted_at IS NULL', [$id]);
        return $row === null ? null : Video::fromRow($row);
    }

    public function findBySlug(string $slug): ?Video
    {
        $row = $this->db->first('SELECT * FROM {videos} WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        return $row === null ? null : Video::fromRow($row);
    }

    public function findByProviderId(string $providerId, string $provider = 'bunny'): ?Video
    {
        $row = $this->db->first(
            'SELECT * FROM {videos} WHERE provider = ? AND provider_id = ?',
            [$provider, $providerId]
        );
        return $row === null ? null : Video::fromRow($row);
    }

    /**
     * The one place listing rules live.
     *
     * @param array<string, mixed> $filters
     *        categoryId, seriesId, speakerId, search, includeHidden,
     *        includeUnpublished, includeMemberOnly, includeProcessing
     * @return array{items: list<Video>, total: int}
     */
    public function query(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->db->value(
            "SELECT COUNT(DISTINCT v.id) FROM {videos} v {$where['join']} WHERE {$where['sql']}",
            $params
        );

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->all(
            "SELECT DISTINCT v.* FROM {videos} v {$where['join']}
              WHERE {$where['sql']}
              ORDER BY v.pinned DESC, v.position ASC, COALESCE(v.published_at, v.created_at) DESC, v.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items' => array_map(static fn (array $row): Video => Video::fromRow($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: array{sql: string, join: string}, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = ['v.deleted_at IS NULL'];
        $params = [];
        $join = '';

        // Processing and failed videos are excluded by default: a player that
        // cannot start reads as a broken site, not as "not ready yet".
        if (empty($filters['includeProcessing'])) {
            $conditions[] = "v.status = 'ready'";
        }
        if (empty($filters['includeUnpublished'])) {
            $conditions[] = 'v.is_published = 1';
            $conditions[] = '(v.published_at IS NULL OR v.published_at <= NOW())';
        }
        if (empty($filters['includeHidden'])) {
            $conditions[] = 'v.hidden = 0';
        }
        if (empty($filters['includeMemberOnly'])) {
            $conditions[] = 'v.member_only = 0';
        }

        if (!empty($filters['categoryId'])) {
            // Include subcategories: "show me everything in Sermons" is what
            // people mean when they click a parent category.
            $ids = $this->categories->descendantIds((int) $filters['categoryId']);
            if ($ids === []) {
                $conditions[] = '1 = 0';
            } else {
                $join .= ' INNER JOIN {video_categories} vc ON vc.video_id = v.id';
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conditions[] = "vc.category_id IN ({$placeholders})";
                $params = [...$params, ...$ids];
            }
        }

        if (!empty($filters['seriesId'])) {
            $conditions[] = 'v.series_id = ?';
            $params[] = (int) $filters['seriesId'];
        }

        if (!empty($filters['speakerId'])) {
            $conditions[] = 'v.speaker_id = ?';
            $params[] = (int) $filters['speakerId'];
        }

        if (!empty($filters['search'])) {
            $term = '%' . $this->db->escapeLike(trim((string) $filters['search'])) . '%';
            $conditions[] = '(v.title LIKE ? OR v.description LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        return [
            ['sql' => implode(' AND ', $conditions), 'join' => $join],
            $params,
        ];
    }

    /**
     * Videos in a series, in series order.
     *
     * @return list<Video>
     */
    public function forSeries(int $seriesId, bool $includeUnpublished = false): array
    {
        $conditions = ['v.series_id = ?', 'v.deleted_at IS NULL'];
        if (!$includeUnpublished) {
            $conditions[] = "v.status = 'ready'";
            $conditions[] = 'v.is_published = 1';
            $conditions[] = 'v.hidden = 0';
        }

        $rows = $this->db->all(
            'SELECT v.* FROM {videos} v WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY v.series_position ASC, v.id ASC',
            [$seriesId]
        );

        return array_map(static fn (array $row): Video => Video::fromRow($row), $rows);
    }

    // ------------------------------------------------------------- taxonomy

    /**
     * Category ids a video belongs to.
     *
     * @return list<int>
     */
    public function categoryIds(int $videoId): array
    {
        return array_map(
            'intval',
            $this->db->column(
                'SELECT category_id FROM {video_categories} WHERE video_id = ? ORDER BY is_primary DESC, position',
                [$videoId]
            )
        );
    }

    /**
     * Replace a video's categories.
     *
     * @param list<int> $categoryIds
     */
    public function setCategories(int $videoId, array $categoryIds, ?int $primaryId = null): void
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));

        $this->db->transaction(function () use ($videoId, $categoryIds, $primaryId): void {
            $this->db->execute('DELETE FROM {video_categories} WHERE video_id = ?', [$videoId]);

            $position = 0;
            foreach ($categoryIds as $categoryId) {
                if ($categoryId <= 0) {
                    continue;
                }
                $this->db->execute(
                    'INSERT IGNORE INTO {video_categories} (video_id, category_id, is_primary, position)
                     VALUES (?, ?, ?, ?)',
                    [
                        $videoId,
                        $categoryId,
                        ($primaryId !== null ? $categoryId === $primaryId : $position === 0) ? 1 : 0,
                        $position,
                    ]
                );
                $position += 10;
            }
        });
    }

    /**
     * The category a video should be filed under.
     *
     * This is where "local taxonomy overrides bunny.net collections" is
     * actually decided. A local assignment always wins; the imported
     * collection is consulted only when there is no local one, so importing
     * gives a sensible starting point without ever fighting an editor's
     * subsequent decisions.
     */
    public function effectiveCategoryId(Video $video): ?int
    {
        $local = $this->categoryIds($video->id);
        if ($local !== []) {
            return $local[0];
        }

        if ($video->providerCollectionId === null) {
            return null;
        }

        $id = $this->db->value(
            'SELECT id FROM {categories} WHERE provider_collection_id = ? LIMIT 1',
            [$video->providerCollectionId]
        );

        return $id === null ? null : (int) $id;
    }

    // ---------------------------------------------------------------- writes

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): Video
    {
        $video = $this->find($id);
        if ($video === null) {
            throw HttpException::notFound('That video does not exist.');
        }

        $fields = [];

        if (isset($attributes['title'])) {
            $title = trim((string) $attributes['title']);
            if ($title === '') {
                throw HttpException::badRequest('A video needs a title.');
            }
            if (mb_strlen($title) > 200) {
                throw HttpException::badRequest('Titles are limited to 200 characters.');
            }
            $fields['title'] = $title;
        }

        if (isset($attributes['slug'])) {
            $slug = $this->uniqueSlug((string) $attributes['slug'], $id);
            if ($slug !== $video->slug) {
                $this->recordAlias($id, $video->slug);
                $fields['slug'] = $slug;
            }
        }

        foreach (['description', 'recorded_at', 'published_at'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = $attributes[$key];
            }
        }

        foreach (['speaker_id', 'series_id', 'series_position', 'position'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = $attributes[$key] === null ? null : (int) $attributes[$key];
            }
        }

        foreach (['is_published', 'member_only', 'hidden', 'featured', 'pinned'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = (int) (bool) $attributes[$key];
            }
        }

        if (isset($attributes['watermark_mode'])) {
            $mode = (string) $attributes['watermark_mode'];
            if (!in_array($mode, ['default', 'on', 'off'], true)) {
                throw HttpException::badRequest('Watermark mode must be default, on, or off.');
            }
            $fields['watermark_mode'] = $mode;
        }

        if ($fields === []) {
            return $video;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('videos', $fields, ['id' => $id]);

        $updated = $this->find($id);
        if ($updated === null) {
            throw new \RuntimeException('The video vanished mid-update.');
        }

        return $updated;
    }

    /**
     * Soft delete. The row survives so share links keep resolving to a clear
     * "this is gone" rather than a 404 that looks like a broken link.
     */
    public function softDelete(int $id): void
    {
        $this->db->execute(
            'UPDATE {videos} SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    public function restore(int $id): void
    {
        $this->db->execute(
            'UPDATE {videos} SET deleted_at = NULL, updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    public function forceDelete(int $id): void
    {
        $this->db->execute('DELETE FROM {videos} WHERE id = ?', [$id]);
    }

    /** @param list<int> $orderedIds */
    public function reorder(array $orderedIds): void
    {
        $this->db->transaction(function () use ($orderedIds): void {
            $position = 0;
            foreach ($orderedIds as $id) {
                $position += 10;
                $this->db->execute(
                    'UPDATE {videos} SET position = ?, updated_at = NOW() WHERE id = ?',
                    [$position, (int) $id]
                );
            }
        });
    }

    // ----------------------------------------------------------------- sync

    /**
     * Reconcile the local table against the provider's library.
     *
     * Rules, in order of how much they matter:
     *
     *  - A new provider video is inserted, unpublished. Auto-publishing would
     *    mean anything uploaded at the provider appears on a public site with
     *    no editorial step.
     *  - For an existing video, only provider-owned fields are refreshed:
     *    duration, encoding status, thumbnail. The title is NOT overwritten —
     *    someone who renamed "final_v3_REALFINAL.mp4" to something readable
     *    must not have that undone by a routine sync.
     *  - A video that has disappeared from the provider is marked failed, not
     *    deleted. Deleting local rows on the strength of one API response
     *    would destroy categorisation and share history if that response were
     *    ever wrong.
     *
     * @param list<VideoMeta> $providerVideos
     * @return array{created: int, updated: int, missing: int}
     */
    public function syncFromProvider(array $providerVideos, string $provider = 'bunny'): array
    {
        $created = 0;
        $updated = 0;
        $seen = [];

        foreach ($providerVideos as $meta) {
            if ($meta->id === '') {
                continue;
            }
            $seen[] = $meta->id;

            $existing = $this->findByProviderId($meta->id, $provider);

            if ($existing === null) {
                $this->insertFromProvider($meta, $provider);
                $created++;
                continue;
            }

            $this->db->update('videos', [
                'duration'               => $meta->duration,
                'thumbnail_file'         => $meta->thumbnailFile,
                'status'                 => $meta->status,
                'encode_progress'        => $meta->encodeProgress,
                'provider_collection_id' => $meta->collectionId,
                'updated_at'             => date('Y-m-d H:i:s'),
            ], ['id' => $existing->id]);
            $updated++;
        }

        $missing = 0;
        if ($seen !== []) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $missing = $this->db->execute(
                "UPDATE {videos}
                    SET status = 'failed', updated_at = NOW()
                  WHERE provider = ?
                    AND deleted_at IS NULL
                    AND status <> 'failed'
                    AND provider_id NOT IN ({$placeholders})",
                [$provider, ...$seen]
            );
        }

        return ['created' => $created, 'updated' => $updated, 'missing' => $missing];
    }

    private function insertFromProvider(VideoMeta $meta, string $provider): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('videos', [
            'provider'               => $provider,
            'provider_id'            => $meta->id,
            'provider_collection_id' => $meta->collectionId,
            'slug'                   => $this->uniqueSlug($meta->title),
            'title'                  => mb_substr($meta->title, 0, 200),
            'duration'               => $meta->duration,
            'thumbnail_file'         => $meta->thumbnailFile,
            'status'                 => $meta->status,
            'encode_progress'        => $meta->encodeProgress,
            'width'                  => $meta->width,
            'height'                 => $meta->height,
            // Unpublished on arrival — publishing is an editorial decision.
            'is_published'           => 0,
            'provider_created_at'    => $meta->createdAt?->format('Y-m-d H:i:s'),
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
    }

    /**
     * Record a video created by an upload ticket, before the bytes arrive.
     *
     * The row has to exist immediately so the upload UI has something to track
     * and so a cancelled upload has something to clean up.
     */
    public function createPlaceholder(string $providerId, string $title, string $provider = 'bunny'): Video
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->db->insert('videos', [
            'provider'     => $provider,
            'provider_id'  => $providerId,
            'slug'         => $this->uniqueSlug($title),
            'title'        => mb_substr(trim($title), 0, 200) ?: 'Untitled',
            'status'       => Video::STATUS_PROCESSING,
            'is_published' => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $video = $this->find($id);
        if ($video === null) {
            throw new \RuntimeException('The video record was created but could not be read back.');
        }

        return $video;
    }

    // ------------------------------------------------------------- internals

    public function uniqueSlug(string $desired, ?int $ignoreId = null): string
    {
        $base = Str::slug($desired);
        $slug = $base;
        $suffix = 1;

        while (true) {
            $sql = 'SELECT id FROM {videos} WHERE slug = ?';
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

    private function recordAlias(int $videoId, string $oldSlug): void
    {
        try {
            $this->db->execute(
                'INSERT IGNORE INTO {slug_aliases} (target_type, target_id, slug, created_at)
                 VALUES ("video", ?, ?, NOW())',
                [$videoId, $oldSlug]
            );
        } catch (Throwable $e) {
            error_log('Portal: could not record video slug alias: ' . $e->getMessage());
        }
    }

    /** Resolve an old slug to its current target, for 301 redirects. */
    public function findByAlias(string $slug): ?Video
    {
        $id = $this->db->value(
            'SELECT target_id FROM {slug_aliases} WHERE target_type = "video" AND slug = ?',
            [$slug]
        );

        return $id === null ? null : $this->find((int) $id);
    }
}
