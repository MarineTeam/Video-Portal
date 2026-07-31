<?php

declare(strict_types=1);

namespace Portal\Plugins;

use Throwable;

/**
 * The action/filter bus, with WordPress semantics.
 *
 * Familiar semantics are the point: anyone who has written a WordPress plugin
 * can write one for this app without learning a new event system. Actions fire
 * and return nothing; filters pass a value through each callback in turn.
 *
 * One deliberate difference from WordPress: a callback that throws is caught,
 * logged, and skipped rather than taking down the request. A third-party plugin
 * with a bug should degrade its own feature, not white-screen a site whose
 * owner has no shell access to disable it. The exception is rethrown when
 * debug mode is on, because during development a silently swallowed error is
 * far worse than a visible one.
 */
final class Hooks
{
    private static ?self $instance = null;

    /**
     * hook => priority => list of callbacks
     *
     * @var array<string, array<int, list<callable>>>
     */
    private array $actions = [];

    /** @var array<string, array<int, list<callable>>> */
    private array $filters = [];

    /** @var array<string, int> How many times each action has fired. */
    private array $fired = [];

    /**
     * Hooks currently executing, to break accidental recursion — a filter that
     * calls something which applies the same filter would otherwise recurse
     * until the stack blows.
     *
     * @var array<string, bool>
     */
    private array $running = [];

    private bool $throwOnError = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Only the test suite should need this. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * In debug mode, let plugin exceptions surface instead of being logged.
     */
    public function throwOnError(bool $throw): void
    {
        $this->throwOnError = $throw;
    }

    // --------------------------------------------------------------- actions

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->actions[$hook][$priority][] = $callback;
    }

    public function removeAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->remove($this->actions, $hook, $callback, $priority);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        $this->fired[$hook] = ($this->fired[$hook] ?? 0) + 1;

        if (!isset($this->actions[$hook]) || isset($this->running[$hook])) {
            return;
        }

        $this->running[$hook] = true;

        try {
            foreach ($this->sorted($this->actions[$hook]) as $callback) {
                try {
                    $callback(...$args);
                } catch (Throwable $e) {
                    $this->handle($hook, $e);
                }
            }
        } finally {
            unset($this->running[$hook]);
        }
    }

    // --------------------------------------------------------------- filters

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][$priority][] = $callback;
    }

    public function removeFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->remove($this->filters, $hook, $callback, $priority);
    }

    /**
     * Pass $value through every callback registered on $hook.
     *
     * A callback that throws is skipped and the value carries on unchanged,
     * so one broken filter cannot blank out content every other filter and the
     * core were happy with.
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (!isset($this->filters[$hook]) || isset($this->running[$hook])) {
            return $value;
        }

        $this->running[$hook] = true;

        try {
            foreach ($this->sorted($this->filters[$hook]) as $callback) {
                try {
                    $filtered = $callback($value, ...$args);

                    // A filter that forgets to return is the single most common
                    // plugin mistake, and silently nulling the value produces a
                    // baffling blank page. Treat it as a no-op and say so.
                    if ($filtered === null && $value !== null) {
                        error_log(
                            "Portal: a callback on filter '{$hook}' returned null; "
                            . 'ignoring it. Filters must return a value.'
                        );
                        continue;
                    }

                    $value = $filtered;
                } catch (Throwable $e) {
                    $this->handle($hook, $e);
                }
            }
        } finally {
            unset($this->running[$hook]);
        }

        return $value;
    }

    // ------------------------------------------------------------ inspection

    public function hasAction(string $hook): bool
    {
        return !empty($this->actions[$hook]);
    }

    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }

    public function didAction(string $hook): int
    {
        return $this->fired[$hook] ?? 0;
    }

    /**
     * Every registered hook name and how many callbacks it has. Powers the
     * plugin developer screen.
     *
     * @return array{actions: array<string, int>, filters: array<string, int>}
     */
    public function inventory(): array
    {
        $count = static function (array $byPriority): int {
            $total = 0;
            foreach ($byPriority as $callbacks) {
                $total += count($callbacks);
            }
            return $total;
        };

        $actions = [];
        foreach ($this->actions as $hook => $byPriority) {
            $actions[$hook] = $count($byPriority);
        }

        $filters = [];
        foreach ($this->filters as $hook => $byPriority) {
            $filters[$hook] = $count($byPriority);
        }

        ksort($actions);
        ksort($filters);

        return ['actions' => $actions, 'filters' => $filters];
    }

    /**
     * Drop every callback a plugin registered.
     *
     * Called on deactivation. Without it, a deactivated plugin's hooks would
     * keep firing for the remainder of the request that deactivated it — which
     * is exactly the request where a broken plugin is most likely to misbehave.
     */
    public function removeAllForPlugin(string $pluginSlug): void
    {
        foreach ([&$this->actions, &$this->filters] as &$registry) {
            foreach ($registry as $hook => $byPriority) {
                foreach ($byPriority as $priority => $callbacks) {
                    $kept = array_values(array_filter(
                        $callbacks,
                        static fn (callable $cb): bool => !self::belongsTo($cb, $pluginSlug)
                    ));

                    if ($kept === []) {
                        unset($registry[$hook][$priority]);
                    } else {
                        $registry[$hook][$priority] = $kept;
                    }
                }
                if (empty($registry[$hook])) {
                    unset($registry[$hook]);
                }
            }
        }
    }

    /**
     * Does this callback live in the plugin's directory?
     *
     * Reflection on the closure's defining file is the only reliable way to
     * attribute a callback, since plugins register plain closures rather than
     * anything that carries an identity.
     */
    private static function belongsTo(callable $callback, string $pluginSlug): bool
    {
        try {
            $reflection = is_array($callback)
                ? new \ReflectionMethod($callback[0], $callback[1])
                : new \ReflectionFunction(\Closure::fromCallable($callback));

            $file = $reflection->getFileName();
            if ($file === false) {
                return false;
            }

            $pluginPath = PORTAL_PLUGINS . DIRECTORY_SEPARATOR . $pluginSlug . DIRECTORY_SEPARATOR;

            return str_starts_with(
                str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file),
                str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pluginPath)
            );
        } catch (Throwable) {
            return false;
        }
    }

    // ----------------------------------------------------------------- internals

    /**
     * @param array<int, list<callable>> $byPriority
     * @return list<callable>
     */
    private function sorted(array $byPriority): array
    {
        ksort($byPriority, SORT_NUMERIC);

        $flat = [];
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as $callback) {
                $flat[] = $callback;
            }
        }

        return $flat;
    }

    /**
     * @param array<string, array<int, list<callable>>> $registry
     */
    private function remove(array &$registry, string $hook, callable $callback, int $priority): void
    {
        if (!isset($registry[$hook][$priority])) {
            return;
        }

        $registry[$hook][$priority] = array_values(array_filter(
            $registry[$hook][$priority],
            static fn (callable $existing): bool => $existing !== $callback
        ));

        if ($registry[$hook][$priority] === []) {
            unset($registry[$hook][$priority]);
        }
        if (empty($registry[$hook])) {
            unset($registry[$hook]);
        }
    }

    private function handle(string $hook, Throwable $e): void
    {
        error_log(sprintf(
            "Portal: a callback on '%s' threw %s: %s in %s:%d",
            $hook,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if ($this->throwOnError) {
            throw $e;
        }
    }
}
