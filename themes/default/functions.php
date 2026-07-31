<?php
/**
 * Default theme bootstrap.
 *
 * Receives $theme (a ThemeManager). Everything here is optional — a theme with
 * no functions.php still works, it just cannot alter behaviour.
 */

declare(strict_types=1);

use Portal\Themes\ThemeManager;

/** @var ThemeManager $theme */

/*
 * Emit the customizer values as CSS custom properties, plus the no-flash
 * pre-paint script.
 *
 * The script matters more than it looks. Without it, a page renders with the
 * stylesheet's built-in colours for one frame before the custom properties
 * apply, and the resulting flash of the wrong palette is the single most
 * noticeable rendering defect a themed site can have. Writing the variables
 * inline in <head>, before any body content, avoids it entirely — no
 * JavaScript, no localStorage round-trip, no flash.
 */
add_action('head', static function () use ($theme): void {
    $css = $theme->cssVariables();
    if ($css !== '') {
        echo "<style id=\"portal-theme-vars\">\n{$css}\n</style>\n";
    }
}, 1);

/*
 * Respect the customizer's per-page setting when the core listing asks how
 * many items to show. Clamped, because a theme should not be able to make the
 * site request ten thousand videos in one query.
 */
add_filter('videos_per_page', static function (int $perPage) use ($theme): int {
    $configured = (int) ($theme->setting('per-page') ?? 0);
    return $configured > 0 ? max(1, min(100, $configured)) : $perPage;
});

/*
 * Let the customizer turn off the continue-watching row without editing a
 * template.
 */
add_filter('show_continue_watching', static function (bool $show) use ($theme): bool {
    return $theme->setting('show-continue-watching') === '0' ? false : $show;
});

add_filter('site_name', static function (string $name) use ($theme): string {
    $configured = trim((string) $theme->setting('site_name', ''));
    return $configured !== '' ? $configured : $name;
});
