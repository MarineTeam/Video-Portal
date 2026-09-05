<?php

declare(strict_types=1);

namespace Portal;

use Portal\Controllers\AccountController;
use Portal\Controllers\AdminController;
use Portal\Controllers\AdminShareController;
use Portal\Controllers\AssetController;
use Portal\Controllers\AssetDownloadController;
use Portal\Controllers\AuthController;
use Portal\Controllers\CronController;
use Portal\Controllers\DownloadController;
use Portal\Controllers\FeedController;
use Portal\Controllers\LibraryController;
use Portal\Controllers\MemberShareController;
use Portal\Controllers\PwaController;
use Portal\Controllers\RegistrationCheckController;
use Portal\Controllers\ShareController;
use Portal\Controllers\SubscriptionController;
use Portal\Controllers\UploadController;
use Portal\Controllers\WatchController;
use Portal\Http\Router;

/**
 * The core route table.
 *
 * Registered before plugins, so a plugin cannot accidentally shadow a core URL
 * — registration order is match order. Middleware is declared per route rather
 * than inferred from the path, so reading this file tells you exactly what
 * guards each endpoint.
 */
final class Routes
{
    public static function register(Router $router, Container $c): void
    {
        // ---------------------------------------------------------- public

        $router->get('/', [LibraryController::class, 'index']);
        $router->get('/category/{slug}', [LibraryController::class, 'category']);
        $router->get('/series/{slug}', [LibraryController::class, 'series']);
        $router->get('/speaker/{slug}', [LibraryController::class, 'speaker']);
        $router->get('/tag/{slug}', [LibraryController::class, 'tag']);

        /*
         * The chapter route is declared BEFORE the book route. Both would match
         * "/scripture/john/3" otherwise — {book} is unconstrained, so the more
         * specific pattern has to be offered first or the chapter is never
         * reached. The same collision that made /comments/report resolve as a
         * video called "report" in Phase 4.
         */
        $router->get('/live', [LibraryController::class, 'live']);
        $router->get('/live/{slug}', [LibraryController::class, 'live']);

        $router->get('/scripture', [LibraryController::class, 'scriptureIndex']);
        $router->get('/scripture/{book}/{chapter:\d+}', [LibraryController::class, 'scriptureBook']);
        $router->get('/scripture/{book}', [LibraryController::class, 'scriptureBook']);
        $router->get('/playlist/{slug}', [LibraryController::class, 'playlist']);
        $router->get('/search', [LibraryController::class, 'search']);

        /*
         * Attachments. No middleware: the handler checks the VIDEO's rules,
         * which is stricter than any blanket guard could be — a public video's
         * handout should reach a stranger, and a members-only one should not,
         * and only the row knows which this is.
         *
         * The trailing name is decoration for the browser's save dialog and is
         * never read; the id is the whole address.
         */
        $router->get('/asset/{id:\d+}/{name}', [AssetDownloadController::class, 'download']);

        // No token: see the note on the handler. This is the only POST here
        // that does not verify one, and the reason is written down.
        $router->post('/announcements/dismiss', [LibraryController::class, 'dismissAnnouncement']);

        /*
         * Feeds, the sitemap, and the media redirect.
         *
         * No middleware, deliberately: these are fetched by podcast clients and
         * crawlers that have no session and never will. Every one of them
         * serves public content only, decided inside the controller rather than
         * from who is asking — see the note on FeedController.
         *
         * The scoped patterns constrain {type}, so /feed/nonsense/x is a 404
         * rather than reaching the controller to be rejected there.
         */
        $router->get('/feed', [FeedController::class, 'rss']);
        $router->get('/feed/{type:category|series|playlist}/{slug}', [FeedController::class, 'rss']);
        $router->get('/podcast', [FeedController::class, 'podcast']);
        $router->get('/podcast/{type:category|series|playlist}/{slug}', [FeedController::class, 'podcast']);
        $router->get('/media/{slug}.mp4', [FeedController::class, 'media']);
        $router->get('/sitemap.xml', [FeedController::class, 'sitemap']);
        $router->get('/robots.txt', [FeedController::class, 'robots']);

        /*
         * Installable-app plumbing. All public and all session-free: the
         * service worker and the offline page are stored on the device and
         * shown to whoever opens the app next, so neither may depend on who is
         * signed in.
         */
        $router->get('/manifest.webmanifest', [PwaController::class, 'manifest']);
        $router->get('/icon.svg', [PwaController::class, 'icon']);
        $router->get('/sw.js', [PwaController::class, 'serviceWorker']);
        $router->get('/offline', [PwaController::class, 'offline']);

        /*
         * Subscribing, and getting out again.
         *
         * Open to people with no account on purpose: somebody who wants to know
         * when the service is posted has no reason to create one, and requiring
         * it would mean only people who already visit ever subscribe.
         *
         * Unsubscribe is a GET that SHOWS a button and a POST that acts. Mail
         * clients and security scanners follow links in email unasked, so an
         * unsubscribe that happened on GET would fire when a scanner looked at
         * the message and quietly remove somebody who never clicked.
         */
        $router->post('/subscribe', [SubscriptionController::class, 'subscribe']);
        $router->get('/unsubscribe/{token}', [SubscriptionController::class, 'confirmUnsubscribe']);
        $router->post('/unsubscribe', [SubscriptionController::class, 'unsubscribe']);

        // Theme and plugin assets. Served by PHP because they live outside the
        // document root, which is what stops anyone fetching a theme's
        // functions.php directly.
        $router->get('/theme-asset/{theme}/{path:.+}', [AssetController::class, 'theme']);
        $router->get('/plugin-asset/{plugin}/{path:.+}', [AssetController::class, 'plugin']);

        // ------------------------------------------------------------ auth

        $router->get('/auth/login', [AuthController::class, 'login']);
        $router->post('/auth/login', [AuthController::class, 'authenticate']);
        $router->get('/auth/callback', [AuthController::class, 'callback']);

        /*
         * Sign in as a guest: the same provider, without the organisation
         * parameter, for an address an administrator has excused that check.
         * 404 unless the feature is switched on — see AuthController::guest().
         */
        $router->get('/auth/guest', [AuthController::class, 'guest']);

        /*
         * Registration, which 404s unless the site has switched it on. One
         * handler for both methods so a failed submission re-renders the form
         * rather than 405-ing.
         */
        $router->any(['GET', 'POST'], '/auth/register', [AuthController::class, 'register']);
        $router->any(['GET', 'POST'], '/auth/logout', [AuthController::class, 'logout']);

        /*
         * Changing your own password.
         *
         * `auth.user`, not `auth.authorized`: holding a password and being
         * approved to watch are different things, and somebody waiting for
         * approval should still be able to rotate a credential. One handler for
         * both methods, so a GET that arrives after a failed POST re-renders
         * the form rather than 405-ing.
         */
        $router->any(
            ['GET', 'POST'],
            '/account/password',
            [AccountController::class, 'password'],
            ['auth.user']
        );

        /*
         * The account area.
         *
         * `auth.user` throughout, matching the password form above and for the
         * same reason: somebody waiting for approval still owns their account
         * and still subscribed to whatever the site has been sending them.
         *
         * Registered AFTER /account/password so the literal path is matched
         * first — these are distinct literals rather than a pattern, so the
         * order is not load-bearing, but keeping the specific one first means
         * it stays correct if either ever becomes a pattern.
         */
        $router->get('/account', [AccountController::class, 'index'], ['auth.user']);
        $router->get('/account/shared-links', [AccountController::class, 'sharedLinks'], ['auth.user']);
        $router->get('/account/downloads', [AccountController::class, 'downloads'], ['auth.user']);

        /*
         * What this person has watched, and everything the site holds on them.
         *
         * No identifier in either URL, deliberately: the only thing that
         * decides what comes back is who is signed in. An id here would make
         * the export an endpoint worth guessing at.
         */
        $router->any(['GET', 'POST'], '/account/history', [AccountController::class, 'history'], ['auth.user']);
        $router->get('/account/export.json', [AccountController::class, 'export'], ['auth.user']);

        /*
         * Member sharing.
         *
         * `auth.user` here and the capability checked inside the handler
         * against the specific video — a middleware can only ask the site-wide
         * question, and the whole point of share_content is that it can be
         * granted on one category.
         */
        $router->post('/share/create', [MemberShareController::class, 'create'], ['auth.user']);
        $router->post('/share/revoke', [MemberShareController::class, 'revoke'], ['auth.user']);
        $router->any(
            ['GET', 'POST'],
            '/account/notifications',
            [AccountController::class, 'notifications'],
            ['auth.user']
        );

        /*
         * Asking for access.
         *
         * Guarded by `auth.user` and NOT by `auth.authorized`, which is the
         * whole point: the people who need this are precisely the ones
         * `auth.authorized` refuses. Guarding it with the middleware that
         * produces the page it appears on would mean you had to be approved in
         * order to ask to be approved.
         *
         * POST only. It writes a row and sends mail, so it must not be
         * reachable by a link somebody can be tricked into following; the CSRF
         * token is checked in the handler.
         */
        $router->post('/request-access', [AuthController::class, 'requestAccess'], ['auth.user']);

        // --------------------------------------------------------- viewing

        // requireAuthorized, not requireUser: signing in proves identity,
        // watching requires an administrator's approval.
        $router->get('/watch/{slug}', [WatchController::class, 'show'], ['auth.authorized']);

        /*
         * Taking a copy away. Behind the same guard as watching, and then
         * behind three more of its own — see DownloadController, which is the
         * only place the capability and the content policy are put together.
         *
         * Not merged with /media/{slug}.mp4: that one is the podcast enclosure
         * and is deliberately anonymous, serving public videos to a feed reader
         * with no session. One handler answering both is how the anonymous
         * route eventually inherits a branch that trusts a capability nobody
         * checked.
         */
        $router->get('/download/{slug}.mp4', [DownloadController::class, 'media'], ['auth.authorized']);

        /*
         * The same decision as JSON, for the code that saves a video for
         * offline viewing. It cannot use the redirect above: fetch() following
         * a cross-origin 302 will not reveal where it landed, and putting the
         * file in Cache Storage needs the URL as well as the bytes.
         *
         * Both run the same four gates, in the same method.
         */
        $router->get('/download/{slug}.json', [DownloadController::class, 'meta'], ['auth.authorized']);
        $router->post('/api/progress', [WatchController::class, 'saveProgress'], ['auth.authorized']);
        $router->get('/api/progress', [WatchController::class, 'getProgress'], ['auth.authorized']);

        // Saved videos. Approved-only for the same reason /watch is: the pages
        // list content, and an unapproved account cannot see the library either.
        $router->get('/notes', [LibraryController::class, 'notes'], ['auth.authorized']);
        $router->post('/notes', [LibraryController::class, 'saveNote'], ['auth.authorized']);
        $router->get('/saved', [LibraryController::class, 'saved'], ['auth.authorized']);
        $router->post('/saved', [LibraryController::class, 'toggleSaved'], ['auth.authorized']);

        // ----------------------------------------------------------- admin

        // Every admin route additionally checks its own capability inside the
        // handler; admin.area only decides who gets through the front door, so
        // a category editor is not met with a 403 on /admin itself.
        $router->get('/admin', [AdminController::class, 'dashboard'], ['admin.area']);
        $router->get('/admin/videos', [AdminController::class, 'videos'], ['admin.area']);
        $router->post('/admin/videos', [AdminController::class, 'updateVideo'], ['admin.area']);
        // Registered before {id} so "trash" is not swallowed as a video id.
        $router->get('/admin/videos/trash', [AdminController::class, 'trash'], ['admin.area']);
        $router->post('/admin/videos/trash', [AdminController::class, 'updateTrash'], ['admin.area']);
        $router->get('/admin/videos/{id}', [AdminController::class, 'editVideo'], ['admin.area']);
        $router->get('/admin/categories', [AdminController::class, 'categories'], ['admin.area']);
        $router->post('/admin/categories', [AdminController::class, 'saveCategory'], ['admin.area']);
        $router->get('/admin/categories/{id}', [AdminController::class, 'editCategory'], ['admin.area']);
        $router->get('/admin/series', [AdminController::class, 'series'], ['admin.area']);
        $router->post('/admin/series', [AdminController::class, 'saveSeries'], ['admin.area']);
        $router->get('/admin/series/{id}', [AdminController::class, 'editSeries'], ['admin.area']);
        $router->get('/admin/playlists', [AdminController::class, 'playlists'], ['admin.area']);
        $router->post('/admin/playlists', [AdminController::class, 'savePlaylist'], ['admin.area']);
        $router->get('/admin/playlists/{id}', [AdminController::class, 'editPlaylist'], ['admin.area']);
        $router->get('/admin/analytics', [AdminController::class, 'analytics'], ['admin.area']);
        $router->get('/admin/analytics.csv', [AdminController::class, 'exportAnalytics'], ['admin.area']);

        /*
         * The activity log. Sixteen files have written to it since Phase 1 and
         * one dashboard tile read fifteen rows of it; view_audit_log has been
         * grantable the whole time, describing itself as "Read the activity
         * log". This is the screen it was always promising.
         */
        $router->get('/admin/activity', [AdminController::class, 'auditLog'], ['admin.area']);
        $router->get('/admin/activity.csv', [AdminController::class, 'auditLogCsv'], ['admin.area']);
        $router->get('/admin/homepage', [AdminController::class, 'homeRows'], ['admin.area']);
        $router->post('/admin/homepage', [AdminController::class, 'saveHomeRow'], ['admin.area']);
        $router->get('/admin/announcements', [AdminController::class, 'announcementsScreen'], ['admin.area']);
        $router->post('/admin/announcements', [AdminController::class, 'saveAnnouncement'], ['admin.area']);
        $router->get('/admin/live', [AdminController::class, 'liveScreen'], ['admin.area']);
        $router->post('/admin/live', [AdminController::class, 'saveLive'], ['admin.area']);
        $router->get('/admin/webhooks', [AdminController::class, 'webhooksScreen'], ['admin.area']);
        $router->post('/admin/webhooks', [AdminController::class, 'saveWebhook'], ['admin.area']);
        $router->get('/admin/speakers', [AdminController::class, 'speakers'], ['admin.area']);
        $router->post('/admin/speakers', [AdminController::class, 'saveSpeaker'], ['admin.area']);
        $router->get('/admin/tags', [AdminController::class, 'tags'], ['admin.area']);
        $router->post('/admin/tags', [AdminController::class, 'saveTag'], ['admin.area']);
        $router->get('/admin/users', [AdminController::class, 'users'], ['admin.area']);
        $router->post('/admin/users', [AdminController::class, 'saveUser'], ['admin.area']);

        /*
         * Who may sign in at all — a different question from who has an
         * account, and on its own screen because the answer lives in a
         * different place. Registered as a literal path before nothing else
         * claims it, so no /admin/users/{id} pattern can ever swallow it.
         */
        $router->get('/admin/access', [AdminController::class, 'signInAccess'], ['admin.area']);
        $router->post('/admin/access', [AdminController::class, 'saveSignInAccess'], ['admin.area']);

        /*
         * The question an identity provider asks before it creates an account.
         *
         * No middleware, deliberately: the caller is Auth0's servers, which
         * have no session and no CSRF token. Authenticated by a bearer secret
         * compared in constant time, rate limited, and answering 404 to
         * everything until a secret is configured — see the controller, which
         * is also where the reason it can afford to be advisory is written.
         */
        $router->post('/auth/registration-check', [RegistrationCheckController::class, 'check']);
        $router->get('/admin/permissions', [AdminController::class, 'permissions'], ['admin.area']);
        $router->post('/admin/permissions', [AdminController::class, 'savePermissions'], ['admin.area']);
        $router->get('/admin/plugins', [AdminController::class, 'plugins'], ['admin.area']);
        $router->post('/admin/plugins', [AdminController::class, 'togglePlugin'], ['admin.area']);
        $router->get('/admin/themes', [AdminController::class, 'themes'], ['admin.area']);
        $router->post('/admin/themes', [AdminController::class, 'saveTheme'], ['admin.area']);
        $router->get('/admin/providers', [AdminController::class, 'providers'], ['admin.area']);
        $router->post('/admin/providers', [AdminController::class, 'saveProvider'], ['admin.area']);
        $router->get('/admin/settings', [AdminController::class, 'settings'], ['admin.area']);
        $router->post('/admin/settings', [AdminController::class, 'saveSettings'], ['admin.area']);

        // ---------------------------------------------------- distribution

        $router->post('/admin/plugins/install', [AdminController::class, 'installPlugin'], ['admin.area']);
        $router->post('/admin/themes/install', [AdminController::class, 'installTheme'], ['admin.area']);
        $router->get('/admin/settings/export', [AdminController::class, 'exportSettings'], ['admin.area']);
        $router->get('/admin/settings/content', [AdminController::class, 'exportContent'], ['admin.area']);
        $router->post('/admin/settings/import', [AdminController::class, 'importSettings'], ['admin.area']);
        $router->post('/admin/settings/content/import', [AdminController::class, 'importContent'], ['admin.area']);

        // ---------------------------------------------------------- upload

        // Signing happens server-side; the bytes never touch this server.
        $router->post('/admin/upload/ticket', [UploadController::class, 'ticket'], ['admin.area']);
        $router->post('/admin/upload/complete', [UploadController::class, 'complete'], ['admin.area']);
        $router->post('/admin/upload/cancel', [UploadController::class, 'cancel'], ['admin.area']);
        $router->get('/admin/upload/status', [UploadController::class, 'status'], ['admin.area']);

        // ------------------------------------------------------ admin sharing

        $router->get('/admin/shares', [AdminShareController::class, 'index'], ['admin.area']);
        $router->post('/admin/shares/create', [AdminShareController::class, 'create'], ['admin.area']);
        $router->post('/admin/shares/act', [AdminShareController::class, 'act'], ['admin.area']);
        $router->post('/admin/shares/cleanup', [AdminShareController::class, 'cleanup'], ['admin.area']);

        // Registered before the {video} pattern below, so "groups" is not
        // swallowed as a video id.
        $router->get('/admin/shares/groups', [AdminShareController::class, 'groupsPage'], ['admin.area']);
        $router->post('/admin/shares/groups', [AdminShareController::class, 'updateGroups'], ['admin.area']);

        $router->post('/admin/shares/private-list', [AdminShareController::class, 'updatePrivateList'], ['admin.area']);
        $router->get('/admin/shares/video/{video}', [AdminShareController::class, 'privateList'], ['admin.area']);

        // ---------------------------------------------------------- sharing

        // No middleware. These are the recipient-facing pages, and the share
        // id IS the credential — an unguessable token that decides access.
        // Both access modes resolve inside the controller, which is also where
        // "revoked", "expired", "unknown", and "malformed" are made
        // deliberately indistinguishable.
        $router->get('/s/{id}', [ShareController::class, 'show']);
        $router->get('/b/{id}', [ShareController::class, 'showBundle']);

        /*
         * The passphrase form.
         *
         * Registered before /s/{id}/request only for readability — these are
         * distinct literal suffixes, so neither can shadow the other. Every
         * refusal it makes returns the same 404 as an unknown link, so this
         * route cannot be used to find out which ids are real.
         */
        $router->post('/s/{id}/unlock', [ShareController::class, 'unlock']);

        // The account-free gate's "what is your email address" form.
        $router->post('/s/{id}/request', [ShareController::class, 'requestLink'], [], 'gate.share');
        $router->post('/b/{id}/request', [ShareController::class, 'requestLink'], [], 'gate.bundle');

        // Playback telemetry, authenticated by the share id for the same
        // reason: a gate recipient has no session to authenticate with.
        $router->post('/api/share-track', [ShareController::class, 'track']);

        // ------------------------------------------------------------ cron

        // No middleware: authenticated by a secret in the query string,
        // because the host's scheduler cannot hold a session.
        $router->get('/cron', [CronController::class, 'run']);
    }
}
