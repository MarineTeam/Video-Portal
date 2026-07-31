<?php

declare(strict_types=1);

namespace Portal\Themes;

/**
 * A theme's theme.json.
 *
 * Themes use a JSON manifest rather than the header-comment style plugins use,
 * because a theme's most important content is its customizer schema — a nested
 * structure that would be miserable to express in a docblock.
 *
 * Example:
 * {
 *   "name": "Default",
 *   "version": "1.0.0",
 *   "author": "...",
 *   "parent": null,
 *   "supports": ["dark-mode", "custom-logo"],
 *   "customizer": {
 *     "colors": {
 *       "label": "Colours",
 *       "settings": {
 *         "accent": { "type": "color", "label": "Accent", "default": "#38bdf8" }
 *       }
 *     }
 *   }
 * }
 */
final class ThemeManifest
{
    /**
     * @param list<string>                                        $supports
     * @param array<string, array{label: string, settings: array<string, array<string, mixed>>}> $customizer
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version = '1.0.0',
        public readonly string $author = '',
        public readonly string $description = '',
        public readonly ?string $parent = null,
        public readonly array $supports = [],
        public readonly array $customizer = [],
        public readonly bool $bundled = false,
    ) {
    }

    public static function fromDirectory(string $directory, string $slug): ?self
    {
        $file = $directory . '/theme.json';
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // A malformed theme.json must not be fatal: a theme with a typo in
            // it should be listed as broken, not crash the themes screen.
            error_log("Portal: theme '{$slug}' has an invalid theme.json.");
            return null;
        }

        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        if ($name === '') {
            return null;
        }

        $parent = isset($data['parent']) && is_string($data['parent']) && $data['parent'] !== ''
            ? self::sanitizeSlug($data['parent'])
            : null;

        // A theme naming itself as its own parent would loop forever in
        // template resolution.
        if ($parent === $slug) {
            $parent = null;
        }

        return new self(
            slug:        $slug,
            name:        $name,
            version:     isset($data['version']) && is_string($data['version']) ? $data['version'] : '1.0.0',
            author:      isset($data['author']) && is_string($data['author']) ? $data['author'] : '',
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : '',
            parent:      $parent,
            supports:    array_values(array_filter(
                (array) ($data['supports'] ?? []),
                static fn ($s): bool => is_string($s)
            )),
            customizer:  is_array($data['customizer'] ?? null) ? $data['customizer'] : [],
            bundled:     $slug === 'default',
        );
    }

    public static function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = (string) preg_replace('/[^a-z0-9_-]/', '', $slug);
        return substr($slug, 0, 64);
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->supports, true);
    }

    /**
     * Flatten the customizer schema into key => definition.
     *
     * The nested section structure is for presentation; storage and lookup want
     * a flat map, and flattening in one place means the two cannot drift.
     *
     * @return array<string, array<string, mixed>>
     */
    public function settingDefinitions(): array
    {
        $flat = [];

        foreach ($this->customizer as $section) {
            if (!is_array($section) || !isset($section['settings']) || !is_array($section['settings'])) {
                continue;
            }
            foreach ($section['settings'] as $key => $definition) {
                if (is_string($key) && is_array($definition)) {
                    $flat[$key] = $definition;
                }
            }
        }

        return $flat;
    }

    /** @return array<string, string> */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->settingDefinitions() as $key => $definition) {
            if (isset($definition['default']) && is_scalar($definition['default'])) {
                $defaults[$key] = (string) $definition['default'];
            }
        }
        return $defaults;
    }
}
