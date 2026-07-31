<?php
/**
 * Video Portal — application bootstrap.
 *
 * Loaded by public/index.php, public/install.php, and the test suite. Safe to
 * require more than once. Deliberately does NOT touch the database: install.php
 * needs the autoloader and config machinery before a database exists.
 */

declare(strict_types=1);

if (defined('PORTAL_BOOTSTRAPPED')) {
    return;
}
define('PORTAL_BOOTSTRAPPED', true);

define('PORTAL_VERSION', '1.0.0');
define('PORTAL_MIN_PHP', '8.2.0');
define('PORTAL_ROOT', dirname(__DIR__));
define('PORTAL_CORE', PORTAL_ROOT . '/core');
define('PORTAL_PUBLIC', PORTAL_ROOT . '/public');
define('PORTAL_PLUGINS', PORTAL_ROOT . '/plugins');
define('PORTAL_THEMES', PORTAL_ROOT . '/themes');
define('PORTAL_STORAGE', PORTAL_ROOT . '/storage');
define('PORTAL_CONFIG_FILE', PORTAL_ROOT . '/config.php');

if (version_compare(PHP_VERSION, PORTAL_MIN_PHP, '<')) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    exit(sprintf(
        "Video Portal needs PHP %s or newer. This server is running PHP %s.\n"
        . "Most shared hosts let you change the PHP version from the control panel.\n",
        PORTAL_MIN_PHP,
        PHP_VERSION
    ));
}

/*
 * Autoloading.
 *
 * vendor/autoload.php is present in a released ZIP (we commit vendor/ on the
 * release branch so hosts never need Composer). During development it may be
 * missing, so we fall back to a hand-rolled PSR-4 loader for the Portal\
 * namespace and only complain about vendor when something actually needs it.
 */
$vendorAutoload = PORTAL_ROOT . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
    define('PORTAL_HAS_VENDOR', true);
} else {
    define('PORTAL_HAS_VENDOR', false);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Portal\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = PORTAL_CORE . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once PORTAL_CORE . '/Support/helpers.php';

/*
 * Error handling.
 *
 * Notices and warnings become exceptions so a typo in a plugin surfaces as a
 * real failure instead of leaking text into the middle of a page. Display is
 * decided later, once config tells us whether we're in debug mode; until then
 * we buffer nothing and log everything.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // suppressed with @ — respect it
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/*
 * Timezone. The installer stores the real one in settings; until the database
 * is readable, UTC keeps every timestamp we generate unambiguous.
 */
date_default_timezone_set('UTC');

/*
 * Sessions are opened lazily by Portal\Auth\Session, never here — install.php
 * and the cron endpoint have no business starting one.
 */
