<?php
/**
 * End-to-end smoke test.
 *
 * Installs the application into a scratch database, starts PHP's built-in
 * server, and drives the real HTTP surface. This is the only check that proves
 * the pieces work *together* — unit tests can all pass while the front
 * controller fails to boot.
 *
 *   php tools/smoke.php
 *
 * Creates config.php, uses it, and removes it. Refuses to run if one already
 * exists, so it can never clobber a real installation.
 */

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

use Portal\Config;
use Portal\Db;
use Portal\Install\Installer;

$host = getenv('SMOKE_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SMOKE_DB_PORT') ?: 3306);
$user = getenv('SMOKE_DB_USER') ?: 'root';
$pass = getenv('SMOKE_DB_PASS') ?: '';
$database = 'portal_smoke_' . bin2hex(random_bytes(3));
$serverPort = (int) (getenv('SMOKE_PORT') ?: 8911);
$baseUrl = "http://127.0.0.1:{$serverPort}";

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** @return array{status: int, body: string, headers: array<string, string>} */
function get(string $url, bool $followRedirects = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = [];
    foreach (preg_split('/\R/', substr($raw, 0, $headerSize)) ?: [] as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
    }

    return ['status' => $status, 'body' => substr($raw, $headerSize), 'headers' => $headers];
}

// ---------------------------------------------------------------- guard rails

if (is_file(PORTAL_CONFIG_FILE)) {
    fwrite(STDERR, "Refusing to run: config.php already exists. This would overwrite a real install.\n");
    exit(1);
}

echo "Video Portal smoke test\n\n";

// ------------------------------------------------------------------- install

try {
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Could not reach MySQL/MariaDB: ' . $e->getMessage() . "\n");
    exit(1);
}

$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Created scratch database {$database}\n";

$serverProcess = null;

$cleanup = static function () use ($admin, $database, &$serverProcess): void {
    if (is_resource($serverProcess)) {
        $status = proc_get_status($serverProcess);

        proc_terminate($serverProcess);

        // proc_terminate signals the shell, not necessarily the server it
        // spawned, and proc_close then blocks forever waiting for a child that
        // is still listening. Kill the process tree explicitly, and never call
        // proc_close — the OS reaps it once this script exits.
        if (!empty($status['pid'])) {
            $pid = (int) $status['pid'];
            if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
                @exec('taskkill /F /T /PID ' . $pid . ' 2>&1', $ignored);
            } else {
                @exec('kill -9 ' . $pid . ' 2>/dev/null', $ignored);
            }
        }
    }
    if (is_file(PORTAL_CONFIG_FILE)) {
        @unlink(PORTAL_CONFIG_FILE);
    }
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    echo "\nCleaned up.\n";
};

register_shutdown_function($cleanup);

$config = new Config();
$result = (new Installer($config))->install([
    'database' => [
        'db_host' => $host, 'db_port' => (string) $port, 'db_name' => $database,
        'db_user' => $user, 'db_pass' => $pass, 'db_prefix' => '',
    ],
    'site' => ['site_name' => 'Smoke Test Portal', 'base_url' => $baseUrl, 'timezone' => 'UTC'],
    'admin' => [
        'name' => 'Smoke Admin', 'email' => 'admin@smoke.test',
        'password' => 'smoke-test-password-1234',
    ],
    'providers' => [
        'auth'  => ['slug' => 'local', 'credentials' => []],
        'video' => ['slug' => 'bunny', 'credentials' => [
            'library_id' => '1', 'api_key' => 'x', 'token_auth_key' => 'y',
        ]],
        'mail'  => ['slug' => 'php_mail', 'credentials' => ['from' => 'test@smoke.test']],
    ],
]);

if (!$result->ok) {
    fwrite(STDERR, 'Install failed: ' . $result->message . ' ' . ($result->detail ?? '') . "\n");
    exit(1);
}

echo "Installed.\n";

// Seed a little content so the library has something to render.
$db = Db::fromConfig(new Config());
Db::setInstance($db);

$now = date('Y-m-d H:i:s');
$db->insert('categories', [
    'slug' => 'sermons', 'name' => 'Sermons', 'path' => '/1/', 'depth' => 0,
    'created_at' => $now, 'updated_at' => $now,
]);
$db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-1', 'slug' => 'a-test-video',
    'title' => 'A Test Video', 'status' => 'ready', 'is_published' => 1,
    'duration' => 125, 'created_at' => $now, 'updated_at' => $now,
]);

echo "Seeded content.\n\n";

// -------------------------------------------------------------------- serve

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$serverProcess = proc_open(
    sprintf('%s -S 127.0.0.1:%d -t %s', escapeshellarg(PHP_BINARY), $serverPort, escapeshellarg(PORTAL_PUBLIC)),
    $descriptors,
    $pipes
);

if (!is_resource($serverProcess)) {
    fwrite(STDERR, "Could not start the built-in server.\n");
    exit(1);
}

// Wait for it to accept connections.
for ($attempt = 0; $attempt < 40; $attempt++) {
    usleep(250_000);
    $probe = @fsockopen('127.0.0.1', $serverPort, $errno, $errstr, 1);
    if ($probe !== false) {
        fclose($probe);
        break;
    }
}

echo "Serving on {$baseUrl}\n\n";

// -------------------------------------------------------------------- checks

echo "Public pages\n";

$home = get($baseUrl . '/');
check('GET / returns 200', $home['status'] === 200, "got {$home['status']}");
check('Homepage renders the site name', str_contains($home['body'], 'Smoke Test Portal'));
check('Homepage lists the seeded video', str_contains($home['body'], 'A Test Video'));
check('Homepage is marked private', str_contains($home['headers']['cache-control'] ?? '', 'private'));

$category = get($baseUrl . '/category/sermons');
check('GET /category/{slug} returns 200', $category['status'] === 200, "got {$category['status']}");

$missing = get($baseUrl . '/category/does-not-exist');
check('Unknown category returns 404', $missing['status'] === 404, "got {$missing['status']}");

$login = get($baseUrl . '/auth/login');
check('GET /auth/login returns 200', $login['status'] === 200, "got {$login['status']}");
check('Login form is offered', str_contains($login['body'], 'name="password"'));

echo "\nAccess control\n";

$watch = get($baseUrl . '/watch/a-test-video');
check(
    'Watching signed out redirects to sign-in',
    $watch['status'] === 302 && str_contains($watch['headers']['location'] ?? '', '/auth/login'),
    "got {$watch['status']}"
);

$adminPage = get($baseUrl . '/admin');
check(
    'Admin signed out redirects to sign-in',
    $adminPage['status'] === 302,
    "got {$adminPage['status']}"
);

$api = get($baseUrl . '/api/progress?videoId=1');
check('API signed out returns 401 JSON, not a redirect', $api['status'] === 401, "got {$api['status']}");
check('API error body is JSON', str_contains($api['headers']['content-type'] ?? '', 'application/json'));

echo "\nAssets\n";

$css = get($baseUrl . '/theme-asset/default/theme.css');
check('Theme CSS is served', $css['status'] === 200, "got {$css['status']}");
check('Theme CSS has the right content type', str_contains($css['headers']['content-type'] ?? '', 'text/css'));

$traversal = get($baseUrl . '/theme-asset/default/../../config.php');
check('Path traversal on assets is refused', $traversal['status'] === 404, "got {$traversal['status']}");

$phpAsset = get($baseUrl . '/theme-asset/default/functions.php');
check('Theme PHP cannot be fetched', $phpAsset['status'] === 404, "got {$phpAsset['status']}");

echo "\nScheduled tasks\n";

$cronNoKey = get($baseUrl . '/cron');
check('Cron without a key returns 404', $cronNoKey['status'] === 404, "got {$cronNoKey['status']}");

$cronBadKey = get($baseUrl . '/cron?key=wrong');
check('Cron with a wrong key returns 404', $cronBadKey['status'] === 404, "got {$cronBadKey['status']}");

$written = require PORTAL_CONFIG_FILE;
$cronOk = get($baseUrl . '/cron?key=' . urlencode((string) $written['cron_secret']));
check('Cron with the right key runs', $cronOk['status'] === 200, "got {$cronOk['status']}");

echo "\nRouting\n";

$notFound = get($baseUrl . '/no-such-page');
check('Unknown path returns 404', $notFound['status'] === 404, "got {$notFound['status']}");

$badMethod = (function () use ($baseUrl): array {
    $ch = curl_init($baseUrl . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_TIMEOUT => 10,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'raw' => $raw];
})();
check('Wrong method returns 405', $badMethod['status'] === 405, "got {$badMethod['status']}");
check('405 carries an Allow header', stripos($badMethod['raw'], 'Allow:') !== false);

// ------------------------------------------------------------------- results

echo "\n";
echo str_repeat('-', 50) . "\n";
printf("%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
