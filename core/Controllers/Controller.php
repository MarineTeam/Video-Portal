<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\App;
use Portal\Auth\Capability;
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

    /**
     * Resolved once per request.
     *
     * Both the navigation and the banner want it, and asking twice would put
     * two queries on every themed page for one answer that cannot change
     * between them.
     *
     * @var array{live: array<string, mixed>|null, scheduled: int}|null
     */
    private ?array $liveState = null;

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
     * Would this navigation entry's screen let this person in?
     *
     * The nav has to ask exactly what the screen asks, or it renders links that
     * 403 or hides ones that would work. `siteWide` marks the screens with no
     * scoped form; see defaultNav().
     *
     * @param array{cap: string|null, siteWide?: bool} $entry
     */
    private function mayReach(array $entry): bool
    {
        if ($entry['cap'] === null) {
            return true;
        }

        return ($entry['siteWide'] ?? false)
            ? $this->guard()->can($entry['cap'])
            : $this->guard()->canAnywhere($entry['cap']);
    }

    /**
     * Enforce a capability on a LISTING, where there is no single object to ask
     * about — site-wide, or anywhere at all.
     *
     * Scoped grants were storable from Phase 1 and enforced nowhere, so every
     * check asked the site-wide question and `resolve()` answers that with false
     * for a grant attached to a category. A category-scoped editor could enter
     * the admin area — canSeeAdmin() matches any grant regardless of scope — and
     * then hit 403 on every screen inside it, including the list that is the
     * only route to the videos they were granted.
     *
     * So a listing admits anyone holding the capability ANYWHERE, and the
     * individual actions ask about the individual object.
     *
     * The consequence, stated rather than hidden: LISTINGS ARE NOT FILTERED. A
     * category-scoped editor sees every video in the library and can only open
     * and change their own. Filtering would mean a permission check per row —
     * each one walking that video's series and categories — which is a few
     * hundred queries on a fifty-row page. The alternative of filtering in SQL
     * means a second implementation of the resolver, and two implementations of
     * a permission rule eventually disagree; the failure mode there is a screen
     * that shows more than the resolver would allow, which is worse than a
     * screen that shows things whose buttons refuse.
     */
    protected function requireAnywhere(string $capability): void
    {
        if ($this->guard()->canAnywhere($capability)) {
            return;
        }

        throw HttpException::forbidden('You do not have permission to do that.');
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
            /*
             * One asset, stamped so a browser re-fetches it after an upgrade.
             *
             * $assetsUrl above is a BASE that templates append a filename to,
             * so it cannot carry a stamp — the stamp has to be per file. Both
             * are provided: a theme written before this still works, unstamped,
             * and one that uses this gets its scripts reloaded when they change.
             *
             * Worth the addition because a stale cached script is the single
             * hardest deployment failure to recognise. It presents as "the fix
             * does not work" and it looks identical, from the outside, to a fix
             * that is genuinely wrong.
             */
            'themeAsset'  => function (string $relative) use ($themes): string {
                $slug = $themes->activeSlug();
                $relative = ltrim($relative, '/');

                return asset_url(
                    $this->config()->url('/theme-asset/' . $slug . '/' . $relative),
                    PORTAL_THEMES . '/' . $slug . '/assets/' . $relative
                );
            },
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
            'announcements' => $this->announcements(),

            /*
             * The stream that is on right now, or null. Shared rather than
             * fetched per template because it belongs above the content on
             * every page — somebody who lands on a sermon from a search engine
             * while the service is going out should be told, and that is not a
             * homepage-only claim.
             */
            'liveNow' => $this->liveState()['live'],

            /*
             * The subscribe form's defaults, shared rather than repeated in
             * four listing actions. Each one overrides the scope; nothing has
             * to remember to supply the token or check the switch.
             *
             * `$data + $shared` means anything the action set wins, so an
             * override is a one-line addition where the page is built.
             */
            'subscribeEnabled' => $this->config()->settingBool('subscriptions_enabled', true),
            'subscribeScope'   => 'site',
            'subscribeScopeId' => null,
            'subscribeLabel'   => 'new videos',
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
    /**
     * The banners this visitor should see, minus the ones they have dismissed.
     *
     * Dismissal lives in a cookie, not the database. A row per viewer per
     * banner would be a write on a GET — from anonymous visitors, on a shared
     * host — to remember something that stops mattering when the announcement
     * ends. The cost is that dismissing does not follow somebody to another
     * browser, which is the right trade for a notice measured in days.
     *
     * @return list<array{id: int, title: string, body: string, level: string, dismissible: bool}>
     */
    /**
     * What is on air, and whether anything is scheduled.
     *
     * `liveNow()` has existed since Phase 5 and had no caller, which means a
     * stream could be going out while every page on the site looked exactly as
     * it does on a Tuesday. For a library whose live moment is a service people
     * are trying to join, that is the one thing worth interrupting a page for.
     *
     * One query, and the same shape as announcements() beside it — that already
     * runs on every themed page, so the cost is a known and accepted one rather
     * than a new precedent. `upcoming()` is asked once and both answers come
     * out of it; calling liveNow() separately would ask the same question twice.
     *
     * Wrapped whole. A banner is the least important thing on any page it
     * appears on, and on an install predating migration 0016 the table is not
     * there at all.
     *
     * @return array{live: array<string, mixed>|null, scheduled: int}
     */
    protected function liveState(): array
    {
        if ($this->liveState !== null) {
            return $this->liveState;
        }

        try {
            /** @var \Portal\Content\LiveStreamRepository $repo */
            $repo = $this->container->get(\Portal\Content\LiveStreamRepository::class);

            $user = $this->user();
            $canWatch = $user !== null && ($user->isAdmin() || $user->authorized);

            $rows = $repo->upcoming($canWatch);

            $live = null;
            foreach ($rows as $row) {
                if (($row['state'] ?? '') === \Portal\Content\LiveStreamPolicy::LIVE) {
                    $live = $row;
                    break;
                }
            }

            return $this->liveState = ['live' => $live, 'scheduled' => count($rows)];
        } catch (\Throwable $e) {
            error_log('Could not read live streams: ' . $e->getMessage());

            // Cached too, so a missing table costs one failed query per request
            // rather than one per caller.
            return $this->liveState = ['live' => null, 'scheduled' => 0];
        }
    }

    protected function announcements(): array
    {
        try {
            /** @var \Portal\Content\AnnouncementRepository $repo */
            $repo = $this->container->get(\Portal\Content\AnnouncementRepository::class);

            $user = $this->user();
            $showing = $repo->showing(
                $user !== null && ($user->isAdmin() || $user->authorized),
                $user !== null && $this->container->get(\Portal\Auth\Capabilities::class)->canSeeAdmin($user),
            );

            $dismissed = $this->dismissedAnnouncements();

            $out = [];
            foreach ($showing as $announcement) {
                if ($announcement->dismissible && in_array($announcement->id, $dismissed, true)) {
                    continue;
                }

                $out[] = [
                    'id'          => $announcement->id,
                    'title'       => $announcement->title,
                    'body'        => $announcement->body,
                    'level'       => $announcement->level,
                    'dismissible' => $announcement->dismissible,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            // Before migration 0006 has run, or if anything else goes wrong: a
            // banner is never worth failing a page over.
            error_log('Could not load announcements: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The ids in the dismissal cookie.
     *
     * Read defensively — it is a value a visitor controls, so it is capped and
     * anything unrecognised is ignored rather than trusted.
     *
     * @return list<int>
     */
    private function dismissedAnnouncements(): array
    {
        $raw = $_COOKIE['portal_dismissed'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
            if (count($ids) >= 50) {
                break;
            }
        }

        return $ids;
    }

    protected function defaultNav(): array
    {
        $items = [
            ['label' => 'Library', 'href' => '/'],
            ['label' => 'Search',  'href' => '/search'],
        ];

        /*
         * Live is offered only when there is something to see. /live has
         * existed since Phase 5 and has never been linked from anywhere, so on
         * every install it has been reachable only by typing the address —
         * but a permanent link to a page reading "nothing scheduled" is the
         * other failure, and it is the one people stop clicking.
         */
        $live = $this->liveState();
        if ($live['scheduled'] > 0) {
            $items[] = [
                'label' => $live['live'] !== null ? 'Live now' : 'Live',
                'href'  => '/live',
            ];
        }

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

        /*
         * Only for accounts that have one. Somebody signing in through Auth0
         * has no local password, and a link whose page tells them so is a link
         * that should not have been offered — the same rule Saved follows just
         * above.
         */
        if ($user !== null && $user->hasPassword) {
            $items[] = ['label' => 'Password', 'href' => '/account/password'];
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
     * The admin navigation, grouped into sections and filtered by what this
     * person can actually do.
     *
     * Lives here rather than on one controller because several render inside
     * the admin shell, and two copies would drift — showing a link that leads
     * to a 403 reads as a broken site rather than a permission boundary.
     *
     * It used to be twenty flat links across a top bar, growing by one with
     * every feature that shipped: a list you scanned rather than a structure
     * you learned, and by the end the trash was reachable only from a
     * conditional line on the videos screen. Eight sections down the left is
     * the arrangement anybody who has run a WordPress site already knows. The
     * point is not imitation — it is that people arrive knowing how to read it.
     *
     * Each entry names the SCREENS it owns rather than relying on its own key,
     * because the screen names are what the shell is handed. Matching on the
     * key alone meant that editing a video, a category, a series or a playlist
     * left the entire navigation unhighlighted — four of the most-used screens
     * in the product, each one leaving you unable to tell where you were.
     *
     * @return list<array{
     *     label: string, path: string, key: string, icon: string,
     *     screens: list<string>,
     *     children: list<array{label: string, path: string, key: string, screens: list<string>}>
     * }>
     */
    protected function adminNav(): array
    {
        $sections = [
            [
                'label' => 'Dashboard', 'path' => '/admin', 'key' => 'dashboard', 'icon' => 'home',
                'cap' => null, 'screens' => ['dashboard'], 'children' => [],
            ],
            [
                'label' => 'Content', 'path' => '/admin/videos', 'key' => 'content', 'icon' => 'film',
                'cap' => Capability::MANAGE_VIDEOS, 'screens' => [],
                'children' => [
                    ['label' => 'Videos',     'path' => '/admin/videos',        'key' => 'videos',        'cap' => Capability::MANAGE_VIDEOS,     'screens' => ['videos', 'video-edit']],
                    ['label' => 'Trash',      'path' => '/admin/videos/trash',  'key' => 'trash',         'cap' => Capability::MANAGE_VIDEOS,     'screens' => ['trash']],
                    ['label' => 'Categories', 'path' => '/admin/categories',    'key' => 'categories',    'cap' => Capability::MANAGE_CATEGORIES, 'screens' => ['categories', 'category-edit']],
                    ['label' => 'Series',     'path' => '/admin/series',        'key' => 'series',        'cap' => Capability::MANAGE_SERIES,     'screens' => ['series', 'series-edit']],
                    /*
                     * `siteWide` marks a screen that has no scoped form, so the
                     * navigation asks the same question the screen asks. A
                     * playlist and a live stream are not values of
                     * `grants.scope_type` — there is no such thing as a grant on
                     * one — so a category-scoped editor holds nothing here and
                     * the link would land on a 403.
                     *
                     * Everything without the flag is filtered with canAnywhere,
                     * matching requireAnywhere() on the screen itself.
                     */
                    ['label' => 'Playlists',  'path' => '/admin/playlists',     'key' => 'playlists',     'cap' => Capability::MANAGE_SERIES,     'screens' => ['playlists', 'playlist-edit'], 'siteWide' => true],
                    ['label' => 'Speakers',   'path' => '/admin/speakers',      'key' => 'speakers',      'cap' => Capability::MANAGE_SPEAKERS,   'screens' => ['speakers'], 'siteWide' => true],
                    ['label' => 'Live',       'path' => '/admin/live',          'key' => 'live',          'cap' => Capability::MANAGE_VIDEOS,     'screens' => ['live'], 'siteWide' => true],
                    ['label' => 'Notices',    'path' => '/admin/announcements', 'key' => 'announcements', 'cap' => Capability::MANAGE_SETTINGS,   'screens' => ['announcements']],
                ],
            ],
            [
                'label' => 'Sharing', 'path' => '/admin/shares', 'key' => 'sharing', 'icon' => 'link',
                'cap' => Capability::MANAGE_SHARES, 'screens' => [],
                'children' => [
                    ['label' => 'Share links', 'path' => '/admin/shares',        'key' => 'shares',        'cap' => Capability::MANAGE_SHARES,  'screens' => ['shares', 'private-list']],
                    ['label' => 'Groups',      'path' => '/admin/shares/groups', 'key' => 'viewer-groups', 'cap' => Capability::MANAGE_VIEWERS, 'screens' => ['viewer-groups']],
                ],
            ],
            [
                'label' => 'Analytics', 'path' => '/admin/analytics', 'key' => 'analytics', 'icon' => 'chart',
                'cap' => Capability::VIEW_ANALYTICS, 'screens' => ['analytics'], 'children' => [],
            ],
            [
                'label' => 'People', 'path' => '/admin/users', 'key' => 'people', 'icon' => 'people',
                'cap' => Capability::MANAGE_USERS, 'screens' => [],
                'children' => [
                    ['label' => 'Accounts',    'path' => '/admin/users',       'key' => 'users',       'cap' => Capability::MANAGE_USERS,       'screens' => ['users']],
                    ['label' => 'Permissions', 'path' => '/admin/permissions', 'key' => 'permissions', 'cap' => Capability::MANAGE_PERMISSIONS, 'screens' => ['permissions']],
                ],
            ],
            [
                'label' => 'Appearance', 'path' => '/admin/themes', 'key' => 'appearance', 'icon' => 'brush',
                'cap' => Capability::MANAGE_THEMES, 'screens' => [],
                'children' => [
                    ['label' => 'Themes',   'path' => '/admin/themes',   'key' => 'themes',    'cap' => Capability::MANAGE_THEMES,   'screens' => ['themes']],
                    ['label' => 'Homepage', 'path' => '/admin/homepage', 'key' => 'home-rows', 'cap' => Capability::MANAGE_SETTINGS, 'screens' => ['home-rows']],
                ],
            ],
            [
                'label' => 'Plugins', 'path' => '/admin/plugins', 'key' => 'plugins-group', 'icon' => 'plug',
                'cap' => Capability::MANAGE_PLUGINS, 'screens' => [],
                'children' => [
                    ['label' => 'Installed', 'path' => '/admin/plugins', 'key' => 'plugins', 'cap' => Capability::MANAGE_PLUGINS, 'screens' => ['plugins']],
                ],
            ],
            [
                'label' => 'Settings', 'path' => '/admin/settings', 'key' => 'settings-group', 'icon' => 'cog',
                'cap' => Capability::MANAGE_SETTINGS, 'screens' => [],
                'children' => [
                    ['label' => 'General',  'path' => '/admin/settings',  'key' => 'settings',  'cap' => Capability::MANAGE_SETTINGS,  'screens' => ['settings']],
                    ['label' => 'Services', 'path' => '/admin/providers', 'key' => 'providers', 'cap' => Capability::MANAGE_PROVIDERS, 'screens' => ['providers']],
                    ['label' => 'Webhooks', 'path' => '/admin/webhooks',  'key' => 'webhooks',  'cap' => Capability::MANAGE_SETTINGS,  'screens' => ['webhooks']],
                ],
            ],
        ];

        // Pages registered by plugins, filtered by the same capability rule, and
        // filed under Plugins rather than dropped at the end. Without this a
        // plugin could call addAdminPage() and get a working route that nothing
        // on the site ever links to — a page reachable only by someone who read
        // the source.
        foreach ($this->pluginPages() as $page) {
            foreach ($sections as $index => $section) {
                if ($section['key'] === 'plugins-group') {
                    $sections[$index]['children'][] = [
                        'label'   => $page['title'],
                        'path'    => $page['path'],
                        'key'     => 'plugin:' . $page['plugin'],
                        'cap'     => $page['capability'],
                        'screens' => ['plugin:' . $page['plugin']],
                    ];
                }
            }
        }

        $visible = [];

        foreach ($sections as $section) {
            $children = [];
            $screens = $section['screens'];

            foreach ($section['children'] as $child) {
                /*
                 * canAnywhere, matching what the screens themselves now ask.
                 * A category-scoped editor holds nothing site-wide, so the
                 * site-wide question would render them an admin area with an
                 * empty sidebar and no route to the videos they were granted.
                 *
                 * Except where the screen has no scoped form at all — see
                 * `siteWide` above — in which case the link would 403.
                 */
                if ($child['cap'] !== null && !$this->mayReach($child)) {
                    continue;
                }

                $children[] = [
                    'label'   => $child['label'],
                    'path'    => $child['path'],
                    'key'     => $child['key'],
                    'screens' => $child['screens'],
                ];
                $screens = array_merge($screens, $child['screens']);
            }

            /*
             * A section survives if this person may see the section itself OR
             * any one of its children. Requiring the section capability would
             * hide Permissions from somebody who can assign permissions but
             * cannot edit accounts — a split these roles genuinely allow.
             */
            if (($section['cap'] !== null && !$this->mayReach($section)) && $children === []) {
                continue;
            }

            $visible[] = [
                'label' => $section['label'],
                // A section with children opens at its first VISIBLE child, so
                // clicking the heading can never land on a 403.
                'path'     => $children === [] ? $section['path'] : $children[0]['path'],
                'key'      => $section['key'],
                'icon'     => $section['icon'],
                'screens'  => $screens,
                'children' => $children,
            ];
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
