<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Portal\Auth\Capability;
use Portal\Config;
use Portal\Db;
use Portal\Install\Installer;
use Portal\Install\RequirementChecker;
use Portal\Support\Crypto;

/**
 * The installer, run for real against a real database.
 *
 * This does not extend DatabaseTestCase, because the whole point is that the
 * installer builds the schema itself from nothing — inheriting a
 * pre-migrated database would test the opposite of what matters.
 *
 * Each test creates its own scratch database and its own config.php in a temp
 * directory, then removes both.
 */
final class InstallerTest extends TestCase
{
    private static ?PDO $admin = null;
    private static string $host = '';
    private static int $port = 3306;
    private static string $user = 'root';
    private static string $pass = '';

    private string $database = '';
    private string $configPath = '';

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('PORTAL_TEST_DB');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('PORTAL_TEST_DB is not set; skipping installer tests.');
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            self::markTestSkipped('PORTAL_TEST_DB is not a valid URL.');
        }

        self::$host = $parts['host'];
        self::$port = $parts['port'] ?? 3306;
        self::$user = $parts['user'] ?? 'root';
        self::$pass = $parts['pass'] ?? '';

        try {
            self::$admin = new PDO(
                'mysql:host=' . self::$host . ';port=' . self::$port . ';charset=utf8mb4',
                self::$user,
                self::$pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            self::markTestSkipped('Could not connect: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->database = 'portal_install_' . bin2hex(random_bytes(4));
        $this->configPath = sys_get_temp_dir() . '/portal-config-' . bin2hex(random_bytes(4)) . '.php';
    }

    protected function tearDown(): void
    {
        if (self::$admin !== null && $this->database !== '') {
            self::$admin->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        }
        if ($this->configPath !== '' && is_file($this->configPath)) {
            @unlink($this->configPath);
        }
        Db::setInstance(null);
    }

    // ------------------------------------------------------------ requirements

    public function testRequirementCheckerRunsAndFindsThisEnvironmentUsable(): void
    {
        $checker = new RequirementChecker();
        $checks = $checker->all();

        self::assertNotEmpty($checks);
        self::assertTrue(
            $checker->canProceed(),
            'The test environment should satisfy every blocking requirement. Blocking: '
            . implode(', ', array_map(
                static fn ($r): string => $r->label,
                $checker->blocking()
            ))
        );
    }

    /**
     * Every check must be able to explain itself when it fails, since the
     * person reading it usually cannot install software and needs to know
     * which control-panel toggle to find.
     *
     * On a healthy machine nothing fails, so this asserts the contract two
     * ways: any actual failure carries a fix, and the Requirement type itself
     * makes a fix reachable.
     */
    public function testEveryUnsatisfiedRequirementExplainsHowToFixIt(): void
    {
        $checks = (new RequirementChecker())->all();
        self::assertNotEmpty($checks);

        foreach ($checks as $check) {
            if (!$check->satisfied) {
                self::assertNotSame(
                    '',
                    $check->fix,
                    "'{$check->label}' fails without telling the user what to do about it."
                );
            }
        }

        // The environment is healthy, so force the failing shape explicitly
        // rather than letting this test quietly assert nothing.
        $failing = new \Portal\Install\Requirement(
            label: 'Synthetic',
            satisfied: false,
            fix: 'Do the thing.'
        );

        self::assertTrue($failing->isBlocking());
        self::assertNotSame('', $failing->fix);
    }

    // --------------------------------------------------------------- database

    public function testDatabaseTestRejectsMissingFields(): void
    {
        $installer = new Installer(new Config($this->configPath));

        $result = $installer->testDatabase(['db_host' => '127.0.0.1']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('required', $result['message']);
    }

    public function testDatabaseTestRejectsAnInvalidPrefix(): void
    {
        $installer = new Installer(new Config($this->configPath));

        $result = $installer->testDatabase($this->dbInput(['db_prefix' => 'bad-prefix!']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('prefix', $result['message']);
    }

    /**
     * "Unknown database" is the single most common thing to get wrong on shared
     * hosting, because control panels silently prefix the name with the account.
     */
    public function testUnknownDatabaseProducesAnActionableMessage(): void
    {
        $installer = new Installer(new Config($this->configPath));

        $result = $installer->testDatabase($this->dbInput(['db_name' => 'definitely_not_a_database']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('control panel', $result['message']);
    }

    public function testWrongCredentialsProduceAnActionableMessage(): void
    {
        $installer = new Installer(new Config($this->configPath));

        $result = $installer->testDatabase($this->dbInput([
            'db_user' => 'nobody_' . bin2hex(random_bytes(3)),
            'db_pass' => 'wrong',
        ]));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('rejected', $result['message']);
    }

    public function testASuccessfulConnectionReportsTheServerVersion(): void
    {
        $this->createDatabase();
        $installer = new Installer(new Config($this->configPath));

        $result = $installer->testDatabase($this->dbInput());

        self::assertTrue($result['ok'], $result['message']);
        self::assertStringContainsString('Connected', $result['message']);
    }

    /** Installing over an existing schema should warn, not silently proceed. */
    public function testExistingTablesProduceAWarning(): void
    {
        $this->createDatabase();
        self::$admin->exec('USE `' . $this->database . '`');
        self::$admin->exec('CREATE TABLE `users` (id INT) ENGINE=InnoDB');

        $installer = new Installer(new Config($this->configPath));
        $result = $installer->testDatabase($this->dbInput());

        self::assertTrue($result['ok']);
        self::assertStringContainsString('already exist', $result['detail'] ?? '');
    }

    // ------------------------------------------------------------ full install

    public function testAFullInstallProducesAWorkingSite(): void
    {
        $this->createDatabase();

        $config = new Config($this->configPath);
        $installer = new Installer($config);

        $result = $installer->install($this->installState());

        self::assertTrue($result->ok, $result->message . ' ' . ($result->detail ?? ''));

        // config.php written, and readable back as an array.
        self::assertFileExists($this->configPath);
        $written = require $this->configPath;
        self::assertIsArray($written);
        self::assertSame('https://example.test', $written['base_url']);
        self::assertSame($this->database, $written['db_name']);

        // Secrets generated, distinct, and of the right shape.
        self::assertNotEmpty($written['app_key']);
        self::assertNotEmpty($written['gate_secret']);
        self::assertNotEmpty($written['cron_secret']);
        self::assertNotSame($written['app_key'], $written['gate_secret']);
        self::assertSame(32, strlen((string) base64_decode($written['app_key'], true)));

        // Geo lists live in the file and start empty — an admin who whitelists
        // the wrong country must be able to recover over FTP.
        self::assertArrayHasKey('geo_whitelist', $written);
        self::assertSame('', $written['geo_whitelist']);

        $db = Db::fromConfig(new Config($this->configPath));
        Db::setInstance($db);

        // Schema is present.
        self::assertTrue($db->tableExists('users'));
        self::assertTrue($db->tableExists('videos'));
        self::assertTrue($db->tableExists('shares'));

        // Roles and capabilities seeded.
        self::assertGreaterThan(0, (int) $db->value('SELECT COUNT(*) FROM {capabilities}'));
        self::assertNotNull($db->value('SELECT id FROM {roles} WHERE slug = ?', [Capability::ROLE_ADMIN]));

        // Exactly one administrator, and they are authorized.
        $admin = $db->first(
            'SELECT u.email, u.authorized, r.slug AS role
               FROM {users} u JOIN {roles} r ON r.id = u.role_id'
        );
        self::assertNotNull($admin);
        self::assertSame('owner@example.test', $admin['email']);
        self::assertSame(Capability::ROLE_ADMIN, $admin['role']);
        self::assertSame(1, (int) $admin['authorized']);

        // Default theme active.
        self::assertSame('default', $db->value('SELECT slug FROM {themes} WHERE is_active = 1'));

        // Cron jobs registered and due.
        self::assertGreaterThan(0, (int) $db->value('SELECT COUNT(*) FROM {cron_jobs}'));
    }

    /**
     * Credentials must not be readable from a database dump — that is the
     * entire justification for encrypting them rather than storing JSON.
     */
    public function testProviderCredentialsAreEncryptedAtRest(): void
    {
        $this->createDatabase();

        $config = new Config($this->configPath);
        $result = (new Installer($config))->install($this->installState());
        self::assertTrue($result->ok, $result->message);

        $written = require $this->configPath;
        $db = Db::fromConfig(new Config($this->configPath));
        Db::setInstance($db);

        $stored = (string) $db->value(
            'SELECT credentials FROM {providers} WHERE kind = ? AND slug = ?',
            ['video', 'bunny']
        );

        self::assertStringNotContainsString('super-secret-api-key', $stored);
        self::assertStringStartsWith('v1.', $stored);

        // And it round-trips with the generated key.
        $crypto = new Crypto((string) $written['app_key']);
        $plain = $crypto->decrypt($stored);
        self::assertNotNull($plain);
        self::assertStringContainsString('super-secret-api-key', $plain);
    }

    public function testChosenProvidersAreMarkedActive(): void
    {
        $this->createDatabase();
        $result = (new Installer(new Config($this->configPath)))->install($this->installState());
        self::assertTrue($result->ok, $result->message);

        $db = Db::fromConfig(new Config($this->configPath));
        Db::setInstance($db);

        foreach (['auth' => 'local', 'video' => 'bunny', 'mail' => 'smtp'] as $kind => $slug) {
            self::assertSame(
                $slug,
                $db->value('SELECT slug FROM {providers} WHERE kind = ? AND is_active = 1', [$kind]),
                "The chosen {$kind} provider should be active."
            );
        }
    }

    public function testTheAdminPasswordIsHashedNotStored(): void
    {
        $this->createDatabase();
        $result = (new Installer(new Config($this->configPath)))->install($this->installState());
        self::assertTrue($result->ok, $result->message);

        $db = Db::fromConfig(new Config($this->configPath));
        Db::setInstance($db);

        $hash = (string) $db->value('SELECT password_hash FROM {users} LIMIT 1');

        self::assertNotSame('a-very-long-install-password', $hash);
        self::assertTrue(password_verify('a-very-long-install-password', $hash));
    }

    public function testInstallIsRefusedWithoutABaseUrl(): void
    {
        $this->createDatabase();

        $state = $this->installState();
        $state['site']['base_url'] = '';

        $result = (new Installer(new Config($this->configPath)))->install($state);

        self::assertFalse($result->ok);
        self::assertFileDoesNotExist(
            $this->configPath,
            'A failed install must not leave a config.php behind — the app would think it was installed.'
        );
    }

    public function testInstallIsRefusedWithAMalformedBaseUrl(): void
    {
        $this->createDatabase();

        $state = $this->installState();
        $state['site']['base_url'] = 'not a url';

        $result = (new Installer(new Config($this->configPath)))->install($state);

        self::assertFalse($result->ok);
    }

    /**
     * isInstalled() must require BOTH a config file and a populated database.
     * A config.php pointing at an empty database is a broken install, and
     * treating it as finished would lock someone out of the installer that
     * could fix it.
     */
    public function testAConfigWithNoUsersIsNotConsideredInstalled(): void
    {
        $this->createDatabase();

        $config = new Config($this->configPath);
        $config->write([
            'db_host' => self::$host,
            'db_port' => self::$port,
            'db_name' => $this->database,
            'db_user' => self::$user,
            'db_pass' => self::$pass,
            'db_prefix' => '',
            'base_url' => 'https://example.test',
            'app_key' => Crypto::generateKey(),
        ]);

        self::assertFalse((new Installer(new Config($this->configPath)))->isInstalled());
    }

    public function testAFinishedInstallIsRecognised(): void
    {
        $this->createDatabase();

        $config = new Config($this->configPath);
        $result = (new Installer($config))->install($this->installState());
        self::assertTrue($result->ok, $result->message);

        self::assertTrue((new Installer(new Config($this->configPath)))->isInstalled());
    }

    public function testTablePrefixIsHonoured(): void
    {
        $this->createDatabase();

        $state = $this->installState();
        $state['database']['db_prefix'] = 'vp_';

        $result = (new Installer(new Config($this->configPath)))->install($state);
        self::assertTrue($result->ok, $result->message . ' ' . ($result->detail ?? ''));

        self::$admin->exec('USE `' . $this->database . '`');
        $tables = self::$admin->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        self::assertNotEmpty($tables);
        foreach ($tables as $table) {
            self::assertStringStartsWith('vp_', (string) $table);
        }
    }

    // -------------------------------------------------------------- fixtures

    private function createDatabase(): void
    {
        self::$admin->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        self::$admin->exec(
            'CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function dbInput(array $overrides = []): array
    {
        return $overrides + [
            'db_host'   => self::$host,
            'db_port'   => (string) self::$port,
            'db_name'   => $this->database,
            'db_user'   => self::$user,
            'db_pass'   => self::$pass,
            'db_prefix' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function installState(): array
    {
        return [
            'database' => $this->dbInput(),
            'site' => [
                'site_name' => 'Test Portal',
                'base_url'  => 'https://example.test',
                'timezone'  => 'UTC',
            ],
            'admin' => [
                'name'     => 'The Owner',
                'email'    => 'owner@example.test',
                'password' => 'a-very-long-install-password',
            ],
            'providers' => [
                'auth'  => ['slug' => 'local', 'credentials' => ['allow_signup' => '0']],
                'video' => ['slug' => 'bunny', 'credentials' => [
                    'library_id'     => '12345',
                    'api_key'        => 'super-secret-api-key',
                    'token_auth_key' => 'super-secret-token-key',
                ]],
                'mail'  => ['slug' => 'smtp', 'credentials' => [
                    'host' => 'smtp.example.test',
                    'from' => 'Test <videos@example.test>',
                ]],
            ],
        ];
    }
}
