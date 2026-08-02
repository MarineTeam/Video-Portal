<?php

declare(strict_types=1);

namespace Portal;

use Portal\Controllers\AdminController;
use Portal\Controllers\AdminShareController;
use Portal\Controllers\AssetController;
use Portal\Controllers\AuthController;
use Portal\Controllers\CronController;
use Portal\Controllers\LibraryController;
use Portal\Controllers\ShareController;
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
        $router->get('/search', [LibraryController::class, 'search']);

        // Theme and plugin assets. Served by PHP because they live outside the
        // document root, which is what stops anyone fetching a theme's
        // functions.php directly.
        $router->get('/theme-asset/{theme}/{path:.+}', [AssetController::class, 'theme']);
        $router->get('/plugin-asset/{plugin}/{path:.+}', [AssetController::class, 'plugin']);

        // ------------------------------------------------------------ auth

        $router->get('/auth/login', [AuthController::class, 'login']);
        $router->post('/auth/login', [AuthController::class, 'authenticate']);
        $router->get('/auth/callback', [AuthController::class, 'callback']);
        $router->any(['GET', 'POST'], '/auth/logout', [AuthController::class, 'logout']);

        // --------------------------------------------------------- viewing

        // requireAuthorized, not requireUser: signing in proves identity,
        // watching requires an administrator's approval.
        $router->get('/watch/{slug}', [WatchController::class, 'show'], ['auth.authorized']);
        $router->post('/api/progress', [WatchController::class, 'saveProgress'], ['auth.authorized']);
        $router->get('/api/progress', [WatchController::class, 'getProgress'], ['auth.authorized']);

        // ----------------------------------------------------------- admin

        // Every admin route additionally checks its own capability inside the
        // handler; admin.area only decides who gets through the front door, so
        // a category editor is not met with a 403 on /admin itself.
        $router->get('/admin', [AdminController::class, 'dashboard'], ['admin.area']);
        $router->get('/admin/videos', [AdminController::class, 'videos'], ['admin.area']);
        $router->post('/admin/videos', [AdminController::class, 'updateVideo'], ['admin.area']);
        $router->get('/admin/videos/{id}', [AdminController::class, 'editVideo'], ['admin.area']);
        $router->get('/admin/categories', [AdminController::class, 'categories'], ['admin.area']);
        $router->post('/admin/categories', [AdminController::class, 'saveCategory'], ['admin.area']);
        $router->get('/admin/categories/{id}', [AdminController::class, 'editCategory'], ['admin.area']);
        $router->get('/admin/series', [AdminController::class, 'series'], ['admin.area']);
        $router->post('/admin/series', [AdminController::class, 'saveSeries'], ['admin.area']);
        $router->get('/admin/series/{id}', [AdminController::class, 'editSeries'], ['admin.area']);
        $router->get('/admin/speakers', [AdminController::class, 'speakers'], ['admin.area']);
        $router->post('/admin/speakers', [AdminController::class, 'saveSpeaker'], ['admin.area']);
        $router->get('/admin/users', [AdminController::class, 'users'], ['admin.area']);
        $router->post('/admin/users', [AdminController::class, 'saveUser'], ['admin.area']);
        $router->get('/admin/plugins', [AdminController::class, 'plugins'], ['admin.area']);
        $router->post('/admin/plugins', [AdminController::class, 'togglePlugin'], ['admin.area']);
        $router->get('/admin/themes', [AdminController::class, 'themes'], ['admin.area']);
        $router->post('/admin/themes', [AdminController::class, 'saveTheme'], ['admin.area']);
        $router->get('/admin/providers', [AdminController::class, 'providers'], ['admin.area']);
        $router->post('/admin/providers', [AdminController::class, 'saveProvider'], ['admin.area']);
        $router->get('/admin/settings', [AdminController::class, 'settings'], ['admin.area']);
        $router->post('/admin/settings', [AdminController::class, 'saveSettings'], ['admin.area']);

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
