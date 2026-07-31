<?php

declare(strict_types=1);

namespace Portal;

use RuntimeException;

/**
 * A deliberately tiny service container.
 *
 * Enough to make services lazy and swappable (which matters a great deal for
 * the provider abstraction) without dragging in a dependency-injection library
 * that has to be vendored and understood by anyone reading this code.
 */
final class Container
{
    private static ?self $instance = null;

    /** @var array<string, callable(self):mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

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
     * Bind a lazy singleton. The factory runs at most once.
     *
     * @param callable(self):mixed $factory
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->resolved[$id]);
    }

    /** Bind an already-constructed value. */
    public function set(string $id, mixed $value): void
    {
        $this->resolved[$id] = $value;
        unset($this->factories[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->resolved[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Nothing is bound to '{$id}' in the container.");
        }
        return $this->resolved[$id] = ($this->factories[$id])($this);
    }

    /**
     * Drop a resolved instance so the next get() rebuilds it.
     *
     * Used when an admin switches providers mid-request: the old provider
     * instance is holding stale credentials and must not be reused.
     */
    public function forget(string $id): void
    {
        unset($this->resolved[$id]);
    }
}
