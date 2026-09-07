<?php

declare(strict_types=1);

namespace Portal\Reader;

use Portal\Db;
use Portal\Http\HttpException;

/**
 * What a song is, and what a licence return may say about it.
 *
 * # A RETURN COUNTS USES, NEVER LOOKUPS
 *
 * {hymn_lookups} counts somebody opening a hymn on their phone. That is a
 * useful signal about what a congregation reaches for and it is NOT evidence
 * that anything was sung — a person checking a first line, a leader deciding
 * against it, a child pressing buttons all land in it.
 *
 * A CCLI return is a legal document with money attached, and one that
 * overstates is worse than one that is late. So the report reads {song_uses}
 * and nothing else, and says on its face what its evidence is.
 *
 * # AND IT NAMES WHAT IT CANNOT INCLUDE
 *
 * A song with no CCLI number cannot go on a return at all. Leaving those out
 * quietly would produce a return that looks complete and is not; they are
 * listed separately so somebody can go and find the numbers.
 */
final class SongRepository
{
    public const FROM_PLAN    = 'plan';
    public const FROM_PRESENT = 'present';
    public const MANUAL       = 'manual';

    public function __construct(private readonly Db $db)
    {
    }

    // --------------------------------------------------------- the metadata

    /** @return array<string, mixed>|null */
    public function song(int $bookId, int $number): ?array
    {
        return $this->db->first(
            'SELECT * FROM {book_songs} WHERE book_id = ? AND number = ?',
            [$bookId, $number]
        );
    }

    /**
     * Every song in a book that anybody has recorded anything about.
     *
     * @return array<int, array<string, mixed>> keyed by number
     */
    public function songsIn(int $bookId): array
    {
        $out = [];

        foreach ($this->db->all('SELECT * FROM {book_songs} WHERE book_id = ?', [$bookId]) as $row) {
            $out[(int) $row['number']] = $row;
        }

        return $out;
    }

    /** @param array<string, mixed> $fields */
    public function saveSong(int $bookId, int $number, array $fields): void
    {
        if ($number <= 0) {
            throw HttpException::badRequest('A song needs a number.');
        }

        $now = date('Y-m-d H:i:s');

        $values = [];
        foreach (['author' => 300, 'copyright' => 300, 'ccli_number' => 20, 'song_key' => 12, 'tempo' => 40] as $field => $limit) {
            $values[$field] = mb_substr(trim((string) ($fields[$field] ?? '')), 0, $limit) ?: null;
        }

        $this->db->execute(
            'INSERT INTO {book_songs}
                (book_id, number, author, copyright, ccli_number, song_key, tempo, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE author = VALUES(author), copyright = VALUES(copyright),
                ccli_number = VALUES(ccli_number), song_key = VALUES(song_key),
                tempo = VALUES(tempo), updated_at = VALUES(updated_at)',
            [
                $bookId,
                $number,
                $values['author'],
                $values['copyright'],
                $values['ccli_number'],
                $values['song_key'],
                $values['tempo'],
                $now,
                $now,
            ]
        );
    }

    // ------------------------------------------------------------- the uses

    /**
     * Record a song as sung.
     *
     * INSERT IGNORE against the unique key, so a plan saved three times is one
     * use — the return has to be a count of Sundays, not of button presses.
     *
     * @return bool whether this created a new use
     */
    public function recordUse(
        int $bookId,
        int $number,
        string $onDate,
        ?int $serviceId = null,
        string $source = self::MANUAL
    ): bool {
        if ($number <= 0) {
            return false;
        }

        $stamp = strtotime($onDate);

        if ($stamp === false) {
            throw HttpException::badRequest('That is not a date this can read.');
        }

        return $this->db->execute(
            'INSERT IGNORE INTO {song_uses}
                (book_id, number, on_date, service_id, source, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $bookId,
                $number,
                date('Y-m-d', $stamp),
                $serviceId !== null && $serviceId > 0 ? $serviceId : null,
                in_array($source, [self::FROM_PLAN, self::FROM_PRESENT, self::MANUAL], true)
                    ? $source
                    : self::MANUAL,
            ]
        ) > 0;
    }

    public function forgetUse(int $id): void
    {
        $this->db->execute('DELETE FROM {song_uses} WHERE id = ?', [$id]);
    }

    /**
     * Record everything a service plan says was sung.
     *
     * ONLY LINKED ITEMS. The `reference` column is free text — "245",
     * "H&M 245", "insert" — and parsing it would be guessing. A guess on a
     * document somebody signs is worse than a gap, so an unlinked hymn is
     * counted by nothing and shows on the screen as needing a link.
     *
     * @return array{recorded: int, unlinked: int}
     */
    public function recordFromService(int $serviceId): array
    {
        $service = $this->db->first(
            'SELECT id, starts_at FROM {rota_services} WHERE id = ?',
            [$serviceId]
        );

        if ($service === null) {
            return ['recorded' => 0, 'unlinked' => 0];
        }

        $onDate = date('Y-m-d', (int) strtotime((string) $service['starts_at']));
        $recorded = 0;
        $unlinked = 0;

        foreach (
            $this->db->all(
                'SELECT * FROM {service_plan_items} WHERE service_id = ? AND kind = ?',
                [$serviceId, 'hymn']
            ) as $item
        ) {
            $bookId = (int) ($item['book_id'] ?? 0);
            $number = (int) ($item['song_number'] ?? 0);

            if ($bookId <= 0 || $number <= 0) {
                $unlinked++;

                continue;
            }

            if ($this->recordUse($bookId, $number, $onDate, $serviceId, self::FROM_PLAN)) {
                $recorded++;
            }
        }

        return ['recorded' => $recorded, 'unlinked' => $unlinked];
    }

    // ----------------------------------------------------------- the return

    /**
     * What we sang, between two dates.
     *
     * Reads {song_uses} and joins the metadata. It does NOT touch
     * {hymn_lookups}, and there is a test that proves it: adding lookups must
     * not move a single number on this report.
     *
     * @return array{
     *     from: string, to: string,
     *     songs: list<array<string, mixed>>,
     *     total: int, withCcli: int, withoutCcli: int
     * }
     */
    public function report(string $from, string $to): array
    {
        $from = date('Y-m-d', (int) strtotime($from));
        $to = date('Y-m-d', (int) strtotime($to));

        $songs = $this->db->all(
            'SELECT u.book_id, u.number, COUNT(*) AS times,
                    MIN(u.on_date) AS first_used, MAX(u.on_date) AS last_used,
                    b.title AS book_title,
                    c.title AS song_title,
                    s.author, s.copyright, s.ccli_number, s.song_key, s.tempo
               FROM {song_uses} u
               INNER JOIN {books} b ON b.id = u.book_id
               LEFT JOIN {book_contents} c ON c.book_id = u.book_id AND c.number = u.number
               LEFT JOIN {book_songs} s ON s.book_id = u.book_id AND s.number = u.number
              WHERE u.on_date BETWEEN ? AND ?
              GROUP BY u.book_id, u.number, b.title, c.title,
                       s.author, s.copyright, s.ccli_number, s.song_key, s.tempo
              ORDER BY times DESC, u.number',
            [$from, $to]
        );

        $withCcli = 0;

        foreach ($songs as $song) {
            if (trim((string) ($song['ccli_number'] ?? '')) !== '') {
                $withCcli++;
            }
        }

        return [
            'from'        => $from,
            'to'          => $to,
            'songs'       => $songs,
            'total'       => count($songs),
            'withCcli'    => $withCcli,
            /*
             * Counted and shown, never dropped. A return that quietly omits the
             * songs it has no number for looks complete and is not.
             */
            'withoutCcli' => count($songs) - $withCcli,
        ];
    }

    /**
     * The uses behind one song, so a number on a return can be explained.
     *
     * @return list<array<string, mixed>>
     */
    public function usesOf(int $bookId, int $number, string $from, string $to): array
    {
        return $this->db->all(
            'SELECT * FROM {song_uses}
              WHERE book_id = ? AND number = ? AND on_date BETWEEN ? AND ?
              ORDER BY on_date',
            [$bookId, $number, date('Y-m-d', (int) strtotime($from)), date('Y-m-d', (int) strtotime($to))]
        );
    }
}
