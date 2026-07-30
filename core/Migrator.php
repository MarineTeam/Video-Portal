<?php

declare(strict_types=1);

namespace Portal;

use RuntimeException;
use Throwable;

/**
 * Schema versioning for core and for plugins.
 *
 * Migrations are plain .sql files named `NNNN_description.sql`, applied in
 * filename order, each recorded once. No down-migrations: on shared hosting a
 * failed rollback is far more destructive than a forward fix, and the recovery
 * path people actually have is a database backup.
 *
 * Statements are split on semicolons at end-of-line. That is a deliberately
 * simple splitter, which is why the migration files avoid stored routines and
 * never put a semicolon inside a string literal.
 */
final class Migrator
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Apply every core migration that hasn't run yet.
     *
     * @return list<string> versions applied during this call
     */
    public function migrateCore(): array
    {
        $this->ensureVersionTable();

        $applied = $this->appliedCoreVersions();
        $done = [];

        foreach ($this->migrationFiles(PORTAL_CORE . '/migrations') as $version => $path) {
            if (in_array($version, $applied, true)) {
                continue;
            }
            $this->applyFile($path, "core migration {$version}");
            $this->db->execute(
                'INSERT INTO {schema_version} (version, applied_at) VALUES (?, NOW())',
                [$version]
            );
            $done[] = $version;
        }

        return $done;
    }

    /**
     * Apply a plugin's migrations. Called on activation and on version bump.
     *
     * @return list<string>
     */
    public function migratePlugin(string $slug, string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $applied = $this->db->column(
            'SELECT version FROM {plugin_migrations} WHERE plugin_slug = ?',
            [$slug]
        );
        $applied = array_map('strval', $applied);
        $done = [];

        foreach ($this->migrationFiles($directory) as $version => $path) {
            if (in_array($version, $applied, true)) {
                continue;
            }
            $this->applyFile($path, "plugin {$slug} migration {$version}");
            $this->db->execute(
                'INSERT INTO {plugin_migrations} (plugin_slug, version, applied_at) VALUES (?, ?, NOW())',
                [$slug, $version]
            );
            $done[] = $version;
        }

        return $done;
    }

    /**
     * Forget a plugin's migration history so a reinstall re-runs it.
     * Called from uninstall(), after the plugin has dropped its own tables.
     */
    public function forgetPlugin(string $slug): void
    {
        $this->db->execute('DELETE FROM {plugin_migrations} WHERE plugin_slug = ?', [$slug]);
    }

    public function coreNeedsMigration(): bool
    {
        try {
            if (!$this->db->tableExists('schema_version')) {
                return true;
            }
            $applied = $this->appliedCoreVersions();
            foreach (array_keys($this->migrationFiles(PORTAL_CORE . '/migrations')) as $version) {
                if (!in_array($version, $applied, true)) {
                    return true;
                }
            }
            return false;
        } catch (Throwable) {
            // If we can't tell, assume yes — the migrate call is idempotent.
            return true;
        }
    }

    /** @return list<string> */
    private function appliedCoreVersions(): array
    {
        return array_map('strval', $this->db->column('SELECT version FROM {schema_version}'));
    }

    /**
     * The version-table bootstrap is the one statement that cannot live in a
     * migration file, because we need it to know which files have run.
     */
    private function ensureVersionTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS {schema_version} (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                version    VARCHAR(64)  NOT NULL,
                applied_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_version (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<string, string> version => absolute path, sorted by version
     */
    private function migrationFiles(string $directory): array
    {
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        $map = [];

        foreach ($files as $path) {
            $name = basename($path, '.sql');
            // "0001_core" -> version "0001". Everything after the first
            // underscore is a human label and is not part of identity, so a
            // file can be renamed for clarity without re-running.
            $version = str_contains($name, '_') ? explode('_', $name, 2)[0] : $name;
            $map[$version] = $path;
        }

        ksort($map, SORT_STRING);
        return $map;
    }

    private function applyFile(string $path, string $label): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Could not read {$label} at {$path}.");
        }

        foreach ($this->splitStatements($sql) as $index => $statement) {
            try {
                // Not wrapped in a transaction: MySQL commits implicitly on
                // every DDL statement, so a transaction here would give a
                // false sense of atomicity rather than actual rollback.
                $this->db->execute($statement);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf(
                        "%s failed on statement %d: %s\n\n%s",
                        ucfirst($label),
                        $index + 1,
                        $e->getMessage(),
                        $this->firstLine($statement)
                    ),
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Split a migration file into statements.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $statements = [];
        $current = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip standalone comments so they never become an empty statement.
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $current .= $line . "\n";

            if (str_ends_with($trimmed, ';')) {
                $statement = trim($current);
                if ($statement !== '' && $statement !== ';') {
                    $statements[] = rtrim($statement, ';');
                }
                $current = '';
            }
        }

        // Tolerate a final statement with no trailing semicolon.
        $tail = trim($current);
        if ($tail !== '') {
            $statements[] = rtrim($tail, ';');
        }

        return $statements;
    }

    /** First line of a statement, for a failure message that fits on screen. */
    private function firstLine(string $text, int $max = 200): string
    {
        $line = strtok(trim($text), "\n");
        $line = $line === false ? '' : $line;
        return strlen($line) > $max ? substr($line, 0, $max) . '…' : $line;
    }
}
