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

    // -------------------------------------------------------- saved videos

    /**
     * The most expensive mistake this file could make, pinned.
     *
     * Bumping VERSION sweeps the shell cache, which is right — it is
     * disposable and rebuilt from the network in a second. If the saved-video
     * cache name carried the version too, every worker update would throw away
     * hundreds of megabytes somebody chose to keep, quite possibly over a
     * metered connection, with no warning and no way back.
     *
     * So the video cache is versioned SEPARATELY and deliberately does not
     * track VERSION. This test fails the moment somebody "tidies" that up.
     */
    public function testUpdatingTheWorkerCannotDiscardSavedVideos(): void
    {
        self::assertStringNotContainsString(
            'portal-shell-',
            ServiceWorker::VIDEO_CACHE,
            'the activate sweep deletes every portal-shell- cache, saved videos included'
        );

        // And the sweep is still narrow enough that it cannot reach it.
        self::assertStringNotContainsString(
            "caches.delete('" . ServiceWorker::VIDEO_CACHE . "')",
            ServiceWorker::script()
        );
    }

    /**
     * A saved video is served from the device and never from the network.
     *
     * There is no server route behind this path — nothing answers it — so a
     * request that fell through to the network would hang until it timed out
     * and then fail with something unhelpful.
     */
    public function testSavedVideosAreAnsweredWithoutTheNetwork(): void
    {
        $js = ServiceWorker::script();

        self::assertStringContainsString(ServiceWorker::VIDEO_PREFIX, $js);
        self::assertStringContainsString('Not saved on this device.', $js);
        self::assertStringContainsString('status: 404', $js);
    }

    /**
     * Range support, which IS the feature.
     *
     * A media element does not fetch a file, it asks for byte ranges, and it
     * asks for a new one every time somebody drags the scrubber. Answering a
     * range request with the whole body and a 200 makes Safari refuse the file
     * and leaves Chrome unable to seek — so a "saved" video plays from the
     * start, once, and cannot be moved through. That is the difference between
     * a download and offline playback, and it is invisible to any check that
     * only asks whether the file came back.
     */
    public function testItAnswersRangeRequests(): void
    {
        $js = ServiceWorker::script();

        self::assertStringContainsString('status: 206', $js);
        self::assertStringContainsString('Content-Range', $js);
        self::assertStringContainsString('Accept-Ranges', $js);

        // An unsatisfiable range is 416 with the real length. A 200 there makes
        // the player retry the same impossible range forever.
        self::assertStringContainsString('status: 416', $js);
    }

    /**
     * The body is streamed, not buffered.
     *
     * `response.arrayBuffer()` is shorter and allocates the entire file —
     * several hundred megabytes for a sermon, on the phone where this feature
     * is actually used. Nothing in a test can measure that, so the shape is
     * asserted instead.
     */
    public function testTheBodyIsNeverBufferedWhole(): void
    {
        $js = ServiceWorker::script();

        self::assertStringContainsString('ReadableStream', $js);
        self::assertStringNotContainsString('arrayBuffer', $js);
    }

    /**
     * The skip loop, which shipped broken and was caught in a browser.
     *
     * A ReadableStream calls `pull()` again only when it is asked for more
     * data. The first version read one chunk per pull and returned empty when
     * the chunk fell entirely before the requested range — satisfying nobody,
     * provoking nothing, and leaving the response hung forever.
     *
     * It passed every assertion in this file. It passed the smoke checks. It
     * even WORKED for playback from the start, because the opening bytes are in
     * the first chunk and no skipping is needed — so only seeking hung, on a
     * feature whose entire purpose is seeking.
     *
     * This asserts the shape rather than the behaviour, which is all PHP can
     * see. The behaviour was proved by driving the generated function in a real
     * browser against a body delivered in chunks of 1, 7, 64, 333, 1024 and
     * 5000 bytes; before the fix every case that had to skip a chunk hung, and
     * after it all twenty-four checks passed byte for byte.
     */
    public function testTheSkipLoopCannotResolveWithoutEnqueueing(): void
    {
        $js = ServiceWorker::script();

        self::assertStringContainsString('function step()', $js, 'the skip must loop inside one pull');

        /*
         * Two skip paths — a chunk before the range, and a slice that turns out
         * to be empty — and BOTH have to continue rather than return. The
         * second one is the easier to lose in a later edit, because it looks
         * like it cannot happen.
         */
        self::assertMatchesRegularExpression(
            '/if \(seen <= start\) \{\s*return step\(\);/',
            $js,
            'a chunk before the range must keep reading, not resolve empty'
        );

        self::assertMatchesRegularExpression(
            '/if \(to <= from\) \{\s*return step\(\);/',
            $js,
            'an empty slice must keep reading, not resolve empty'
        );
    }

    /**
     * The worker READS the video cache and never writes to it.
     *
     * Saving is the page's job, where there is a person to show progress to
     * and a way to report a refusal. A worker that could also write would be a
     * second path into the same cache with no way to tell the two apart when
     * something arrives half-finished.
     */
    public function testTheWorkerNeverWritesToTheVideoCache(): void
    {
        self::assertStringNotContainsString('cache.put', ServiceWorker::script());
    }
}
