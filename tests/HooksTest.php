<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Hooks;
use RuntimeException;

/**
 * The hook bus is the contract every plugin is written against. If priority
 * ordering or error containment changes, every third-party plugin's behaviour
 * changes with it — so both are pinned here.
 */
final class HooksTest extends TestCase
{
    private Hooks $hooks;

    protected function setUp(): void
    {
        Hooks::reset();
        $this->hooks = Hooks::instance();
    }

    protected function tearDown(): void
    {
        Hooks::reset();
    }

    // --------------------------------------------------------------- actions

    public function testActionsFireWithTheirArguments(): void
    {
        $received = [];

        $this->hooks->addAction('video_published', function (int $id, string $title) use (&$received): void {
            $received = [$id, $title];
        });

        $this->hooks->doAction('video_published', 42, 'A sermon');

        self::assertSame([42, 'A sermon'], $received);
    }

    public function testActionsRunInPriorityOrderThenRegistrationOrder(): void
    {
        $order = [];

        $this->hooks->addAction('init', static function () use (&$order): void { $order[] = 'default-first'; });
        $this->hooks->addAction('init', static function () use (&$order): void { $order[] = 'late'; }, 20);
        $this->hooks->addAction('init', static function () use (&$order): void { $order[] = 'early'; }, 1);
        $this->hooks->addAction('init', static function () use (&$order): void { $order[] = 'default-second'; });

        $this->hooks->doAction('init');

        self::assertSame(['early', 'default-first', 'default-second', 'late'], $order);
    }

    public function testDoActionOnAnUnregisteredHookIsHarmless(): void
    {
        $this->hooks->doAction('nobody_listens', 1, 2, 3);
        self::assertSame(1, $this->hooks->didAction('nobody_listens'));
    }

    public function testRemoveActionUnregisters(): void
    {
        $calls = 0;
        $callback = static function () use (&$calls): void { $calls++; };

        $this->hooks->addAction('init', $callback);
        $this->hooks->doAction('init');
        $this->hooks->removeAction('init', $callback);
        $this->hooks->doAction('init');

        self::assertSame(1, $calls);
    }

    // --------------------------------------------------------------- filters

    public function testFiltersChainInPriorityOrder(): void
    {
        $this->hooks->addFilter('title', static fn (string $t): string => $t . '-b', 20);
        $this->hooks->addFilter('title', static fn (string $t): string => $t . '-a', 10);

        self::assertSame('base-a-b', $this->hooks->applyFilters('title', 'base'));
    }

    public function testFiltersReceiveExtraArguments(): void
    {
        $this->hooks->addFilter(
            'video_title',
            static fn (string $title, int $id): string => "{$title} (#{$id})"
        );

        self::assertSame('Sermon (#7)', $this->hooks->applyFilters('video_title', 'Sermon', 7));
    }

    public function testUnfilteredValuePassesThroughUnchanged(): void
    {
        self::assertSame('untouched', $this->hooks->applyFilters('nothing_registered', 'untouched'));
    }

    /**
     * Forgetting to return is the most common plugin mistake there is. Nulling
     * the value would blank out whatever was being filtered, which on a page
     * title or a video list is a baffling failure to debug.
     */
    public function testFilterReturningNullIsIgnoredRatherThanBlankingTheValue(): void
    {
        $this->hooks->addFilter('title', static function (string $t): void {
            // deliberately returns nothing
        });
        $this->hooks->addFilter('title', static fn (string $t): string => $t . '!', 20);

        self::assertSame('kept!', $this->hooks->applyFilters('title', 'kept'));
    }

    public function testAFilterMayLegitimatelyReturnNullWhenGivenNull(): void
    {
        $this->hooks->addFilter('maybe', static fn (mixed $v): mixed => $v);

        self::assertNull($this->hooks->applyFilters('maybe', null));
    }

    // ------------------------------------------------------- error containment

    /**
     * The property the whole plugin system rests on: someone on shared hosting
     * has no shell to disable a broken plugin with, so a throwing callback must
     * never take the request down.
     */
    public function testAThrowingActionDoesNotStopOtherCallbacks(): void
    {
        $ran = [];

        $this->hooks->addAction('init', static function () use (&$ran): void { $ran[] = 'before'; }, 10);
        $this->hooks->addAction('init', static function (): void {
            throw new RuntimeException('plugin is broken');
        }, 20);
        $this->hooks->addAction('init', static function () use (&$ran): void { $ran[] = 'after'; }, 30);

        $this->hooks->doAction('init');

        self::assertSame(['before', 'after'], $ran);
    }

    public function testAThrowingFilterLeavesTheValueIntactAndChainContinues(): void
    {
        $this->hooks->addFilter('title', static fn (string $t): string => $t . '-one', 10);
        $this->hooks->addFilter('title', static function (string $t): string {
            throw new RuntimeException('plugin is broken');
        }, 20);
        $this->hooks->addFilter('title', static fn (string $t): string => $t . '-three', 30);

        self::assertSame('base-one-three', $this->hooks->applyFilters('title', 'base'));
    }

    /**
     * During development the opposite is wanted: a swallowed exception is far
     * harder to diagnose than a visible one.
     */
    public function testDebugModeRethrows(): void
    {
        $this->hooks->throwOnError(true);
        $this->hooks->addAction('init', static function (): void {
            throw new RuntimeException('surface me');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('surface me');

        $this->hooks->doAction('init');
    }

    // ------------------------------------------------------------- recursion

    public function testRecursiveActionDoesNotOverflowTheStack(): void
    {
        $calls = 0;

        $this->hooks->addAction('recurse', function () use (&$calls): void {
            $calls++;
            $this->hooks->doAction('recurse');
        });

        $this->hooks->doAction('recurse');

        self::assertSame(1, $calls, 'A re-entrant action should be suppressed, not looped.');
    }

    public function testRecursiveFilterDoesNotOverflowTheStack(): void
    {
        $calls = 0;

        $this->hooks->addFilter('recurse', function (string $value) use (&$calls): string {
            $calls++;
            return $this->hooks->applyFilters('recurse', $value . '+');
        });

        $result = $this->hooks->applyFilters('recurse', 'x');

        self::assertSame(1, $calls);
        self::assertSame('x+', $result);
    }

    // ------------------------------------------------------------ inspection

    public function testInventoryCountsCallbacksPerHook(): void
    {
        $this->hooks->addAction('init', static fn () => null);
        $this->hooks->addAction('init', static fn () => null, 20);
        $this->hooks->addFilter('title', static fn (string $t): string => $t);

        $inventory = $this->hooks->inventory();

        self::assertSame(2, $inventory['actions']['init']);
        self::assertSame(1, $inventory['filters']['title']);
    }

    public function testDidActionCountsFirings(): void
    {
        $this->hooks->addAction('init', static fn () => null);

        $this->hooks->doAction('init');
        $this->hooks->doAction('init');

        self::assertSame(2, $this->hooks->didAction('init'));
        self::assertSame(0, $this->hooks->didAction('never'));
    }

    public function testHasActionAndHasFilter(): void
    {
        self::assertFalse($this->hooks->hasAction('init'));
        self::assertFalse($this->hooks->hasFilter('title'));

        $this->hooks->addAction('init', static fn () => null);
        $this->hooks->addFilter('title', static fn (string $t): string => $t);

        self::assertTrue($this->hooks->hasAction('init'));
        self::assertTrue($this->hooks->hasFilter('title'));
    }

    // -------------------------------------------------- global helper functions

    public function testGlobalHelpersReachTheSameBus(): void
    {
        $ran = false;

        add_action('helper_test', static function () use (&$ran): void { $ran = true; });
        do_action('helper_test');

        self::assertTrue($ran);

        add_filter('helper_filter', static fn (string $v): string => $v . '!');

        self::assertSame('hi!', apply_filters('helper_filter', 'hi'));
    }
}
