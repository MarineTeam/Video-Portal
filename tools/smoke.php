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

/*
 * Flush every line as it happens.
 *
 * PHP buffers stdout when it is a file or a pipe rather than a terminal, so a
 * run that hangs shows nothing at all — which makes locating the hang far
 * harder than it needs to be. This script talks to a live server and a live
 * database, so knowing exactly which check it stopped on is the difference
 * between a diagnosis and a guess.
 */
ob_implicit_flush(true);
while (ob_get_level() > 0) {
    ob_end_flush();
}

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

/**
 * @param array<string, string> $fields
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function post(string $url, array $fields): array
{
    return send($url, 'POST', http_build_query($fields), 'application/x-www-form-urlencoded');
}

/**
 * @param array<string, mixed> $payload
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function postJson(string $url, array $payload): array
{
    return send($url, 'POST', (string) json_encode($payload), 'application/json');
}

/** @return array{status: int, body: string, headers: array<string, string>} */
function send(string $url, string $method, string $body, string $contentType): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: ' . $contentType],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'body'    => substr($raw, $headerSize),
        'headers' => [],
    ];
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
        // SMTP pointed at a port that refuses instantly, NOT php_mail.
        // mail() hands off to the SMTP host in php.ini — localhost:25 by
        // default on Windows — and blocks until that connection times out,
        // which hung this script for the full ten minutes. A refused
        // connection on loopback fails in microseconds, so the send path is
        // still exercised end to end and simply reports failure.
        'mail'  => ['slug' => 'smtp', 'credentials' => [
            'host' => '127.0.0.1',
            'port' => '1',
            'from' => 'Smoke Test <test@smoke.test>',
        ]],
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

echo "\nSharing\n";

/*
 * Create both kinds of share directly, then drive the recipient-facing routes.
 * These are the pages someone who has never visited the site will see, so the
 * refusals matter as much as the successes.
 */
$shares = new Portal\Sharing\ShareRepository(
    $db,
    new Portal\Content\VideoRepository($db, new Portal\Content\CategoryRepository($db))
);

$videoRow = (int) $db->value('SELECT id FROM {videos} LIMIT 1');

$accountShare = $shares->create($videoRow, 'recipient@smoke.test', ['accessMode' => 'account']);
$gateShare    = $shares->create($videoRow, 'recipient@smoke.test', ['accessMode' => 'gate']);

$revoked = $shares->create($videoRow, 'revoked@smoke.test');
$shares->revoke($revoked->id);

$expired = $shares->create($videoRow, 'expired@smoke.test');
$db->execute('UPDATE {shares} SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?', [$expired->id]);

$accountPage = get($baseUrl . '/s/' . $accountShare->id);
check(
    'Account-mode share redirects an anonymous visitor to sign in',
    $accountPage['status'] === 302 && str_contains($accountPage['headers']['location'] ?? '', '/auth/login'),
    "got {$accountPage['status']}"
);

$gatePage = get($baseUrl . '/s/' . $gateShare->id);
check('Gate-mode share shows the email form', $gatePage['status'] === 200, "got {$gatePage['status']}");
check('Gate form asks for an address', str_contains($gatePage['body'], 'name="email"'));
check(
    'Gate form does not name the recipient',
    !str_contains($gatePage['body'], 'recipient@smoke.test'),
    'the page must not reveal who the link was for'
);

/*
 * The anti-enumeration requirement, end to end: revoked, expired, and
 * never-existed must be byte-identical, not merely similar.
 */
$revokedPage = get($baseUrl . '/s/' . $revoked->id);
$expiredPage = get($baseUrl . '/s/' . $expired->id);
$unknownPage = get($baseUrl . '/s/aaaaaaaaaaaaaaaaaaaa');
$malformedPage = get($baseUrl . '/s/nope');

check('Revoked share returns 404', $revokedPage['status'] === 404, "got {$revokedPage['status']}");
check('Expired share returns 404', $expiredPage['status'] === 404, "got {$expiredPage['status']}");
check('Unknown share returns 404', $unknownPage['status'] === 404, "got {$unknownPage['status']}");
check('Malformed share id returns 404', $malformedPage['status'] === 404, "got {$malformedPage['status']}");

check(
    'Revoked and expired are byte-identical',
    $revokedPage['body'] === $expiredPage['body'],
    'a recipient must not be able to tell revoked from expired'
);
check(
    'Unknown and revoked are byte-identical',
    $unknownPage['body'] === $revokedPage['body'],
    'the page must not reveal whether an id was ever real'
);

check(
    'The refusal page names no recipient',
    !str_contains($revokedPage['body'], 'revoked@smoke.test')
);

/* Requesting a gate link answers the same way whatever the address. */
$goodRequest = post($baseUrl . '/s/' . $gateShare->id . '/request', ['email' => 'recipient@smoke.test']);
$badRequest = post($baseUrl . '/s/' . $gateShare->id . '/request', ['email' => 'nobody@smoke.test']);

check('Gate link request returns 200', $goodRequest['status'] === 200, "got {$goodRequest['status']}");
check(
    'Right and wrong addresses get identical answers',
    $goodRequest['body'] === $badRequest['body'],
    'the response must not reveal whether the address was correct'
);

/* Tracking is rejected for a link that no longer works. */
$trackDead = postJson($baseUrl . '/api/share-track', [
    'shareId' => $revoked->id, 'event' => 'play', 'percent' => 0,
]);
check('Tracking a revoked share is refused', $trackDead['status'] === 404, "got {$trackDead['status']}");

$trackBad = postJson($baseUrl . '/api/share-track', ['shareId' => 'nope', 'event' => 'play']);
check('Tracking with a malformed id is refused', $trackBad['status'] === 400, "got {$trackBad['status']}");

$shareAsset = get($baseUrl . '/assets/share-track.js');
check('Share tracking script is served', $shareAsset['status'] === 200, "got {$shareAsset['status']}");

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
