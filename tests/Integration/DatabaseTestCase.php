<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Portal\Db;
use Portal\Migrator;

/**
 * Base class for tests that need a real database.
 *
 * Skips itself when PORTAL_TEST_DB is unset, so someone without MySQL still
 * gets a green unit run rather than a wall of errors they cannot act on.
 *
 *   PORTAL_TEST_DB=mysql://root@127.0.0.1:3306/portal_test
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?Db $db = null;
    private static ?PDO $admin = null;
    private static string $database = '';

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('PORTAL_TEST_DB');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('PORTAL_TEST_DB is not set; skipping database tests.');
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            self::markTestSkipped('PORTAL_TEST_DB is not a valid URL.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 3306;
        $user = $parts['user'] ?? 'root';
        $pass = $parts['pass'] ?? '';
        self::$database = ltrim($parts['path'] ?? '/portal_test', '/');

        try {
            self::$admin = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            self::markTestSkipped('Could not connect to the test database: ' . $e->getMessage());
        }

        // A fresh database per class: leftover rows from a previous run are a
        // classic source of tests that pass alone and fail together.
        self::$admin->exec('DROP DATABASE IF EXISTS `' . self::$database . '`');
        self::$admin->exec(
            'CREATE DATABASE `' . self::$database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        self::$db = new Db(
            "mysql:host={$host};port={$port};dbname=" . self::$database . ';charset=utf8mb4',
            $user,
            $pass,
            ''
        );
        Db::setInstance(self::$db);

        (new Migrator(self::$db))->migrateCore();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null && self::$database !== '') {
            self::$admin->exec('DROP DATABASE IF EXISTS `' . self::$database . '`');
        }
        self::$admin = null;
        self::$db = null;
        Db::setInstance(null);
    }

    protected function db(): Db
    {
        if (self::$db === null) {
            self::fail('The test database was not initialised.');
        }
        return self::$db;
    }

    /**
     * Empty the tables a test writes to, without re-running migrations.
     *
     * Foreign key checks are disabled for the truncate because the tables form
     * a cycle-free but order-dependent graph, and getting that order wrong is
     * a maintenance burden with no benefit.
     *
     * @param list<string> $tables
     */
    protected function truncate(array $tables): void
    {
        $this->db()->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->db()->execute("TRUNCATE TABLE {{$table}}");
        }
        $this->db()->execute('SET FOREIGN_KEY_CHECKS = 1');
    }
}
