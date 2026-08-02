<?php

declare(strict_types=1);

namespace Portal\Themes;

use Portal\Plugins\Hooks;
use Throwable;

/**
 * Resolves and renders templates.
 *
 * Template resolution walks three places in order — active theme, its parent,
 * then the bundled default — and returns the first hit. That ordering is what
 * makes overriding work: dropping `video.php` into a theme replaces the core
 * one with no registration step, and deleting it restores the original.
 *
 * The default theme is always the final fallback and ships every template, so
 * resolution can never come up empty for a core template. A theme author only
 * writes the files they actually want to change.
 */
final class TemplateLoader
{
    /** @var array<string, string|null> Resolved paths, memoised per request. */
    private array $cache = [];

    /** @var list<string> Directories searched, nearest first. */
    private array $searchPath;

    /** @param list<string> $searchPath */
    public function __construct(
        array $searchPath,
        private readonly Hooks $hooks,
    ) {
        $this->searchPath = $searchPath;
    }

    /**
     * Find the first template matching any of $candidates.
     *
     * Candidates express the hierarchy from most to least specific, e.g.
     * ['video-sermons', 'video', 'single', 'index'].
     *
     * @param list<string> $candidates
     */
    public function resolve(array $candidates): ?string
    {
        $key = implode('|', $candidates);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        foreach ($candidates as $candidate) {
            $name = $this->sanitize($candidate);
            if ($name === '') {
                continue;
            }

            foreach ($this->searchPath as $directory) {
                $path = $directory . '/' . $name . '.php';
                if (is_file($path)) {
                    return $this->cache[$key] = $path;
                }
            }
        }

        return $this->cache[$key] = null;
    }

    /**
     * Render a template to a string.
     *
     * Output buffering rather than direct echo, so a template that fails
     * halfway does not emit half a page — the buffer is discarded and the
     * caller decides what to show instead.
     *
     * @param list<string>         $candidates
     * @param array<string, mixed> $data extracted into the template's scope
     */
    public function render(array $candidates, array $data = []): string
    {
        $path = $this->resolve($candidates);

        if ($path === null) {
            throw new \RuntimeException(
                'No template found for: ' . implode(', ', $candidates)
                . '. The default theme may be missing or damaged.'
            );
        }

        return $this->renderFile($path, $data);
    }

    /** @param array<string, mixed> $data */
    public function renderFile(string $path, array $data = []): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            // Scoped closure so a template cannot reach $this or the loader's
            // internals, and so $data keys become plain local variables.
            (static function (string $__path, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__path;
            })($path, $data + ['template' => $this]);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            // Unwind any buffers the template opened and did not close.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Render a partial from within a template.
     *
     * Failures are contained: a broken partial leaves a gap rather than taking
     * down the whole page, which matters most for theme-supplied partials on a
     * site whose owner cannot easily edit files.
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $name, array $data = []): string
    {
        try {
            $path = $this->resolve(['partials/' . $name, $name]);
            if ($path === null) {
                error_log("Portal: no partial named '{$name}'.");
                return '';
            }
            return $this->renderFile($path, $data);
        } catch (Throwable $e) {
            error_log("Portal: partial '{$name}' failed: " . $e->getMessage());
            return '';
        }
    }

    public function exists(string $name): bool
    {
        return $this->resolve([$name]) !== null;
    }

    /**
     * Build the candidate list for a piece of content.
     *
     * Mirrors WordPress's template hierarchy closely enough that the mental
     * model transfers.
     *
     * @param array<string, string|null> $context slug, category, etc.
     * @return list<string>
     */
    public function hierarchy(string $type, array $context = []): array
    {
        $candidates = [];

        $slug = isset($context['slug']) ? $this->sanitize((string) $context['slug']) : '';
        $category = isset($context['category']) ? $this->sanitize((string) $context['category']) : '';

        if ($slug !== '') {
            $candidates[] = "{$type}-{$slug}";
        }
        if ($category !== '') {
            $candidates[] = "{$type}-category-{$category}";
        }

        $candidates[] = $type;

        // Generic fallbacks by kind.
        //
        // Series and speaker pages are LISTINGS — a heading, a description, and
        // a grid of videos — so they fall back to archive alongside categories,
        // not to single. archive.php has always named them in its own docblock;
        // routing them to single was a mismatch that cost them pagination and
        // sub-listings, and meant a theme author overriding archive.php did not
        // affect the pages it claimed to cover.
        $candidates = [...$candidates, ...match ($type) {
            'video'                                        => ['single', 'index'],
            'category', 'series', 'speaker', 'tag', 'search' => ['archive', 'index'],
            default                                        => ['index'],
        }];

        /** @var list<string> */
        return $this->hooks->applyFilters('template_hierarchy', array_values(array_unique($candidates)), $type, $context);
    }

    /**
     * Template names are built from user-supplied slugs, so they must never be
     * able to escape the theme directory.
     */
    private function sanitize(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $name = (string) preg_replace('#[^a-z0-9/_-]#i', '', strtolower($name));

        // Reject anything with traversal or an absolute root.
        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
            return '';
        }

        return $name;
    }

    /** @return list<string> */
    public function searchPath(): array
    {
        return $this->searchPath;
    }
}
