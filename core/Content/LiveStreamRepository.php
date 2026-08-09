<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Support\Str;

/**
 * Live streams, and which of them is on.
 *
 * The state is never stored. Every read resolves it from the timestamps
 * through LiveStreamPolicy, so nothing has to run for a stream to go live and
 * there is no flag that can be left saying the wrong thing — the failure mode
 * a stored state has is a permanent LIVE badge nobody can explain.
 */
final class LiveStreamRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->decorate($this->db->all(
            'SELECT * FROM {live_streams} ORDER BY COALESCE(starts_at, created_at) DESC, id DESC'
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM {live_streams} WHERE id = ?', [$id]);

        return $row === null ? null : $this->decorate([$row])[0];
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->first('SELECT * FROM {live_streams} WHERE slug = ?', [$slug]);

        return $row === null ? null : $this->decorate([$row])[0];
    }

    /**
     * What a visitor should see: anything on now, then anything coming.
     *
     * Ended streams are excluded unless they have a recording, in which case
     * they are the recording's announcement and belong in the archive rather
     * than here.
     *
     * @param  bool $includeMemberOnly whether this viewer may see members-only streams
     * @return list<array<string, mixed>>
     */
    public function upcoming(bool $includeMemberOnly, int $limit = 20): array
    {
        $where = 'is_published = 1';

        if (!$includeMemberOnly) {
            $where .= ' AND member_only = 0';
        }

        $rows = $this->decorate($this->db->all(
            'SELECT * FROM {live_streams}
              WHERE ' . $where . '
              ORDER BY COALESCE(starts_at, created_at) ASC, id ASC
              LIMIT ' . max(1, min($limit, 100))
        ));

        // Filtered in PHP rather than SQL because the safety net — a stream
        // with no end time stops being live after so many hours — is a rule,
        // not a column, and duplicating it in a WHERE clause is how the two
        // come to disagree.
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['state'] !== LiveStreamPolicy::ENDED
        ));
    }

    /**
     * The one that is on right now, if any.
     *
     * @return array<string, mixed>|null
     */
    public function liveNow(bool $includeMemberOnly): ?array
    {
        foreach ($this->upcoming($includeMemberOnly) as $row) {
            if ($row['state'] === LiveStreamPolicy::LIVE) {
                return $row;
            }
        }

        return null;
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {live_streams}');
    }

    // ----------------------------------------------------------------- writes

    /**
     * @param  array<string, mixed> $attributes
     * @return int the new id
     */
    public function create(array $attributes): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('live_streams', [
            'slug'         => $this->uniqueSlug((string) ($attributes['slug'] ?? ''), (string) ($attributes['title'] ?? '')),
            'title'        => substr(trim((string) ($attributes['title'] ?? 'Live')), 0, 190),
            'description'  => $attributes['description'] ?? null,
            'embed_url'    => trim((string) ($attributes['embed_url'] ?? '')),
            'starts_at'    => self::datetime($attributes['starts_at'] ?? null),
            'ends_at'      => self::datetime($attributes['ends_at'] ?? null),
            'is_published' => !empty($attributes['is_published']) ? 1 : 0,
            'member_only'  => !empty($attributes['member_only']) ? 1 : 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): void
    {
        $fields = [];

        foreach (['title', 'description', 'embed_url'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = $attributes[$key];
            }
        }

        foreach (['starts_at', 'ends_at'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = self::datetime($attributes[$key]);
            }
        }

        foreach (['is_published', 'member_only'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = (int) (bool) $attributes[$key];
            }
        }

        if (array_key_exists('video_id', $attributes)) {
            $videoId = (int) $attributes['video_id'];
            $fields['video_id'] = $videoId > 0 ? $videoId : null;
        }

        if (array_key_exists('slug', $attributes)) {
            $slug = trim((string) $attributes['slug']);
            if ($slug !== '') {
                $fields['slug'] = $this->uniqueSlug($slug, $slug, $id);
            }
        }

        if ($fields === []) {
            return;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('live_streams', $fields, ['id' => $id]);
    }

    /**
     * Mark a stream as finished, now.
     *
     * The button somebody presses when a service ends early. Beats the
     * schedule, which is the point — a stream that finished at eleven must not
     * keep saying LIVE until its planned noon.
     */
    public function end(int $id): void
    {
        $this->db->execute(
            'UPDATE {live_streams} SET ended_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Undo that.
     *
     * Present because ending a stream is one click and streams are ended by
     * mistake mid-service, when the cost of having no way back is the rest of
     * the broadcast.
     */
    public function resume(int $id): void
    {
        $this->db->execute(
            'UPDATE {live_streams} SET ended_at = NULL, updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {live_streams} WHERE id = ?', [$id]);
    }

    // -------------------------------------------------------------- internals

    /**
     * Add the resolved state to each row.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['state'] = LiveStreamPolicy::state(
                $row['starts_at'] === null ? null : (string) $row['starts_at'],
                $row['ends_at'] === null ? null : (string) $row['ends_at'],
                $row['ended_at'] === null ? null : (string) $row['ended_at']
            );
            $rows[$index]['url'] = '/live/' . (string) $row['slug'];
        }

        return $rows;
    }

    private function uniqueSlug(string $desired, string $fallback, ?int $ignoreId = null): string
    {
        $base = Str::slug($desired !== '' ? $desired : $fallback);

        if ($base === '') {
            $base = 'live';
        }

        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($slug, $ignoreId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        $sql = 'SELECT id FROM {live_streams} WHERE slug = ?';
        $params = [$slug];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }

        return $this->db->value($sql, $params) !== null;
    }

    /** A datetime-local value as something MySQL will take, or null. */
    private static function datetime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $parsed = strtotime($value);

        // An unparseable date becomes null rather than "now". Storing the
        // current time for something somebody typed wrong would put a stream
        // live immediately, which is the worst available reading of a typo.
        return $parsed === false ? null : date('Y-m-d H:i:s', $parsed);
    }
}
