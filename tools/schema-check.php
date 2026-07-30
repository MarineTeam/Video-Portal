<?php
/**
 * Apply the core migrations to a scratch database and report what landed.
 *
 * This is the cheapest proof that the schema is actually valid — that every
 * foreign key resolves, every index is legal, and the migration splitter
 * handles the file. It creates and drops its own database, so it is safe to
 * run repeatedly and never touches real data.
 *
 *   php tools/schema-check.php [--host=127.0.0.1] [--user=root] [--pass=] [--db=portal_schema_check]
 */

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

use Portal\Db;
use Portal\Migrator;

$options = getopt('', ['host::', 'port::', 'user::', 'pass::', 'db::', 'keep']);

$host = $options['host'] ?? '127.0.0.1';
$port = (int) ($options['port'] ?? 3306);
$user = $options['user'] ?? 'root';
$pass = $options['pass'] ?? '';
$name = $options['db'] ?? 'portal_schema_check';
$keep = array_key_exists('keep', $options);

function out(string $line): void
{
    echo $line, PHP_EOL;
}

try {
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    out('Could not connect to MySQL/MariaDB: ' . $e->getMessage());
    exit(1);
}

out("Connected to " . $admin->query('SELECT VERSION()')->fetchColumn());

// Start from a clean slate so a previous failed run can't mask a problem.
$admin->exec("DROP DATABASE IF EXISTS `{$name}`");
$admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
out("Created scratch database `{$name}`");

$db = new Db("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, '');
Db::setInstance($db);

$exitCode = 0;

try {
    $migrator = new Migrator($db);
    $applied = $migrator->migrateCore();
    out('Applied migrations: ' . ($applied === [] ? '(none)' : implode(', ', $applied)));

    $tables = $db->column('SHOW TABLES');
    sort($tables);
    out(sprintf("\n%d tables created:", count($tables)));
    foreach ($tables as $table) {
        $columns = (int) $db->value(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ?',
            [$name, $table]
        );
        out(sprintf('  %-28s %2d columns', $table, $columns));
    }

    $fks = $db->all(
        'SELECT table_name, constraint_name, referenced_table_name
         FROM information_schema.key_column_usage
         WHERE table_schema = ? AND referenced_table_name IS NOT NULL
         ORDER BY table_name, constraint_name',
        [$name]
    );
    out(sprintf("\n%d foreign keys resolved.", count($fks)));

    $uniques = $db->all(
        'SELECT table_name, index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
         FROM information_schema.statistics
         WHERE table_schema = ? AND non_unique = 0 AND index_name <> "PRIMARY"
         GROUP BY table_name, index_name
         ORDER BY table_name, index_name',
        [$name]
    );
    out(sprintf("\n%d unique constraints:", count($uniques)));
    foreach ($uniques as $u) {
        out(sprintf('  %-24s %-24s (%s)', $u['table_name'], $u['index_name'], $u['cols']));
    }

    // Re-running must be a no-op. If it isn't, an upgrade would re-apply
    // migrations on every request.
    $second = $migrator->migrateCore();
    if ($second !== []) {
        out("\nFAIL: re-running the migrator applied " . implode(', ', $second) . ' again.');
        $exitCode = 1;
    } else {
        out("\nRe-run applied nothing (idempotent).");
    }
} catch (Throwable $e) {
    out("\nFAILED: " . $e->getMessage());
    $exitCode = 1;
} finally {
    if (!$keep) {
        $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
        out("Dropped scratch database.");
    } else {
        out("Kept scratch database `{$name}` (--keep).");
    }
}

exit($exitCode);
