<?php

declare(strict_types=1);

namespace Portal;

use Portal\Auth\AuthProvider;
use Portal\Auth\Capabilities;
use Portal\Auth\Guard;
use Portal\Auth\Session;
use Portal\Auth\UserRepository;
use Portal\Content\CategoryRepository;
use Portal\Content\SeriesRepository;
use Portal\Content\SpeakerRepository;
use Portal\Content\VideoRepository;
use Portal\Http\ErrorPage;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Http\Router;
use Portal\Mail\MailProvider;
use Portal\Plugins\Hooks;
use Portal\Plugins\PluginManager;
use Portal\Providers\ProviderRegistry;
use Portal\Sharing\AccessResolver;
use Portal\Sharing\BundleRepository;
use Portal\Sharing\Gate;
use Portal\Sharing\PrivateList;
use Portal\Sharing\ShareMailer;
use Portal\Sharing\ShareRepository;
use Portal\Sharing\ViewerGroups;
use Portal\Support\Cron;
use Portal\Support\Crypto;
use Portal\Themes\ThemeManager;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * The application kernel.
 *
 * Boot order is not arbitrary and changing it breaks things in ways that are
 * hard to diagnose:
 *
 *   1. config + database        — everything needs these
 *   2. container bindings       — lazy, so nothing is built that isn't used
 *   3. pending core migrations  — an upgraded codebase against an old schema
 *                                 fails obscurely; catching it here is cheap
 *   4. plugins                  — they register hooks and routes, so they must
 *                                 come before both are consumed
 *   5. theme                    — its functions.php hooks onto `head`, which
 *                                 plugins may already have filtered
 *   6. `init` action            — the first point where everything exists
 *   7. routes                   — core first, then plugins, so a plugin cannot
 *                                 accidentally shadow a core URL
 */
final class App
{
    private Container $container;
    private bool $booted = false;

    public function __construct(private readonly Config $config)
    {
        $this->container = Container::instance();
    }

    public static function create(): self
    {
        return new self(new Config());
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    // ------------------------------------------------------------------ boot

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $db = Db::fromConfig($this->config);
        Db::setInstance($db);

        $this->bindServices($db);

        if ($this->config->isDebug()) {
            Hooks::instance()->throwOnError(true);
        }

        $this->applyTimezone();
        $this->runPendingMigrations($db);

        /** @var PluginManager $plugins */
        $plugins = $this->container->get(PluginManager::class);
        $plugins->loadActive();

        /** @var ThemeManager $themes */
        $themes = $this->container->get(ThemeManager::class);
        $themes->boot();

        /*
         * After plugins load, so an event a plugin fires — comment_posted —
         * has a listener by the time it can happen, and so a plugin could add
         * its own event ahead of this if it wanted one.
         */
        \Portal\Content\WebhookEvents::register();

        do_action('init', $this);

        $this->registerRoutes();
    }

    private function bindServices(Db $db): void
    {
        $c = $this->container;

        $c->set(Config::class, $this->config);
        $c->set(Db::class, $db);
        $c->set(App::class, $this);

        $c->singleton(Hooks::class, static fn (): Hooks => Hooks::instance());
        $c->singleton(Router::class, static fn (): Router => new Router());

        $c->singleton(Crypto::class, fn (): Crypto => new Crypto($this->config->str('app_key')));
        $c->singleton(Session::class, static fn (Container $c): Session => new Session($c->get(Db::class)));

        $c->singleton(ProviderRegistry::class, static fn (Container $c): ProviderRegistry => new ProviderRegistry(
            $c->get(Db::class),
            $c->get(Config::class),
            $c->get(Crypto::class),
            $c->get(Session::class),
        ));

        // Providers resolve through the registry so a mid-request switch is
        // picked up by anything that asks afterwards.
        $c->singleton(AuthProvider::class, static fn (Container $c): AuthProvider
            => $c->get(ProviderRegistry::class)->auth());
        $c->singleton(VideoProvider::class, static fn (Container $c): VideoProvider
            => $c->get(ProviderRegistry::class)->video());
        $c->singleton(MailProvider::class, static fn (Container $c): MailProvider
            => $c->get(ProviderRegistry::class)->mail());

        $c->singleton(UserRepository::class, static fn (Container $c): UserRepository
            => new UserRepository($c->get(Db::class)));
        $c->singleton(Capabilities::class, static fn (Container $c): Capabilities
            => new Capabilities($c->get(Db::class)));

        $c->singleton(Guard::class, static fn (Container $c): Guard => new Guard(
            $c->get(Session::class),
            $c->get(UserRepository::class),
            $c->get(Capabilities::class),
            $c->get(AuthProvider::class),
        ));

        $c->singleton(CategoryRepository::class, static fn (Container $c): CategoryRepository
            => new CategoryRepository($c->get(Db::class)));
        $c->singleton(SeriesRepository::class, static fn (Container $c): SeriesRepository
            => new SeriesRepository($c->get(Db::class)));
        $c->singleton(SpeakerRepository::class, static fn (Container $c): SpeakerRepository
            => new SpeakerRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\PlaylistRepository::class, static fn (Container $c): \Portal\Content\PlaylistRepository
            => new \Portal\Content\PlaylistRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\SavedVideoRepository::class, static fn (Container $c): \Portal\Content\SavedVideoRepository
            => new \Portal\Content\SavedVideoRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\AnnouncementRepository::class, static fn (Container $c): \Portal\Content\AnnouncementRepository
            => new \Portal\Content\AnnouncementRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\RevisionRepository::class, static fn (Container $c): \Portal\Content\RevisionRepository
            => new \Portal\Content\RevisionRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\TranscriptRepository::class, static fn (Container $c): \Portal\Content\TranscriptRepository
            => new \Portal\Content\TranscriptRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\ChapterRepository::class, static fn (Container $c): \Portal\Content\ChapterRepository
            => new \Portal\Content\ChapterRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\ViewRepository::class, static fn (Container $c): \Portal\Content\ViewRepository
            => new \Portal\Content\ViewRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\AssetRepository::class, static fn (Container $c): \Portal\Content\AssetRepository
            // Outside the document root on purpose — see the migration.
            => new \Portal\Content\AssetRepository($c->get(Db::class), PORTAL_STORAGE));
        $c->singleton(\Portal\Content\WebhookRepository::class, static fn (Container $c): \Portal\Content\WebhookRepository
            => new \Portal\Content\WebhookRepository($c->get(Db::class)));
        $c->singleton(\Portal\Content\WebhookDispatcher::class, static fn (Container $c): \Portal\Content\WebhookDispatcher
            => new \Portal\Content\WebhookDispatcher(
                $c->get(\Portal\Content\WebhookRepository::class),
                /*
                 * The escape hatch lives in config.php, never in the settings
                 * table — the same rule the geo country lists follow, for a
                 * sharper reason. This switch turns off the check that stops
                 * the server being pointed at its own network, so an admin
                 * screen that could flip it would be an admin screen that
                 * could disable the protection from the web.
                 */
                $c->get(\Portal\Config::class)->bool('webhook_allow_private_addresses', false),
            ));
        $c->singleton(\Portal\Content\SubscriptionRepository::class, static fn (Container $c): \Portal\Content\SubscriptionRepository
            => new \Portal\Content\SubscriptionRepository(
                $c->get(Db::class),
                $c->get(CategoryRepository::class),
            ));
        $c->singleton(\Portal\Content\Notifier::class, static fn (Container $c): \Portal\Content\Notifier
            => new \Portal\Content\Notifier(
                $c->get(Db::class),
                $c->get(Config::class),
                $c->get(\Portal\Content\SubscriptionRepository::class),
                $c->get(VideoRepository::class),
                $c->get(\Portal\Mail\MailProvider::class),
            ));
        $c->singleton(\Portal\Content\HomeRowRepository::class, static fn (Container $c): \Portal\Content\HomeRowRepository
            => new \Portal\Content\HomeRowRepository(
                $c->get(Db::class),
                $c->get(VideoRepository::class),
                $c->get(CategoryRepository::class),
                $c->get(SeriesRepository::class),
                $c->get(\Portal\Content\PlaylistRepository::class),
            ));
        $c->singleton(VideoRepository::class, static fn (Container $c): VideoRepository => new VideoRepository(
            $c->get(Db::class),
            $c->get(CategoryRepository::class),
        ));

        $c->singleton(PluginManager::class, static fn (Container $c): PluginManager => new PluginManager(
            $c->get(Db::class),
            $c->get(Config::class),
            $c->get(Hooks::class),
            $c->get(Router::class),
        ));

        $c->singleton(ThemeManager::class, static fn (Container $c): ThemeManager => new ThemeManager(
            $c->get(Db::class),
            $c->get(Config::class),
            $c->get(Hooks::class),
        ));

        $c->singleton(Cron::class, static fn (Container $c): Cron => new Cron(
            $c->get(Db::class),
            $c->get(App::class),
        ));

        // ------------------------------------------------------------ sharing

        $c->singleton(ShareRepository::class, static fn (Container $c): ShareRepository => new ShareRepository(
            $c->get(Db::class),
            $c->get(VideoRepository::class),
        ));

        $c->singleton(BundleRepository::class, static fn (Container $c): BundleRepository => new BundleRepository(
            $c->get(Db::class),
            $c->get(ShareRepository::class),
        ));

        $c->singleton(Gate::class, static fn (Container $c): Gate => new Gate(
            $c->get(Db::class),
            $c->get(Config::class),
        ));

        $c->singleton(PrivateList::class, static fn (Container $c): PrivateList => new PrivateList(
            $c->get(Db::class),
            $c->get(ShareRepository::class),
        ));

        $c->singleton(ViewerGroups::class, static fn (Container $c): ViewerGroups
            => new ViewerGroups($c->get(Db::class)));

        $c->singleton(ShareMailer::class, static fn (Container $c): ShareMailer => new ShareMailer(
            $c->get(Config::class),
            $c->get(MailProvider::class),
            $c->get(ShareRepository::class),
            $c->get(BundleRepository::class),
        ));

        $c->singleton(AccessResolver::class, static fn (Container $c): AccessResolver => new AccessResolver(
            $c->get(ShareRepository::class),
            $c->get(BundleRepository::class),
            $c->get(Gate::class),
            $c->get(Guard::class),
        ));
    }

    private function applyTimezone(): void
    {
        $timezone = $this->config->setting('timezone', 'UTC') ?? 'UTC';
        if (in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * Apply migrations the deployed code expects but the database lacks.
     *
     * Upgrading is "overwrite the files over FTP", so there is no deploy step
     * to hang a migration on. Doing it here means the first request after an
     * upgrade fixes the schema, rather than failing obscurely on a missing
     * column.
     */
    private function runPendingMigrations(Db $db): void
    {
        try {
            $migrator = new Migrator($db);
            if ($migrator->coreNeedsMigration()) {
                $migrator->migrateCore();

                /*
                 * A migration is the only moment the deployed code is known to
                 * have changed, which makes it the right place to notice that
                 * a job this version defines has no row on this database. Core
                 * job rows had only ever been written by the installer, so a
                 * site installed before a job existed never got one — and a
                 * job with no row is never due, so it does nothing, silently,
                 * forever.
                 */
                (new \Portal\Support\Cron($db, $this))->ensureCoreJobs();
            }
        } catch (Throwable $e) {
            // Not fatal on its own — the request may not touch the new tables.
            error_log('Portal: automatic migration failed: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- routing

    private function registerRoutes(): void
    {
        /** @var Router $router */
        $router = $this->container->get(Router::class);
        /** @var Guard $guard */
        $guard = $this->container->get(Guard::class);

        $router->middleware('auth.user', $guard->requireUser());
        $router->middleware('auth.authorized', $guard->requireAuthorized());
        $router->middleware('admin.area', $guard->requireAdminArea());

        Routes::register($router, $this->container);

        // Plugins last: registration order is match order, so a plugin cannot
        // silently shadow a core URL, but can claim a more specific pattern.
        do_action('routes_register', $router);
    }

    // --------------------------------------------------------------- handling

    public function handle(Request $request): Response
    {
        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $session->boot($request);

        /** @var Router $router */
        $router = $this->container->get(Router::class);

        try {
            $response = $router->dispatch($request);
        } catch (HttpException $e) {
            $response = $e->toResponse($request);
        } catch (Throwable $e) {
            $response = $this->handleUnexpected($request, $e);
        }

        $session->commit($response, $request);

        /** @var Response */
        return apply_filters('response', $response, $request);
    }

    /**
     * An unhandled exception.
     *
     * The message is only shown when debug is on. On a live site it goes to
     * the log and the visitor gets a generic page — an exception message can
     * contain a query, a path, or a credential.
     */
    private function handleUnexpected(Request $request, Throwable $e): Response
    {
        error_log(sprintf(
            'Portal: unhandled %s: %s in %s:%d%s%s',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString()
        ));

        if ($request->wantsJson()) {
            return Response::error(
                $this->config->isDebug() ? $e->getMessage() : 'Something went wrong.',
                500
            );
        }

        return Response::html(
            ErrorPage::render(
                500,
                'Something went wrong',
                $this->config->isDebug()
                    ? $e->getMessage()
                    : 'The site hit an unexpected problem. It has been recorded in the error log.',
                $this->config->isDebug() ? $e->getTraceAsString() : null,
                $this->safeHomeUrl()
            ),
            500
        );
    }

    private function safeHomeUrl(): ?string
    {
        try {
            return $this->config->baseUrl();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Work that happens after the response is sent.
     *
     * Shared hosting has no worker process, so scheduled jobs piggyback on
     * ordinary traffic. Deliberately after the response so a slow job never
     * delays a page.
     */
    public function terminate(): void
    {
        do_action('shutdown');

        try {
            /** @var Cron $cron */
            $cron = $this->container->get(Cron::class);
            $cron->tick();
        } catch (Throwable $e) {
            error_log('Portal: scheduled work failed: ' . $e->getMessage());
        }
    }

    // --------------------------------------------------------------- shortcuts

    public function guard(): Guard
    {
        return $this->container->get(Guard::class);
    }

    public function themes(): ThemeManager
    {
        return $this->container->get(ThemeManager::class);
    }

    public function videos(): VideoRepository
    {
        return $this->container->get(VideoRepository::class);
    }

    public function categories(): CategoryRepository
    {
        return $this->container->get(CategoryRepository::class);
    }

    public function providers(): ProviderRegistry
    {
        return $this->container->get(ProviderRegistry::class);
    }
}
