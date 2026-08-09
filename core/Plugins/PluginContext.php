<?php

declare(strict_types=1);

namespace Portal\Plugins;

use Portal\Auth\PermissionSeeder;
use Portal\Config;
use Portal\Db;
use Portal\Http\Router;

/**
 * What a plugin file receives when it loads.
 *
 * Passing a context object rather than letting plugins reach for globals means
 * the surface a plugin depends on is explicit and greppable. When something
 * changes in a future version, `PluginContext` is the one place that says what
 * plugins were promised.
 *
 * Inside plugin.php this is available as `$plugin`.
 */
final class PluginContext
{
    /** @var array<string, mixed>|null Lazily decoded settings. */
    private ?array $settings = null;

    public function __construct(
        public readonly string $slug,
        public readonly string $directory,
        public readonly PluginHeader $header,
        private readonly Db $db,
        private readonly Config $config,
        private readonly Hooks $hooks,
        private readonly Router $router,
        private readonly PluginManager $manager,
    ) {
    }

    // --------------------------------------------------------------- helpers

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Db
    {
        return $this->db;
    }

    /**
     * The signed-in user, or null.
     *
     * Wrapped rather than left to the container so a plugin does not have to
     * know which class to ask for, and so "not signed in" and "no session on
     * this request" arrive as the same answer instead of an exception.
     */
    public function user(): ?\Portal\Auth\User
    {
        try {
            return \Portal\Container::instance()->get(\Portal\Auth\Guard::class)->user();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The CSRF field for a form this plugin renders.
     *
     * Offered as ready-made HTML rather than a raw token, because a token an
     * author has to place themselves is one they can forget to place — and a
     * plugin form without it appears to work perfectly.
     */
    public function csrfField(): string
    {
        return \Portal\Support\Csrf::field(\Portal\Container::instance()->get(\Portal\Auth\Session::class));
    }

    /** Throws 419 unless the request carries this session's token. */
    public function verifyCsrf(\Portal\Http\Request $request): void
    {
        \Portal\Support\Csrf::verify(
            \Portal\Container::instance()->get(\Portal\Auth\Session::class),
            $request
        );
    }

    /** Absolute path to a file inside this plugin. */
    public function path(string $relative = ''): string
    {
        return rtrim($this->directory . '/' . ltrim($relative, '/'), '/');
    }

    /** Public URL for an asset in this plugin's assets/ directory. */
    public function assetUrl(string $relative): string
    {
        return $this->config->url('/plugin-asset/' . $this->slug . '/' . ltrim($relative, '/'));
    }

    // -------------------------------------------------------------- settings

    /**
     * A stored setting for this plugin.
     *
     * Settings live as one JSON blob per plugin rather than a key-value table,
     * because a plugin's settings are always read together and never queried
     * across plugins.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        if ($this->settings === null) {
            $raw = $this->db->value('SELECT settings FROM {plugins} WHERE slug = ?', [$this->slug]);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $this->settings = is_array($decoded) ? $decoded : [];
        }

        return $this->settings[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->setting($key); // ensure loaded
        $this->settings[$key] = $value;

        $this->db->execute(
            'UPDATE {plugins} SET settings = ?, updated_at = NOW() WHERE slug = ?',
            [json_encode($this->settings, JSON_UNESCAPED_SLASHES), $this->slug]
        );
    }

    // ----------------------------------------------------------- registration

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hooks->addAction($hook, $callback, $priority);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hooks->addFilter($hook, $callback, $priority);
    }

    /**
     * Register a route.
     *
     * Plugin routes are registered after core's, so a plugin cannot
     * accidentally shadow a core URL — but it can deliberately claim a more
     * specific pattern, which is the intended extension point.
     *
     * @param string|list<string> $methods
     * @param list<string>        $middleware
     */
    public function addRoute(
        string|array $methods,
        string $pattern,
        callable $handler,
        array $middleware = []
    ): void {
        $this->router->add($methods, $pattern, $handler, $middleware);
    }

    /**
     * Guard every route with a check of this plugin's own.
     *
     * The callback returns null to let the request through, or a Response to
     * stop it. Registered under a namespaced name so two plugins cannot claim
     * the same one, and added to the global chain rather than replacing it.
     *
     * This runs on every single request, including ones that would 404. Keep
     * it cheap, and — because a plugin that throws here would take the whole
     * site down with it — decide in favour of letting the request through
     * whenever the answer is unclear.
     *
     * @param callable(\Portal\Http\Request, array<string,string>): (\Portal\Http\Response|null) $check
     */
    public function addGlobalMiddleware(string $name, callable $check): void
    {
        $namespaced = $this->slug . '.' . $name;

        $this->router->middleware($namespaced, $check);
        $this->router->addGlobalMiddleware($namespaced);
    }

    /**
     * Add an entry to the admin navigation.
     *
     * @param string $capability the capability required to see and open it
     */
    public function addAdminPage(
        string $title,
        string $path,
        string $capability,
        callable $handler,
        int $position = 100
    ): void {
        $this->manager->registerAdminPage($this->slug, $title, $path, $capability, $position);
        $this->router->add(
            ['GET', 'POST'],
            '/admin/' . ltrim($path, '/'),
            $handler,
            ['admin.area']
        );
    }

    /**
     * Declare a capability this plugin checks.
     *
     * Tagged with the plugin so uninstalling removes it, along with any grants
     * that referenced it.
     */
    public function addCapability(string $slug, string $description): void
    {
        (new PermissionSeeder($this->db))->registerPluginCapability($this->slug, $slug, $description);
    }

    /**
     * Register a scheduled job.
     *
     * @param int $intervalSeconds how often it should run
     */
    public function addCronJob(string $slug, int $intervalSeconds, callable $handler): void
    {
        $namespaced = $this->slug . '.' . $slug;
        $this->manager->registerCronJob($namespaced, $intervalSeconds, $handler);
    }

    // ---------------------------------------------------------- availability

    /**
     * Is this plugin active for the given category?
     *
     * Resolution walks the category's ancestors and the nearest explicit
     * override wins; with no override anywhere, the plugin's global state
     * applies. A plugin that renders per-video should call this so an admin
     * can turn it off for one section of the site.
     */
    public function isEnabledFor(?int $categoryId): bool
    {
        return $this->manager->isEnabledForCategory($this->slug, $categoryId);
    }
}
