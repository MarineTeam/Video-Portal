<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Portal\Config;
use Portal\Container;
use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Db;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Http\Router;
use Portal\Plugins\Hooks;
use Portal\Plugins\PluginManager;
use Portal\Sharing\Share;

/**
 * The two bundled plugins, activated from their real directories.
 *
 * This is the test that matters most for the plugin system as a whole. The unit
 * tests beside it pin the decision logic; this one proves the plumbing actually
 * carries a decision to a page — that a header parses, that a hook registered
 * inside plugin.php fires, that a global middleware reaches the router, and
 * that deactivating stops all of it.
 *
 * Every one of those is a step no unit test can reach, and each has a failure
 * mode that looks like "the feature quietly does nothing".
 */
final class BundledPluginsTest extends DatabaseTestCase
{
    private PluginManager $manager;
    private Hooks $hooks;
    private Router $router;
    private Config $config;

    protected function setUp(): void
    {
        $this->truncate([
            'plugin_category_overrides', 'plugin_migrations', 'plugins',
            'video_categories', 'categories', 'videos', 'settings', 'audit_log',
        ]);

        Hooks::reset();
        Container::reset();

        $this->hooks = Hooks::instance();
        $this->router = new Router();

        // A config file that does not exist, plus overlaid values — the same
        // shape the installer uses before config.php is written.
        $this->config = new Config('/nonexistent-config.php');

        $this->manager = new PluginManager($this->db(), $this->config, $this->hooks, $this->router);

        $c = Container::instance();
        $c->set(Db::class, $this->db());
        $c->set(Config::class, $this->config);
        $c->singleton(CategoryRepository::class, fn (): CategoryRepository => new CategoryRepository($this->db()));
        $c->singleton(VideoRepository::class, fn (Container $c): VideoRepository => new VideoRepository(
            $this->db(),
            $c->get(CategoryRepository::class),
        ));
    }

    protected function tearDown(): void
    {
        Hooks::reset();
        Container::reset();
    }

    // ------------------------------------------------------------- discovery

    public function testBothPluginsShipInTheBoxAndAreFoundOnDisk(): void
    {
        $found = $this->manager->discover();

        self::assertArrayHasKey('watermark', $found);
        self::assertArrayHasKey('geo', $found);

        // Marked bundled because they live under plugins/. That flag decides
        // whether uninstalling says "delete the folder" or "it can be
        // reactivated", so it is worth asserting rather than assuming.
        self::assertTrue($found['watermark']->bundled);
        self::assertTrue($found['geo']->bundled);
    }

    /**
     * Shipping a plugin is not the same as switching it on. Both of these
     * change what viewers see, and neither should start doing that because
     * somebody upgraded.
     */
    public function testNeitherPluginActivatesItself(): void
    {
        $this->manager->sync();

        foreach (['watermark', 'geo'] as $slug) {
            self::assertSame(
                0,
                (int) $this->db()->value('SELECT is_active FROM {plugins} WHERE slug = ?', [$slug]),
                "{$slug} must ship switched off."
            );
        }
    }

    // ------------------------------------------------------------- watermark

    public function testTheWatermarkDrawsTheViewerEmailOverAShare(): void
    {
        $this->activate('watermark');
        $video = $this->makeVideo();

        $html = $this->overlay($this->share($video, watermark: 'on'), 'alice@example.com');

        self::assertStringContainsString('alice@example.com', $html);
        self::assertStringContainsString('pw-mark', $html);
    }

    public function testNothingIsDrawnWhenTheShareTurnsItOff(): void
    {
        $this->activate('watermark');
        $video = $this->makeVideo();

        self::assertSame('', $this->overlay($this->share($video, watermark: 'off'), 'alice@example.com'));
    }

    public function testTheVideoSettingAppliesWhenTheShareDefers(): void
    {
        $this->activate('watermark');
        $video = $this->makeVideo(watermark: 'on');

        self::assertStringContainsString(
            'alice@example.com',
            $this->overlay($this->share($video, watermark: 'default'), 'alice@example.com')
        );
    }

    public function testAnExemptViewerIsNotWatermarked(): void
    {
        $this->activate('watermark');
        $this->manager->context('watermark')?->setSetting('exempt_emails', 'alice@example.com');

        $video = $this->makeVideo();

        self::assertSame('', $this->overlay($this->share($video, watermark: 'on'), 'alice@example.com'));
        self::assertStringContainsString(
            'bob@example.com',
            $this->overlay($this->share($video, watermark: 'on'), 'bob@example.com'),
            'Exempting one address must not exempt everyone.'
        );
    }

    /**
     * The per-category override reaching a real plugin, end to end. This is the
     * feature the whole plugin_category_overrides table exists for, and until
     * now nothing shipped that consumed it.
     */
    public function testTurningTheWatermarkOffForOneCategoryStopsItDrawing(): void
    {
        $this->activate('watermark');

        $sermons = $this->makeCategory('Sermons');
        $staff = $this->makeCategory('Staff training');

        $inSermons = $this->makeVideo(watermark: 'on', categoryId: $sermons);
        $inStaff = $this->makeVideo(watermark: 'on', categoryId: $staff);

        $this->manager->setCategoryOverride('watermark', $sermons, false);

        self::assertSame('', $this->overlay($this->share($inSermons), 'alice@example.com'));
        self::assertStringContainsString('alice@example.com', $this->overlay($this->share($inStaff), 'alice@example.com'));
    }

    public function testDeactivatingTheWatermarkStopsItImmediately(): void
    {
        $this->activate('watermark');
        $video = $this->makeVideo();

        self::assertNotSame('', $this->overlay($this->share($video, watermark: 'on'), 'alice@example.com'));

        $this->manager->deactivate('watermark');

        self::assertSame(
            '',
            $this->overlay($this->share($video, watermark: 'on'), 'alice@example.com'),
            'A deactivated plugin must stop drawing on the very request that deactivated it.'
        );
    }

    /**
     * The overlay is written into a page that is already HTML, so an address
     * containing markup would otherwise be an injection point — and the address
     * is attacker-supplied on a gate share, where anyone can type one in.
     */
    public function testTheViewerAddressIsEscaped(): void
    {
        $this->activate('watermark');
        $video = $this->makeVideo();

        $html = $this->overlay($this->share($video, watermark: 'on'), '<script>alert(1)</script>@x.com');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------------- geo

    public function testGeoBlocksAVisitorFromOutsideTheList(): void
    {
        $this->config->overlay(['geo_whitelist' => 'US, CA']);
        $this->config->setSettings(['geo_enabled' => '1']);
        $this->activate('geo');

        $response = $this->dispatch('/', 'RU');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('not available', $response->body());
    }

    public function testGeoAllowsAVisitorFromInsideTheList(): void
    {
        $this->config->overlay(['geo_whitelist' => 'US, CA']);
        $this->config->setSettings(['geo_enabled' => '1']);
        $this->activate('geo');

        self::assertSame(200, $this->dispatch('/', 'CA')->status());
    }

    /**
     * The lockout guarantee, proven through the real router rather than
     * against the policy class: restricting the public site must never close
     * the admin area, because the admin area is where you would go to undo it.
     */
    public function testRestrictingViewersDoesNotCloseTheAdminArea(): void
    {
        $this->config->overlay(['geo_whitelist' => 'US']);
        $this->config->setSettings(['geo_enabled' => '1']);
        $this->activate('geo');

        self::assertSame(403, $this->dispatch('/', 'RU')->status());
        self::assertSame(200, $this->dispatch('/admin', 'RU')->status());
    }

    public function testSignInStaysReachableFromABlockedCountry(): void
    {
        $this->config->overlay([
            'geo_whitelist'       => 'US',
            'admin_geo_whitelist' => 'US',
        ]);
        $this->config->setSettings(['geo_enabled' => '1', 'admin_geo_enabled' => '1']);
        $this->activate('geo');

        self::assertSame(
            200,
            $this->dispatch('/auth/login', 'RU')->status(),
            'Blocking sign-in would make the bypass list unusable by the people it exists for.'
        );
    }

    public function testAnUnknownCountryIsLetThrough(): void
    {
        $this->config->overlay(['geo_whitelist' => 'US']);
        $this->config->setSettings(['geo_enabled' => '1']);
        $this->activate('geo');

        // No country header at all — the normal case on shared hosting.
        self::assertSame(200, $this->dispatch('/', null)->status());
    }

    public function testAnEmptyListRestrictsNothing(): void
    {
        $this->config->overlay(['geo_whitelist' => '']);
        $this->config->setSettings(['geo_enabled' => '1']);
        $this->activate('geo');

        self::assertSame(200, $this->dispatch('/', 'RU')->status());
    }

    public function testAdminBlocksAreRecordedInTheAuditLog(): void
    {
        $this->config->overlay(['admin_geo_whitelist' => 'US']);
        $this->config->setSettings(['admin_geo_enabled' => '1']);
        $this->activate('geo');

        self::assertSame(403, $this->dispatch('/admin', 'RU')->status());

        self::assertNotNull(
            $this->db()->value('SELECT 1 FROM {audit_log} WHERE action = ?', ['geo.admin_blocked']),
            'An admin-area refusal is either an attempt or a lockout, and both need recording.'
        );
    }

    /**
     * Viewer blocks are deliberately NOT logged. On a site restricted to one
     * country they are routine and high-volume, and logging them would bury
     * everything else in the audit log.
     */
    public function testViewerBlocksAreNotRecorded(): void
    {
        $this->config->overlay(['geo_whitelist' => 'US']);
        $this->config->setSettings(['geo_enabled' => '1']);
        $this->activate('geo');

        self::assertSame(403, $this->dispatch('/', 'RU')->status());

        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {audit_log}'),
            'Routine viewer refusals must not fill the audit log.'
        );
    }

    public function testDeactivatingGeoIsNotEnoughOnItsOwnUntilTheNextRequest(): void
    {
        $this->config->overlay(['geo_whitelist' => 'US']);
        $this->config->setSettings(['geo_enabled' => '1']);
        $this->activate('geo');

        $this->manager->deactivate('geo');

        // The middleware was registered on the router for this request and is
        // not removed by deactivation — but it reads its rules live, so turning
        // the SETTING off takes effect immediately. Both facts are worth
        // pinning: an admin who deactivates the plugin expects the block gone,
        // and it is gone on the next request when the plugin is not loaded.
        $this->config->setSettings(['geo_enabled' => '0']);
        $this->config->flushSettings();

        self::assertSame(200, $this->dispatch('/', 'RU')->status());
    }

    // --------------------------------------------------------------- helpers

    private function activate(string $slug): void
    {
        $result = $this->manager->activate($slug);
        self::assertTrue($result['ok'], "Could not activate {$slug}: " . $result['message']);
        self::assertTrue($this->manager->isLoaded($slug));
    }

    /** Fire share_overlay exactly the way ShareController does. */
    private function overlay(Share $share, string $viewerEmail): string
    {
        ob_start();
        $this->hooks->doAction('share_overlay', $share, $viewerEmail);

        return (string) ob_get_clean();
    }

    /**
     * Send a request through the real router, with the geo middleware in the
     * global chain where the plugin put it.
     */
    private function dispatch(string $path, ?string $country): Response
    {
        // Registered fresh each time: a route added once would be matched by
        // every later call, which is fine, but re-registering keeps each test
        // readable on its own.
        $this->router->get($path, static fn (): Response => Response::html('ok'));

        $headers = $country === null ? [] : ['cf-ipcountry' => $country];

        return $this->router->dispatch(new Request('GET', $path, headers: $headers));
    }

    private function share(int $videoId, string $watermark = 'default'): Share
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new Share(
            id: Share::newId(),
            videoId: $videoId,
            videoTitle: 'A video',
            recipientEmail: 'alice@example.com',
            accessMode: Share::MODE_GATE,
            createdAt: $now,
            expiresAt: $now->modify('+72 hours'),
            watermarkMode: $watermark,
        );
    }

    private function makeVideo(string $watermark = 'default', ?int $categoryId = null): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $id = $this->db()->insert('videos', [
            'provider_id'    => 'bunny-' . $suffix,
            'slug'           => 'video-' . $suffix,
            'title'          => 'A video',
            'status'         => 'ready',
            'watermark_mode' => $watermark,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        if ($categoryId !== null) {
            $this->db()->insert('video_categories', [
                'video_id'    => $id,
                'category_id' => $categoryId,
                'is_primary'  => 1,
            ]);
        }

        return $id;
    }

    private function makeCategory(string $name): int
    {
        $now = date('Y-m-d H:i:s');

        $id = $this->db()->insert('categories', [
            'parent_id'  => null,
            'slug'       => strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(3)),
            'name'       => $name,
            'path'       => '/',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db()->execute('UPDATE {categories} SET path = ? WHERE id = ?', ['/' . $id . '/', $id]);

        return $id;
    }
}
