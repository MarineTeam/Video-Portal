<?php

declare(strict_types=1);

namespace Portal\Plugins;

/**
 * Metadata parsed from a plugin's header comment.
 *
 * The header is a docblock at the top of plugin.php, exactly as WordPress does
 * it. Parsing a comment rather than requiring a separate manifest file means a
 * single-file plugin really is a single file, and the metadata sits where an
 * author will actually keep it up to date.
 */
final class PluginHeader
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version = '0.0.0',
        public readonly string $description = '',
        public readonly string $author = '',
        public readonly string $requiresPortal = '',
        public readonly string $requiresPhp = '',
        public readonly bool $bundled = false,
    ) {
    }

    /**
     * Read the header out of a plugin file.
     *
     * Only the first 8 KB is read: the header is always at the top, and a
     * plugin with a large file should not cost megabytes of memory just to be
     * listed on the plugins screen.
     */
    public static function fromFile(string $path, ?string $fallbackSlug = null): ?self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $contents = (string) fread($handle, 8192);
        fclose($handle);

        return self::fromString($contents, $fallbackSlug, str_starts_with(
            str_replace('\\', '/', $path),
            str_replace('\\', '/', PORTAL_PLUGINS)
        ));
    }

    public static function fromString(string $contents, ?string $fallbackSlug = null, bool $bundled = false): ?self
    {
        // Normalize line endings so a file authored on Windows parses the same.
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        $fields = [];
        foreach (explode("\n", $contents) as $line) {
            // Strip the leading " * " of a docblock line.
            $line = ltrim($line);
            $line = ltrim($line, '*');
            $line = trim($line);

            if ($line === '' || $line === '/**' || $line === '/') {
                continue;
            }

            // Stop at the end of the docblock; anything after is code.
            if (str_contains($line, '*/')) {
                break;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $key = strtolower(str_replace([' ', '-'], '_', trim(substr($line, 0, $colon))));
            $value = trim(substr($line, $colon + 1));

            if ($key !== '' && $value !== '') {
                $fields[$key] = $value;
            }
        }

        $name = $fields['plugin_name'] ?? '';
        if ($name === '') {
            // No Plugin Name means this is not a plugin file. Refusing here is
            // what stops a stray .php in the plugins directory being listed.
            return null;
        }

        $slug = $fields['slug'] ?? $fallbackSlug ?? '';
        if ($slug === '') {
            return null;
        }

        return new self(
            slug:           self::sanitizeSlug($slug),
            name:           $name,
            version:        $fields['version'] ?? '0.0.0',
            description:    $fields['description'] ?? '',
            author:         $fields['author'] ?? '',
            requiresPortal: $fields['requires'] ?? $fields['requires_portal'] ?? '',
            requiresPhp:    $fields['requires_php'] ?? '',
            bundled:        $bundled,
        );
    }

    /**
     * A slug is a directory name and a database key, so it must not contain
     * anything that could escape a path or confuse a query.
     */
    public static function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = (string) preg_replace('/[^a-z0-9_-]/', '', $slug);
        return substr($slug, 0, 64);
    }

    /**
     * Why this plugin cannot run here, or null if it can.
     */
    public function incompatibilityReason(): ?string
    {
        if ($this->requiresPhp !== '' && version_compare(PHP_VERSION, $this->requiresPhp, '<')) {
            return sprintf(
                'Needs PHP %s or newer; this server runs PHP %s.',
                $this->requiresPhp,
                PHP_VERSION
            );
        }

        if ($this->requiresPortal !== '' && version_compare(PORTAL_VERSION, $this->requiresPortal, '<')) {
            return sprintf(
                'Needs Video Portal %s or newer; this site runs %s.',
                $this->requiresPortal,
                PORTAL_VERSION
            );
        }

        return null;
    }
}
