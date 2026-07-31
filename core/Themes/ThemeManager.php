<?php

declare(strict_types=1);

namespace Portal\Themes;

use Portal\Config;
use Portal\Db;
use Portal\Plugins\Hooks;
use Throwable;

/**
 * Discovers themes, tracks which is active, and holds customizer values.
 *
 * The bundled `default` theme is special: it is the final fallback in template
 * resolution and cannot be deleted. Everything else is optional decoration
 * layered on top of it, which is what lets a third-party theme ship three files
 * and still render every page on the site.
 */
final class ThemeManager
{
    private const DEFAULT_SLUG = 'default';

    /** @var array<string, ThemeManifest>|null */
    private ?array $discovered = null;

    private ?ThemeManifest $active = null;
    private ?TemplateLoader $loader = null;

    /** @var array<string, array<string, string>> theme slug => key => value */
    private array $settingsCache = [];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Hooks $hooks,
    ) {
    }

    // ------------------------------------------------------------- discovery

    /** @return array<string, ThemeManifest> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $found = [];

        foreach ((array) glob(PORTAL_THEMES . '/*', GLOB_ONLYDIR) as $directory) {
            if (!is_string($directory)) {
                continue;
            }

            $slug = ThemeManifest::sanitizeSlug(basename($directory));
            if ($slug === '' || $slug !== basename($directory)) {
                continue;
            }

            $manifest = ThemeManifest::fromDirectory($directory, $slug);
            if ($manifest !== null) {
                $found[$slug] = $manifest;
            }
        }

        ksort($found);
        return $this->discovered = $found;
    }

    /**
     * Record themes found on disk. Themes install by copying a folder, so the
     * database follows the filesystem rather than the other way round.
     */
    public function sync(): void
    {
        foreach ($this->discover() as $slug => $manifest) {
            $this->db->execute(
                'INSERT INTO {themes} (slug, name, version, parent_slug, is_active, is_bundled, installed_at)
                 VALUES (?, ?, ?, ?, 0, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    version = VALUES(version),
                    parent_slug = VALUES(parent_slug)',
                [$slug, $manifest->name, $manifest->version, $manifest->parent, $manifest->bundled ? 1 : 0]
            );
        }
    }

    // ---------------------------------------------------------------- active

    public function activeSlug(): string
    {
        try {
            $slug = $this->db->value('SELECT slug FROM {themes} WHERE is_active = 1 LIMIT 1');
        } catch (Throwable) {
            // A themes-table failure must still render a page.
            return self::DEFAULT_SLUG;
        }

        if (!is_string($slug) || $slug === '') {
            return self::DEFAULT_SLUG;
        }

        // Guard against a row pointing at a folder someone deleted over FTP.
        return isset($this->discover()[$slug]) ? $slug : self::DEFAULT_SLUG;
    }

    public function active(): ThemeManifest
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $slug = $this->activeSlug();
        $themes = $this->discover();

        if (isset($themes[$slug])) {
            return $this->active = $themes[$slug];
        }

        if (isset($themes[self::DEFAULT_SLUG])) {
            return $this->active = $themes[self::DEFAULT_SLUG];
        }

        // Nothing on disk at all. Return a stub so the caller gets a sensible
        // error page rather than a null dereference.
        return $this->active = new ThemeManifest(
            slug: self::DEFAULT_SLUG,
            name: 'Default (missing)',
            bundled: true,
        );
    }

    /** @return array{ok: bool, message: string} */
    public function activate(string $slug): array
    {
        $slug = ThemeManifest::sanitizeSlug($slug);
        $themes = $this->discover();

        if (!isset($themes[$slug])) {
            return ['ok' => false, 'message' => "No theme named '{$slug}' was found in the themes folder."];
        }

        $manifest = $themes[$slug];

        // A parent that is not installed means half the templates resolve
        // straight to default, which looks like a broken theme rather than a
        // missing dependency. Say which it is.
        if ($manifest->parent !== null && !isset($themes[$manifest->parent])) {
            return [
                'ok' => false,
                'message' => sprintf(
                    '%s is a child of "%s", which is not installed. Install the parent theme first.',
                    $manifest->name,
                    $manifest->parent
                ),
            ];
        }

        $this->sync();

        $this->db->transaction(function () use ($slug): void {
            $this->db->execute('UPDATE {themes} SET is_active = 0');
            $this->db->execute('UPDATE {themes} SET is_active = 1 WHERE slug = ?', [$slug]);
        });

        // Reset derived state; the rest of this request should see the new one.
        $this->active = null;
        $this->loader = null;

        $this->hooks->doAction('theme_activated', $slug);

        return ['ok' => true, 'message' => "{$manifest->name} is now the active theme."];
    }

    // -------------------------------------------------------------- templates

    /**
     * The template loader for the active theme.
     *
     * Search path is active theme, then parent (one level — deeper chains are
     * more confusing than useful), then always the bundled default.
     */
    public function loader(): TemplateLoader
    {
        if ($this->loader !== null) {
            return $this->loader;
        }

        $active = $this->active();
        $path = [];

        $path[] = PORTAL_THEMES . '/' . $active->slug;

        if ($active->parent !== null) {
            $path[] = PORTAL_THEMES . '/' . $active->parent;
        }

        $default = PORTAL_THEMES . '/' . self::DEFAULT_SLUG;
        if (!in_array($default, $path, true)) {
            $path[] = $default;
        }

        /** @var list<string> $path */
        $path = $this->hooks->applyFilters('theme_search_path', $path, $active->slug);

        return $this->loader = new TemplateLoader($path, $this->hooks);
    }

    /**
     * Load the active theme's functions.php, if it has one.
     *
     * Contained the same way plugins are: a theme that throws on load must not
     * white-screen the site, because switching themes back requires reaching
     * the admin area that just stopped rendering.
     */
    public function boot(): void
    {
        $active = $this->active();

        foreach (array_reverse($this->loader()->searchPath()) as $directory) {
            $file = $directory . '/functions.php';
            if (!is_file($file)) {
                continue;
            }

            try {
                $theme = $this;
                (static function (string $file, ThemeManager $theme): void {
                    require_once $file;
                })($file, $this);
            } catch (Throwable $e) {
                error_log(sprintf(
                    "Portal: theme '%s' functions.php failed: %s in %s:%d",
                    $active->slug,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
        }
    }

    // -------------------------------------------------------------- settings

    /**
     * A customizer value, falling back to the manifest default.
     *
     * Values are stored per theme, so switching away and back restores the
     * settings someone spent an afternoon on.
     */
    public function setting(string $key, ?string $default = null): ?string
    {
        $slug = $this->active()->slug;
        $stored = $this->settings($slug);

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return $this->active()->defaults()[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function settings(string $slug): array
    {
        if (isset($this->settingsCache[$slug])) {
            return $this->settingsCache[$slug];
        }

        try {
            $rows = $this->db->all(
                'SELECT `key`, `value` FROM {theme_settings} WHERE theme_slug = ?',
                [$slug]
            );
        } catch (Throwable) {
            return $this->settingsCache[$slug] = [];
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        return $this->settingsCache[$slug] = $map;
    }

    /**
     * Save customizer values.
     *
     * Only keys the theme actually declares are stored — a stale key from a
     * previous version of the theme would otherwise linger forever and show up
     * in the generated CSS.
     *
     * @param array<string, string> $values
     */
    public function saveSettings(string $slug, array $values): void
    {
        $manifest = $this->discover()[$slug] ?? null;
        if ($manifest === null) {
            return;
        }

        $known = $manifest->settingDefinitions();

        foreach ($values as $key => $value) {
            if (!isset($known[$key])) {
                continue;
            }

            $value = $this->sanitizeValue($known[$key], (string) $value);

            $this->db->execute(
                'INSERT INTO {theme_settings} (theme_slug, `key`, `value`, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
                [$slug, $key, $value]
            );
        }

        unset($this->settingsCache[$slug]);
    }

    /**
     * Validate a customizer value against its declared type.
     *
     * These values are interpolated into a <style> block, so a colour that is
     * not a colour is a CSS injection vector. Anything that fails validation
     * falls back to the declared default rather than being stored.
     *
     * @param array<string, mixed> $definition
     */
    private function sanitizeValue(array $definition, string $value): string
    {
        $type = isset($definition['type']) && is_string($definition['type']) ? $definition['type'] : 'text';
        $default = isset($definition['default']) && is_scalar($definition['default'])
            ? (string) $definition['default']
            : '';

        $value = trim($value);

        return match ($type) {
            'color' => preg_match('/^#[0-9a-f]{3}([0-9a-f]{3}([0-9a-f]{2})?)?$/i', $value) === 1
                ? strtolower($value)
                : $default,

            'number' => is_numeric($value) ? $value : $default,

            'bool' => in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? '1' : '0',

            'select' => in_array($value, array_map('strval', (array) ($definition['choices'] ?? [])), true)
                ? $value
                : $default,

            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : $default,

            // Free text still gets stripped of anything that could close the
            // style block or open a script.
            default => mb_substr(strip_tags($value), 0, 500),
        };
    }

    /**
     * Customizer values as CSS custom properties for the document head.
     *
     * Emitting variables rather than rules keeps theming declarative: a theme's
     * stylesheet references var(--accent) and never needs to know how the value
     * was chosen.
     */
    public function cssVariables(): string
    {
        $active = $this->active();
        $values = $this->settings($active->slug) + $active->defaults();

        $declarations = [];
        foreach ($values as $key => $value) {
            if ($value === '') {
                continue;
            }
            $name = (string) preg_replace('/[^a-z0-9-]/', '', str_replace('_', '-', strtolower($key)));
            if ($name === '') {
                continue;
            }
            // Value is already type-validated on save; escape defensively in
            // case a row predates a schema change.
            $declarations[] = sprintf('--%s: %s;', $name, str_replace(['<', '>', '"', ';'], '', $value));
        }

        if ($declarations === []) {
            return '';
        }

        return ":root{\n  " . implode("\n  ", $declarations) . "\n}";
    }

    /**
     * Everything the themes admin screen needs.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $this->sync();
        $activeSlug = $this->activeSlug();
        $themes = $this->discover();

        $rows = [];
        foreach ($themes as $slug => $manifest) {
            $rows[] = [
                'slug'          => $slug,
                'name'          => $manifest->name,
                'version'       => $manifest->version,
                'author'        => $manifest->author,
                'description'   => $manifest->description,
                'parent'        => $manifest->parent,
                'parentMissing' => $manifest->parent !== null && !isset($themes[$manifest->parent]),
                'active'        => $slug === $activeSlug,
                'bundled'       => $manifest->bundled,
                'screenshot'    => is_file(PORTAL_THEMES . '/' . $slug . '/screenshot.png')
                    ? $this->config->url('/theme-asset/' . $slug . '/screenshot.png')
                    : null,
            ];
        }

        return $rows;
    }
}
