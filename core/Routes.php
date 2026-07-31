<?php

declare(strict_types=1);

namespace Portal;

use Portal\Controllers\AdminController;
use Portal\Controllers\AssetController;
use Portal\Controllers\AuthController;
use Portal\Controllers\CronController;
use Portal\Controllers\LibraryController;
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
        $router->get('/admin/categories', [AdminController::class, 'categories'], ['admin.area']);
        $router->post('/admin/categories', [AdminController::class, 'saveCategory'], ['admin.area']);
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

        // ------------------------------------------------------------ cron

        // No middleware: authenticated by a secret in the query string,
        // because the host's scheduler cannot hold a session.
        $router->get('/cron', [CronController::class, 'run']);
    }
}
