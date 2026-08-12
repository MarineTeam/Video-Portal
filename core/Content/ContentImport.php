<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Throwable;

/**
 * Reading a library export back in.
 *
 * ContentExport has said since it shipped that it is "a record of the library,
 * not a restore file", and that there is no importer because writing one is a
 * different job with real questions behind it. This answers them.
 *
 * WHAT HAPPENS TO A SLUG THAT ALREADY EXISTS: it is skipped, and nothing is
 * overwritten. Ever.
 *
 * That is the whole safety of this feature. The primary use is restoring into
 * a site that lost its database, where nothing collides and everything lands.
 * The secondary use is merging one library into another, where things do
 * collide — and there, overwriting means one wrong file destroys a year of
 * cataloguing with no undo, on a host where the person cannot reach a database
 * console to put it back. Skipping is recoverable by deleting and importing
 * again; overwriting is not recoverable at all. There is deliberately no
 * "replace" option, because the option is the danger.
 *
 * IDS ARE REMAPPED, NOT REUSED. Every record in the file carries the id it had
 * on the site that wrote it, and a video refers to its series, speaker and
 * categories by those ids. Inserting them as-is would either collide with
 * unrelated rows or attach a video to whatever now happens to hold that
 * number. So this builds old-id => new-id maps as it goes, which is exactly
 * what the export's dependency order — categories, series and speakers before
 * videos — was designed to make possible in one pass.
 *
 * CATEGORY PATHS ARE RECOMPUTED. `path` caches the ancestor chain as "/1/7/22/"
 * using ids, so a copied path points at the old site's tree. CategoryRepository
 * is the only thing that writes a path, and this goes through it rather than
 * writing rows directly — two implementations of that rule would drift, and the
 * failure is a category silently inheriting a stranger's permissions.
 *
 * STREAMED, never loaded. The format exists so a huge library is N lines rather
 * than one document; reading it with file_get_contents would undo that at the
 * last step.
 */
final class ContentImport
{
    /**
     * What a file may contain before this refuses to look at it.
     *
     * Not a memory limit — nothing is held — but a sanity bound. A file with a
     * million lines is either not one of ours or is going to exceed the
     * execution limit halfway through, and stopping at the start with a clear
     * message beats stopping in the middle with a partial library.
     */
    public const MAX_LINES = 200000;

    /** @var array<int, int> old category id => new */
    private array $categories = [];

    /** @var array<int, int> old series id => new */
    private array $series = [];

    /** @var array<int, int> old speaker id => new */
    private array $speakers = [];

    /** @var array<int, int> old video id => new */
    private array $videos = [];

    /** @var array<string, int> */
    private array $counts = [
        'categories' => 0,
        'series'     => 0,
        'speakers'   => 0,
        'videos'     => 0,
        'transcripts' => 0,
        'skipped'    => 0,
        'failed'     => 0,
    ];

    /** @var list<string> */
    private array $problems = [];

    public function __construct(
        private readonly Db $db,
        private readonly CategoryRepository $categoryRepo,
    ) {
    }

    /**
     * Read a file, one line at a time.
     *
     * @param resource $handle an open, readable stream
     * @return array{counts: array<string, int>, problems: list<string>, version: string}
     */
    public function read($handle): array
    {
        $version = '';
        $lines = 0;

        while (($line = fgets($handle)) !== false) {
            if (++$lines > self::MAX_LINES) {
                $this->problems[] = sprintf(
                    'Stopped after %s lines. If this really is a library that big, '
                    . 'import it in pieces — a run that hits the execution limit halfway '
                    . 'leaves a half-imported library and no way to tell where it stopped.',
                    number_format(self::MAX_LINES)
                );
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true);

            /*
             * A line that will not parse is SKIPPED, not fatal.
             *
             * That is the property NDJSON was chosen for: a file truncated by a
             * timeout or a closed laptop is N valid records and one partial
             * line, and refusing the whole file over the partial line would
             * throw away the N that are fine.
             */
            if (!is_array($record) || !isset($record['type'])) {
                $this->counts['failed']++;
                continue;
            }

            if ($record['type'] === 'meta') {
                $version = (string) ($record['version'] ?? '');
                continue;
            }

            try {
                $this->record($record);
            } catch (Throwable $e) {
                $this->counts['failed']++;

                // Only the first few. A file that fails on every line would
                // otherwise produce a report nobody can read.
                if (count($this->problems) < 10) {
                    $this->problems[] = sprintf(
                        '%s "%s": %s',
                        (string) $record['type'],
                        (string) ($record['slug'] ?? $record['title'] ?? '?'),
                        $e->getMessage()
                    );
                }
            }
        }

        return ['counts' => $this->counts, 'problems' => $this->problems, 'version' => $version];
    }

    /** @param array<string, mixed> $record */
    private function record(array $record): void
    {
        match ((string) $record['type']) {
            'category'   => $this->category($record),
            'series'     => $this->series($record),
            'speaker'    => $this->speaker($record),
            'video'      => $this->video($record),
            'transcript' => $this->transcript($record),
            // An unknown type is ignored rather than counted as a failure: a
            // file written by a later version may carry records this one has
            // never heard of, and the rest of it is still worth importing.
            default      => null,
        };
    }

    /** @param array<string, mixed> $record */
    private function category(array $record): void
    {
        $oldId = (int) ($record['id'] ?? 0);
        $slug = (string) ($record['slug'] ?? '');

        if ($oldId <= 0 || $slug === '') {
            $this->counts['failed']++;
            return;
        }

        $existing = $this->db->value('SELECT id FROM {categories} WHERE slug = ?', [$slug]);
        if ($existing !== null) {
            // Mapped anyway. A video in the file that belongs to this category
            // should land in the one already here rather than nowhere.
            $this->categories[$oldId] = (int) $existing;
            $this->counts['skipped']++;
            return;
        }

        /*
         * Through the repository, so the path is computed from the NEW parent
         * id. The file's `path` and `depth` describe the old site's tree and
         * are deliberately not carried across.
         *
         * The parent is looked up in the map rather than taken from the record.
         * Categories are exported in id order and a parent always has a lower
         * id than its children, so by the time a child is read its parent has
         * been seen — which is why this works in one pass. A parent that is
         * somehow missing becomes a root rather than an orphan pointing at a
         * stranger's category.
         */
        $parentOld = isset($record['parent_id']) && $record['parent_id'] !== null
            ? (int) $record['parent_id']
            : null;

        $created = $this->categoryRepo->create([
            'name'        => (string) ($record['name'] ?? $slug),
            'slug'        => $slug,
            'description' => $record['description'] ?? null,
            'image_url'   => $record['image_url'] ?? null,
            'parent_id'   => $parentOld === null ? null : ($this->categories[$parentOld] ?? null),
            // Ordering is content. A restored library whose categories come
            // back alphabetically has lost a decision somebody made.
            'position'    => (int) ($record['position'] ?? 0),
            /*
             * Defaulting to VISIBLE only when the file does not say — which is
             * an older export that predates these fields. When the file does
             * say, it is obeyed: restoring a hidden category as public
             * republishes something taken down on purpose.
             */
            'is_published' => (int) ($record['is_published'] ?? 1),
            'member_only'  => (int) ($record['member_only'] ?? 0),
            'hidden'       => (int) ($record['hidden'] ?? 0),
        ]);

        $this->categories[$oldId] = $created->id;
        $this->counts['categories']++;
    }

    /** @param array<string, mixed> $record */
    private function series(array $record): void
    {
        $oldId = (int) ($record['id'] ?? 0);
        $slug = (string) ($record['slug'] ?? '');

        if ($oldId <= 0 || $slug === '') {
            $this->counts['failed']++;
            return;
        }

        $existing = $this->db->value('SELECT id FROM {series} WHERE slug = ?', [$slug]);
        if ($existing !== null) {
            $this->series[$oldId] = (int) $existing;
            $this->counts['skipped']++;
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->series[$oldId] = (int) $this->db->insert('series', [
            'slug'         => $slug,
            'title'        => (string) ($record['title'] ?? $slug),
            'description'  => $record['description'] ?? null,
            'is_published' => (int) ($record['is_published'] ?? 0),
            'sequential'   => (int) ($record['sequential'] ?? 0),
            'created_at'   => $this->when($record['created_at'] ?? null, $now),
            'updated_at'   => $now,
        ]);

        $this->counts['series']++;
    }

    /** @param array<string, mixed> $record */
    private function speaker(array $record): void
    {
        $oldId = (int) ($record['id'] ?? 0);
        $slug = (string) ($record['slug'] ?? '');

        if ($oldId <= 0 || $slug === '') {
            $this->counts['failed']++;
            return;
        }

        $existing = $this->db->value('SELECT id FROM {speakers} WHERE slug = ?', [$slug]);
        if ($existing !== null) {
            $this->speakers[$oldId] = (int) $existing;
            $this->counts['skipped']++;
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->speakers[$oldId] = (int) $this->db->insert('speakers', [
            'slug'       => $slug,
            'name'       => (string) ($record['name'] ?? $slug),
            'bio'        => $record['bio'] ?? null,
            'created_at' => $this->when($record['created_at'] ?? null, $now),
        ]);

        $this->counts['speakers']++;
    }

    /** @param array<string, mixed> $record */
    private function video(array $record): void
    {
        $oldId = (int) ($record['id'] ?? 0);
        $slug = (string) ($record['slug'] ?? '');
        $providerId = (string) ($record['provider_id'] ?? '');

        if ($oldId <= 0 || $slug === '' || $providerId === '') {
            $this->counts['failed']++;
            return;
        }

        /*
         * Matched on EITHER slug or provider id.
         *
         * The slug is the address people have links to; the provider id is the
         * same underlying file at bunny.net. Either one already being here
         * means this video is present, and inserting would produce two rows for
         * one video — one of which is unreachable, because the slug is unique
         * and the second would have to be renamed to land at all.
         */
        $existing = $this->db->value(
            'SELECT id FROM {videos} WHERE slug = ? OR provider_id = ? LIMIT 1',
            [$slug, $providerId]
        );

        if ($existing !== null) {
            $this->videos[$oldId] = (int) $existing;
            $this->counts['skipped']++;
            return;
        }

        $now = date('Y-m-d H:i:s');
        $seriesOld = isset($record['series_id']) && $record['series_id'] !== null
            ? (int) $record['series_id']
            : null;
        $speakerOld = isset($record['speaker_id']) && $record['speaker_id'] !== null
            ? (int) $record['speaker_id']
            : null;

        $newId = (int) $this->db->insert('videos', [
            'slug'             => $slug,
            'title'            => (string) ($record['title'] ?? $slug),
            'description'      => $record['description'] ?? null,
            'status'           => $this->status((string) ($record['status'] ?? 'processing')),
            'provider'         => (string) ($record['provider'] ?? 'bunny'),
            'provider_id'      => $providerId,
            'duration'         => (int) ($record['duration'] ?? 0),
            // Through the maps. A reference the file names but the map does not
            // hold becomes null rather than pointing at whatever now owns that
            // number, which is the failure that would attach a sermon to a
            // stranger's series.
            'series_id'        => $seriesOld === null ? null : ($this->series[$seriesOld] ?? null),
            'series_position'  => (int) ($record['series_position'] ?? 0),
            'speaker_id'       => $speakerOld === null ? null : ($this->speakers[$speakerOld] ?? null),
            'is_published'     => (int) ($record['is_published'] ?? 0),
            'published_at'     => $record['published_at'] ?? null,
            'unpublish_at'     => $record['unpublish_at'] ?? null,
            'premiere'         => (int) ($record['premiere'] ?? 0),
            'member_only'      => (int) ($record['member_only'] ?? 0),
            'hidden'           => (int) ($record['hidden'] ?? 0),
            'featured'         => (int) ($record['featured'] ?? 0),
            'pinned'           => (int) ($record['pinned'] ?? 0),
            'position'         => (int) ($record['position'] ?? 0),
            'watermark_mode'   => (string) ($record['watermark_mode'] ?? 'inherit'),
            'thumbnail_mode'   => (string) ($record['thumbnail_mode'] ?? 'inherit'),
            'created_at'       => $this->when($record['created_at'] ?? null, $now),
            'updated_at'       => $now,
            /*
             * deleted_at is carried, so a video that was in the trash comes
             * back in the trash. Restoring somebody's bin as live content
             * would republish things they deliberately took down.
             */
            'deleted_at'       => $record['deleted_at'] ?? null,
        ]);

        $this->videos[$oldId] = $newId;
        $this->counts['videos']++;

        $this->videoCategories($newId, (array) ($record['categories'] ?? []));
    }

    /** @param array<int, mixed> $oldCategoryIds */
    private function videoCategories(int $videoId, array $oldCategoryIds): void
    {
        $first = true;

        foreach ($oldCategoryIds as $oldCategoryId) {
            $newId = $this->categories[(int) $oldCategoryId] ?? null;

            // A category the file references and this site does not have. The
            // video still lands; it is simply not in that category.
            if ($newId === null) {
                continue;
            }

            $this->db->execute(
                'INSERT IGNORE INTO {video_categories} (video_id, category_id, is_primary, position)
                 VALUES (?, ?, ?, 0)',
                [$videoId, $newId, $first ? 1 : 0]
            );

            $first = false;
        }
    }

    /** @param array<string, mixed> $record */
    private function transcript(array $record): void
    {
        $oldVideo = (int) ($record['video_id'] ?? 0);
        $newVideo = $this->videos[$oldVideo] ?? null;

        // A transcript for a video that was skipped or absent. Dropped rather
        // than attached to nothing.
        if ($newVideo === null) {
            $this->counts['skipped']++;
            return;
        }

        $this->db->execute(
            'INSERT IGNORE INTO {transcripts} (video_id, language, body, source, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())',
            [
                $newVideo,
                (string) ($record['language'] ?? 'en'),
                (string) ($record['body'] ?? ''),
                (string) ($record['source'] ?? 'import'),
            ]
        );

        $this->counts['transcripts']++;
    }

    /**
     * A status the column will accept.
     *
     * The ENUM would silently store '' for anything else under a non-strict
     * server, which produces a video that is neither ready nor failed and never
     * appears anywhere.
     */
    private function status(string $status): string
    {
        return in_array($status, Video::STATUSES, true) ? $status : Video::STATUS_PROCESSING;
    }

    /**
     * A date from the file, or now.
     *
     * Timestamps are carried across so an imported library keeps its history —
     * "added last March" is information, and rewriting every row to today would
     * make a restored site look like it was created in an afternoon.
     */
    private function when(mixed $value, string $fallback): string
    {
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $time = strtotime($value);

        return $time === false ? $fallback : date('Y-m-d H:i:s', $time);
    }
}
