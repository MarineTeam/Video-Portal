<?php

declare(strict_types=1);

namespace Portal;

use RuntimeException;

/**
 * Two-layer configuration.
 *
 *  1. config.php   — written once by the installer. Holds only what must NOT be
 *                    editable from the admin UI: database credentials, the
 *                    encryption keys, the canonical base URL, and the geo
 *                    country whitelists.
 *  2. settings tbl — everything else, so an admin can change it without FTP.
 *
 * The split is load-bearing, not stylistic:
 *
 *  - BASE_URL lives in the file because emailed share links are built from it.
 *    Deriving it from $_SERVER['HTTP_HOST'] is a host-header-poisoning hole
 *    that was found and fixed once already in the MarineTeamVideos app; we do
 *    not reintroduce it.
 *  - The geo country lists live in the file because an admin who whitelists the
 *    wrong country locks themselves out. Recovery has to be possible over FTP.
 *    Only the on/off toggle is DB-editable.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $file = [];

    /** @var array<string, string|null>|null Lazily loaded from the settings table. */
    private ?array $settings = null;

    private bool $loaded = false;

    public function __construct(private readonly string $path = PORTAL_CONFIG_FILE)
    {
    }

    /** True once the installer has written config.php. */
    public function isInstalled(): bool
    {
        return is_file($this->path);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $this->load();
        return $this->file;
    }

    /**
     * Supply values in memory, without writing config.php.
     *
     * Exists for the installer. Providers are tested on the Services step,
     * after the site address has been collected but before anything is written
     * to disk — and an OIDC provider cannot even describe its callback URL
     * without knowing the base URL. Overlaying lets the wizard hand over what
     * it has gathered so far.
     *
     * Values already read from a real config.php are not overwritten: once the
     * file exists it is authoritative, and nothing should be able to shadow it
     * at runtime.
     *
     * @param array<string, mixed> $values
     */
    public function overlay(array $values): void
    {
        $this->load();

        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->file)) {
                $this->file[(string) $key] = $value;
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return $this->file[$key] ?? $default;
    }

    /**
     * Read a config value as a trimmed string.
     *
     * Credentials pasted into the installer routinely carry a trailing newline.
     * With bunny.net that produces a signature that is silently wrong and a
     * 401 with no useful message, so trimming happens at every read rather
     * than being someone's responsibility to remember.
     */
    public function str(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Comma-separated config value as an uppercased, de-duplicated list.
     * Used for the geo country whitelists.
     *
     * @return list<string>
     */
    public function csv(string $key): array
    {
        $raw = $this->str($key);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_map(
            static fn (string $p): string => strtoupper(trim($p)),
            $parts
        )));
    }

    /**
     * The canonical, externally reachable base URL, without a trailing slash.
     * Never falls back to the request host — see the class docblock.
     */
    public function baseUrl(): string
    {
        $url = rtrim($this->str('base_url'), '/');
        if ($url === '') {
            throw new RuntimeException(
                'base_url is not set in config.php. Emailed links cannot be built without it.'
            );
        }
        return $url;
    }

    public function url(string $path = ''): string
    {
        return $this->baseUrl() . '/' . ltrim($path, '/');
    }

    public function isDebug(): bool
    {
        return $this->bool('debug');
    }

    // ---------------------------------------------------------------- settings

    /**
     * A DB-backed setting. Falls back to $default when the settings table is
     * unreachable, so a database hiccup degrades a preference rather than
     * taking the whole page down.
     */
    public function setting(string $key, ?string $default = null): ?string
    {
        return $this->settings()[$key] ?? $default;
    }

    public function settingBool(string $key, bool $default = false): bool
    {
        $value = $this->setting($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }

    public function settingInt(string $key, int $default = 0): int
    {
        $value = $this->setting($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, mixed> */
    public function settingJson(string $key, array $default = []): array
    {
        $raw = $this->setting($key);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public function setSetting(string $key, ?string $value): void
    {
        Db::instance()->execute(
            'INSERT INTO {settings} (`key`, `value`, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
            [$key, $value]
        );
        $this->settings ??= [];
        $this->settings[$key] = $value;
    }

    /** @param array<string, string|null> $pairs */
    public function setSettings(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->setSetting($key, $value);
        }
    }

    /** Force the next setting() read to hit the database again. */
    public function flushSettings(): void
    {
        $this->settings = null;
    }

    /** @return array<string, string|null> */
    private function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        try {
            $rows = Db::instance()->all('SELECT `key`, `value` FROM {settings}');
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row['key']] = $row['value'] === null ? null : (string) $row['value'];
            }
            return $this->settings = $map;
        } catch (\Throwable) {
            // Fail open: a settings read failure must not blank the site.
            return $this->settings = [];
        }
    }

    // ------------------------------------------------------------------ file IO

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        if (!is_file($this->path)) {
            return;
        }

        /** @psalm-suppress UnresolvableInclude */
        $data = require $this->path;
        if (!is_array($data)) {
            throw new RuntimeException(
                'config.php exists but did not return an array. It may have been truncated — '
                . 'restore it from backup or delete it and re-run the installer.'
            );
        }
        $this->file = $data;
    }

    /**
     * Render a config array as a PHP file.
     *
     * Used by the installer and by the admin screens that legitimately edit
     * file-level config. Values are exported with var_export so quoting and
     * escaping are the language's problem, not ours.
     *
     * @param array<string, mixed> $data
     */
    public static function render(array $data): string
    {
        $lines = [
            '<?php',
            '',
            '/**',
            ' * Video Portal configuration.',
            ' *',
            ' * Written by the installer. Safe to edit by hand — the app re-reads it on',
            ' * every request. Everything NOT in here is editable from the admin UI.',
            ' *',
            ' * Keep this file out of version control and off the public web.',
            ' */',
            '',
            'return [',
        ];

        foreach ($data as $key => $value) {
            $lines[] = sprintf(
                "    %s => %s,",
                var_export((string) $key, true),
                self::exportValue($value)
            );
        }

        $lines[] = '];';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function exportValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = array_map(
                static fn ($v): string => self::exportValue($v),
                $value
            );
            return '[' . implode(', ', $parts) . ']';
        }
        return var_export($value, true);
    }

    /**
     * Atomically write config.php with restrictive permissions.
     *
     * @param array<string, mixed> $data
     */
    public function write(array $data): void
    {
        $tmp = $this->path . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, self::render($data), LOCK_EX) === false) {
            throw new RuntimeException("Could not write to {$tmp}. Check directory permissions.");
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not move config into place at {$this->path}.");
        }
        @chmod($this->path, 0600);

        $this->file = $data;
        $this->loaded = true;

        // Opcache will happily serve the previous (or empty) version otherwise.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }
}
