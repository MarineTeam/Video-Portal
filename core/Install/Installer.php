<?php

declare(strict_types=1);

namespace Portal\Install;

use PDO;
use PDOException;
use Portal\Auth\Capability;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\UserRepository;
use Portal\Config;
use Portal\Db;
use Portal\Migrator;
use Portal\Support\Crypto;
use Portal\Support\Str;
use Portal\Themes\ThemeManager;
use RuntimeException;
use Throwable;

/**
 * The install process, minus the HTML.
 *
 * Wizard state lives in a PHP session rather than hidden form fields, because
 * credentials pass through several steps and round-tripping an API key through
 * the browser on every Next click is needless exposure.
 *
 * The ordering matters: nothing is written to config.php until the final step,
 * so an abandoned install leaves no half-configured file that would make the
 * app think it was installed.
 */
final class Installer
{
    public const STEP_REQUIREMENTS = 'requirements';
    public const STEP_DATABASE     = 'database';
    public const STEP_SITE         = 'site';
    public const STEP_PROVIDERS    = 'providers';
    public const STEP_ADMIN        = 'admin';
    public const STEP_FINISH       = 'finish';

    /** @return list<string> */
    public static function steps(): array
    {
        return [
            self::STEP_REQUIREMENTS,
            self::STEP_DATABASE,
            self::STEP_SITE,
            self::STEP_PROVIDERS,
            self::STEP_ADMIN,
            self::STEP_FINISH,
        ];
    }

    public static function stepLabel(string $step): string
    {
        return match ($step) {
            self::STEP_REQUIREMENTS => 'Server check',
            self::STEP_DATABASE     => 'Database',
            self::STEP_SITE         => 'Your site',
            self::STEP_PROVIDERS    => 'Services',
            self::STEP_ADMIN        => 'Administrator',
            self::STEP_FINISH       => 'Done',
            default                 => ucfirst($step),
        };
    }

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Is the app already installed?
     *
     * Both conditions are required. A config.php with an unreachable database
     * is a broken install, not a finished one, and should not lock someone out
     * of the installer that could fix it.
     */
    public function isInstalled(): bool
    {
        if (!$this->config->isInstalled()) {
            return false;
        }

        try {
            $db = Db::fromConfig($this->config);
            return $db->tableExists('users')
                && (int) $db->value('SELECT COUNT(*) FROM {users}') > 0;
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------- database

    /**
     * Try the supplied credentials and report precisely what went wrong.
     *
     * MySQL's own errors are accurate but unhelpful to someone reading them in
     * a browser, so the common ones are translated into the thing to change.
     *
     * @param array<string, string> $input
     * @return array{ok: bool, message: string, detail?: string}
     */
    public function testDatabase(array $input): array
    {
        $host = trim($input['db_host'] ?? '127.0.0.1');
        $port = (int) ($input['db_port'] ?? 3306);
        $name = trim($input['db_name'] ?? '');
        $user = trim($input['db_user'] ?? '');
        $pass = (string) ($input['db_pass'] ?? '');
        $prefix = trim($input['db_prefix'] ?? '');

        if ($name === '' || $user === '') {
            return ['ok' => false, 'message' => 'A database name and username are both required.'];
        }

        if ($prefix !== '' && preg_match('/^[a-z0-9_]+$/i', $prefix) !== 1) {
            return [
                'ok' => false,
                'message' => 'The table prefix can only contain letters, numbers, and underscores.',
            ];
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
            );
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => $this->explainDatabaseError($e, $host, $name)];
        }

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        if (!$this->versionIsSupported($version)) {
            return [
                'ok' => false,
                'message' => "This server runs {$version}. MySQL 8.0 or MariaDB 10.6 or newer is required.",
            ];
        }

        // Creating a table is the only honest way to find out whether the user
        // has the privileges the migrations will need. A GRANT check would be
        // more elegant and less reliable.
        $probe = ($prefix !== '' ? $prefix : '') . 'portal_install_probe';
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$probe}` (id INT) ENGINE=InnoDB");
            $pdo->exec("DROP TABLE IF EXISTS `{$probe}`");
        } catch (PDOException $e) {
            return [
                'ok' => false,
                'message' => "Connected, but this user cannot create tables in '{$name}'.",
                'detail'  => 'Grant it ALL PRIVILEGES on that database. ' . $e->getMessage(),
            ];
        }

        $existing = $this->countExistingTables($pdo, $prefix);

        return [
            'ok' => true,
            'message' => "Connected to {$version}.",
            'detail' => $existing > 0
                ? "Warning: {$existing} table(s) with this prefix already exist. "
                . 'Installing will use them, and may overwrite data. Use a different prefix to be safe.'
                : '',
        ];
    }

    private function versionIsSupported(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            preg_match('/(\d+\.\d+)/', $version, $m);
            return isset($m[1]) && version_compare($m[1], '10.6', '>=');
        }

        preg_match('/^(\d+\.\d+)/', $version, $m);
        return isset($m[1]) && version_compare($m[1], '8.0', '>=');
    }

    private function countExistingTables(PDO $pdo, string $prefix): int
    {
        try {
            $like = ($prefix !== '' ? str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) : '') . '%';
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$like]);
            return count($stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return 0;
        }
    }

    private function explainDatabaseError(PDOException $e, string $host, string $name): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Unknown database') =>
                "There is no database called '{$name}' on this server. Create it in your hosting "
                . 'control panel first — most panels prefix the name with your account, '
                . 'so it may be something like "user_' . $name . '".',

            str_contains($message, 'Access denied') =>
                'The server rejected that username or password. On most shared hosts the database '
                . 'user must also be explicitly added to the database, with all privileges.',

            str_contains($message, 'Connection refused'), str_contains($message, "getaddrinfo") =>
                "Nothing answered at '{$host}'. Check your control panel for the database hostname — "
                . "many hosts use 'localhost', but several give each database its own hostname "
                . '(DreamHost, for example, uses something like mysql.yourdomain.com).',

            str_contains($message, 'timed out') =>
                "Connecting to '{$host}' timed out. Check the hostname, and whether the database "
                . 'server allows connections from this account.',

            default => 'Could not connect: ' . $message,
        };
    }

    // --------------------------------------------------------------- writing

    /**
     * Write config.php and build the schema.
     *
     * Deliberately ordered so the destructive-feeling step comes last: the
     * database is migrated first, and config.php is only written once that has
     * succeeded. A failure halfway leaves no config.php, so the installer can
     * simply be re-run.
     *
     * @param array<string, mixed> $state the accumulated wizard answers
     */
    public function install(array $state): InstallResult
    {
        $database = $state['database'] ?? [];
        $site     = $state['site'] ?? [];
        $admin    = $state['admin'] ?? [];
        $providers = $state['providers'] ?? [];

        $configData = [
            'db_host'   => trim((string) ($database['db_host'] ?? '127.0.0.1')),
            'db_port'   => (int) ($database['db_port'] ?? 3306),
            'db_name'   => trim((string) ($database['db_name'] ?? '')),
            'db_user'   => trim((string) ($database['db_user'] ?? '')),
            'db_pass'   => (string) ($database['db_pass'] ?? ''),
            'db_prefix' => trim((string) ($database['db_prefix'] ?? '')),

            'base_url'  => rtrim(trim((string) ($site['base_url'] ?? '')), '/'),

            'app_key'     => Crypto::generateKey(),
            'gate_secret' => Crypto::token(32),
            'cron_secret' => Crypto::token(16),

            'debug' => false,

            // Geo lists live in the file, never the database. An admin who
            // whitelists the wrong country locks themselves out, and recovery
            // has to be possible over FTP.
            'geo_whitelist'            => '',
            'admin_geo_whitelist'      => '',
            'admin_geo_bypass_emails'  => '',
        ];

        if ($configData['base_url'] === '') {
            return InstallResult::failure('The site address is required.');
        }
        if (filter_var($configData['base_url'], FILTER_VALIDATE_URL) === false) {
            return InstallResult::failure('That site address is not a valid URL.');
        }

        // Build a Db from the pending config without writing anything yet.
        $db = new Db(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $configData['db_host'],
                $configData['db_port'],
                $configData['db_name']
            ),
            $configData['db_user'],
            $configData['db_pass'],
            $configData['db_prefix']
        );
        Db::setInstance($db);

        try {
            (new Migrator($db))->migrateCore();
        } catch (Throwable $e) {
            return InstallResult::failure('Setting up the database failed.', $e->getMessage());
        }

        try {
            $this->seed($db, $site, $admin, $providers, $configData);
        } catch (Throwable $e) {
            return InstallResult::failure('Setting up the initial data failed.', $e->getMessage());
        }

        // Everything worked. Only now does the app start considering itself
        // installed.
        try {
            $this->config->write($configData);
        } catch (Throwable $e) {
            return InstallResult::failure(
                'The database is ready, but config.php could not be written.',
                $e->getMessage() . ' — you can create it by hand; the installer will show the contents.'
            );
        }

        $this->lockInstaller();

        return InstallResult::success(
            $configData['base_url'],
            (string) ($admin['email'] ?? ''),
            $configData['cron_secret']
        );
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $providers
     * @param array<string, mixed> $configData
     */
    private function seed(Db $db, array $site, array $admin, array $providers, array $configData): void
    {
        $crypto = new Crypto((string) $configData['app_key']);

        // Roles and capabilities before anything that references them.
        (new PermissionSeeder($db))->seed();

        $siteName = trim((string) ($site['site_name'] ?? 'Video Portal')) ?: 'Video Portal';
        $timezone = trim((string) ($site['timezone'] ?? 'UTC')) ?: 'UTC';

        $now = date('Y-m-d H:i:s');
        foreach ([
            'site_name'          => $siteName,
            'timezone'           => $timezone,
            'installed_at'       => $now,
            'installed_version'  => PORTAL_VERSION,
            'watermark_default'  => '0',
            'geo_enabled'        => '0',
            'admin_geo_enabled'  => '0',
        ] as $key => $value) {
            $db->execute(
                'INSERT INTO {settings} (`key`, `value`, updated_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
                [$key, $value]
            );
        }

        // Providers: store credentials encrypted and mark the chosen ones
        // active. They were already tested during the wizard step.
        foreach ($providers as $kind => $choice) {
            if (!is_array($choice) || !isset($choice['slug'])) {
                continue;
            }

            $credentials = is_array($choice['credentials'] ?? null) ? $choice['credentials'] : [];

            $db->execute(
                'INSERT INTO {providers}
                    (kind, slug, credentials, is_active, last_tested_at, last_test_ok, created_at, updated_at)
                 VALUES (?, ?, ?, 1, NOW(), 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    credentials = VALUES(credentials),
                    is_active = 1,
                    updated_at = NOW()',
                [
                    (string) $kind,
                    (string) $choice['slug'],
                    $crypto->encrypt(json_encode($credentials, JSON_UNESCAPED_SLASHES) ?: '{}'),
                ]
            );
        }

        // The default theme, active.
        $themes = new ThemeManager($db, $this->config, \Portal\Plugins\Hooks::instance());
        $themes->sync();
        $db->execute('UPDATE {themes} SET is_active = 0');
        $db->execute('UPDATE {themes} SET is_active = 1 WHERE slug = ?', ['default']);
        $themes->saveSettings('default', ['site_name' => $siteName]);

        // The first administrator. Created directly rather than through the
        // normal sign-in path, because bootstrapping has to start somewhere:
        // this is the only account that is authorized without another admin
        // approving it.
        $email = Str::normalizeEmail((string) ($admin['email'] ?? ''));
        if (!Str::isEmail($email)) {
            throw new RuntimeException('The administrator email address is not valid.');
        }

        $users = new UserRepository($db);
        $password = (string) ($admin['password'] ?? '');

        $users->create(
            email: $email,
            name: trim((string) ($admin['name'] ?? '')) ?: null,
            roleSlug: Capability::ROLE_ADMIN,
            password: $password !== '' ? $password : null,
            authorized: true,
            authorizedBy: 'installer',
        );

        // Scheduled jobs, due immediately so the first run happens soon after
        // install rather than an hour later.
        foreach ([
            'sessions.purge'      => 3600,
            'videos.sync'         => 900,
            'shares.cleanup'      => 86400,
        ] as $slug => $interval) {
            $db->execute(
                'INSERT INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
                 VALUES (?, ?, NOW(), 1)
                 ON DUPLICATE KEY UPDATE interval_seconds = VALUES(interval_seconds)',
                [$slug, $interval]
            );
        }
    }

    /**
     * Make the installer unusable once it has succeeded.
     *
     * Leaving a working installer on a live site lets anyone who finds it
     * reconfigure the database connection and take the site over. Renaming is
     * preferred over deleting so an admin can see what happened, but either
     * outcome is acceptable — and if both fail, the app refuses to serve
     * /install anyway because isInstalled() returns true.
     */
    private function lockInstaller(): void
    {
        // Only meaningful when actually serving the web installer. Under CLI —
        // which in practice means the test suite — this would rename the real
        // source file out of the working tree, and it caught us out exactly
        // once before this guard existed.
        if (PHP_SAPI === 'cli') {
            return;
        }

        $installer = PORTAL_PUBLIC . '/install.php';
        if (!is_file($installer)) {
            return;
        }

        $locked = PORTAL_PUBLIC . '/install.php.installed';

        if (!@rename($installer, $locked)) {
            @unlink($installer);
        }
    }
}
