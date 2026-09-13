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

    /*
     * Light, dark, or following the device — chosen on /settings and kept in
     * this browser. Inline and in <head> for the same reason as the variables
     * above: set any later and the page paints in the wrong palette for a
     * frame, which on a white-on-dark site is a flash of black at somebody who
     * asked for light.
     *
     * No choice means DARK, the site's own design, not "follow the device".
     * Defaulting to the device would turn the site white on deploy for every
     * visitor whose phone is in light mode, which is a site owner's decision
     * this setting has no business making for them.
     *
     * A theme without a light palette ignores the attribute, so a child theme
     * or a third-party one is unaffected. Storage that throws (some private
     * windows) leaves the default.
     */
    echo <<<'HTML'
    <script>
    (function () {
      var root = document.documentElement;
      var query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;
      var choice = null;
      try { choice = window.localStorage.getItem('portal.theme'); } catch (e) {}
      function apply() {
        var mode = choice === 'light' || choice === 'dark' ? choice
          : (choice === 'system' && query && query.matches ? 'light' : 'dark');
        root.setAttribute('data-theme', mode);

        /* The browser and task-switcher chrome, which is otherwise a dark bar
           above a white page. The site's own colour is kept to restore. */
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
          if (!meta.getAttribute('data-site-color')) { meta.setAttribute('data-site-color', meta.content); }
          meta.content = mode === 'light' ? '#f8fafc' : meta.getAttribute('data-site-color');
        }
      }
      apply();
      /* Again once the meta tag, which comes later in <head>, exists. */
      document.addEventListener('DOMContentLoaded', apply);
      if (query && query.addEventListener) {
        query.addEventListener('change', function () { if (choice === 'system') { apply(); } });
      }
      /* Another tab changed it. */
      window.addEventListener('storage', function (event) {
        if (event.key === 'portal.theme') { choice = event.newValue; apply(); }
      });
      /* This tab changed it: /settings calls this, since `storage` does not
         fire in the tab that made the change. */
      window.portalTheme = { set: function (value) { choice = value; apply(); } };
    })();
    </script>

    HTML;
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
