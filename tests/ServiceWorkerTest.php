<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\ServiceWorker;

/**
 * The one service worker.
 *
 * A service worker is the single piece of this product that can serve one
 * person's page to another, so what it must NOT do is worth pinning as
 * carefully as what it does. These read the generated script, which is the
 * only thing that can be checked without a browser — the behaviour itself
 * needs a real one, and that is stated rather than implied.
 */
final class ServiceWorkerTest extends TestCase
{
    public function testItCachesOnlyTheOfflinePage(): void
    {
        $js = ServiceWorker::script();

        self::assertStringContainsString(ServiceWorker::OFFLINE_URL, $js);

        /*
         * cache.add / cache.put are the only ways anything enters the cache.
         * Exactly one call, and it is the offline page — if a second appears,
         * somebody has started caching content and this test should say so
         * before a members-only page is served to a stranger.
         */
        self::assertSame(1, substr_count($js, 'cache.add'), 'something else is being cached');
        self::assertStringNotContainsString('cache.put', $js);
    }

    /**
     * The fetch handler must ignore everything that is not a plain navigation.
     *
     * Without both guards it would intercept API calls and form posts, and a
     * worker that answers a POST from cache is a worker that loses somebody's
     * data.
     */
    public function testItOnlyInterceptsNavigations(): void
    {
        $js = ServiceWorker::script();

        self::assertStringContainsString("request.method !== 'GET'", $js);
        self::assertStringContainsString("request.mode !== 'navigate'", $js);
    }

    /**
     * Network first, cache only on failure.
     *
     * The order is the whole safety property: `fetch(request).catch(...)` can
     * only ever serve the cached page when the network has already failed. A
     * cache-first worker would serve the offline page to somebody who is
     * online.
     */
    public function testTheNetworkIsTriedFirst(): void
    {
        $js = ServiceWorker::script();

        self::assertMatchesRegularExpression(
            '/fetch\(request\)\s*\.catch\(/',
            $js,
            'the cache must only be reached after the network fails'
        );
    }

    /** Sweeping old caches must not touch anything it did not create. */
    public function testItOnlySweepsItsOwnCaches(): void
    {
        $js = ServiceWorker::script();

        self::assertStringContainsString("name.indexOf('portal-shell-') === 0", $js);
    }

    public function testPluginsCanAddToIt(): void
    {
        $js = ServiceWorker::script("self.addEventListener('push', function () {});");

        self::assertStringContainsString("addEventListener('push'", $js);
        // And the core half survives alongside it.
        self::assertStringContainsString(ServiceWorker::OFFLINE_URL, $js);
    }

    /** Nothing appended means nothing appended — no stray marker section. */
    public function testNoPluginsMeansNoAddedSection(): void
    {
        self::assertStringNotContainsString('added by plugins', ServiceWorker::script());
        self::assertStringNotContainsString('added by plugins', ServiceWorker::script('   '));
    }

    /** The cache name carries the version, so activate can sweep an old one. */
    public function testTheCacheIsVersioned(): void
    {
        self::assertStringContainsString(ServiceWorker::VERSION, ServiceWorker::CACHE);
        self::assertStringContainsString(ServiceWorker::CACHE, ServiceWorker::script());
    }
}
