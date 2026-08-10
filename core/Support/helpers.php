<?php
/**
 * Global helpers.
 *
 * Kept deliberately small. The hook functions are global on purpose — plugin
 * and theme authors write `add_action('init', ...)` the way they would in
 * WordPress, without importing anything.
 */

declare(strict_types=1);

use Portal\Container;
use Portal\Plugins\Hooks;

if (!function_exists('add_action')) {
    /** Register a callback to run when $hook fires. */
    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        Hooks::instance()->addAction($hook, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    /** Fire $hook. Return values are ignored; exceptions are swallowed and logged. */
    function do_action(string $hook, mixed ...$args): void
    {
        Hooks::instance()->doAction($hook, ...$args);
    }
}

if (!function_exists('add_filter')) {
    /** Register a callback that transforms a value when $hook is applied. */
    function add_filter(string $hook, callable $callback, int $priority = 10): void
    {
        Hooks::instance()->addFilter($hook, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    /** Pass $value through every callback registered on $hook, in priority order. */
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return Hooks::instance()->applyFilters($hook, $value, ...$args);
    }
}

if (!function_exists('remove_action')) {
    function remove_action(string $hook, callable $callback, int $priority = 10): void
    {
        Hooks::instance()->removeAction($hook, $callback, $priority);
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, callable $callback, int $priority = 10): void
    {
        Hooks::instance()->removeFilter($hook, $callback, $priority);
    }
}

if (!function_exists('app')) {
    /** Resolve a service out of the container. */
    function app(?string $id = null): mixed
    {
        $c = Container::instance();
        return $id === null ? $c : $c->get($id);
    }
}

if (!function_exists('e')) {
    /** Escape for HTML output. Every template uses this. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset_url')) {
    /**
     * A static asset's URL, stamped so a browser re-fetches it after a deploy.
     *
     * This exists because of a real afternoon lost to it. A fix to upload.js
     * was written, tested, released and pulled onto the live host — and the
     * browser went on running the previous copy, because /assets/upload.js is
     * a plain static file and nothing in the URL ever changed. The network tab
     * showed a request with the old headers, which reads as "the fix does not
     * work" rather than "the fix is not loaded", and the two are impossible to
     * tell apart from the outside.
     *
     * The stamp is the file's modification time, which is what `git pull`
     * changes and what nothing else does. A content hash would be marginally
     * more correct — it survives a touch with no edit — and costs a read of
     * every asset on every page render, which is the wrong trade on a shared
     * host for a difference nobody would notice.
     *
     * Deliberately NOT the app version: a fix inside 1.0.0 has to reach people
     * too, and that is exactly the case this was written for.
     */
    function asset_url(string $url, ?string $absolutePath = null): string
    {
        $absolutePath ??= PORTAL_PUBLIC . '/' . ltrim($url, '/');

        $stamp = @filemtime($absolutePath);

        if ($stamp === false) {
            // An asset that cannot be found is left alone rather than stamped
            // with a guess. It is somebody else's problem to explain, and a
            // made-up version would cache-bust on every single request.
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $stamp;
    }
}
