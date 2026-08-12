<?php

declare(strict_types=1);

namespace Portal\Content;

use Generator;
use Portal\Db;

/**
 * The whole library, as a file you can keep.
 *
 * Settings have been exportable since Phase 3; the content never has. On a host
 * with no shell and no database console — which is the entire target of this
 * product — that means the only copy of what somebody spent a year cataloguing
 * lives in a database they cannot reach except through this application.
 *
 * NDJSON: one JSON object per line, not one document.
 *
 * A single JSON array would have to be either built in memory, which is what
 * the memory limit refuses on a real library, or streamed with hand-written
 * brackets and commas, which produces an invalid document the moment anything
 * interrupts it. Shared hosting interrupts things — execution time limits,
 * idle proxies, somebody closing a laptop. A truncated NDJSON file is N valid
 * records and one partial line; a truncated JSON array is a syntax error and
 * nothing else.
 *
 * Batched for the same reason. Peak memory is one batch, whether the library
 * holds fifty videos or fifty thousand.
 *
 * This used to say it was a record and not a restore, because writing an
 * importer needed answers to real questions — what happens to a slug that
 * already exists, whether a provider id from another account means anything,
 * how to merge rather than clobber. ContentImport answers them: nothing is ever
 * overwritten, ids are remapped rather than reused, and a reference the file
 * names but the site does not have is dropped rather than invented.
 *
 * The dependency order below is what makes that possible in ONE PASS, and it
 * stopped being a nicety the moment something read this back. A video refers to
 * its series, speaker and categories by the ids they had here, so an importer
 * has to have seen and remapped those before it meets the video.
 */
final class ContentExport
{
    /**
     * Rows per query.
     *
     * Small enough that one batch of videos with descriptions is comfortably
     * inside any memory limit worth supporting, large enough that a thousand
     * videos is twenty round trips rather than a thousand.
     */
    private const BATCH = 200;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every record, in dependency order.
     *
     * Categories, series and speakers first, so anything reading this stream in
     * one pass has seen what a video refers to before it meets the video. That
     * costs nothing here and is the difference between a one-pass reader and
     * one that has to buffer the whole file — which would reintroduce the
     * problem this format exists to avoid, one step downstream.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function records(bool $includeTranscripts = false): Generator
    {
        yield [
            'type'       => 'meta',
            'version'    => PORTAL_VERSION,
            'exportedAt' => date('c'),
            'note'       => 'One JSON object per line. This is a record of the library, not a restore file.',
        ];

        /*
         * No created_at on this table — it has never had one.
         *
         * The visibility flags were missing until an importer was written and
         * the gap became a harm rather than an omission: restoring a hidden or
         * members-only category as public republishes something that was taken
         * down on purpose. Adding fields is backward-compatible — an older file
         * simply lacks them and the importer defaults.
         */
        yield from $this->table('category', 'categories', [
            'id', 'parent_id', 'slug', 'name', 'description', 'image_url',
            'path', 'depth', 'position',
            'is_published', 'member_only', 'hidden',
        ]);

        yield from $this->table('series', 'series', [
            'id', 'slug', 'title', 'description', 'is_published', 'sequential', 'created_at',
        ]);

        yield from $this->table('speaker', 'speakers', [
            'id', 'slug', 'name', 'bio', 'created_at',
        ]);

        yield from $this->videos();

        if ($includeTranscripts) {
            yield from $this->transcripts();
        }
    }

    /**
     * A straightforward table, in id order.
     *
     * Keyset pagination on the primary key rather than LIMIT/OFFSET. OFFSET
     * makes the database count past every row it has already returned, so the
     * last batch of a large export costs the most — and a row inserted during
     * the export shifts the window and silently skips a record.
     *
     * @param list<string> $columns
     * @return Generator<int, array<string, mixed>>
     */
    private function table(string $type, string $table, array $columns): Generator
    {
        $select = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $after = 0;

        while (true) {
            $rows = $this->db->all(
                "SELECT {$select} FROM {{$table}} WHERE id > ? ORDER BY id ASC LIMIT " . self::BATCH,
                [$after]
            );

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $after = (int) $row['id'];
                yield ['type' => $type] + $row;
            }
        }
    }

    /**
     * Videos, with the taxonomy that would otherwise be lost.
     *
     * Categories and scripture references are collected per batch rather than
     * per video — two extra queries for two hundred videos instead of four
     * hundred. A per-row lookup here is the shape that turns an export of a
     * real library into something that never finishes.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function videos(): Generator
    {
        $after = 0;

        while (true) {
            $rows = $this->db->all(
                'SELECT id, slug, title, description, status, provider, provider_id,
                        duration, series_id, series_position, speaker_id,
                        is_published, published_at, unpublish_at, premiere,
                        member_only, hidden, featured, pinned, position,
                        watermark_mode, thumbnail_mode, created_at, updated_at, deleted_at
                   FROM {videos}
                  WHERE id > ?
                  ORDER BY id ASC
                  LIMIT ' . self::BATCH,
                [$after]
            );

            if ($rows === []) {
                return;
            }

            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $categories = $this->categoriesFor($ids);
            $scripture = $this->scriptureFor($ids);

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $after = $id;

                yield ['type' => 'video'] + $row + [
                    'categories' => $categories[$id] ?? [],
                    'scripture'  => $scripture[$id] ?? [],
                ];
            }
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<int>>
     */
    private function categoriesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT video_id, category_id FROM {video_categories}
              WHERE video_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY video_id, position',
            $ids
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['video_id']][] = (int) $row['category_id'];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function scriptureFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->db->all(
                'SELECT video_id, book, chapter FROM {scripture_refs}
                  WHERE video_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                  ORDER BY video_id, id',
                $ids
            );
        } catch (\Throwable) {
            // Before migration 0014 on a very old install. An export missing
            // scripture is worth far more than no export.
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['video_id']][] = [
                'book'    => (string) $row['book'],
                'chapter' => (int) $row['chapter'],
            ];
        }

        return $out;
    }

    /**
     * Transcripts, opt-in.
     *
     * A sermon transcript runs to tens of kilobytes, so including them can
     * multiply the size of an export by fifty. Streaming means that is a
     * question of time rather than memory, but it is still the difference
     * between a file somebody can email and one they cannot — so it is asked
     * rather than assumed.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function transcripts(): Generator
    {
        // Keyed on video_id, not on an id of its own — one transcript per
        // video, which is what the primary key says. Paginating on `id` here
        // would have failed on the first query.
        $after = 0;

        while (true) {
            try {
                $rows = $this->db->all(
                    'SELECT video_id, body, source, cue_count, updated_at FROM {transcripts}
                      WHERE video_id > ? ORDER BY video_id ASC LIMIT 25',
                    [$after]
                );
            } catch (\Throwable) {
                // Before migration 0009. An export without transcripts beats
                // no export.
                return;
            }

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $after = (int) $row['video_id'];
                yield ['type' => 'transcript'] + $row;
            }
        }
    }

    /**
     * One NDJSON line.
     *
     * Invalid UTF-8 would make json_encode return false and silently drop the
     * record, so it is substituted instead. A description pasted from a word
     * processor is a normal source of a stray byte, and losing a video from the
     * export because of one is not a trade anybody would choose.
     *
     * @param array<string, mixed> $record
     */
    public static function line(array $record): string
    {
        $json = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $json = json_encode([
                'type'  => 'error',
                'about' => $record['type'] ?? 'unknown',
                'id'    => $record['id'] ?? null,
            ]);
        }

        return $json . "\n";
    }
}
