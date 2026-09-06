<?php

declare(strict_types=1);

namespace Portal\Events;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Recurring events: the template, and turning it into real ones.
 *
 * # A SERIES IS NOT ITSELF AN EVENT
 *
 * The tempting shape is to let the first meeting carry the repeat rule and
 * generate the rest from it. Then deleting the first meeting deletes the year:
 * somebody cancels one week in January and loses every Tuesday until December,
 * with no undo, because the rule went with the row.
 *
 * So this is a template and nothing else. Every date it produces is an ordinary
 * event with its own slug, capacity and sign-up list, editable and cancellable
 * on its own. Deleting the series leaves the meetings standing.
 *
 * # A CANCELLATION HAS TO STICK
 *
 * Delete one generated event and the rule still names that date, so the next
 * run would put it straight back. `cancelDate()` writes the exclusion IN THE
 * SAME TRANSACTION as the delete — either both happen or neither — because a
 * delete that committed without its exclusion restores the meeting within the
 * day, and that is reported as "the site un-cancelled my event".
 */
final class SeriesRepository
{
    /** How far ahead meetings are made. Pushed forward daily by the cron job. */
    public const HORIZON_MONTHS = 6;

    public function __construct(
        private readonly Db $db,
        private readonly EventRepository $events,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM {event_series} ORDER BY starts_at DESC');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {event_series} WHERE id = ?', [$id]);
    }

    /**
     * Start a series. The rule is validated before anything is written.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): int
    {
        $title = trim((string) ($attributes['title'] ?? ''));

        if ($title === '') {
            throw HttpException::badRequest('A series needs a name.');
        }

        // Throws, naming the part it did not understand, before a row exists.
        $rule = Recurrence::parse((string) ($attributes['rrule'] ?? ''));

        $starts = $this->wallClock((string) ($attributes['starts_at'] ?? ''));
        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('event_series', [
            'title'            => mb_substr($title, 0, 190),
            'description'      => trim((string) ($attributes['description'] ?? '')) ?: null,
            'location'         => mb_substr(trim((string) ($attributes['location'] ?? '')), 0, 190) ?: null,
            'rrule'            => (string) $rule,
            'starts_at'        => $starts,
            'duration_minutes' => isset($attributes['duration_minutes']) && $attributes['duration_minutes'] !== ''
                ? max(0, (int) $attributes['duration_minutes'])
                : null,
            'timezone'         => mb_substr(trim((string) ($attributes['timezone'] ?? '')), 0, 64) ?: null,
            'is_published'     => !empty($attributes['is_published']) ? 1 : 0,
            'member_only'      => !empty($attributes['member_only']) ? 1 : 0,
            'signup_enabled'   => !empty($attributes['signup_enabled']) ? 1 : 0,
            'capacity'         => isset($attributes['capacity']) && $attributes['capacity'] !== ''
                ? max(0, (int) $attributes['capacity'])
                : null,
            'max_guests'       => max(0, (int) ($attributes['max_guests'] ?? 0)),
            'generated_to'     => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    /**
     * Make the meetings this series names, up to the horizon.
     *
     * Idempotent, which is what lets a daily job run it without thinking: an
     * event that already exists for a date is LEFT ALONE, not rewritten.
     * Rewriting would undo every edit somebody made to one meeting — a moved
     * time, a raised capacity, a different room — every night.
     *
     * @return int how many were made
     */
    public function generate(int $seriesId, ?string $horizon = null): int
    {
        $series = $this->find($seriesId);

        if ($series === null) {
            return 0;
        }

        $horizon ??= date('Y-m-d', strtotime('+' . self::HORIZON_MONTHS . ' months'));

        try {
            $rule = Recurrence::parse((string) $series['rrule']);
        } catch (HttpException) {
            /*
             * A rule that no longer parses — this build understands less than
             * the one that stored it, or somebody edited the row by hand.
             * Nothing is generated and nothing is destroyed; the meetings
             * already made stand, and the series screen can show the rule for
             * somebody to fix.
             */
            return 0;
        }

        $dates = $rule->dates((string) $series['starts_at'], $horizon);

        if ($dates === []) {
            return 0;
        }

        $excluded = $this->exclusions($seriesId);
        $existing = $this->generatedDates($seriesId);
        $made = 0;

        foreach ($dates as $wall) {
            $day = substr($wall, 0, 10);

            if (isset($excluded[$day]) || isset($existing[$day])) {
                continue;
            }

            $this->makeOne($series, $wall, $day);
            $made++;
        }

        $this->db->execute(
            'UPDATE {event_series} SET generated_to = ?, updated_at = NOW() WHERE id = ?',
            [$horizon, $seriesId]
        );

        return $made;
    }

    /** Every series, brought up to the horizon. The daily job's whole body. */
    public function generateAll(?string $horizon = null): int
    {
        $made = 0;

        foreach ($this->db->column('SELECT id FROM {event_series}') as $id) {
            $made += $this->generate((int) $id, $horizon);
        }

        return $made;
    }

    /**
     * Cancel one meeting for good.
     *
     * THE RULE: the delete and the exclusion are one transaction. A delete that
     * committed without its exclusion is a meeting the next run puts straight
     * back, because the rule still names that date.
     *
     * The date recorded is the SERIES DATE — the one the rule produced — not
     * the event's own start, which somebody may have moved. Recording the moved
     * date would leave the original still unexcluded, and the generator would
     * make a second meeting on it.
     */
    public function cancelDate(int $seriesId, int $eventId): bool
    {
        return $this->db->transaction(function () use ($seriesId, $eventId): bool {
            $event = $this->db->first(
                'SELECT id, series_id, series_date FROM {events} WHERE id = ? AND series_id = ?',
                [$eventId, $seriesId]
            );

            if ($event === null || $event['series_date'] === null) {
                return false;
            }

            $this->db->execute(
                'INSERT IGNORE INTO {event_series_exclusions} (series_id, excluded_on, created_at)
                 VALUES (?, ?, NOW())',
                [$seriesId, (string) $event['series_date']]
            );

            $this->db->execute('DELETE FROM {events} WHERE id = ?', [$eventId]);

            return true;
        });
    }

    /**
     * Put a cancelled date back. The next run makes the meeting again.
     *
     * Not the same as un-deleting: the sign-ups that were on the old row are
     * gone with it, and there is no honest way to bring those back. The screen
     * says so rather than implying a restore.
     */
    public function uncancelDate(int $seriesId, string $day): bool
    {
        return $this->db->execute(
            'DELETE FROM {event_series_exclusions} WHERE series_id = ? AND excluded_on = ?',
            [$seriesId, $day]
        ) > 0;
    }

    /** @return array<string, true> keyed by date, for a cheap lookup */
    public function exclusions(int $seriesId): array
    {
        $out = [];

        foreach ($this->db->column(
            'SELECT excluded_on FROM {event_series_exclusions} WHERE series_id = ?',
            [$seriesId]
        ) as $day) {
            $out[(string) $day] = true;
        }

        return $out;
    }

    /**
     * The meetings this series has already made.
     *
     * Keyed by SERIES DATE rather than by the event's own start, so a meeting
     * somebody moved to another day is still recognised as that date's meeting
     * and not made twice.
     *
     * @return array<string, int>
     */
    public function generatedDates(int $seriesId): array
    {
        $out = [];

        foreach ($this->db->all(
            'SELECT id, series_date FROM {events} WHERE series_id = ? AND series_date IS NOT NULL',
            [$seriesId]
        ) as $row) {
            $out[(string) $row['series_date']] = (int) $row['id'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function events(int $seriesId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT * FROM {events} WHERE series_id = ? ORDER BY starts_at ASC LIMIT '
            . max(1, min(500, $limit)),
            [$seriesId]
        );
    }

    /**
     * Stop a series without touching what it has already made.
     *
     * The foreign key is ON DELETE SET NULL, so every meeting stays exactly
     * where it is and becomes an ordinary event. That is the whole point of the
     * series not being an event: the strongest thing this can mean is "make no
     * more".
     */
    public function delete(int $seriesId): void
    {
        $this->db->execute('DELETE FROM {event_series} WHERE id = ?', [$seriesId]);
    }

    // --------------------------------------------------------- internals

    /** @param array<string, mixed> $series */
    private function makeOne(array $series, string $wall, string $day): void
    {
        $now = date('Y-m-d H:i:s');
        $duration = $series['duration_minutes'] === null ? null : (int) $series['duration_minutes'];

        $this->db->insert('events', [
            'series_id'      => (int) $series['id'],
            'series_date'    => $day,
            'slug'           => $this->uniqueSlug((string) $series['title'] . ' ' . $day),
            'title'          => (string) $series['title'],
            'description'    => $series['description'],
            'location'       => $series['location'],
            'starts_at'      => $wall,
            'ends_at'        => $duration === null
                ? null
                : date('Y-m-d H:i:s', (int) strtotime($wall) + $duration * 60),
            'timezone'       => $series['timezone'],
            'is_published'   => (int) $series['is_published'],
            'member_only'    => (int) $series['member_only'],
            'signup_enabled' => (int) $series['signup_enabled'],
            'capacity'       => $series['capacity'],
            'max_guests'     => (int) $series['max_guests'],
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }

    private function wallClock(string $raw): string
    {
        $stamp = strtotime(str_replace('T', ' ', trim($raw)));

        if ($stamp === false) {
            throw HttpException::badRequest('That is not a date and time this can read.');
        }

        return date('Y-m-d H:i:s', $stamp);
    }

    private function uniqueSlug(string $desired): string
    {
        $base = Str::slug($desired) ?: 'event';
        $slug = $base;
        $suffix = 1;

        while ($this->db->value('SELECT id FROM {events} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
