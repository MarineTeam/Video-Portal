<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Account\PersonalData;
use Portal\Auth\User;
use Portal\Support\SecretGuard;

/**
 * The completeness rule, enforced against the SCHEMA rather than a list.
 *
 * "If a row is keyed to them it is in the file, because a partial export looks
 * complete and is therefore worse than none."
 *
 * A test that checked a hand-written list of sections would pass for ever while
 * the export quietly went stale — which is exactly what happened: it was
 * written against eight tables and then seven sections of work added more,
 * none of which it knew about. So this one asks the DATABASE which tables are
 * keyed to a person, and fails when one of them is neither exported nor
 * explicitly excused below.
 *
 * The next section to add a user-keyed table fails this test. That is the point.
 */
final class PersonalDataCompletenessTest extends DatabaseTestCase
{
    /**
     * Tables keyed to a person that are deliberately NOT in the export, each
     * with the reason it would be wrong to include.
     *
     * Every entry here is a decision. An empty list would be ideal and is not
     * achievable — some rows keyed to somebody are live secrets, and an export
     * is a file that gets emailed to a laptop and forwarded to a solicitor.
     *
     * @var array<string, string>
     */
    private const EXCUSED = [
        // LIVE SECRETS. Exporting one hands over a working credential, and
        // SecretGuard would throw on it anyway.
        'sessions'            => 'a live session is a working credential',
        'calendar_feeds'      => 'the token IS the authentication; metadata is exported instead',
        'push_subscriptions'  => 'the keys and endpoint are a working capability to push',

        // Machinery, not a record about the person.
        'rate_limits'         => 'hashed buckets with no personal content',
        'reading_positions'   => 'exported as `reading` — keyed by book, not a row per person',

        /*
         * SOMEBODY ELSE'S DECISION, NOT THE MEMBER'S DATA. A broadcast
         * recipient row says the church sent something; what the member was
         * told is already in `notifications`, which is the record addressed to
         * them.
         */
        'broadcast_recipients' => 'the delivery attempt is the church\'s record; notifications holds theirs',
    ];

    private PersonalData $data;

    protected function setUp(): void
    {
        $this->data = new PersonalData($this->db());
    }

    /**
     * Every table with a user_id column is either exported or excused.
     *
     * @return list<string>
     */
    private function userKeyedTables(): array
    {
        $prefix = $this->db()->prefix();

        $rows = $this->db()->all(
            'SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND COLUMN_NAME = ?
                AND TABLE_NAME LIKE ?
              ORDER BY TABLE_NAME',
            ['user_id', $prefix . '%']
        );

        return array_map(
            static fn (array $row): string => $row['TABLE_NAME'],
            $rows
        );
    }

    public function testEveryTableKeyedToAPersonIsExportedOrExcused(): void
    {
        $prefix = $this->db()->prefix();
        $export = $this->data->export($this->someone());

        /*
         * Matched by table name appearing as a section key, or by the section
         * being named in the export's own map below. A name-based check is
         * loose on purpose: the alternative is a second hand-written list, and
         * two lists drift.
         */
        $covered = $this->coveredTables();
        $unaccounted = [];

        foreach ($this->userKeyedTables() as $table) {
            $bare = substr($table, strlen($prefix));

            if (isset(self::EXCUSED[$bare]) || in_array($bare, $covered, true)) {
                continue;
            }

            $unaccounted[] = $bare;
        }

        self::assertSame(
            [],
            $unaccounted,
            "These tables are keyed to a person and do not reach the export:\n  "
            . implode("\n  ", $unaccounted)
            . "\n\nA partial export looks complete, which makes it worse than none. Either export "
            . 'them in PersonalData::export() or add them to EXCUSED here with the reason.'
        );

        self::assertNotSame([], $export, 'the export produced nothing at all');
    }

    /**
     * Which tables the export actually reads.
     *
     * Read out of the source rather than declared, so it cannot disagree with
     * what the code does — a hand-kept list is the thing this whole test exists
     * to stop relying on.
     *
     * @return list<string>
     */
    private function coveredTables(): array
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/Account/PersonalData.php'
        );

        preg_match_all('/\{([a-z_]+)\}/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Every query in the export actually runs.
     *
     * rows() swallows a failure so that one bad section cannot 500 the whole
     * download — which is right, and which also means a mistyped column name
     * produces a section that is silently EMPTY. An empty rota and a rota query
     * naming a column that does not exist look identical in the file, and "a
     * partial export looks complete, so it is worse than none" is the rule this
     * feature is built on.
     *
     * Four columns were in fact wrong when the church-life sections were added,
     * and every one came back as an empty list. This is the check that would
     * have said so.
     */
    public function testNoSectionOfTheExportSilentlyFailsToRun(): void
    {
        $this->data->export($this->someone());

        self::assertSame(
            [],
            $this->data->failures(),
            "Some queries in the export did not run, so those sections are empty rather than true:\n  "
            . implode("\n  ", $this->data->failures())
        );
    }

    /** An export carries no live secret. The guard decides, not a reviewer. */
    public function testTheExportCarriesNoLiveSecret(): void
    {
        self::assertTrue(
            SecretGuard::isClean($this->data->export($this->someone())),
            'A LIVE SECRET REACHED AN EXPORT — this file gets emailed onwards'
        );
    }

    private function someone(): User
    {
        $now = date('Y-m-d H:i:s');
        $email = 'export-' . bin2hex(random_bytes(4)) . '@example.test';

        $id = (int) $this->db()->insert('users', [
            'email'      => $email,
            'name'       => 'A member',
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new User(
            id: $id,
            email: $email,
            name: 'A member',
            roleSlug: 'viewer',
            authorized: true,
            emailVerified: true,
        );
    }
}
