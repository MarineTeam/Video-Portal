<?php

declare(strict_types=1);

namespace Portal\Plugins;

use Portal\Auth\PermissionSeeder;
use Portal\Config;
use Portal\Db;
use Portal\Http\Router;
use Portal\Migrator;
use RuntimeException;
use Throwable;

/**
 * Discovers, loads, and manages the lifecycle of plugins.
 *
 * The lifecycle mirrors WordPress because the mental model is worth more than
 * originality here:
 *
 *   activate    runs the plugin's migrations, seeds its settings, starts firing
 *               its hooks. Reversible.
 *   deactivate  stops firing its hooks. Data is kept, so reactivating restores
 *               everything. This is what an admin reaches for when a plugin
 *               misbehaves, and it must never lose their content.
 *   uninstall   the plugin drops its own tables and its capabilities are
 *               removed. Irreversible, and confirmed in the UI.
 *
 * The single most important property: a broken plugin must not be able to take
 * the site down. Someone on shared hosting has no shell to disable it with, so
 * a fatal error during load would leave them permanently locked out of their
 * own admin area. Load failures are caught, logged, and the plugin is
 * automatically deactivated.
 */
final class PluginManager
{
    /** @var array<string, PluginContext> Loaded, keyed by slug. */
    private array $loaded = [];

    /** @var array<string, PluginHeader>|null Discovered on disk. */
    private ?array $discovered = null;

    /** @var list<array{plugin: string, title: string, path: string, capability: string, position: int}> */
    private array $adminPages = [];

    /** @var array<string, array{interval: int, handler: callable}> */
    private array $cronJobs = [];

    /** @var array<string, array<int, bool>> plugin slug => category id => enabled */
    private array $overrideCache = [];

    /** @var array<int, string>|null category id => materialized path */
    private ?array $categoryPaths = null;

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Hooks $hooks,
        private readonly Router $router,
    ) {
    }

    // ------------------------------------------------------------- discovery

    /**
     * Every plugin present on disk, whether active or not.
     *
     * @return array<string, PluginHeader>
     */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $found = [];

        if (!is_dir(PORTAL_PLUGINS)) {
            return $this->discovered = $found;
        }

        foreach ((array) glob(PORTAL_PLUGINS . '/*', GLOB_ONLYDIR) as $directory) {
            if (!is_string($directory)) {
                continue;
            }

            $slug = PluginHeader::sanitizeSlug(basename($directory));
            if ($slug === '' || $slug !== basename($directory)) {
                // A directory name that doesn't survive sanitisation is either
                // a mistake or an attempt at path traversal. Skip it.
                continue;
            }

            $file = $directory . '/plugin.php';
            if (!is_file($file)) {
                continue;
            }

            $header = PluginHeader::fromFile($file, $slug);
            if ($header !== null) {
                $found[$header->slug] = $header;
            }
        }

        ksort($found);
        return $this->discovered = $found;
    }

    /**
     * Reconcile the database against what is on disk.
     *
     * Plugins are installed by copying a directory, so the database can be out
     * of date in both directions: a new directory that has never been recorded,
     * and a row for a directory someone deleted over FTP.
     *
     * @return array{added: list<string>, missing: list<string>}
     */
    public function sync(): array
    {
        $onDisk = $this->discover();
        $added = [];

        foreach ($onDisk as $slug => $header) {
            $exists = $this->db->value('SELECT 1 FROM {plugins} WHERE slug = ?', [$slug]);

            if ($exists === null) {
                $this->db->insert('plugins', [
                    'slug'       => $slug,
                    'name'       => $header->name,
                    'version'    => $header->version,
                    'is_active'  => 0,
                    'is_bundled' => $header->bundled ? 1 : 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $added[] = $slug;
                continue;
            }

            $this->db->execute(
                'UPDATE {plugins} SET name = ?, version = ?, updated_at = NOW() WHERE slug = ?',
                [$header->name, $header->version, $slug]
            );
        }

        // Rows whose directory has vanished. Deactivated rather than deleted:
        // the settings and migration history are kept so restoring the folder
        // brings the plugin back exactly as it was.
        $missing = [];
        foreach ($this->db->all('SELECT slug, is_active FROM {plugins}') as $row) {
            $slug = (string) $row['slug'];
            if (!isset($onDisk[$slug])) {
                $missing[] = $slug;
                if ((int) $row['is_active'] === 1) {
                    $this->db->execute('UPDATE {plugins} SET is_active = 0 WHERE slug = ?', [$slug]);
                }
            }
        }

        return ['added' => $added, 'missing' => $missing];
    }

    // ----------------------------------------------------------------- loading

    /**
     * Load and boot every active plugin. Called once during bootstrap.
     */
    public function loadActive(): void
    {
        try {
            $rows = $this->db->all('SELECT slug FROM {plugins} WHERE is_active = 1');
        } catch (Throwable $e) {
            // No plugins is a survivable state; a dead site is not.
            error_log('Portal: could not read the plugins table: ' . $e->getMessage());
            return;
        }

        $available = $this->discover();

        foreach ($rows as $row) {
            $slug = (string) $row['slug'];

            if (!isset($available[$slug])) {
                continue; // sync() will deactivate it
            }

            $this->load($slug, $available[$slug]);
        }
    }

    /**
     * Load one plugin.
     *
     * Any throwable during load deactivates the plugin. This is the guardrail
     * that keeps a bad plugin from bricking a site whose owner cannot SSH in
     * to remove it.
     */
    private function load(string $slug, PluginHeader $header): void
    {
        if (isset($this->loaded[$slug])) {
            return;
        }

        $reason = $header->incompatibilityReason();
        if ($reason !== null) {
            error_log("Portal: not loading plugin '{$slug}': {$reason}");
            $this->deactivate($slug, "Incompatible: {$reason}");
            return;
        }

        $directory = PORTAL_PLUGINS . '/' . $slug;
        $file = $directory . '/plugin.php';

        $context = new PluginContext(
            slug: $slug,
            directory: $directory,
            header: $header,
            db: $this->db,
            config: $this->config,
            hooks: $this->hooks,
            router: $this->router,
            manager: $this,
        );

        try {
            // Named $plugin because that is what the file will reference.
            (static function (string $file, PluginContext $plugin): void {
                require $file;
            })($file, $context);

            $this->loaded[$slug] = $context;
        } catch (Throwable $e) {
            error_log(sprintf(
                "Portal: plugin '%s' failed to load and has been deactivated: %s in %s:%d",
                $slug,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $this->hooks->removeAllForPlugin($slug);
            $this->deactivate($slug, 'Failed to load: ' . $e->getMessage());
        }
    }

    public function isLoaded(string $slug): bool
    {
        return isset($this->loaded[$slug]);
    }

    public function context(string $slug): ?PluginContext
    {
        return $this->loaded[$slug] ?? null;
    }

    // --------------------------------------------------------------- lifecycle

    /**
     * Activate a plugin: run its migrations, then load it.
     *
     * @return array{ok: bool, message: string}
     */
    public function activate(string $slug): array
    {
        $slug = PluginHeader::sanitizeSlug($slug);
        $available = $this->discover();

        if (!isset($available[$slug])) {
            return ['ok' => false, 'message' => "No plugin named '{$slug}' was found in the plugins folder."];
        }

        $header = $available[$slug];

        $reason = $header->incompatibilityReason();
        if ($reason !== null) {
            return ['ok' => false, 'message' => $reason];
        }

        $directory = PORTAL_PLUGINS . '/' . $slug;

        try {
            (new Migrator($this->db))->migratePlugin($slug, $directory . '/migrations');
        } catch (Throwable $e) {
            // Do not activate a plugin whose tables failed to create; it would
            // fail on every request afterwards.
            return ['ok' => false, 'message' => 'Database setup failed: ' . $e->getMessage()];
        }

        // Upsert rather than UPDATE. A plugin is installed by copying a folder,
        // so the very first activation is frequently for a slug that has no row
        // yet — an UPDATE there matches nothing and silently does nothing,
        // leaving the admin staring at a plugin that refuses to turn on.
        $this->db->execute(
            'INSERT INTO {plugins}
                (slug, name, version, is_active, is_bundled, activated_at, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                version = VALUES(version),
                is_active = 1,
                activated_at = NOW(),
                updated_at = NOW()',
            [$slug, $header->name, $header->version, $header->bundled ? 1 : 0]
        );

        $this->load($slug, $header);

        if (!isset($this->loaded[$slug])) {
            return ['ok' => false, 'message' => 'The plugin failed to load and was deactivated. Check the error log.'];
        }

        $this->hooks->doAction('plugin_activated', $slug);

        return ['ok' => true, 'message' => "{$header->name} is now active."];
    }

    /**
     * Deactivate: stop firing its hooks, keep all its data.
     */
    public function deactivate(string $slug, ?string $reason = null): array
    {
        $slug = PluginHeader::sanitizeSlug($slug);

        try {
            $this->db->execute('UPDATE {plugins} SET is_active = 0, updated_at = NOW() WHERE slug = ?', [$slug]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not deactivate: ' . $e->getMessage()];
        }

        $this->hooks->removeAllForPlugin($slug);
        unset($this->loaded[$slug]);

        // Drop this plugin's admin pages so the navigation doesn't link to a
        // route that no longer exists.
        $this->adminPages = array_values(array_filter(
            $this->adminPages,
            static fn (array $page): bool => $page['plugin'] !== $slug
        ));

        $this->hooks->doAction('plugin_deactivated', $slug, $reason);

        return ['ok' => true, 'message' => $reason ?? 'Plugin deactivated. Its data has been kept.'];
    }

    /**
     * Uninstall: let the plugin clean up, then forget it entirely.
     *
     * The plugin's own uninstall.php runs first so it can drop its tables while
     * it still knows their names. Its capabilities go next, which cascades to
     * any grants that referenced them.
     */
    public function uninstall(string $slug): array
    {
        $slug = PluginHeader::sanitizeSlug($slug);

        $row = $this->db->first('SELECT is_bundled FROM {plugins} WHERE slug = ?', [$slug]);
        if ($row === null) {
            return ['ok' => false, 'message' => 'That plugin is not installed.'];
        }

        $this->deactivate($slug);

        $uninstallFile = PORTAL_PLUGINS . '/' . $slug . '/uninstall.php';
        if (is_file($uninstallFile)) {
            try {
                $db = $this->db;
                (static function (string $file, Db $db): void {
                    require $file;
                })($uninstallFile, $db);
            } catch (Throwable $e) {
                error_log("Portal: uninstall script for '{$slug}' failed: " . $e->getMessage());
                // Continue anyway — leaving the row behind would make the
                // plugin permanently un-reinstallable.
            }
        }

        (new PermissionSeeder($this->db))->removePluginCapabilities($slug);
        (new Migrator($this->db))->forgetPlugin($slug);

        $this->db->execute('DELETE FROM {plugin_category_overrides} WHERE plugin_id IN (SELECT id FROM {plugins} WHERE slug = ?)', [$slug]);
        $this->db->execute('DELETE FROM {plugins} WHERE slug = ?', [$slug]);

        $this->discovered = null;
        $this->hooks->doAction('plugin_uninstalled', $slug);

        return [
            'ok' => true,
            'message' => (int) $row['is_bundled'] === 1
                ? 'Plugin uninstalled. Because it ships with the app, its files are still on disk and it can be reactivated.'
                : 'Plugin uninstalled. Delete its folder to remove the files.',
        ];
    }

    // ------------------------------------------------------ category overrides

    /**
     * Is $slug active for $categoryId?
     *
     * Walks the category's ancestor chain; the nearest explicit override wins.
     * With no override anywhere, the plugin's global state applies.
     */
    public function isEnabledForCategory(string $slug, ?int $categoryId): bool
    {
        $globallyActive = isset($this->loaded[$slug]);

        // A deactivated plugin is off everywhere, full stop. Overrides refine
        // where an *active* plugin applies; they must never resurrect one an
        // admin has switched off, or "deactivate" would stop meaning what
        // everyone reasonably expects it to mean.
        if (!$globallyActive) {
            return false;
        }

        if ($categoryId === null || $categoryId <= 0) {
            return true;
        }

        $overrides = $this->overridesFor($slug);
        if ($overrides === []) {
            return true;
        }

        // Nearest first: the category itself, then each ancestor upward.
        foreach ($this->selfAndAncestors($categoryId) as $id) {
            if (array_key_exists($id, $overrides)) {
                return $overrides[$id];
            }
        }

        return true;
    }

    /** @return array<int, bool> */
    private function overridesFor(string $slug): array
    {
        if (isset($this->overrideCache[$slug])) {
            return $this->overrideCache[$slug];
        }

        try {
            $rows = $this->db->all(
                'SELECT o.category_id, o.enabled
                   FROM {plugin_category_overrides} o
                   JOIN {plugins} p ON p.id = o.plugin_id
                  WHERE p.slug = ?',
                [$slug]
            );
        } catch (Throwable) {
            return $this->overrideCache[$slug] = [];
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['category_id']] = (bool) $row['enabled'];
        }

        return $this->overrideCache[$slug] = $map;
    }

    /**
     * The category and its ancestors, nearest first.
     *
     * @return list<int>
     */
    private function selfAndAncestors(int $categoryId): array
    {
        $paths = $this->categoryPaths ??= $this->loadCategoryPaths();

        $path = $paths[$categoryId] ?? null;
        if ($path === null) {
            return [$categoryId];
        }

        $ids = array_values(array_filter(
            array_map('intval', explode('/', trim($path, '/'))),
            static fn (int $id): bool => $id > 0
        ));

        // path is root-first; we want nearest-first.
        return array_reverse($ids);
    }

    /** @return array<int, string> */
    private function loadCategoryPaths(): array
    {
        try {
            $paths = [];
            foreach ($this->db->all('SELECT id, path FROM {categories}') as $row) {
                $paths[(int) $row['id']] = (string) $row['path'];
            }
            return $paths;
        } catch (Throwable) {
            return [];
        }
    }

    public function setCategoryOverride(string $slug, int $categoryId, ?bool $enabled): void
    {
        $pluginId = $this->db->value('SELECT id FROM {plugins} WHERE slug = ?', [$slug]);
        if ($pluginId === null) {
            throw new RuntimeException("No plugin named '{$slug}'.");
        }

        if ($enabled === null) {
            // Removing the row restores inheritance, which is a different
            // state from "explicitly off" and must be expressible.
            $this->db->execute(
                'DELETE FROM {plugin_category_overrides} WHERE plugin_id = ? AND category_id = ?',
                [(int) $pluginId, $categoryId]
            );
        } else {
            $this->db->execute(
                'INSERT INTO {plugin_category_overrides} (plugin_id, category_id, enabled) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                [(int) $pluginId, $categoryId, $enabled ? 1 : 0]
            );
        }

        unset($this->overrideCache[$slug]);
    }

    // ------------------------------------------------------------ registration

    public function registerAdminPage(
        string $plugin,
        string $title,
        string $path,
        string $capability,
        int $position
    ): void {
        $this->adminPages[] = [
            'plugin'     => $plugin,
            'title'      => $title,
            'path'       => '/admin/' . ltrim($path, '/'),
            'capability' => $capability,
            'position'   => $position,
        ];
    }

    /** @return list<array{plugin: string, title: string, path: string, capability: string, position: int}> */
    public function adminPages(): array
    {
        $pages = $this->adminPages;
        usort($pages, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        return $pages;
    }

    public function registerCronJob(string $slug, int $intervalSeconds, callable $handler): void
    {
        $this->cronJobs[$slug] = ['interval' => max(60, $intervalSeconds), 'handler' => $handler];
    }

    /** @return array<string, array{interval: int, handler: callable}> */
    public function cronJobs(): array
    {
        return $this->cronJobs;
    }

    /**
     * Everything the plugins admin screen needs.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $this->sync();

        $rows = [];
        foreach ($this->db->all('SELECT * FROM {plugins} ORDER BY name') as $row) {
            $slug = (string) $row['slug'];
            $header = $this->discover()[$slug] ?? null;

            $rows[] = [
                'slug'        => $slug,
                'name'        => (string) $row['name'],
                'version'     => (string) $row['version'],
                'description' => $header?->description ?? '',
                'author'      => $header?->author ?? '',
                'active'      => (int) $row['is_active'] === 1,
                'bundled'     => (int) $row['is_bundled'] === 1,
                'loaded'      => isset($this->loaded[$slug]),
                'missing'     => $header === null,
                'incompatible' => $header?->incompatibilityReason(),
            ];
        }

        return $rows;
    }
}
