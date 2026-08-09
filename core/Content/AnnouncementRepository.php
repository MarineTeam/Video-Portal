<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Http\HttpException;

/**
 * Announcements, and which of them are showing right now.
 *
 * showing() runs on every page load, so it is one indexed query and no more.
 * The audience filter is applied in PHP rather than SQL deliberately: there are
 * three possible values and never many rows, and the rule then lives in one
 * testable place instead of being half in a WHERE clause.
 */
final class AnnouncementRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    /**
     * What this visitor should see, in order.
     *
     * @return list<Announcement>
     */
    public function showing(bool $isApproved, bool $canSeeAdmin): array
    {
        $rows = $this->db->all(
            'SELECT * FROM {announcements}
              WHERE is_active = 1
                AND (starts_at IS NULL OR starts_at <= NOW())
                AND (ends_at IS NULL OR ends_at > NOW())
              ORDER BY position, id'
        );

        $out = [];
        foreach ($rows as $row) {
            $announcement = Announcement::fromRow($row);
            if ($announcement->isFor($isApproved, $canSeeAdmin)) {
                $out[] = $announcement;
            }
        }

        return $out;
    }

    /** @return list<Announcement> */
    public function all(): array
    {
        $rows = $this->db->all('SELECT * FROM {announcements} ORDER BY position, id');

        return array_map(static fn (array $row): Announcement => Announcement::fromRow($row), $rows);
    }

    public function find(int $id): ?Announcement
    {
        $row = $this->db->first('SELECT * FROM {announcements} WHERE id = ?', [$id]);

        return $row === null ? null : Announcement::fromRow($row);
    }

    // ----------------------------------------------------------------- writes

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Announcement
    {
        $body = trim((string) ($attributes['body'] ?? ''));
        if ($body === '') {
            throw HttpException::badRequest('An announcement needs something to say.');
        }

        $now = date('Y-m-d H:i:s');
        $window = $this->window($attributes);

        $id = $this->db->insert('announcements', [
            'title'       => trim((string) ($attributes['title'] ?? '')),
            'body'        => $body,
            'level'       => Announcement::sanitizeLevel($attributes['level'] ?? null),
            'audience'    => Announcement::sanitizeAudience($attributes['audience'] ?? null),
            'starts_at'   => $window[0],
            'ends_at'     => $window[1],
            'dismissible' => isset($attributes['dismissible']) ? (int) (bool) $attributes['dismissible'] : 1,
            'is_active'   => 1,
            'position'    => $this->nextPosition(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $announcement = $this->find($id);
        if ($announcement === null) {
            throw new \RuntimeException('The announcement vanished immediately after being created.');
        }

        return $announcement;
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): Announcement
    {
        $announcement = $this->find($id);
        if ($announcement === null) {
            throw HttpException::notFound('That announcement does not exist.');
        }

        $fields = [];

        if (array_key_exists('title', $attributes)) {
            $fields['title'] = trim((string) $attributes['title']);
        }

        if (array_key_exists('body', $attributes)) {
            $body = trim((string) $attributes['body']);
            if ($body === '') {
                throw HttpException::badRequest('An announcement needs something to say.');
            }
            $fields['body'] = $body;
        }

        if (array_key_exists('level', $attributes)) {
            $fields['level'] = Announcement::sanitizeLevel($attributes['level']);
        }

        if (array_key_exists('audience', $attributes)) {
            $fields['audience'] = Announcement::sanitizeAudience($attributes['audience']);
        }

        if (array_key_exists('starts_at', $attributes) || array_key_exists('ends_at', $attributes)) {
            [$starts, $ends] = $this->window([
                'starts_at' => $attributes['starts_at'] ?? $announcement->startsAt,
                'ends_at'   => $attributes['ends_at'] ?? $announcement->endsAt,
            ]);
            $fields['starts_at'] = $starts;
            $fields['ends_at'] = $ends;
        }

        foreach (['dismissible', 'is_active'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = (int) (bool) $attributes[$key];
            }
        }

        if ($fields === []) {
            return $announcement;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('announcements', $fields, ['id' => $id]);

        return $this->find($id) ?? $announcement;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {announcements} WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------- internals

    /**
     * The date window, normalised and checked.
     *
     * Same rule as scheduled videos, and refused for the same reason: a window
     * that never opens produces a banner nobody ever sees, with nothing on
     * screen to explain why.
     *
     * @param  array<string, mixed> $attributes
     * @return array{0: ?string, 1: ?string}
     */
    private function window(array $attributes): array
    {
        $starts = $this->normalizeDate($attributes['starts_at'] ?? null);
        $ends = $this->normalizeDate($attributes['ends_at'] ?? null);

        if ($starts !== null && $ends !== null && $ends <= $starts) {
            throw HttpException::badRequest('The end has to be after the start.');
        }

        return [$starts, $ends];
    }

    private function normalizeDate(mixed $value): ?string
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
            throw HttpException::badRequest(sprintf('"%s" is not a date this can use.', $raw));
        }
    }

    private function nextPosition(): int
    {
        $max = $this->db->value('SELECT MAX(position) FROM {announcements}');

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
