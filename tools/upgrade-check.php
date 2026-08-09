<?php
/**
 * Prove that an UPGRADE works, not just a fresh install.
 *
 * Everything else in this project applies the migrations to an empty database:
 * the installer does, tools/schema-check.php does, and tools/smoke.php does.
 * That is not the path a live site takes. A live site is `git pull` onto a
 * database with a year of content in it, and the first request afterwards
 * applies whatever is pending — against populated tables.
 *
 * The difference is not cosmetic. An `ALTER TABLE ... ADD COLUMN` that is
 * instant on an empty table has to rewrite a real one; a foreign key added
 * later has to be satisfiable by rows that already exist; and — the reason
 * this tool exists — every one-time backfill in this project is an
 * `INSERT IGNORE ... SELECT` that inserts NOTHING on an empty database and so
 * passes trivially everywhere it has ever been tested.
 *
 * There are three of those, and each of them is load-bearing:
 *
 *   0007  everything already published counts as already announced, so
 *         enabling subscriptions does not email a year of back catalogue
 *   0013  the same for webhooks
 *   0014  scripture_scanned_at starts null so the scanner has work to do
 *
 * If any of them were wrong, every existing check would still be green and the
 * failure would appear on somebody's live site as a thousand emails.
 *
 *   php tools/upgrade-check.php [--through=0012] [--host=…] [--user=…] [--pass=…]
 */

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

use Portal\Db;
use Portal\Migrator;

$options = getopt('', ['host::', 'port::', 'user::', 'pass::', 'db::', 'through::', 'keep']);

$host = $options['host'] ?? '127.0.0.1';
$port = (int) ($options['port'] ?? 3306);
$user = $options['user'] ?? 'root';
$pass = $options['pass'] ?? '';
$name = $options['db'] ?? 'portal_upgrade_check';
$keep = array_key_exists('keep', $options);

/*
 * Which version to pretend the old site is on.
 *
 * Defaults to the last release's schema. release/1.0 was cut from the Phase 3
 * commit, whose newest migration was 0002 — so that is the upgrade a real site
 * is actually facing, and it is the one worth proving by default.
 */
$through = (string) ($options['through'] ?? '0002');

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
        return;
    }

    $failed++;
    echo "  FAIL  {$label}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

echo "Video Portal upgrade check\n\n";
echo "Pretending the old site is on schema {$through}.\n";

// ------------------------------------------------------------ scratch database

try {
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Could not connect: ' . $e->getMessage() . "\n");
    exit(1);
}

$admin->exec("DROP DATABASE IF EXISTS `{$name}`");
$admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$db = new Db("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, '');
Db::setInstance($db);

/**
 * Stage a subset of the migrations somewhere the Migrator can be pointed at.
 *
 * Copied rather than filtered in code, because the Migrator's own splitter and
 * error handling are what needs exercising — a second implementation here
 * would prove that the second implementation works. That mistake was made once
 * already in a test that split migration SQL on semicolons and broke on the
 * first semicolon inside a comment.
 *
 * @return list<string> the versions staged
 */
function stage(string $directory, callable $wanted): array
{
    if (is_dir($directory)) {
        foreach ((array) glob($directory . '/*.sql') as $file) {
            @unlink((string) $file);
        }
    } else {
        mkdir($directory, 0775, true);
    }

    $staged = [];

    foreach ((array) glob(PORTAL_CORE . '/migrations/*.sql') as $file) {
        $file = (string) $file;
        $version = substr(basename($file), 0, 4);

        if (!$wanted($version)) {
            continue;
        }

        copy($file, $directory . '/' . basename($file));
        $staged[] = $version;
    }

    sort($staged);

    return $staged;
}

$scratch = sys_get_temp_dir() . '/portal-upgrade-' . getmypid();

$before = stage($scratch . '/before', static fn (string $v): bool => $v <= $through);
$after = stage($scratch . '/after', static fn (string $v): bool => $v > $through);

echo 'Old schema: ' . count($before) . " migration(s). Pending: " . count($after) . ".\n\n";

if ($after === []) {
    fwrite(STDERR, "Nothing to upgrade — --through is already the newest migration.\n");
    exit(1);
}

$migrator = new Migrator($db);

/*
 * The bookkeeping table the Migrator records plugin runs in.
 *
 * migrateCore() creates its own; migratePlugin() expects 0001_core.sql to have
 * created this one already — which is exactly the file about to be applied.
 * Creating it up front is the price of using the plugin path to stage a subset
 * of the core migrations, and it is preferable to the alternative, which is a
 * second copy of the SQL splitter in this file. That mistake has already been
 * made once in this project, in a test that split migration SQL on semicolons
 * and broke on the first semicolon inside a comment.
 *
 * IF NOT EXISTS, so 0001's own version of this is a no-op rather than a clash.
 */
$db->execute(
    'CREATE TABLE IF NOT EXISTS {plugin_migrations} (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        plugin_slug VARCHAR(64)  NOT NULL,
        version     VARCHAR(64)  NOT NULL,
        applied_at  DATETIME     NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_plugin_version (plugin_slug, version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

// ------------------------------------------------------- the old site's schema

echo "Old schema\n";

try {
    $migrator->migratePlugin('upgrade-before', $scratch . '/before');
    check('The old schema applies', true);
} catch (Throwable $e) {
    check('The old schema applies', false, $e->getMessage());
    exit(1);
}

// ---------------------------------------------------------------- content

/*
 * Realistic content, not one row.
 *
 * The point is that later migrations meet a populated database, and the
 * backfills below can only be checked against a number somebody knows.
 */
$now = date('Y-m-d H:i:s');

$db->insert('categories', [
    'slug' => 'sermons', 'name' => 'Sermons', 'path' => '/1/', 'depth' => 0,
    'created_at' => $now, 'updated_at' => $now,
]);

$videoCount = 12;
$publishedCount = 0;

for ($i = 1; $i <= $videoCount; $i++) {
    // A mix, so the backfills' WHERE clauses are actually exercised rather
    // than every row qualifying.
    $deleted = $i === 11 ? $now : null;
    $published = $i !== 12;

    if ($deleted === null) {
        $publishedCount++;
    }

    $db->insert('videos', [
        'provider'     => 'bunny',
        'provider_id'  => 'upgrade-' . $i,
        'slug'         => 'video-' . $i,
        'title'        => 'Video ' . $i,
        'description'  => 'A sermon on Romans 8:28 and Psalm 23.',
        'status'       => 'ready',
        'is_published' => $published ? 1 : 0,
        'deleted_at'   => $deleted,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
}

$db->insert('users', [
    'email' => 'upgrade@example.com', 'name' => 'Existing Admin',
    'authorized' => 1, 'created_at' => $now, 'updated_at' => $now,
]);

echo "Seeded {$videoCount} video(s), {$publishedCount} of them not deleted.\n\n";

// --------------------------------------------------------------- the upgrade

echo "Upgrade\n";

$applied = [];

try {
    $applied = $migrator->migratePlugin('upgrade-after', $scratch . '/after');
    check('Every pending migration applies to a populated database', true);
} catch (Throwable $e) {
    check('Every pending migration applies to a populated database', false, $e->getMessage());
}

check(
    'All of them ran',
    count($applied) === count($after),
    sprintf('%d of %d applied', count($applied), count($after))
);

check(
    'Nothing was lost on the way',
    (int) $db->value('SELECT COUNT(*) FROM {videos}') === $videoCount,
    'a migration destroyed rows that were already there'
);

// ------------------------------------------------------------ the backfills

echo "\nOne-time backfills\n";

/*
 * These are the checks nothing else in the project can make. On an empty
 * database each of these inserts zero rows and passes; it takes an upgrade
 * with content in it for the number to mean anything.
 */
$expected = fn (string $table): int => (int) $db->value(
    'SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NULL'
);

if (in_array('0007', $after, true)) {
    check(
        'Existing videos count as already announced',
        (int) $db->value('SELECT COUNT(*) FROM {announced_videos}') === $expected('announced_videos'),
        'turning subscriptions on would email the entire back catalogue'
    );
}

if (in_array('0013', $after, true)) {
    check(
        'Existing videos count as already reported to webhooks',
        (int) $db->value('SELECT COUNT(*) FROM {webhook_seen_videos}') === $expected('webhook_seen_videos'),
        'adding an endpoint would fire the entire back catalogue at it'
    );
}

if (in_array('0014', $after, true)) {
    check(
        'No description has been scanned for scripture yet',
        (int) $db->value('SELECT COUNT(*) FROM {videos} WHERE scripture_scanned_at IS NULL') === $videoCount,
        'the scanner would have nothing to do and the back catalogue would never be indexed'
    );
}

/*
 * And the deleted video is NOT in the ledgers. Every backfill filters on
 * deleted_at, and a version that did not would leave rows pointing at content
 * in the trash — which is only visible on a database that HAS a trash.
 */
if (in_array('0013', $after, true)) {
    check(
        'A video in the trash is not backfilled',
        (int) $db->value(
            'SELECT COUNT(*) FROM {webhook_seen_videos} s
               JOIN {videos} v ON v.id = s.video_id
              WHERE v.deleted_at IS NOT NULL'
        ) === 0
    );
}

// ------------------------------------------------------------ added columns

echo "\nColumns added to populated tables\n";

foreach ([
    'videos'  => ['unpublish_at', 'premiere', 'scripture_scanned_at'],
    'series'  => ['sequential'],
] as $table => $columns) {
    foreach ($columns as $column) {
        $exists = (int) $db->value(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        ) === 1;

        check("{$table}.{$column} exists", $exists);
    }
}

/*
 * Existing rows get the default rather than null where the column is NOT NULL.
 * An ALTER that got this wrong would leave a column MySQL filled with zeroes
 * and a site where every series had suddenly become a locked course.
 */
check(
    'Existing series did not become sequential',
    (int) $db->value('SELECT COUNT(*) FROM {series} WHERE sequential <> 0') === 0
);
check(
    'Existing videos did not become premieres',
    (int) $db->value('SELECT COUNT(*) FROM {videos} WHERE premiere <> 0') === 0,
    'the whole back catalogue would list as coming soon and refuse to play'
);

// -------------------------------------------------------------------- tidy up

foreach ([$scratch . '/before', $scratch . '/after'] as $directory) {
    foreach ((array) glob($directory . '/*.sql') as $file) {
        @unlink((string) $file);
    }
    @rmdir($directory);
}
@rmdir($scratch);

if (!$keep) {
    $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "{$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
