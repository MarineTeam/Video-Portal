<?php
/**
 * Front controller.
 *
 * Every request that is not an existing file lands here.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use Portal\App;
use Portal\Config;
use Portal\Http\ErrorPage;
use Portal\Http\Request;
use Portal\Http\Response;

$config = new Config();

/*
 * Not installed yet: send them to the wizard rather than showing a database
 * connection error, which is what an uninstalled site would otherwise produce.
 */
if (!$config->isInstalled()) {
    if (is_file(__DIR__ . '/install.php')) {
        header('Location: install.php');
        exit;
    }

    http_response_code(503);
    echo ErrorPage::render(
        503,
        'Not installed yet',
        'This site has no config.php, and the installer is not present either. '
        . 'Re-upload public/install.php from the release ZIP to set it up.'
    );
    exit;
}

$request = Request::capture();
$app = new App($config);

try {
    $app->boot();
    $response = $app->handle($request);
} catch (Throwable $e) {
    // A failure during boot — bad credentials, a corrupt config — happens
    // before the kernel's own error handling exists, so it is caught here.
    error_log(sprintf(
        'Portal: fatal during boot: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    $debug = $config->isDebug();

    $response = Response::html(
        ErrorPage::render(
            500,
            'This site is not available',
            $debug
                ? $e->getMessage()
                : 'The site could not start. Check the error log, and that config.php still has the '
                  . 'right database details.',
            $debug ? $e->getTraceAsString() : null
        ),
        500
    );
}

$response->send($request->isSecure());

/*
 * Flush to the client before running scheduled work, so a background job never
 * delays a page. Only some SAPIs support this; where it is unavailable the work
 * still runs, just before the connection closes.
 */
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

$app->terminate();
