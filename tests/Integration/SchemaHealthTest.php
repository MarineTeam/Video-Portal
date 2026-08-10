<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Migrator;

/**
 * Knowing whether the database is the shape the code expects.
 *
 * The upgrade path for this product is "git pull, and the next request
 * migrates". That works until it does not, and until this existed a migration
 * that failed was caught, written to the error log, and invisible everywhere
 * else — on a host with no shell, which is the one place nobody can read.
 *
 * The site then went on serving a half-applied schema. That does not look
 * broken. It looks like features that mysteriously do not work.
 */
final class SchemaHealthTest extends DatabaseTestCase
{
    public function testAFullyMigratedDatabaseHasNothingPending(): void
    {
        $status = (new Migrator($this->db()))->coreStatus();

        self::assertSame([], $status['pending']);
        self::assertNotSame([], $status['expected'], 'no migrations were found at all');
        self::assertSame(
            count($status['expected']),
            count(array_intersect($status['expected'], $status['applied'])),
            'the test database is not fully migrated'
        );
    }

    /**
     * The state a half-applied upgrade leaves behind, and the one the banner
     * exists for.
     */
    public function testAMissingMigrationIsReportedAsPending(): void
    {
        $status = (new Migrator($this->db()))->coreStatus();
        $newest = end($status['expected']);

        $this->db()->execute('DELETE FROM {schema_version} WHERE version = ?', [$newest]);

        try {
            $after = (new Migrator($this->db()))->coreStatus();

            self::assertSame([$newest], $after['pending']);
            self::assertNotContains($newest, $after['applied']);
        } finally {
            // Put it back, or every later test in the run is looking at a
            // database this one broke.
            $this->db()->execute(
                'INSERT IGNORE INTO {schema_version} (version, applied_at) VALUES (?, NOW())',
                [$newest]
            );
        }
    }

    /**
     * Not being able to tell is reported as a problem rather than as fine.
     *
     * A brand-new database has no schema_version table at all, and answering
     * "nothing pending" there would be the most dangerous possible answer.
     */
    public function testEverythingIsPendingWhenNothingHasBeenApplied(): void
    {
        $applied = $this->db()->all('SELECT version, applied_at FROM {schema_version}');

        $this->db()->execute('DELETE FROM {schema_version}');

        try {
            $status = (new Migrator($this->db()))->coreStatus();

            self::assertSame([], $status['applied']);
            self::assertSame(
                $status['expected'],
                $status['pending'],
                'a database with nothing applied must report everything as pending'
            );
        } finally {
            foreach ($applied as $row) {
                $this->db()->execute(
                    'INSERT IGNORE INTO {schema_version} (version, applied_at) VALUES (?, ?)',
                    [(string) $row['version'], (string) $row['applied_at']]
                );
            }
        }
    }
}
