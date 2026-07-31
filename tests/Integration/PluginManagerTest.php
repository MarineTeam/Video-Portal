<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Http\Router;
use Portal\Plugins\Hooks;
use Portal\Plugins\PluginHeader;
use Portal\Plugins\PluginManager;

/**
 * Plugin lifecycle, exercised with real files on disk and a real database.
 *
 * Fixture plugins are written into the actual plugins/ directory and removed
 * afterwards, because the thing being tested is discovery from the filesystem —
 * mocking that away would test nothing worth testing.
 */
final class PluginManagerTest extends DatabaseTestCase
{
    /** @var list<string> Slugs to delete in tearDown. */
    private array $fixtures = [];

    private PluginManager $manager;
    private Hooks $hooks;

    protected function setUp(): void
    {
        $this->truncate([
            'plugin_category_overrides', 'plugin_migrations', 'plugins',
            'categories', 'capabilities',
        ]);

        Hooks::reset();
        $this->hooks = Hooks::instance();

        $this->manager = new PluginManager(
            $this->db(),
            new Config('/nonexistent-config.php'),
            $this->hooks,
            new Router(),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            $this->deleteDirectory(PORTAL_PLUGINS . '/' . $slug);
        }
        $this->fixtures = [];
        Hooks::reset();
    }

    // -------------------------------------------------------------- discovery

    public function testDiscoversAPluginAndParsesItsHeader(): void
    {
        $this->makePlugin('hello', [
            'Plugin Name' => 'Hello World',
            'Version'     => '2.1.0',
            'Description' => 'A greeting.',
            'Author'      => 'Someone',
        ]);

        $found = $this->manager->discover();

        self::assertArrayHasKey('hello', $found);
        self::assertSame('Hello World', $found['hello']->name);
        self::assertSame('2.1.0', $found['hello']->version);
        self::assertSame('A greeting.', $found['hello']->description);
    }

    public function testIgnoresADirectoryWithoutAPluginFile(): void
    {
        $slug = 'notaplugin';
        $this->fixtures[] = $slug;
        @mkdir(PORTAL_PLUGINS . '/' . $slug, 0775, true);
        file_put_contents(PORTAL_PLUGINS . '/' . $slug . '/readme.txt', 'nothing here');

        self::assertArrayNotHasKey($slug, $this->manager->discover());
    }

    /** A .php file with no Plugin Name header is not a plugin. */
    public function testIgnoresAFileWithoutAPluginNameHeader(): void
    {
        $slug = 'headerless';
        $this->fixtures[] = $slug;
        @mkdir(PORTAL_PLUGINS . '/' . $slug, 0775, true);
        file_put_contents(PORTAL_PLUGINS . '/' . $slug . '/plugin.php', "<?php\n// just some code\n");

        self::assertArrayNotHasKey($slug, $this->manager->discover());
    }

    public function testSyncRecordsNewPluginsAsInactive(): void
    {
        $this->makePlugin('syncme', ['Plugin Name' => 'Sync Me']);

        $result = $this->manager->sync();

        self::assertContains('syncme', $result['added']);
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT is_active FROM {plugins} WHERE slug = ?', ['syncme']),
            'A newly discovered plugin must not activate itself.'
        );
    }

    /**
     * Plugins are installed by copying a folder, so a folder can also vanish.
     * The row is kept and deactivated, not deleted, so restoring the folder
     * brings back its settings and migration history.
     */
    public function testSyncDeactivatesButKeepsAPluginWhoseFolderVanished(): void
    {
        $this->makePlugin('vanishing', ['Plugin Name' => 'Vanishing']);
        $this->manager->activate('vanishing');

        $this->deleteDirectory(PORTAL_PLUGINS . '/vanishing');
        $this->freshManager();

        $result = $this->manager->sync();

        self::assertContains('vanishing', $result['missing']);
        self::assertSame(0, (int) $this->db()->value('SELECT is_active FROM {plugins} WHERE slug = ?', ['vanishing']));
        self::assertNotNull(
            $this->db()->value('SELECT 1 FROM {plugins} WHERE slug = ?', ['vanishing']),
            'The row must survive so the plugin can be restored.'
        );
    }

    // -------------------------------------------------------------- lifecycle

    public function testActivateLoadsThePluginAndFiresItsHooks(): void
    {
        $this->makePlugin('greeter', ['Plugin Name' => 'Greeter'], <<<'PHP'
            $plugin->addFilter('greeting', static fn (string $g): string => $g . ', world');
            PHP);

        $result = $this->manager->activate('greeter');

        self::assertTrue($result['ok'], $result['message']);
        self::assertTrue($this->manager->isLoaded('greeter'));
        self::assertSame('hello, world', $this->hooks->applyFilters('greeting', 'hello'));
    }

    public function testDeactivateStopsHooksFiringImmediately(): void
    {
        $this->makePlugin('noisy', ['Plugin Name' => 'Noisy'], <<<'PHP'
            $plugin->addFilter('greeting', static fn (string $g): string => $g . '!');
            PHP);

        $this->manager->activate('noisy');
        self::assertSame('hi!', $this->hooks->applyFilters('greeting', 'hi'));

        $this->manager->deactivate('noisy');

        self::assertSame(
            'hi',
            $this->hooks->applyFilters('greeting', 'hi'),
            'A deactivated plugin must stop affecting the very request that deactivated it.'
        );
        self::assertFalse($this->manager->isLoaded('noisy'));
    }

    public function testDeactivateKeepsData(): void
    {
        $this->makePlugin('keeper', ['Plugin Name' => 'Keeper']);
        $this->manager->activate('keeper');

        $this->db()->execute(
            'UPDATE {plugins} SET settings = ? WHERE slug = ?',
            ['{"colour":"blue"}', 'keeper']
        );

        $this->manager->deactivate('keeper');

        self::assertSame(
            '{"colour":"blue"}',
            $this->db()->value('SELECT settings FROM {plugins} WHERE slug = ?', ['keeper'])
        );
    }

    public function testActivateRunsPluginMigrations(): void
    {
        $this->makePlugin('withtable', ['Plugin Name' => 'With Table']);
        @mkdir(PORTAL_PLUGINS . '/withtable/migrations', 0775, true);
        file_put_contents(
            PORTAL_PLUGINS . '/withtable/migrations/0001_create.sql',
            'CREATE TABLE IF NOT EXISTS {withtable_notes} (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                body TEXT NULL,
                PRIMARY KEY (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
        );

        $result = $this->manager->activate('withtable');

        self::assertTrue($result['ok'], $result['message']);
        self::assertTrue($this->db()->tableExists('withtable_notes'));
        self::assertNotNull($this->db()->value(
            'SELECT 1 FROM {plugin_migrations} WHERE plugin_slug = ? AND version = ?',
            ['withtable', '0001']
        ));
    }

    public function testUninstallRunsTheUninstallScriptAndForgetsThePlugin(): void
    {
        $this->makePlugin('removable', ['Plugin Name' => 'Removable']);
        @mkdir(PORTAL_PLUGINS . '/removable/migrations', 0775, true);
        file_put_contents(
            PORTAL_PLUGINS . '/removable/migrations/0001_create.sql',
            'CREATE TABLE IF NOT EXISTS {removable_data} (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))
             ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
        );
        file_put_contents(
            PORTAL_PLUGINS . '/removable/uninstall.php',
            "<?php\n\$db->execute('DROP TABLE IF EXISTS {removable_data}');\n"
        );

        $this->manager->activate('removable');
        self::assertTrue($this->db()->tableExists('removable_data'));

        $result = $this->manager->uninstall('removable');

        self::assertTrue($result['ok'], $result['message']);
        self::assertFalse($this->db()->tableExists('removable_data'), 'uninstall.php should have dropped its table.');
        self::assertNull($this->db()->value('SELECT 1 FROM {plugins} WHERE slug = ?', ['removable']));
        self::assertNull($this->db()->value(
            'SELECT 1 FROM {plugin_migrations} WHERE plugin_slug = ?',
            ['removable']
        ), 'Migration history must be forgotten so a reinstall re-runs it.');
    }

    // ------------------------------------------------------ failure containment

    /**
     * The guardrail the whole system depends on. Someone on shared hosting
     * cannot SSH in to delete a plugin that fatals, so a throwing plugin must
     * deactivate itself rather than bricking the site.
     */
    public function testAPluginThatThrowsOnLoadIsAutomaticallyDeactivated(): void
    {
        $this->makePlugin('broken', ['Plugin Name' => 'Broken'], <<<'PHP'
            throw new RuntimeException('I am broken');
            PHP);

        $result = $this->manager->activate('broken');

        self::assertFalse($result['ok']);
        self::assertFalse($this->manager->isLoaded('broken'));
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT is_active FROM {plugins} WHERE slug = ?', ['broken']),
            'A plugin that fails to load must not stay marked active.'
        );
    }

    public function testABrokenPluginDoesNotPreventOthersLoading(): void
    {
        $this->makePlugin('good', ['Plugin Name' => 'Good'], <<<'PHP'
            $plugin->addFilter('greeting', static fn (string $g): string => $g . ' [good]');
            PHP);
        $this->makePlugin('bad', ['Plugin Name' => 'Bad'], <<<'PHP'
            throw new RuntimeException('nope');
            PHP);

        $this->manager->sync();
        $this->db()->execute('UPDATE {plugins} SET is_active = 1');

        $this->freshManager();
        $this->manager->loadActive();

        self::assertTrue($this->manager->isLoaded('good'));
        self::assertFalse($this->manager->isLoaded('bad'));
        self::assertSame('hi [good]', $this->hooks->applyFilters('greeting', 'hi'));
    }

    public function testAPluginRequiringANewerPhpIsRefused(): void
    {
        $this->makePlugin('future', [
            'Plugin Name' => 'From The Future',
            'Requires PHP' => '99.0',
        ]);

        $result = $this->manager->activate('future');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('PHP 99.0', $result['message']);
    }

    // ------------------------------------------------------ category overrides

    public function testGlobalStateAppliesWhenNoOverrideExists(): void
    {
        $this->makePlugin('overridable', ['Plugin Name' => 'Overridable']);
        $this->manager->activate('overridable');

        $category = $this->makeCategory('Sermons');

        self::assertTrue($this->manager->isEnabledForCategory('overridable', $category));
        self::assertTrue($this->manager->isEnabledForCategory('overridable', null));
    }

    public function testAnOverrideDisablesThePluginForOneCategoryOnly(): void
    {
        $this->makePlugin('overridable', ['Plugin Name' => 'Overridable']);
        $this->manager->activate('overridable');

        $sermons = $this->makeCategory('Sermons');
        $classes = $this->makeCategory('Classes');

        $this->manager->setCategoryOverride('overridable', $sermons, false);

        self::assertFalse($this->manager->isEnabledForCategory('overridable', $sermons));
        self::assertTrue($this->manager->isEnabledForCategory('overridable', $classes));
        self::assertTrue($this->manager->isEnabledForCategory('overridable', null));
    }

    public function testOverridesAreInheritedByDescendants(): void
    {
        $this->makePlugin('overridable', ['Plugin Name' => 'Overridable']);
        $this->manager->activate('overridable');

        $sermons = $this->makeCategory('Sermons');
        $year    = $this->makeCategory('2026', $sermons);
        $advent  = $this->makeCategory('Advent', $year);

        $this->manager->setCategoryOverride('overridable', $sermons, false);

        self::assertFalse($this->manager->isEnabledForCategory('overridable', $advent));
    }

    /** The nearest explicit override wins, so a child can re-enable it. */
    public function testNearestOverrideWins(): void
    {
        $this->makePlugin('overridable', ['Plugin Name' => 'Overridable']);
        $this->manager->activate('overridable');

        $sermons = $this->makeCategory('Sermons');
        $year    = $this->makeCategory('2026', $sermons);
        $advent  = $this->makeCategory('Advent', $year);

        $this->manager->setCategoryOverride('overridable', $sermons, false);
        $this->manager->setCategoryOverride('overridable', $year, true);

        self::assertFalse($this->manager->isEnabledForCategory('overridable', $sermons));
        self::assertTrue($this->manager->isEnabledForCategory('overridable', $year));
        self::assertTrue(
            $this->manager->isEnabledForCategory('overridable', $advent),
            'Advent should inherit the nearer re-enable, not the distant disable.'
        );
    }

    /**
     * Clearing an override must restore inheritance, which is a distinct state
     * from "explicitly enabled" and has to be expressible.
     */
    public function testClearingAnOverrideRestoresInheritance(): void
    {
        $this->makePlugin('overridable', ['Plugin Name' => 'Overridable']);
        $this->manager->activate('overridable');

        $sermons = $this->makeCategory('Sermons');
        $year    = $this->makeCategory('2026', $sermons);

        $this->manager->setCategoryOverride('overridable', $sermons, false);
        $this->manager->setCategoryOverride('overridable', $year, true);
        self::assertTrue($this->manager->isEnabledForCategory('overridable', $year));

        $this->manager->setCategoryOverride('overridable', $year, null);

        self::assertFalse(
            $this->manager->isEnabledForCategory('overridable', $year),
            'With its own override cleared, the category should inherit the parent disable.'
        );
    }

    public function testAnInactivePluginIsDisabledEverywhereRegardlessOfOverrides(): void
    {
        $this->makePlugin('overridable', ['Plugin Name' => 'Overridable']);
        $this->manager->activate('overridable');

        $sermons = $this->makeCategory('Sermons');
        $this->manager->setCategoryOverride('overridable', $sermons, true);

        $this->manager->deactivate('overridable');

        self::assertFalse(
            $this->manager->isEnabledForCategory('overridable', $sermons),
            'An enabling override must not resurrect a deactivated plugin.'
        );
    }

    // -------------------------------------------------------- header parsing

    public function testSlugSanitisationStripsPathTraversal(): void
    {
        self::assertSame('etcpasswd', PluginHeader::sanitizeSlug('../../etc/passwd'));
        self::assertSame('myplugin', PluginHeader::sanitizeSlug('My Plugin'));
        self::assertSame('good-slug_1', PluginHeader::sanitizeSlug('Good-Slug_1'));
    }

    // ------------------------------------------------------------- fixtures

    /** @param array<string, string> $header */
    private function makePlugin(string $slug, array $header, string $body = ''): void
    {
        $this->fixtures[] = $slug;

        $directory = PORTAL_PLUGINS . '/' . $slug;
        @mkdir($directory, 0775, true);

        $lines = ['<?php', '/**'];
        foreach ($header as $key => $value) {
            $lines[] = " * {$key}: {$value}";
        }
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = $body;

        file_put_contents($directory . '/plugin.php', implode("\n", $lines) . "\n");

        // Discovery is memoised, so a plugin created mid-test needs a reset.
        $this->freshManager();
    }

    private function freshManager(): void
    {
        $this->manager = new PluginManager(
            $this->db(),
            new Config('/nonexistent-config.php'),
            $this->hooks,
            new Router(),
        );
    }

    private function makeCategory(string $name, ?int $parentId = null): int
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->db()->insert('categories', [
            'parent_id'  => $parentId,
            'slug'       => strtolower($name) . '-' . bin2hex(random_bytes(3)),
            'name'       => $name,
            'path'       => '/',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $parentPath = $parentId === null
            ? '/'
            : (string) $this->db()->value('SELECT path FROM {categories} WHERE id = ?', [$parentId]);

        $this->db()->execute('UPDATE {categories} SET path = ? WHERE id = ?', [$parentPath . $id . '/', $id]);

        return $id;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->deleteDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
