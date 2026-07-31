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
