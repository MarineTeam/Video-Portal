<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\App;
use Portal\Auth\Guard;
use Portal\Auth\User;
use Portal\Config;
use Portal\Container;
use Portal\Db;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Themes\ThemeManager;

/**
 * Shared controller plumbing.
 *
 * Controllers are resolved by the router without constructor arguments, so
 * dependencies come from the container on demand rather than being injected.
 * That is a deliberate trade: slightly less pure, but it keeps route
 * registration to a class name and a method.
 */
abstract class Controller
{
    protected Container $container;

    public function __construct()
    {
        $this->container = Container::instance();
    }

    protected function app(): App
    {
        return $this->container->get(App::class);
    }

    protected function config(): Config
    {
        return $this->container->get(Config::class);
    }

    protected function db(): Db
    {
        return $this->container->get(Db::class);
    }

    protected function guard(): Guard
    {
        return $this->container->get(Guard::class);
    }

    /**
     * Service accessors are prefixed, and deliberately so.
     *
     * A controller's public methods are route handlers, and admin screens are
     * naturally named after the thing they manage — themes(), users(),
     * plugins(). An unprefixed helper on this base class collides with one of
     * those and PHP fatals at class-resolution time with an incompatible
     * signature. php -l cannot see it, because it never resolves the parent.
     *
     * That exact collision on themes() took down /admin on a live host, so the
     * naming is a guardrail, not a style preference.
     */
    protected function themeManager(): ThemeManager
    {
        return $this->container->get(ThemeManager::class);
    }

    protected function user(): ?User
    {
        return $this->guard()->user();
    }

    /**
     * Enforce a capability inside a handler.
     *
     * Routes declare a coarse guard; this is the specific one. Both exist
     * because /admin should be reachable by anyone with any admin capability,
     * while each action needs its own.
     */
    protected function require(string $capability, ?string $scopeType = null, ?int $scopeId = null): void
    {
        if (!$this->guard()->can($capability, $scopeType, $scopeId)) {
            throw HttpException::forbidden('You do not have permission to do that.');
        }
    }

    /**
     * Render through the active theme.
     *
     * @param list<string>         $candidates
     * @param array<string, mixed> $data
     */
    protected function view(array $candidates, array $data = []): Response
    {
        $themes = $this->themeManager();
        $user = $this->user();

        $shared = [
            'theme'       => $themes,
            'siteName'    => apply_filters('site_name', $themes->setting('site_name', 'Video Portal') ?? 'Video Portal'),
            'logoUrl'     => $themes->setting('logo_url') ?: null,
            'assetsUrl'   => $this->config()->url('/theme-asset/' . $themes->activeSlug()),
            'currentUser' => $user === null ? null : [
                'name'    => $user->displayName(),
                'email'   => $user->email,
                'isAdmin' => $this->container->get(\Portal\Auth\Capabilities::class)->canSeeAdmin($user),
            ],
            'nav' => apply_filters('site_nav', $this->defaultNav()),
            /*
             * Whether search engines may index the public pages.
             *
             * Off by default, which is what the theme has always hardcoded.
             * This is a portal with private sharing built into it, so turning
             * a site public is a decision its owner makes deliberately — not
             * something that happens because a sitemap route shipped.
             */
            'allowIndexing' => $this->config()->settingBool('allow_indexing', false),
        ];

        $html = $themes->loader()->render($candidates, $data + $shared);

        // Every themed page is user-specific: it reflects who is signed in and
        // carries signed, expiring media URLs. A shared cache holding one
        // person's page and serving it to another is the failure mode.
        return Response::html($html)->private();
    }

    /**
     * @return list<array{label: string, href: string}>
     *
     * Only routes that actually exist. This previously offered "My activity"
     * pointing at /activity, which was never built — so the site's own
     * navigation led to a 404. Add entries here when the route lands, not
     * when it is planned.
     */
    protected function defaultNav(): array
    {
        $items = [
            ['label' => 'Library', 'href' => '/'],
            ['label' => 'Search',  'href' => '/search'],
        ];

        /*
         * Saved is offered only to somebody who could actually open it. The
         * route is behind auth.authorized, so linking it unconditionally would
         * put a permanent trip to the sign-in page in the navigation for every
         * visitor — and, worse, in front of accounts that are signed in but not
         * yet approved, for whom it would bounce with no explanation.
         */
        $user = $this->user();
        if ($user !== null && ($user->isAdmin() || $user->authorized)) {
            $items[] = ['label' => 'Saved', 'href' => '/saved'];
        }

        /** @var list<array{label: string, href: string}> */
        return apply_filters('default_nav', $items);
    }

    /**
     * A CSRF token bound to the session.
     *
     * Derived from the session id rather than stored separately, so it cannot
     * drift out of sync with the session it protects and needs no cleanup.
     */
    protected function csrfToken(): string
    {
        return \Portal\Support\Csrf::token($this->container->get(\Portal\Auth\Session::class));
    }

    /**
     * Reject a state-changing request that did not come from our own form.
     *
     * Applied to every POST that mutates anything. Without it, a page on
     * another site can make an authenticated admin's browser perform admin
     * actions simply by being visited.
     */
    protected function verifyCsrf(Request $request): void
    {
        \Portal\Support\Csrf::verify($this->container->get(\Portal\Auth\Session::class), $request);
    }

    /**
     * The admin navigation, filtered by what this person can actually do.
     *
     * Lives here rather than on one controller because several render inside
     * the admin shell, and two copies would drift — showing a link that leads
     * to a 403 reads as a broken site rather than a permission boundary.
     *
     * @return list<array{label: string, path: string, key: string}>
     */
    protected function adminNav(): array
    {
        $items = [
            ['label' => 'Dashboard',  'path' => '/admin',               'key' => 'dashboard',     'cap' => null],
            ['label' => 'Videos',     'path' => '/admin/videos',        'key' => 'videos',        'cap' => \Portal\Auth\Capability::MANAGE_VIDEOS],
            ['label' => 'Categories', 'path' => '/admin/categories',    'key' => 'categories',    'cap' => \Portal\Auth\Capability::MANAGE_CATEGORIES],
            ['label' => 'Series',     'path' => '/admin/series',        'key' => 'series',        'cap' => \Portal\Auth\Capability::MANAGE_SERIES],
            ['label' => 'Playlists',  'path' => '/admin/playlists',     'key' => 'playlists',     'cap' => \Portal\Auth\Capability::MANAGE_SERIES],
            ['label' => 'Speakers',   'path' => '/admin/speakers',      'key' => 'speakers',      'cap' => \Portal\Auth\Capability::MANAGE_SPEAKERS],
            ['label' => 'Sharing',    'path' => '/admin/shares',        'key' => 'shares',        'cap' => \Portal\Auth\Capability::MANAGE_SHARES],
            ['label' => 'Groups',     'path' => '/admin/shares/groups', 'key' => 'viewer-groups', 'cap' => \Portal\Auth\Capability::MANAGE_VIEWERS],
            ['label' => 'People',     'path' => '/admin/users',         'key' => 'users',         'cap' => \Portal\Auth\Capability::MANAGE_USERS],
            ['label' => 'Permissions', 'path' => '/admin/permissions',  'key' => 'permissions',   'cap' => \Portal\Auth\Capability::MANAGE_PERMISSIONS],
            ['label' => 'Plugins',    'path' => '/admin/plugins',       'key' => 'plugins',       'cap' => \Portal\Auth\Capability::MANAGE_PLUGINS],
            ['label' => 'Appearance', 'path' => '/admin/themes',        'key' => 'themes',        'cap' => \Portal\Auth\Capability::MANAGE_THEMES],
            ['label' => 'Services',   'path' => '/admin/providers',     'key' => 'providers',     'cap' => \Portal\Auth\Capability::MANAGE_PROVIDERS],
            ['label' => 'Settings',   'path' => '/admin/settings',      'key' => 'settings',      'cap' => \Portal\Auth\Capability::MANAGE_SETTINGS],
        ];

        $visible = [];
        foreach ($items as $item) {
            if ($item['cap'] === null || $this->guard()->can($item['cap'])) {
                $visible[] = ['label' => $item['label'], 'path' => $item['path'], 'key' => $item['key']];
            }
        }

        // Pages registered by plugins, filtered by the same capability rule.
        // Without this a plugin could call addAdminPage() and get a working
        // route that nothing on the site ever links to — a page reachable only
        // by someone who read the source.
        foreach ($this->pluginPages() as $page) {
            if ($this->guard()->can($page['capability'])) {
                $visible[] = [
                    'label' => $page['title'],
                    'path'  => $page['path'],
                    'key'   => 'plugin:' . $page['plugin'],
                ];
            }
        }

        return $visible;
    }

    /**
     * @return list<array{plugin: string, title: string, path: string, capability: string, position: int}>
     */
    private function pluginPages(): array
    {
        try {
            /** @var \Portal\Plugins\PluginManager $plugins */
            $plugins = $this->container->get(\Portal\Plugins\PluginManager::class);
            return $plugins->adminPages();
        } catch (\Throwable) {
            // A navigation bar is not worth a 500. Losing a plugin's link is
            // survivable; losing the screen that deactivates it is not.
            return [];
        }
    }

    /** @param array<string, mixed> $data */
    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status)->private();
    }

    protected function redirect(string $path, int $status = 302): Response
    {
        return Response::redirect($this->config()->url($path), $status);
    }

    /**
     * Redirect back with a one-shot message.
     */
    protected function back(Request $request, string $message = '', string $type = 'success'): Response
    {
        /** @var \Portal\Auth\Session $session */
        $session = $this->container->get(\Portal\Auth\Session::class);

        if ($message !== '') {
            $session->put('flash', ['type' => $type, 'message' => $message]);
        }

        $referer = $request->header('referer');
        $target = '/';

        if ($referer !== null) {
            $path = parse_url($referer, PHP_URL_PATH);
            if (is_string($path)) {
                $target = Request::sanitizeReturnTo($path);
            }
        }

        return $this->redirect($target);
    }

    /** @return array{type: string, message: string}|null */
    protected function flash(): ?array
    {
        /** @var \Portal\Auth\Session $session */
        $session = $this->container->get(\Portal\Auth\Session::class);

        $flash = $session->pull('flash');

        return is_array($flash) && isset($flash['message']) ? [
            'type'    => (string) ($flash['type'] ?? 'success'),
            'message' => (string) $flash['message'],
        ] : null;
    }
}
