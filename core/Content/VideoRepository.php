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

        /*
         * Two orderings, chosen by whether anybody searched.
         *
         * A browsing listing is curated — pinned first, then the order an
         * editor arranged. A search result is not: putting a pinned video above
         * an exact title match means the site argues with the person using it.
         * Relevance leads, and the curated order survives underneath it as the
         * tiebreak, so equally relevant results still come back in a sensible
         * arrangement rather than by row id.
         */
        $order = $where['score'] === ''
            ? 'v.pinned DESC, v.position ASC, COALESCE(v.published_at, v.created_at) DESC, v.id DESC'
            : 'relevance DESC, v.pinned DESC, COALESCE(v.published_at, v.created_at) DESC, v.id DESC';

        $select = $where['score'] === ''
            ? 'v.*'
            : "v.*, ({$where['score']}) AS relevance";

        $rows = $this->db->all(
            "SELECT DISTINCT {$select} FROM {videos} v {$where['join']}
              WHERE {$where['sql']}
              ORDER BY {$order}
              LIMIT {$perPage} OFFSET {$offset}",
            [...$where['scoreParams'], ...$params]
        );

        return [
            'items' => array_map(static fn (array $row): Video => Video::fromRow($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: array{sql: string, join: string, score: string, scoreParams: list<mixed>}, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = ['v.deleted_at IS NULL'];
        $params = [];
        $join = '';
        $score = '';
        $scoreParams = [];

        // Processing and failed videos are excluded by default: a player that
        // cannot start reads as a broken site, not as "not ready yet".
        if (empty($filters['includeProcessing'])) {
            $conditions[] = "v.status = 'ready'";
        }
        if (empty($filters['includeUnpublished'])) {
            $conditions[] = 'v.is_published = 1';

            /*
             * The schedule window, evaluated here rather than by a job that
             * flips a flag. Cron is optional on the hosts this ships to and the
             * built-in pseudo-cron only fires on traffic, so a scheduled video
             * on a quiet site would appear late or not at all. A comparison
             * cannot be late.
             *
             * A premiere is the exception at the near end: it is listed before
             * its date so people know it is coming, and the watch page refuses
             * to play it. The far end has no exception — an expired video is
             * gone for everybody.
             */
            $conditions[] = empty($filters['includePremieres'])
                ? '(v.published_at IS NULL OR v.published_at <= NOW())'
                : '(v.published_at IS NULL OR v.published_at <= NOW() OR v.premiere = 1)';

            $conditions[] = '(v.unpublish_at IS NULL OR v.unpublish_at > NOW())';
        }
        if (empty($filters['includeHidden'])) {
            $conditions[] = 'v.hidden = 0';
        }

        /*
         * Restrict to a set of ids somebody else worked out.
         *
         * How the scripture pages list videos: that index answers "which videos
         * touch Romans 8" and then hands the ids here, so the listing goes
         * through the same visibility rules, the same presenter and the same
         * pagination as every other one. Building a second listing query beside
         * this one would be a second place for the members-only rules to be
         * wrong, and only one of them would get fixed.
         *
         * An EMPTY array is a real answer meaning "nothing matched", and it has
         * to produce no rows rather than being ignored — the natural bug here
         * is an empty IN () that either is a syntax error or silently drops the
         * filter and lists the whole library.
         */
        if (isset($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $filters['ids']))));

            if ($ids === []) {
                $conditions[] = '1 = 0';
            } else {
                $conditions[] = 'v.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
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

        // The featured flag has existed on videos since Phase 1 and nothing
        // ever filtered on it. A homepage row is its first consumer.
        if (!empty($filters['featured'])) {
            $conditions[] = 'v.featured = 1';
        }

        if (!empty($filters['speakerId'])) {
            $conditions[] = 'v.speaker_id = ?';
            $params[] = (int) $filters['speakerId'];
        }

        // Published between two dates. Compared against the effective date the
        // listing sorts by, so a filter and the order it filters agree.
        if (!empty($filters['from'])) {
            $conditions[] = 'COALESCE(v.published_at, v.created_at) >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $conditions[] = 'COALESCE(v.published_at, v.created_at) <= ?';
            $params[] = (string) $filters['to'];
        }

        if (!empty($filters['search'])) {
            $terms = SearchQuery::terms((string) $filters['search']);

            if ($terms === []) {
                // Punctuation only, or quotes with nothing between them. The
                // previous behaviour — a LIKE on the raw string — would have
                // matched every video containing a double quote, which is not
                // what anybody meant.
                $conditions[] = '1 = 0';
            } else {
                /*
                 * Speaker and series are joined rather than sub-queried because
                 * both are at most one row per video, so nothing multiplies.
                 * Categories are many, so they stay an EXISTS: an extra join
                 * there would duplicate a video once per category and the
                 * DISTINCT would then have to fight the score column.
                 */
                /*
                 * Transcripts are joined rather than sub-queried for the same
                 * reason as speakers: one row per video at most. The body is a
                 * MEDIUMTEXT, so this is the one join here that carries real
                 * weight — worth it, because the alternative is an EXISTS
                 * evaluated once per term per row.
                 */
                $join .= ' LEFT JOIN {speakers} sp ON sp.id = v.speaker_id'
                       . ' LEFT JOIN {series} se ON se.id = v.series_id'
                       . ' LEFT JOIN {transcripts} tr ON tr.video_id = v.id';

                $categoryExists =
                    'EXISTS (SELECT 1 FROM {video_categories} vcs
                               JOIN {categories} cs ON cs.id = vcs.category_id
                              WHERE vcs.video_id = v.id AND LOWER(cs.name) LIKE ?)';

                $parts = [];

                /*
                 * The whole query as typed, matching the title exactly. Scored
                 * once rather than per term, mirroring SearchQuery::score().
                 */
                $parts[] = '(CASE WHEN LOWER(v.title) = ? THEN ' . SearchQuery::WEIGHT_TITLE_EXACT . ' ELSE 0 END)';
                $scoreParams[] = mb_strtolower(implode(' ', $terms));

                foreach ($terms as $term) {
                    $escaped = $this->db->escapeLike($term);
                    $prefix = $escaped . '%';
                    $contains = '%' . $escaped . '%';

                    /*
                     * Every term must match SOMETHING, but not the same
                     * something. "grace romans" should find a video titled
                     * "Romans 8" in a series called "Grace Abounding" — and a
                     * single LIKE on the raw string, which is what this used to
                     * be, finds nothing the moment anybody types two words.
                     */
                    $conditions[] = '(LOWER(v.title) LIKE ?
                        OR LOWER(v.description) LIKE ?
                        OR LOWER(sp.name) LIKE ?
                        OR LOWER(se.title) LIKE ?
                        OR LOWER(tr.body) LIKE ?
                        OR ' . $categoryExists . ')';

                    array_push($params, $contains, $contains, $contains, $contains, $contains, $contains);

                    $parts[] = '(CASE
                        WHEN LOWER(v.title) LIKE ? THEN ' . SearchQuery::WEIGHT_TITLE_PREFIX . '
                        WHEN LOWER(v.title) LIKE ? THEN ' . SearchQuery::WEIGHT_TITLE . '
                        ELSE 0 END)';
                    array_push($scoreParams, $prefix, $contains);

                    $parts[] = '(CASE WHEN LOWER(sp.name) LIKE ? THEN '
                        . SearchQuery::WEIGHT_SPEAKER . ' ELSE 0 END)';
                    $scoreParams[] = $contains;

                    $parts[] = '(CASE WHEN LOWER(se.title) LIKE ? THEN '
                        . SearchQuery::WEIGHT_SERIES . ' ELSE 0 END)';
                    $scoreParams[] = $contains;

                    $parts[] = '(CASE WHEN ' . $categoryExists . ' THEN '
                        . SearchQuery::WEIGHT_CATEGORY . ' ELSE 0 END)';
                    $scoreParams[] = $contains;

                    $parts[] = '(CASE WHEN LOWER(v.description) LIKE ? THEN '
                        . SearchQuery::WEIGHT_DESCRIPTION . ' ELSE 0 END)';
                    $scoreParams[] = $contains;

                    $parts[] = '(CASE WHEN LOWER(tr.body) LIKE ? THEN '
                        . SearchQuery::WEIGHT_TRANSCRIPT . ' ELSE 0 END)';
                    $scoreParams[] = $contains;
                }

                $score = implode(' + ', $parts);
            }
        }

        return [
            [
                'sql'         => implode(' AND ', $conditions),
                'join'        => $join,
                'score'       => $score,
                'scoreParams' => $scoreParams,
            ],
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
    /**
     * Signals that other videos share something with this one.
     *
     * One statement rather than four, because this runs on the busiest page in
     * the product. Four queries per watch page is the exact shape the query
     * monitor exists to name, and it would be four more on a page that already
     * loads chapters, transcripts, attachments and scripture.
     *
     * A UNION ALL of four cheap index reads, aggregated once. Each branch emits
     * a row per candidate per match, and the outer query counts them — so
     * "shares two categories" arrives as a number without any branch needing to
     * know about the others.
     *
     * Visibility is deliberately NOT decided here. This returns candidates; the
     * caller passes them through query(), which owns every rule about what a
     * given viewer may see. Two places that both decide visibility is one place
     * that will eventually disagree, and the failure mode is an unpublished or
     * members-only video appearing in a public list.
     *
     * @return array<int, array{series: bool, speaker: bool, categories: int, scriptures: int}>
     */
    public function relatednessSignals(Video $video, int $candidateLimit = 60): array
    {
        $limit = max(1, min(200, $candidateLimit));

        $rows = $this->db->all(
            "SELECT candidate, MAX(is_series) AS is_series, MAX(is_speaker) AS is_speaker,
                    SUM(is_category) AS categories, SUM(is_scripture) AS scriptures
               FROM (
                   SELECT v.id AS candidate, 1 AS is_series, 0 AS is_speaker,
                          0 AS is_category, 0 AS is_scripture
                     FROM {videos} v
                    WHERE v.series_id IS NOT NULL AND v.series_id = ? AND v.id <> ?

                   UNION ALL

                   SELECT v.id, 0, 1, 0, 0
                     FROM {videos} v
                    WHERE v.speaker_id IS NOT NULL AND v.speaker_id = ? AND v.id <> ?

                   UNION ALL

                   SELECT vc.video_id, 0, 0, 1, 0
                     FROM {video_categories} vc
                    WHERE vc.category_id IN (
                              SELECT category_id FROM {video_categories} WHERE video_id = ?
                          )
                      AND vc.video_id <> ?

                   UNION ALL

                   SELECT sr.video_id, 0, 0, 0, 1
                     FROM {scripture_refs} sr
                    WHERE (sr.book, sr.chapter) IN (
                              SELECT book, chapter FROM {scripture_refs} WHERE video_id = ?
                          )
                      AND sr.video_id <> ?
               ) AS signals
              GROUP BY candidate
              LIMIT {$limit}",
            [
                $video->seriesId, $video->id,
                $video->speakerId, $video->id,
                $video->id, $video->id,
                $video->id, $video->id,
            ]
        );

        $signals = [];
        foreach ($rows as $row) {
            $signals[(int) $row['candidate']] = [
                'series'     => (int) $row['is_series'] === 1,
                'speaker'    => (int) $row['is_speaker'] === 1,
                'categories' => (int) $row['categories'],
                'scriptures' => (int) $row['scriptures'],
            ];
        }

        return $signals;
    }

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

    /**
     * Resolve the thumbnail mode for many videos at once.
     *
     * Batched deliberately. A listing renders up to a hundred cards, and the
     * obvious per-video implementation would be two queries each — the kind of
     * thing that is invisible on a seeded test database and crippling on a real
     * library.
     *
     * Where several categories disagree, MEMBERS wins. A video that sits in
     * both "Sermons" and "Staff training" is in a members-only section
     * regardless of which one is primary, and the protective reading is the
     * only one that cannot surprise someone. A video's own explicit setting
     * still overrides all of it, so a single video can always be forced public.
     *
     * @param list<Video> $videos
     * @return array<int, string> video id => resolved mode
     */
    public function thumbnailModes(array $videos, bool $siteDefault = false): array
    {
        if ($videos === []) {
            return [];
        }

        $ids = array_map(static fn (Video $v): int => $v->id, $videos);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        /** @var array<int, list<int>> $categoriesByVideo */
        $categoriesByVideo = [];
        foreach ($this->db->all(
            "SELECT video_id, category_id FROM {video_categories} WHERE video_id IN ({$placeholders})",
            $ids
        ) as $row) {
            $categoriesByVideo[(int) $row['video_id']][] = (int) $row['category_id'];
        }

        // The whole category table in one read. There are tens of these, not
        // thousands, and every video's chain is resolved against the same rows.
        $paths = [];
        $modes = [];
        foreach ($this->db->all('SELECT id, path, thumbnail_mode FROM {categories}') as $row) {
            $id = (int) $row['id'];
            $paths[$id] = (string) $row['path'];
            $modes[$id] = (string) $row['thumbnail_mode'];
        }

        $resolved = [];

        foreach ($videos as $video) {
            $opinions = [];

            foreach ($categoriesByVideo[$video->id] ?? [] as $categoryId) {
                $opinion = $this->nearestCategoryOpinion($categoryId, $paths, $modes);
                if ($opinion !== null) {
                    $opinions[] = $opinion;
                }
            }

            $chain = [];
            if (in_array(ThumbnailPolicy::MEMBERS, $opinions, true)) {
                $chain = [ThumbnailPolicy::MEMBERS];
            } elseif ($opinions !== []) {
                $chain = [ThumbnailPolicy::PUBLIC_ART];
            }

            $resolved[$video->id] = ThumbnailPolicy::resolve($video->thumbnailMode, $chain, $siteDefault);
        }

        return $resolved;
    }

    /**
     * Walk one category's ancestors, nearest first, for the first real opinion.
     *
     * @param array<int, string> $paths
     * @param array<int, string> $modes
     */
    private function nearestCategoryOpinion(int $categoryId, array $paths, array $modes): ?string
    {
        // path is root-first ("/1/7/22/"), and the nearest ancestor wins, so it
        // is walked in reverse. A category with no path row still gets its own
        // mode consulted rather than being skipped entirely.
        $chain = isset($paths[$categoryId])
            ? array_reverse(array_map('intval', array_filter(explode('/', trim($paths[$categoryId], '/')), 'strlen')))
            : [$categoryId];

        foreach ($chain as $id) {
            $mode = $modes[$id] ?? ThumbnailPolicy::INHERIT;
            if ($mode === ThumbnailPolicy::MEMBERS || $mode === ThumbnailPolicy::PUBLIC_ART) {
                return $mode;
            }
        }

        return null;
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

        if (array_key_exists('description', $attributes)) {
            $fields['description'] = $attributes['description'];
        }

        /*
         * Dates are normalised on the way in, and an unusable one is refused
         * rather than stored.
         *
         * These decide when content appears and disappears, so a value the
         * comparison cannot read is not a cosmetic problem: it either publishes
         * something early or hides it forever, and both look like a bug in the
         * schedule rather than a typo in a field.
         */
        foreach (['recorded_at', 'published_at', 'unpublish_at'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = $this->normalizeDate($attributes[$key], $key);
            }
        }

        /*
         * An end date before the start date is a window that never opens. It is
         * always a mistake, and silently accepting it produces a video that
         * simply never appears with nothing on screen to explain why.
         */
        $start = $fields['published_at'] ?? $video->publishedAt;
        $end = $fields['unpublish_at'] ?? $video->unpublishAt;

        if ($start !== null && $end !== null && $end <= $start) {
            throw HttpException::badRequest('The end date has to be after the publication date.');
        }

        foreach (['speaker_id', 'series_id', 'series_position', 'position'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = $attributes[$key] === null ? null : (int) $attributes[$key];
            }
        }

        foreach (['is_published', 'member_only', 'hidden', 'featured', 'pinned', 'premiere'] as $key) {
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

        if (isset($attributes['thumbnail_mode'])) {
            $fields['thumbnail_mode'] = ThumbnailPolicy::sanitize($attributes['thumbnail_mode']);
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
    /**
     * Everything in the trash, newest deletion first.
     *
     * Not routed through query(), which filters deleted rows out by design.
     * Reaching for an includeDeleted flag there would put a "show me the
     * deleted ones" switch on the same method every public listing calls, and
     * one wrong caller would then leak deleted content.
     *
     * @return list<Video>
     */
    public function trashed(int $limit = 100): array
    {
        $rows = $this->db->all(
            'SELECT * FROM {videos} WHERE deleted_at IS NOT NULL
              ORDER BY deleted_at DESC LIMIT ' . max(1, min(500, $limit))
        );

        return array_map(static fn (array $row): Video => Video::fromRow($row), $rows);
    }

    public function trashedCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NOT NULL');
    }

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

    /**
     * A date the schedule can actually compare, or null.
     *
     * Accepts what a browser's datetime-local input sends ("2026-03-04T09:05")
     * as well as ordinary SQL datetimes, and stores one canonical form. An
     * empty string means "clear it" — a person emptying the field is saying
     * there is no date, not asking for the epoch.
     */
    private function normalizeDate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw HttpException::badRequest(
                sprintf('"%s" is not a date this can use.', $raw)
            );
        }
    }

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
