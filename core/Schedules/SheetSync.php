<?php

declare(strict_types=1);

namespace Portal\Schedules;

use Portal\Db;
use Portal\Support\Http;

/**
 * Pulling a rota out of a spreadsheet.
 *
 * # A FAILED SYNC DELETES NOTHING
 *
 * The one rule this class exists for. Every failure — the host cannot reach
 * Google, the sheet stopped being shared, somebody renamed the tab, the export
 * came back as a sign-in page — returns before a single write. Only a fetch
 * that produced rows this could read gets as far as changing anything, and even
 * then the tidying pass is bounded by the span the sheet covers.
 *
 * The reason is that this runs unattended. A sync that deleted on failure would
 * empty a rota at three in the morning because somebody's Google account was
 * being upgraded, and the first sign of it would be an empty page on Sunday.
 *
 * # AN UNCHANGED SHEET COSTS ONE REQUEST
 *
 * One, not zero: this cannot know a sheet is unchanged without asking. The ETag
 * and Last-Modified from the last run go back as conditional headers, so an
 * unchanged sheet is usually a 304 with no body; when the far end offers
 * neither, the body is hashed and compared. Either way nothing is parsed and
 * nothing is written, which is what makes a quarter-hourly job affordable on
 * shared hosting.
 *
 * # THE PREVIEW AND THE SYNC ARE THE SAME CODE
 *
 * plan() decides what would change and apply() carries it out. A preview
 * written separately is a preview of something else, and the day it disagrees
 * is the day somebody approves one thing and gets another.
 */
final class SheetSync
{
    public const OK = 'ok';
    public const UNCHANGED = 'unchanged';
    public const FAILED = 'failed';

    /** Long enough for Google to build a CSV, short enough for a page render. */
    private const TIMEOUT = 20;

    public function __construct(
        private readonly Db $db,
        private readonly ScheduleRepository $schedules
    ) {
    }

    // ------------------------------------------------------------- fetching

    /**
     * Ask the sheet for its contents.
     *
     * @param array<string, mixed> $source
     * @return array{status: string, message: string, csv: string, etag: ?string,
     *               modified: ?string, hash: ?string}
     */
    public function fetch(array $source, bool $conditional = true): array
    {
        $headers = [];

        if ($conditional) {
            if (!empty($source['etag'])) {
                $headers['If-None-Match'] = (string) $source['etag'];
            }

            if (!empty($source['last_modified'])) {
                $headers['If-Modified-Since'] = (string) $source['last_modified'];
            }
        }

        /*
         * Redirects are followed here where the rest of the application does
         * not follow them: the export URL is a 307 to googleusercontent by
         * design, and no credential is sent, so the usual reason for refusing —
         * leaking an Authorization header to another host — does not apply.
         */
        $response = Http::get((string) $source['url'], $headers, [
            'timeout' => self::TIMEOUT,
            'follow'  => true,
        ]);

        if ($response->transportFailed()) {
            return self::failure(
                'This site could not reach docs.google.com: ' . $response->errorMessage()
            );
        }

        if ($response->status === 304) {
            return [
                'status'   => self::UNCHANGED,
                'message'  => 'The sheet has not changed since the last run.',
                'csv'      => '',
                'etag'     => null,
                'modified' => null,
                'hash'     => null,
            ];
        }

        $problem = self::explain($response->status, $response->header('content-type'), $response->body);

        if ($problem !== null) {
            return self::failure($problem);
        }

        $hash = hash('sha256', $response->body);

        if ($conditional && $hash === ($source['content_hash'] ?? null)) {
            return [
                'status'   => self::UNCHANGED,
                'message'  => 'The sheet has not changed since the last run.',
                'csv'      => '',
                'etag'     => $response->header('etag'),
                'modified' => $response->header('last-modified'),
                'hash'     => $hash,
            ];
        }

        return [
            'status'   => self::OK,
            'message'  => 'Read the sheet.',
            'csv'      => $response->body,
            'etag'     => $response->header('etag'),
            'modified' => $response->header('last-modified'),
            'hash'     => $hash,
        ];
    }

    /**
     * What went wrong, in words that name the fix.
     *
     * The HTML case is the one that matters and the one that would otherwise
     * be invisible: an unshared sheet does not answer 403, it answers 200 with
     * Google's sign-in page. Taken as CSV that parses as a sheet with no
     * heading row, and the screen would say "no heading row was found" to
     * somebody whose sheet has perfectly good headings.
     */
    public static function explain(int $status, ?string $contentType, string $body): ?string
    {
        if ($status === 404) {
            return 'There is no sheet at that address.';
        }

        if ($status === 401 || $status === 403) {
            return self::notShared();
        }

        if ($status < 200 || $status >= 300) {
            return sprintf('docs.google.com answered HTTP %d.', $status);
        }

        $looksLikeHtml = ($contentType !== null && str_contains(strtolower($contentType), 'text/html'))
            || preg_match('/^\s*<(?:!doctype|html)/i', $body) === 1;

        if ($looksLikeHtml) {
            return self::notShared();
        }

        if (trim($body) === '') {
            return 'The sheet came back empty.';
        }

        return null;
    }

    private static function notShared(): string
    {
        // Named as steps, because "403" sends somebody to their hosting
        // control panel and the answer is three clicks inside Google.
        return 'The sheet is not readable without signing in. In Google Sheets: Share → '
            . 'General access → Anyone with the link → Viewer.';
    }

    /**
     * @return array{status: string, message: string, csv: string, etag: ?string,
     *               modified: ?string, hash: ?string}
     */
    private static function failure(string $message): array
    {
        return [
            'status'   => self::FAILED,
            'message'  => $message,
            'csv'      => '',
            'etag'     => null,
            'modified' => null,
            'hash'     => null,
        ];
    }

    // --------------------------------------------------------- the planning

    /**
     * What a sync would do, without doing any of it.
     *
     * @param array<string, mixed> $source
     * @param list<array{date: string, name: string, role: string, note: string}> $rows
     * @return array{
     *     add: list<array{date: string, name: string, role: string, note: string}>,
     *     keep: int,
     *     remove: list<int>,
     *     newPeople: list<string>,
     *     from: ?string, to: ?string
     * }
     */
    public function plan(array $source, array $rows): array
    {
        $empty = ['add' => [], 'keep' => 0, 'remove' => [], 'newPeople' => [], 'from' => null, 'to' => null];

        if ($rows === []) {
            return $empty;
        }

        $dates = array_column($rows, 'date');
        $from = min($dates);
        $to = max($dates);

        $scheduleId = (int) $source['schedule_id'];

        /*
         * The tidying pass is bounded by the span THE SHEET COVERS. A sheet
         * holding September to December must not sweep away January, which
         * another sheet or somebody's hands put there — "not in this document"
         * and "no longer happening" are different claims, and the sync only
         * knows the first.
         */
        $existing = $this->schedules->sheetEntriesBetween($scheduleId, $from, $to);

        $wanted = [];
        $newPeople = [];
        $seenNames = [];

        foreach ($rows as $row) {
            $person = $this->schedules->findPerson($row['name']);

            if ($person === null) {
                $key = PersonKey::for($row['name']);

                if ($key !== '' && !isset($seenNames[$key])) {
                    $seenNames[$key] = true;
                    $newPeople[] = $row['name'];
                }

                // A person who does not exist yet cannot match an existing
                // row, so this is always an addition.
                $wanted['new:' . count($wanted)] = $row;

                continue;
            }

            $wanted[self::slot($row['date'], $person, $row['role'])] = $row;
        }

        $keep = 0;
        $remove = [];

        foreach ($existing as $entry) {
            $slot = self::slot(
                (string) $entry['on_date'],
                (int) $entry['person_id'],
                (string) ($entry['role'] ?? '')
            );

            if (isset($wanted[$slot])) {
                $keep++;
                unset($wanted[$slot]);

                continue;
            }

            $remove[] = (int) $entry['id'];
        }

        return [
            'add'       => array_values($wanted),
            'keep'      => $keep,
            'remove'    => $remove,
            'newPeople' => $newPeople,
            'from'      => $from,
            'to'        => $to,
        ];
    }

    /**
     * Read the sheet and say what would happen. WRITES NOTHING.
     *
     * @param array<string, mixed> $source
     * @return array{status: string, message: string, problems: list<string>,
     *               rows: list<array{date: string, name: string, role: string, note: string}>,
     *               plan: array<string, mixed>}
     */
    public function preview(array $source): array
    {
        // Unconditional: somebody pressing Preview wants to see the sheet, and
        // "it has not changed" is not an answer to that question.
        $fetched = $this->fetch($source, false);

        if ($fetched['status'] !== self::OK) {
            return [
                'status'   => $fetched['status'],
                'message'  => $fetched['message'],
                'problems' => [],
                'rows'     => [],
                'plan'     => $this->plan($source, []),
            ];
        }

        $read = SheetLayout::read(
            $fetched['csv'],
            (string) $source['layout'],
            (string) $source['date_order']
        );

        return [
            'status'   => self::OK,
            'message'  => $fetched['message'],
            'problems' => $read['problems'],
            'rows'     => $read['rows'],
            'plan'     => $this->plan($source, $read['rows']),
        ];
    }

    // ----------------------------------------------------------- the doing

    /**
     * Fetch, read, and write.
     *
     * @param array<string, mixed> $source
     * @return array{status: string, message: string}
     */
    public function run(array $source): array
    {
        return $this->runWith($source, $this->fetch($source));
    }

    /**
     * Everything after the fetch, given what the fetch said.
     *
     * Split out so the rule this class exists for can be TESTED rather than
     * merely asserted in a comment: a failed fetch is a condition a test cannot
     * stage through fetch() without a real request to somebody else's server,
     * and this project has been caught before by a guard whose whole purpose
     * was a state the suite never reached. fetch() remains the only thing here
     * that touches the network.
     *
     * @param array<string, mixed> $source
     * @param array{status: string, message: string, csv: string, etag: ?string,
     *              modified: ?string, hash: ?string} $fetched
     * @return array{status: string, message: string}
     */
    public function runWith(array $source, array $fetched): array
    {
        $sourceId = (int) $source['id'];

        if ($fetched['status'] === self::FAILED) {
            // Recorded, and NOTHING ELSE HAPPENS. The validators are left
            // alone so the next run is still cheap if the sheet is fine.
            $this->schedules->recordRun($sourceId, self::FAILED, $fetched['message']);

            return ['status' => self::FAILED, 'message' => $fetched['message']];
        }

        if ($fetched['status'] === self::UNCHANGED) {
            $this->schedules->recordRun(
                $sourceId,
                self::UNCHANGED,
                $fetched['message'],
                (int) ($source['last_rows'] ?? 0),
                $fetched['etag'],
                $fetched['modified'],
                $fetched['hash']
            );

            return ['status' => self::UNCHANGED, 'message' => $fetched['message']];
        }

        $read = SheetLayout::read(
            $fetched['csv'],
            (string) $source['layout'],
            (string) $source['date_order']
        );

        /*
         * A sheet that reads as nothing changes nothing, and says so.
         *
         * The tempting reading — "the sheet is empty, so the rota is empty" —
         * is wrong far more often than it is right: the usual causes are a
         * renamed tab, a sharing change, and a sign-in page served as CSV. Each
         * is recoverable in seconds; a year of rota deleted at three in the
         * morning is not.
         */
        if ($read['rows'] === []) {
            $message = 'The sheet had no rows this could read, so nothing was changed. '
                . ($read['problems'][0] ?? '');

            $this->schedules->recordRun(
                $sourceId,
                self::OK,
                $message,
                0,
                $fetched['etag'],
                $fetched['modified'],
                $fetched['hash']
            );

            return ['status' => self::OK, 'message' => trim($message)];
        }

        $written = $this->apply($source, $read['rows']);

        $message = sprintf(
            '%d row(s) read for %s to %s: %d added, %d unchanged, %d no longer on the sheet.',
            count($read['rows']),
            (string) $written['from'],
            (string) $written['to'],
            $written['added'],
            $written['kept'],
            $written['removed']
        );

        if ($read['problems'] !== []) {
            // Said in the same breath as the success, because a sync that
            // worked for 90% of a sheet and silently dropped the rest is the
            // one nobody investigates.
            $message .= sprintf(' %d row(s) could not be read.', count($read['problems']));
        }

        $this->schedules->recordRun(
            $sourceId,
            self::OK,
            $message,
            count($read['rows']),
            $fetched['etag'],
            $fetched['modified'],
            $fetched['hash']
        );

        return ['status' => self::OK, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<array{date: string, name: string, role: string, note: string}> $rows
     * @return array{added: int, kept: int, removed: int, from: string, to: string}
     */
    public function apply(array $source, array $rows): array
    {
        $scheduleId = (int) $source['schedule_id'];

        return $this->db->transaction(function () use ($source, $rows, $scheduleId): array {
            // Re-planned inside the transaction, so what is deleted is decided
            // against the same rows that are about to be written rather than
            // against a snapshot somebody took before pressing a button.
            $plan = $this->plan($source, $rows);

            foreach ($plan['add'] as $row) {
                $this->schedules->put(
                    $scheduleId,
                    $this->schedules->personFor($row['name']),
                    $row['date'],
                    $row['role'],
                    $row['note'],
                    'sheet'
                );
            }

            $removed = $this->schedules->removeEntries($plan['remove']);

            return [
                'added'   => count($plan['add']),
                'kept'    => $plan['keep'],
                'removed' => $removed,
                'from'    => (string) $plan['from'],
                'to'      => (string) $plan['to'],
            ];
        });
    }

    /**
     * How a date, a person and a job identify one slot.
     *
     * Through ScheduleRepository::role() so this and the write agree about
     * "Coffee " — two normalisations would make the sync delete and re-add the
     * same row every quarter of an hour, for ever.
     */
    private static function slot(string $date, int $personId, string $role): string
    {
        return $date . '|' . $personId . '|' . (ScheduleRepository::role($role) ?? '');
    }
}
