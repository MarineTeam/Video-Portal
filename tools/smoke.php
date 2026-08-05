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

/**
 * Requests that carry a session, via a cookie jar.
 *
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function getWithJar(string $url, string $jar): array
{
    return withJar($url, $jar, null);
}

/**
 * @param array<string, string|list<string>> $fields
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function postWithJar(string $url, array $fields, string $jar): array
{
    // http_build_query turns list values into videos[0]=..., which PHP parses
    // back into the array the form would have sent.
    return withJar($url, $jar, http_build_query($fields));
}

/**
 * A real multipart file upload, cookies and all.
 *
 * CURLFile rather than a hand-built body: the point is to exercise the same
 * path a browser takes, and a form that only ever receives urlencoded input in
 * testing is not the form anybody actually uses.
 *
 * @param array<string, string> $fields
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function uploadWithJar(string $url, array $fields, string $fileField, string $path, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields + [
            $fileField => new CURLFile($path, 'application/zip', basename($path)),
        ],
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'body'    => substr($raw, $headerSize),
        'headers' => parseHeaders(substr($raw, 0, $headerSize)),
    ];
}

/** @return array{status: int, body: string, headers: array<string, string>} */
function withJar(string $url, string $jar, ?string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 15,
        // Deliberately not following: the status code IS the assertion.
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'body'    => substr($raw, $headerSize),
        // Previously always empty, while the signature promised otherwise. Any
        // check reading a redirect target through a signed-in session therefore
        // compared against nothing and reported "landed on nowhere" — a failure
        // that looks like the application's fault and is not.
        'headers' => parseHeaders(substr($raw, 0, $headerSize)),
    ];
}

/** @return array<string, string> lower-cased names */
function parseHeaders(string $raw): array
{
    $headers = [];

    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
    }

    return $headers;
}

/** Pull the CSRF token out of a rendered form. */
function csrfFrom(string $html): string
{
    return preg_match('/name="_token" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
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

    return [
        'status'  => $status,
        'body'    => substr($raw, $headerSize),
        'headers' => parseHeaders(substr($raw, 0, $headerSize)),
    ];
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

$cleanup = static function () use ($admin, $database, &$serverProcess, &$serverLog): void {
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
    // Anything the install-from-a-file checks put on disk. Deliberately named
    // one by one rather than wiping plugins/, which holds the bundled ones.
    $strays = [PORTAL_PLUGINS . '/smoketest', PORTAL_PLUGINS . '/evil'];
    foreach ((array) glob(PORTAL_PLUGINS . '/.*.replacing-*', GLOB_ONLYDIR) as $backup) {
        if (is_string($backup)) {
            $strays[] = $backup;
        }
    }

    foreach ($strays as $stray) {
        if (!is_dir($stray)) {
            continue;
        }

        foreach ((array) glob($stray . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        // Retried, because on Windows rmdir routinely loses a race with the OS
        // releasing the handle to a file deleted microseconds earlier. Left
        // alone this leaves an empty folder in plugins/ — harmless, since
        // discovery skips anything without a plugin.php, but it accumulates and
        // it makes a clean tree look dirty.
        for ($attempt = 0; $attempt < 5 && is_dir($stray); $attempt++) {
            clearstatcache(true, $stray);
            @rmdir($stray);
            if (is_dir($stray)) {
                usleep(100000);
            }
        }

        if (is_dir($stray)) {
            // Something outside this script is holding it — on a tree synced by
            // OneDrive, which is where this repo lives, that is routine. The
            // folder is now empty and discovery ignores anything without a
            // plugin.php, so it is untidy rather than harmful, and the next run
            // sweeps it at startup.
            echo "  note: {$stray} is empty but could not be removed; the next run will clear it.\n";
        }
    }
    @unlink(PORTAL_PUBLIC . '/smoke-hack.php');

    if (isset($serverLog) && is_file($serverLog)) {
        @unlink($serverLog);
    }
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    echo "\nCleaned up.\n";
};

register_shutdown_function($cleanup);

/*
 * Sweep anything a previous run could not delete.
 *
 * Removing a directory can fail on a tree a sync agent is watching, so the
 * teardown is not guaranteed to succeed. Doing it again at STARTUP is, because
 * by now nothing is holding it — and it means residue can never accumulate
 * across runs even when an individual teardown loses the race.
 */
foreach ([PORTAL_PLUGINS . '/smoketest', PORTAL_PLUGINS . '/evil'] as $stale) {
    if (is_dir($stale)) {
        foreach ((array) glob($stale . '/*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($stale);
    }
}

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
        // A pull zone is configured so thumbnail URLs are actually minted.
        // Signing is local — no network call — and without it every thumbnail
        // would be null, which would quietly make the members-only artwork
        // checks below assert nothing at all.
        'video' => ['slug' => 'bunny', 'credentials' => [
            'library_id' => '1', 'api_key' => 'x', 'token_auth_key' => 'y',
            'cdn_hostname' => 'vz-smoke-test.b-cdn.net', 'cdn_token_key' => 'z',
        ]],
        // Mail is deliberately left unconfigured: an empty host makes
        // SmtpProvider::isConfigured() false, so send() returns a failure
        // immediately without touching the network.
        //
        // Two earlier attempts here both stalled the whole run, because PHP's
        // built-in server is SINGLE-THREADED — one blocking request freezes
        // every subsequent one, and the failures show up as connection errors
        // on unrelated checks further down.
        //
        //   php_mail  hands off to the SMTP host in php.ini (localhost:25 by
        //             default) and blocks until that times out.
        //   smtp to a refused port  was slower than expected on Windows.
        //
        // The send paths are covered thoroughly by ShareMailerTest against a
        // recording provider, which is the right place for them. What this
        // script is for is proving the ROUTES behave, and they behave the same
        // whether or not a message actually left the building.
        'mail'  => ['slug' => 'smtp', 'credentials' => [
            'host' => '',
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

/*
 * Disable the scheduled jobs.
 *
 * Pseudo-cron runs after the response is sent, and videos.sync calls the video
 * provider over HTTPS. With the placeholder credentials this install has, that
 * call hangs until its timeout — and PHP's built-in server is single-threaded,
 * so the whole run freezes behind it. The symptom is baffling: unrelated checks
 * further down fail with connection errors while the server appears alive.
 *
 * The /cron endpoint is still exercised below; with nothing due it reports
 * exactly that, which is all those checks are asserting anyway.
 */
$db->execute('UPDATE {cron_jobs} SET is_enabled = 0');

echo "Seeded content, scheduled jobs disabled.\n\n";

// -------------------------------------------------------------------- serve

/*
 * The server's output goes to a FILE, not a pipe.
 *
 * PHP's built-in server writes a log line per request to stderr. Handed a pipe
 * that nobody drains, it fills the OS buffer after roughly twenty-five
 * requests and then blocks forever on the next write — mid-request, holding
 * the single-threaded server hostage.
 *
 * The symptom is thoroughly misleading: the run passes for a while, then every
 * later check fails with a connection error, and adding checks anywhere makes
 * previously-passing ones start failing. Two rounds of blaming the mail
 * provider and the cron scheduler went by before the buffer was the answer.
 */
$serverLog = sys_get_temp_dir() . '/portal-smoke-server-' . getmypid() . '.log';

/*
 * Refuse to start if something is already on the port.
 *
 * The teardown kills the server through its process tree, and that does not
 * always work — a run interrupted at the wrong moment, or a shell that exited
 * before its child, leaves the old server listening. The readiness probe below
 * would then connect to IT: old code, and a database this run is about to
 * create and the previous run already dropped.
 *
 * Every check would still execute, and the failures would point everywhere
 * except at the cause. Better to stop before the first one.
 */
$occupied = @fsockopen('127.0.0.1', $serverPort, $errno, $errstr, 1);
if ($occupied !== false) {
    fclose($occupied);
    fwrite(STDERR, sprintf(
        "Something is already listening on port %d — almost certainly a server left over from an\n"
        . "earlier run. This script would silently test that one instead of the code you just changed.\n\n"
        . "Stop it first:\n"
        . "  Windows:  Get-Process php ^| Stop-Process -Force\n"
        . "  Otherwise: pkill -f 'php -S 127.0.0.1:%d'\n",
        $serverPort,
        $serverPort
    ));
    exit(1);
}

$descriptors = [
    1 => ['file', $serverLog, 'a'],
    2 => ['file', $serverLog, 'a'],
];

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

echo "\nAdmin sharing (signed in)\n";

/*
 * Everything above is anonymous. This signs in as the administrator the
 * installer created and drives the admin sharing screens for real — the only
 * way to know the forms, the routes, and the capability checks agree with each
 * other.
 */
$jar = sys_get_temp_dir() . '/portal-smoke-cookies-' . getmypid() . '.txt';
@unlink($jar);

$login = postWithJar($baseUrl . '/auth/login', [
    'email'    => 'admin@smoke.test',
    'password' => 'smoke-test-password-1234',
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $jar)['body']),
    'returnTo' => '/admin',
], $jar);

check('Administrator can sign in', $login['status'] === 302, "got {$login['status']}");

$adminHome = getWithJar($baseUrl . '/admin', $jar);
check('Admin dashboard renders when signed in', $adminHome['status'] === 200, "got {$adminHome['status']}");
check('Sharing appears in the admin navigation', str_contains($adminHome['body'], '/admin/shares'));

$sharesPage = getWithJar($baseUrl . '/admin/shares', $jar);
check('Sharing screen renders', $sharesPage['status'] === 200, "got {$sharesPage['status']}");
check('Share creation form is present', str_contains($sharesPage['body'], 'name="videos[]"'));
check('Both access modes are offered', str_contains($sharesPage['body'], 'no account needed'));

$groupsPage = getWithJar($baseUrl . '/admin/shares/groups', $jar);
check('Viewer groups screen renders', $groupsPage['status'] === 200, "got {$groupsPage['status']}");
check(
    'Groups screen says a group grants nothing',
    str_contains($groupsPage['body'], 'grants') || str_contains($groupsPage['body'], 'no access')
);

/* Create a share through the real form, then open the link it produced. */
$token = csrfFrom($sharesPage['body']);

$created = postWithJar($baseUrl . '/admin/shares/create', [
    '_token'      => $token,
    'videos'      => [(string) $videoRow],
    'emails'      => 'formtest@smoke.test',
    'access_mode' => 'gate',
    'hours'       => '72',
    'watermark'   => 'default',
], $jar);

check('Creating a share through the form succeeds', $created['status'] === 302, "got {$created['status']}");

$formShareId = (string) $db->value(
    'SELECT id FROM {shares} WHERE recipient_email = ? ORDER BY created_at DESC LIMIT 1',
    ['formtest@smoke.test']
);
check('The form actually created a link', $formShareId !== '', 'no share row appeared');

if ($formShareId !== '') {
    $opened = get($baseUrl . '/s/' . $formShareId);
    check('That link opens the gate form', $opened['status'] === 200, "got {$opened['status']}");

    /* And revoke it through the same admin route. */
    $revokedViaUi = postWithJar($baseUrl . '/admin/shares/act', [
        '_token' => $token,
        'action' => 'revoke',
        'id'     => $formShareId,
    ], $jar);

    check('Revoking through the admin succeeds', $revokedViaUi['status'] === 302, "got {$revokedViaUi['status']}");

    $afterRevoke = get($baseUrl . '/s/' . $formShareId);
    check('The revoked link stops working immediately', $afterRevoke['status'] === 404, "got {$afterRevoke['status']}");
}

/* A CSRF-less post must be refused. */
$noCsrf = postWithJar($baseUrl . '/admin/shares/create', [
    'videos' => [(string) $videoRow],
    'emails' => 'nocsrf@smoke.test',
], $jar);
check('A form post without a CSRF token is refused', $noCsrf['status'] === 419, "got {$noCsrf['status']}");

echo "\nContent editing (signed in)\n";

/*
 * The video and category edit screens.
 *
 * Their save handlers existed long before any form did, so everything below is
 * really asking one question: can an administrator actually reach the settings
 * the backend has always accepted?
 */
$videoList = getWithJar($baseUrl . '/admin/videos', $jar);
check('Videos screen links to an edit page', str_contains($videoList['body'], '/admin/videos/' . $videoRow));

$videoEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('Video edit screen renders', $videoEdit['status'] === 200, "got {$videoEdit['status']}");
check('It offers category assignment', str_contains($videoEdit['body'], 'name="categories[]"'));
check('It offers the thumbnail setting', str_contains($videoEdit['body'], 'name="thumbnail_mode"'));
check('It says what "inherit" currently resolves to', str_contains($videoEdit['body'], 'Inherit — currently'));

$categoryRow = (int) $db->value('SELECT id FROM {categories} LIMIT 1');

$categoryEdit = getWithJar($baseUrl . '/admin/categories/' . $categoryRow, $jar);
check('Category edit screen renders', $categoryEdit['status'] === 200, "got {$categoryEdit['status']}");
check('It offers the thumbnail setting', str_contains($categoryEdit['body'], 'name="thumbnail_mode"'));

/* Put the video in the category — the thing that was impossible before. */
$assigned = postWithJar($baseUrl . '/admin/videos', [
    '_token'         => csrfFrom($videoEdit['body']),
    'id'             => (string) $videoRow,
    'action'         => 'save',
    'title'          => 'A Test Video',
    'categories'     => [(string) $categoryRow],
    'thumbnail_mode' => 'default',
    'watermark_mode' => 'default',
    // What the real form sends: this POST carries every field, so an absent
    // checkbox means unticked rather than omitted.
    '_whole_form'    => '1',
], $jar);

check('Saving a video succeeds', $assigned['status'] === 302, "got {$assigned['status']}");
check(
    'The video is now in the category',
    (int) $db->value(
        'SELECT COUNT(*) FROM {video_categories} WHERE video_id = ? AND category_id = ?',
        [$videoRow, $categoryRow]
    ) === 1,
    'the assignment did not stick'
);

echo "\nInstalling from a file\n";

$pluginsScreen = getWithJar($baseUrl . '/admin/plugins', $jar);
check('Plugins screen offers an installer', str_contains($pluginsScreen['body'], 'name="package"'));
check(
    'It says plainly that a plugin is code',
    str_contains($pluginsScreen['body'], 'is code that runs on this site'),
    'an admin about to install something from a forum post is exactly who needs telling'
);

$themesScreen = getWithJar($baseUrl . '/admin/themes', $jar);
check('Appearance screen offers an installer', str_contains($themesScreen['body'], 'name="package"'));

/*
 * Upload a real archive through the real form, then a hostile one.
 *
 * multipart has to be built by hand here because postWithJar sends urlencoded
 * bodies; a form that only ever gets urlencoded input is not the form users hit.
 */
$goodZip = sys_get_temp_dir() . '/portal-smoke-plugin-' . getmypid() . '.zip';
$zip = new ZipArchive();
$zip->open($goodZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('smoketest/plugin.php', "<?php\n/**\n * Plugin Name: Smoke Test Plugin\n */\n");
$zip->close();

$installed = uploadWithJar(
    $baseUrl . '/admin/plugins/install',
    ['_token' => csrfFrom($pluginsScreen['body'])],
    'package',
    $goodZip,
    $jar
);

check('Installing a plugin from a file succeeds', $installed['status'] === 302, "got {$installed['status']}");
check(
    'The plugin folder appeared',
    is_dir(PORTAL_PLUGINS . '/smoketest'),
    'nothing was written'
);
check(
    'It is recorded but switched off',
    (int) ($db->value('SELECT is_active FROM {plugins} WHERE slug = ?', ['smoketest']) ?? -1) === 0,
    'an uploaded plugin must not activate itself'
);

$evilZip = sys_get_temp_dir() . '/portal-smoke-evil-' . getmypid() . '.zip';
$zip = new ZipArchive();
$zip->open($evilZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('evil/plugin.php', "<?php\n/**\n * Plugin Name: Evil\n */\n");
$zip->addFromString('evil/../../public/smoke-hack.php', '<?php echo "pwned";');
$zip->close();

uploadWithJar(
    $baseUrl . '/admin/plugins/install',
    ['_token' => csrfFrom($pluginsScreen['body'])],
    'package',
    $evilZip,
    $jar
);

check(
    'A traversal archive writes nothing outside its folder',
    !is_file(PORTAL_PUBLIC . '/smoke-hack.php'),
    'AN ARCHIVE ESCAPED ITS DIRECTORY'
);
check('And it is not installed either', !is_dir(PORTAL_PLUGINS . '/evil'));

$noCsrfInstall = uploadWithJar($baseUrl . '/admin/plugins/install', [], 'package', $goodZip, $jar);
check(
    'Installing without a CSRF token is refused',
    $noCsrfInstall['status'] === 419,
    "got {$noCsrfInstall['status']}"
);

@unlink($goodZip);
@unlink($evilZip);

echo "\nExport and import\n";

$export = getWithJar($baseUrl . '/admin/settings/export', $jar);
check('Settings export downloads', $export['status'] === 200, "got {$export['status']}");
check('It is offered as a file', str_contains($export['headers']['content-disposition'] ?? '', 'attachment'));

$exported = json_decode($export['body'], true);
check('It is valid JSON', is_array($exported), 'the export could not be parsed');
check('It carries the site settings', isset($exported['settings']['site_name']));
check(
    'It carries no credentials',
    !str_contains(strtolower($export['body']), 'api_key')
        && !str_contains(strtolower($export['body']), 'password'),
    'a settings export must not become a secrets leak'
);

echo "\nPermissions\n";

$permsScreen = getWithJar($baseUrl . '/admin/permissions', $jar);
check('Permissions screen renders', $permsScreen['status'] === 200, "got {$permsScreen['status']}");
check('It lists the roles', str_contains($permsScreen['body'], 'Editor'));
check(
    'It says administrator is not editable here',
    str_contains($permsScreen['body'], 'Administrator is not on this screen'),
    'the no-self-escalation rule should be stated where someone would look for it'
);

$permsToken = csrfFrom($permsScreen['body']);

$madeGroup = postWithJar($baseUrl . '/admin/permissions', [
    '_token' => $permsToken,
    'action' => 'group-create',
    'name'   => 'Smoke Group',
], $jar);

check('Creating a permission group succeeds', $madeGroup['status'] === 302, "got {$madeGroup['status']}");

$groupId = (int) $db->value('SELECT id FROM {permission_groups} WHERE name = ?', ['Smoke Group']);
check('The group row exists', $groupId > 0, 'nothing was created');

$addedMember = postWithJar($baseUrl . '/admin/permissions', [
    '_token'   => $permsToken,
    'action'   => 'group-add-member',
    'group_id' => (string) $groupId,
    'email'    => 'Future.Person@Example.COM',
], $jar);

check('Adding a member succeeds', $addedMember['status'] === 302, "got {$addedMember['status']}");
check(
    'The address is normalised on the way in',
    $db->value('SELECT email FROM {group_members} WHERE group_id = ?', [$groupId]) === 'future.person@example.com',
    'stored as typed, so the same person could be added twice'
);

/* A scoped grant, and the rule that a site-only capability cannot be scoped. */
$grantScoped = postWithJar($baseUrl . '/admin/permissions', [
    '_token'       => $permsToken,
    'action'       => 'grant',
    'subject_type' => 'email',
    'email'        => 'scoped@smoke.test',
    'capability'   => 'manage_videos',
    'scope'        => 'category:' . $categoryRow,
], $jar);

check('Granting a scoped permission succeeds', $grantScoped['status'] === 302, "got {$grantScoped['status']}");
check(
    'It is stored against the category',
    (int) $db->value(
        'SELECT scope_id FROM {grants} g JOIN {capabilities} c ON c.id = g.capability_id
          WHERE g.email = ? AND c.slug = ?',
        ['scoped@smoke.test', 'manage_videos']
    ) === $categoryRow,
    'the scope was lost'
);

postWithJar($baseUrl . '/admin/permissions', [
    '_token'       => $permsToken,
    'action'       => 'grant',
    'subject_type' => 'email',
    'email'        => 'sitewide@smoke.test',
    'capability'   => 'manage_plugins',
    'scope'        => 'category:' . $categoryRow,
], $jar);

check(
    'A site-only capability is forced site-wide',
    (string) $db->value(
        'SELECT scope_type FROM {grants} g JOIN {capabilities} c ON c.id = g.capability_id
          WHERE g.email = ? AND c.slug = ?',
        ['sitewide@smoke.test', 'manage_plugins']
    ) === 'site',
    'a grant was stored implying a limit that is never applied when it is checked'
);

echo "\nTrash\n";

/*
 * Delete, restore, and confirm a permanent delete refuses when the video
 * service cannot be reached — which, with this install's placeholder
 * credentials, is always. That refusal is the interesting path: removing the
 * local row alone would let the next sync re-import the video.
 */
$trashVideo = (int) $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-trash', 'slug' => 'trash-me',
    'title' => 'Trash Me', 'status' => 'ready', 'is_published' => 1,
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);

$deleted = postWithJar($baseUrl . '/admin/videos', [
    '_token' => $token,
    'id'     => (string) $trashVideo,
    'action' => 'delete',
], $jar);

check('Deleting a video succeeds', $deleted['status'] === 302, "got {$deleted['status']}");
check(
    'It is a soft delete',
    $db->value('SELECT deleted_at FROM {videos} WHERE id = ?', [$trashVideo]) !== null,
    'the row was destroyed rather than trashed'
);

$publicAfterDelete = get($baseUrl . '/');
check('A trashed video leaves the library', !str_contains($publicAfterDelete['body'], 'Trash Me'));

$trashScreen = getWithJar($baseUrl . '/admin/videos/trash', $jar);
check('Trash screen renders', $trashScreen['status'] === 200, "got {$trashScreen['status']}");
check('The trashed video is listed', str_contains($trashScreen['body'], 'Trash Me'));

$trashToken = csrfFrom($trashScreen['body']);

$purge = postWithJar($baseUrl . '/admin/videos/trash', [
    '_token' => $trashToken,
    'id'     => (string) $trashVideo,
    'action' => 'purge',
], $jar);

check('Permanent delete is attempted', $purge['status'] === 302, "got {$purge['status']}");
check(
    'It refuses when the video service cannot be reached',
    $db->value('SELECT id FROM {videos} WHERE id = ?', [$trashVideo]) !== null,
    'the row went even though the provider delete failed — the next sync would bring it back'
);

$restored = postWithJar($baseUrl . '/admin/videos/trash', [
    '_token' => $trashToken,
    'id'     => (string) $trashVideo,
    'action' => 'restore',
], $jar);

check('Restoring succeeds', $restored['status'] === 302, "got {$restored['status']}");
check(
    'The video comes back',
    $db->value('SELECT deleted_at FROM {videos} WHERE id = ?', [$trashVideo]) === null,
    'restore did not clear the deletion'
);

/*
 * Put it back in the trash before moving on.
 *
 * A restored video sits outside the category the thumbnail checks below lock,
 * so leaving it visible puts a legitimate CDN URL on the homepage and fails
 * "the real thumbnail URL is never sent" for a reason that has nothing to do
 * with thumbnails. A section that leaves state behind is the bug, not the
 * assertion that trips over it.
 */
$db->execute('UPDATE {videos} SET deleted_at = NOW() WHERE id = ?', [$trashVideo]);

echo "\nSeries and speakers\n";

$seriesScreen = getWithJar($baseUrl . '/admin/series', $jar);
check('Series screen renders', $seriesScreen['status'] === 200, "got {$seriesScreen['status']}");
check('Series appears in the admin navigation', str_contains($adminHome['body'], '/admin/series'));

/* Creating a series lands on its edit screen, where episodes are added. */
$madeSeries = postWithJar($baseUrl . '/admin/series', [
    '_token' => csrfFrom($seriesScreen['body']),
    'action' => 'create',
    'title'  => 'Smoke Series',
], $jar);

check('Creating a series succeeds', $madeSeries['status'] === 302, "got {$madeSeries['status']}");

$seriesId = (int) $db->value('SELECT id FROM {series} WHERE title = ? LIMIT 1', ['Smoke Series']);
check('The series row exists', $seriesId > 0, 'nothing was created');
check(
    'It redirects straight to the edit screen',
    str_contains($madeSeries['headers']['location'] ?? '', '/admin/series/' . $seriesId),
    'landed on ' . ($madeSeries['headers']['location'] ?? 'nowhere')
);

$seriesEdit = getWithJar($baseUrl . '/admin/series/' . $seriesId, $jar);
check('Series edit screen renders', $seriesEdit['status'] === 200, "got {$seriesEdit['status']}");
check('It offers an episode picker', str_contains($seriesEdit['body'], 'name="videos[]"'));

/* Put the seeded video in it, then confirm the public page works. */
$episodes = postWithJar($baseUrl . '/admin/series', [
    '_token' => csrfFrom($seriesEdit['body']),
    'action' => 'episodes',
    'id'     => (string) $seriesId,
    'videos' => [(string) $videoRow],
], $jar);

check('Adding an episode succeeds', $episodes['status'] === 302, "got {$episodes['status']}");
check(
    'The video is now in the series',
    (int) $db->value('SELECT series_id FROM {videos} WHERE id = ?', [$videoRow]) === $seriesId,
    'the assignment did not stick'
);

$seriesSlug = (string) $db->value('SELECT slug FROM {series} WHERE id = ?', [$seriesId]);
$publicSeries = get($baseUrl . '/series/' . $seriesSlug);
check('The public series page renders', $publicSeries['status'] === 200, "got {$publicSeries['status']}");
check('It lists the episode', str_contains($publicSeries['body'], 'A Test Video'));

/* Renaming keeps the old address alive. */
postWithJar($baseUrl . '/admin/series', [
    '_token'       => csrfFrom($seriesEdit['body']),
    'action'       => 'update',
    'id'           => (string) $seriesId,
    'title'        => 'Smoke Series',
    'slug'         => 'renamed-series',
    'is_published' => '1',
], $jar);

$oldAddress = get($baseUrl . '/series/' . $seriesSlug);
check(
    'The old series address still resolves',
    $oldAddress['status'] === 301,
    "got {$oldAddress['status']} — a printed link would have broken"
);

$speakersScreen = getWithJar($baseUrl . '/admin/speakers', $jar);
check('Speakers screen renders', $speakersScreen['status'] === 200, "got {$speakersScreen['status']}");

$madeSpeaker = postWithJar($baseUrl . '/admin/speakers', [
    '_token' => csrfFrom($speakersScreen['body']),
    'action' => 'create',
    'name'   => 'Smoke Speaker',
], $jar);

check('Adding a speaker succeeds', $madeSpeaker['status'] === 302, "got {$madeSpeaker['status']}");

$speakerSlug = (string) $db->value('SELECT slug FROM {speakers} WHERE name = ?', ['Smoke Speaker']);
check('It derived an address', $speakerSlug === 'smoke-speaker', "got '{$speakerSlug}'");

$publicSpeaker = get($baseUrl . '/speaker/' . $speakerSlug);
check('The public speaker page renders', $publicSpeaker['status'] === 200, "got {$publicSpeaker['status']}");

echo "\nUploading\n";

/*
 * The upload panel, but deliberately NOT a real upload.
 *
 * /admin/upload/ticket creates a video at bunny.net over HTTPS. With the
 * placeholder credentials this install has, that call blocks until it times
 * out — and PHP's built-in server is single-threaded, so it would freeze every
 * check after it. This has already cost three rounds of debugging once.
 *
 * The CSRF refusal below is safe precisely because verifyCsrf() runs before
 * the provider is ever touched, which is worth knowing independently.
 */
check('Upload script is served', get($baseUrl . '/assets/upload.js')['status'] === 200);

$videosScreen = getWithJar($baseUrl . '/admin/videos', $jar);
check('Upload panel renders when a provider is configured', str_contains($videosScreen['body'], 'id="upload-panel"'));
check('It offers a drop zone', str_contains($videosScreen['body'], 'id="upload-drop"'));
check(
    'It explains that files bypass this server',
    // Deliberately a short phrase: the markup is wrapped, so anything longer
    // straddles a newline and fails for reasons that have nothing to do with
    // what is on the screen.
    str_contains($videosScreen['body'], 'not pass through this site')
);

$ticketNoCsrf = postWithJar($baseUrl . '/admin/upload/ticket', ['title' => 'Nope'], $jar);
check(
    'An upload ticket without a CSRF token is refused',
    $ticketNoCsrf['status'] === 419,
    "got {$ticketNoCsrf['status']} — and if this ever hangs instead, CSRF stopped being checked first"
);

$status = getWithJar($baseUrl . '/admin/upload/status?ids[]=' . $videoRow, $jar);
check('Encoding status is reported', $status['status'] === 200, "got {$status['status']}");
check('It names the video it was asked about', str_contains($status['body'], '"id":' . $videoRow));

echo "\nMembers-only thumbnails\n";

/*
 * The artwork is withheld by never minting the URL, so the only honest test is
 * to read what a signed-out visitor's HTML actually contains.
 */
$publicBefore = get($baseUrl . '/');
check(
    'A guest sees real artwork by default',
    str_contains($publicBefore['body'], 'b-cdn.net'),
    'no thumbnail URL was rendered at all, so the checks below would prove nothing'
);

/* Lock the whole category. */
$lockCategory = postWithJar($baseUrl . '/admin/categories', [
    '_token'         => csrfFrom($categoryEdit['body']),
    'id'             => (string) $categoryRow,
    'action'         => 'update',
    'name'           => 'Sermons',
    'is_published'   => '1',
    'thumbnail_mode' => 'members',
], $jar);

check('Locking a category succeeds', $lockCategory['status'] === 302, "got {$lockCategory['status']}");

$publicAfter = get($baseUrl . '/');
check('The library still lists the video', str_contains($publicAfter['body'], 'A Test Video'));
check('A guest is shown the placeholder', str_contains($publicAfter['body'], 'Members only'));
check(
    'The real thumbnail URL is never sent',
    !str_contains($publicAfter['body'], 'b-cdn.net'),
    'the artwork was hidden in the markup rather than withheld — it is a right-click away'
);

/* An administrator can still watch, so they still see the artwork. */
$adminView = getWithJar($baseUrl . '/', $jar);
check(
    'Someone who can watch still sees the artwork',
    str_contains($adminView['body'], 'b-cdn.net'),
    'the placeholder is showing to people who are entitled to the real thing'
);

/* And one video can be forced public inside a locked category. */
$forcePublic = postWithJar($baseUrl . '/admin/videos', [
    '_token'         => csrfFrom($videoEdit['body']),
    'id'             => (string) $videoRow,
    'action'         => 'save',
    'title'          => 'A Test Video',
    'categories'     => [(string) $categoryRow],
    'thumbnail_mode' => 'public',
], $jar);

check('Overriding one video succeeds', $forcePublic['status'] === 302, "got {$forcePublic['status']}");

/*
 * That POST carried a subset of the form — no series, no speaker, no
 * checkboxes — which is exactly the shape a plugin screen or a bulk action
 * would send. It must change what it named and nothing else.
 *
 * This is not hypothetical. Before the fix it silently detached the video from
 * its series and its speaker, and the only reason anybody noticed was that a
 * search filter written weeks later stopped finding it.
 */
check(
    'A partial save leaves the series alone',
    (int) $db->value('SELECT series_id FROM {videos} WHERE id = ?', [$videoRow]) === $seriesId,
    'saving one field wiped the series assignment'
);
check(
    'A partial save leaves the categories alone',
    (int) $db->value(
        'SELECT COUNT(*) FROM {video_categories} WHERE video_id = ? AND category_id = ?',
        [$videoRow, $categoryRow]
    ) === 1,
    'saving one field emptied the category list'
);

$publicOverride = get($baseUrl . '/');
check(
    'A video set to public overrides its locked category',
    str_contains($publicOverride['body'], 'b-cdn.net'),
    'the per-video escape hatch does not work'
);

/* Put it back, so the plugin checks below run against a normal library. */
postWithJar($baseUrl . '/admin/categories', [
    '_token'         => csrfFrom($categoryEdit['body']),
    'id'             => (string) $categoryRow,
    'action'         => 'update',
    'name'           => 'Sermons',
    'is_published'   => '1',
    'thumbnail_mode' => 'default',
], $jar);

echo "\nBundled plugins (signed in)\n";

/*
 * The bundled plugins, driven through the admin the way an owner would.
 *
 * Unit tests already pin the decision logic and an integration test fires the
 * hooks directly. Neither can tell you whether the plugin loads inside a real
 * request — a plugin that fatals on load is caught, logged, and silently
 * deactivated, which looks exactly like a plugin that is working but has
 * nothing to do.
 */
$pluginsPage = getWithJar($baseUrl . '/admin/plugins', $jar);
check('Plugins screen renders', $pluginsPage['status'] === 200, "got {$pluginsPage['status']}");
check('Watermark is listed', str_contains($pluginsPage['body'], 'Watermark'));
check('Country restrictions is listed', str_contains($pluginsPage['body'], 'Country restrictions'));

$pluginToken = csrfFrom($pluginsPage['body']);

foreach (['watermark', 'geo', 'comments', 'ratings'] as $slug) {
    $activated = postWithJar($baseUrl . '/admin/plugins', [
        '_token' => $pluginToken,
        'slug'   => $slug,
        'action' => 'activate',
    ], $jar);

    check("Activating {$slug} succeeds", $activated['status'] === 302, "got {$activated['status']}");
    check(
        "{$slug} stayed active after the redirect",
        (int) $db->value('SELECT is_active FROM {plugins} WHERE slug = ?', [$slug]) === 1,
        'it was deactivated again, which means it threw on load — check the error log'
    );
}

/*
 * A plugin's admin page has to be both reachable and linked. An unlinked page
 * is one only somebody who read the source could ever find.
 */
$watermarkPage = getWithJar($baseUrl . '/admin/watermark', $jar);
check('Watermark settings page renders', $watermarkPage['status'] === 200, "got {$watermarkPage['status']}");
check('It explains the resolution order', str_contains($watermarkPage['body'], 'Exempt address'));

$geoPage = getWithJar($baseUrl . '/admin/geo', $jar);
check('Country settings page renders', $geoPage['status'] === 200, "got {$geoPage['status']}");
check(
    'It reports whether the host sends a country at all',
    str_contains($geoPage['body'], 'does not report visitor countries')
        || str_contains($geoPage['body'], 'This request came from'),
    'the diagnostic is the one thing on that screen worth reading'
);

$adminAfter = getWithJar($baseUrl . '/admin', $jar);
check('Plugin pages appear in the admin navigation', str_contains($adminAfter['body'], '/admin/watermark'));

/*
 * The point of the whole plugin: an actual watermark on an actual player.
 * Made as an account-mode share for the signed-in administrator, because that
 * is the one recipient this script can authenticate as.
 */
$marked = $shares->create($videoRow, 'admin@smoke.test', [
    'accessMode' => 'account',
    'watermark'  => 'on',
]);

$player = getWithJar($baseUrl . '/s/' . $marked->id, $jar);
check('A watermarked share plays for its recipient', $player['status'] === 200, "got {$player['status']}");
check(
    'The watermark is drawn with the viewer address',
    str_contains($player['body'], 'pw-mark') && str_contains($player['body'], 'admin@smoke.test'),
    'the plugin loaded but drew nothing'
);

$unmarked = $shares->create($videoRow, 'admin@smoke.test', [
    'accessMode' => 'account',
    'watermark'  => 'off',
]);

$plain = getWithJar($baseUrl . '/s/' . $unmarked->id, $jar);
check(
    'A share set to never watermark is left clean',
    $plain['status'] === 200 && !str_contains($plain['body'], 'pw-mark'),
    "got {$plain['status']}"
);

/*
 * Geo is active but restricts nothing, because config.php has empty country
 * lists. That combination is the single most dangerous one to get wrong: it is
 * what every install looks like the moment someone activates the plugin, and
 * reading an empty list as "block everyone" would take the whole site down.
 */
$stillUp = get($baseUrl . '/');
check(
    'Activating geo with empty lists restricts nothing',
    $stillUp['status'] === 302 || $stillUp['status'] === 200,
    "got {$stillUp['status']} — an empty country list must never mean 'block everyone'"
);

$adminStillUp = getWithJar($baseUrl . '/admin', $jar);
check('The admin area is still reachable with geo active', $adminStillUp['status'] === 200, "got {$adminStillUp['status']}");

echo "\nComments\n";

/*
 * The comments plugin is active by now — the loop above switched it on. What
 * matters here is the moderation boundary: a held comment must not reach the
 * page, which is the one failure nobody notices until it is public.
 */
$commentsAdmin = getWithJar($baseUrl . '/admin/comments', $jar);
check('Moderation screen renders', $commentsAdmin['status'] === 200, "got {$commentsAdmin['status']}");
check('It offers the moderation setting', str_contains($commentsAdmin['body'], 'name="moderation"'));

$videoSlug = (string) $db->value('SELECT slug FROM {videos} WHERE id = ?', [$videoRow]);

$watchPage = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check('The thread renders under the video', str_contains($watchPage['body'], 'id="comments"'));
check('A signed-in viewer gets a form', str_contains($watchPage['body'], 'class="comment-form"'));

$posted = postWithJar($baseUrl . '/comments/' . $videoRow, [
    '_token'    => csrfFrom($watchPage['body']),
    'body'      => 'A first comment from the smoke test.',
    'parent_id' => '',
], $jar);

/*
 * The redirect TARGET is asserted, not just the 302.
 *
 * Signed out, this endpoint also answers 302 — to the sign-in page. A bare
 * status check therefore passes while testing nothing, which is exactly what
 * happened the first time this section ran with an expired cookie jar.
 */
check(
    'Posting a comment succeeds',
    $posted['status'] === 302 && str_contains($posted['headers']['location'] ?? '', '/watch/'),
    'got ' . $posted['status'] . ' to ' . ($posted['headers']['location'] ?? 'nowhere')
);

$commentId = (int) $db->value('SELECT id FROM {comments} ORDER BY id DESC LIMIT 1');
check('The comment was stored', $commentId > 0, 'nothing was written');

/*
 * The administrator is a newcomer by the plugin's own rule — no approved
 * comments yet — so this one is held. That is the default doing its job.
 */
$storedStatus = (string) $db->value('SELECT status FROM {comments} WHERE id = ?', [$commentId]);
check(
    "A newcomer's first comment is held",
    $storedStatus === 'pending',
    "got '{$storedStatus}'"
);

$anonymousWatch = get($baseUrl . '/watch/' . $videoSlug);
check(
    'A held comment is not on the page',
    !str_contains($anonymousWatch['body'], 'A first comment from the smoke test'),
    'AN UNMODERATED COMMENT WAS PUBLISHED'
);

/* Approve it, and only then does it appear. */
$approved = postWithJar($baseUrl . '/admin/comments', [
    '_token' => csrfFrom($commentsAdmin['body']),
    'id'     => (string) $commentId,
    'action' => 'approved',
], $jar);

check('Approving succeeds', $approved['status'] === 302, "got {$approved['status']}");

$afterApproval = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'An approved comment appears',
    str_contains($afterApproval['body'], 'A first comment from the smoke test'),
    'approval did not publish it'
);

/* Spam is held whatever the setting says. */
postWithJar($baseUrl . '/admin/comments', [
    '_token'     => csrfFrom($commentsAdmin['body']),
    'action'     => 'settings',
    'moderation' => 'none',
], $jar);

postWithJar($baseUrl . '/comments/' . $videoRow, [
    '_token'    => csrfFrom($watchPage['body']),
    'body'      => 'http://a.test http://b.test http://c.test http://d.test',
    'parent_id' => '',
], $jar);

$spamStatus = (string) $db->value('SELECT status FROM {comments} ORDER BY id DESC LIMIT 1');
check(
    'Obvious spam is held even with moderation off',
    $spamStatus === 'pending',
    "got '{$spamStatus}' — turning moderation off should not publish link farms"
);

$noCsrfComment = postWithJar($baseUrl . '/comments/' . $videoRow, ['body' => 'no token'], $jar);
check('Commenting without a CSRF token is refused', $noCsrfComment['status'] === 419, "got {$noCsrfComment['status']}");

/*
 * Reporting, end to end.
 *
 * The repository method had integration tests from the start and the feature
 * still did not exist: nothing on any page called it. Only a check that goes
 * through the rendered page and a real POST can tell those two apart.
 */
$afterApproval = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check('An approved comment offers a report button', str_contains($afterApproval['body'], 'comment-report'));

$reported = postWithJar($baseUrl . '/comments/report', [
    '_token'     => csrfFrom($afterApproval['body']),
    'comment_id' => (string) $commentId,
    'reason'     => 'smoke test',
], $jar);

check('Reporting succeeds', $reported['status'] === 302, "got {$reported['status']}");
check(
    'The report reaches the moderation queue',
    (int) $db->value('SELECT report_count FROM {comments} WHERE id = ?', [$commentId]) === 1,
    'the count did not move, so a moderator would never see it'
);

$reportedTwice = postWithJar($baseUrl . '/comments/report', [
    '_token'     => csrfFrom($afterApproval['body']),
    'comment_id' => (string) $commentId,
], $jar);

check('Reporting twice is accepted but not double-counted', $reportedTwice['status'] === 302);
check(
    'The same person cannot inflate the count',
    (int) $db->value('SELECT report_count FROM {comments} WHERE id = ?', [$commentId]) === 1,
    'one visitor could make an ordinary comment look like a crisis'
);

echo "\nRatings\n";

/*
 * Driven entirely through the rendered page and real POSTs, for the reason the
 * comments section learned the hard way: a repository with full coverage looks
 * identical whether or not anything on any page ever calls it.
 */
$ratingsAdmin = getWithJar($baseUrl . '/admin/ratings', $jar);
check('Ratings screen renders', $ratingsAdmin['status'] === 200, "got {$ratingsAdmin['status']}");
check('It offers the threshold setting', str_contains($ratingsAdmin['body'], 'name="minimum_votes"'));

$watchForRating = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check('The widget renders under the video', str_contains($watchForRating['body'], 'id="ratings"'));
check(
    'A signed-in viewer gets rating buttons',
    str_contains($watchForRating['body'], 'name="score"'),
    'the widget rendered but offered no way to use it'
);
check(
    'An unrated video says so rather than showing 0.0',
    str_contains($watchForRating['body'], 'Not rated yet'),
    'a zero average on an unrated video reads as a bad review'
);

$rated = postWithJar($baseUrl . '/ratings/' . $videoRow, [
    '_token' => csrfFrom($watchForRating['body']),
    'score'  => '4',
], $jar);

/* The target, not just the 302 — signed out this endpoint also answers 302. */
check(
    'Rating succeeds',
    $rated['status'] === 302 && str_contains($rated['headers']['location'] ?? '', '/watch/'),
    'got ' . $rated['status'] . ' to ' . ($rated['headers']['location'] ?? 'nowhere')
);

check(
    'The rating was stored',
    (int) $db->value('SELECT score FROM {ratings} WHERE video_id = ?', [$videoRow]) === 4,
    'nothing was written'
);
check(
    'The cached total was written with it',
    (int) $db->value('SELECT vote_count FROM {rating_totals} WHERE video_id = ?', [$videoRow]) === 1,
    'the widget would show an average nobody gave'
);

$afterRating = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'The average appears on the page',
    str_contains($afterRating['body'], '4.0'),
    'the rating was stored but never displayed'
);
check(
    'The widget remembers what you gave',
    str_contains($afterRating['body'], 'aria-pressed="true"'),
    'somebody who already rated cannot tell that they did'
);

/* Changing a rating replaces it. The row count is the claim, not the average. */
postWithJar($baseUrl . '/ratings/' . $videoRow, [
    '_token' => csrfFrom($afterRating['body']),
    'score'  => '2',
], $jar);

check(
    'Rating again replaces rather than adds',
    (int) $db->value('SELECT COUNT(*) FROM {ratings} WHERE video_id = ?', [$videoRow]) === 1
        && (int) $db->value('SELECT score FROM {ratings} WHERE video_id = ?', [$videoRow]) === 2,
    'one person voted twice'
);

/*
 * A tampered score is refused outright. Clamping it to 5 would record a
 * five-star rating nobody gave, and the page would look perfectly normal.
 */
postWithJar($baseUrl . '/ratings/' . $videoRow, [
    '_token' => csrfFrom($afterRating['body']),
    'score'  => '9',
], $jar);

check(
    'An out-of-range score is refused, not clamped',
    (int) $db->value('SELECT score FROM {ratings} WHERE video_id = ?', [$videoRow]) === 2,
    'a tampered form rewrote the rating'
);

$noCsrfRating = postWithJar($baseUrl . '/ratings/' . $videoRow, ['score' => '5'], $jar);
check('Rating without a CSRF token is refused', $noCsrfRating['status'] === 419, "got {$noCsrfRating['status']}");

/* Withdrawing takes the totals row with it, rather than leaving a zeroed one. */
postWithJar($baseUrl . '/ratings/' . $videoRow, [
    '_token' => csrfFrom($afterRating['body']),
    'action' => 'remove',
], $jar);

check(
    'Withdrawing a rating removes it',
    (int) $db->value('SELECT COUNT(*) FROM {ratings} WHERE video_id = ?', [$videoRow]) === 0,
    'the rating survived its own removal'
);
check(
    'And leaves no stale total behind',
    (int) $db->value('SELECT COUNT(*) FROM {rating_totals} WHERE video_id = ?', [$videoRow]) === 0,
    'the video would still show an average with no ratings behind it'
);

echo "\nPlaylists and saved videos\n";

/*
 * Driven through the admin the way an owner would, then read back as a
 * visitor. The repository tests already pin the ordering rules; what only this
 * can tell you is whether a person can reach any of it.
 */
$playlistScreen = getWithJar($baseUrl . '/admin/playlists', $jar);
check('Playlists screen renders', $playlistScreen['status'] === 200, "got {$playlistScreen['status']}");
check('Playlists appears in the admin navigation', str_contains($adminHome['body'], '/admin/playlists'));

$madePlaylist = postWithJar($baseUrl . '/admin/playlists', [
    '_token' => csrfFrom($playlistScreen['body']),
    'action' => 'create',
    'title'  => 'Smoke Playlist',
], $jar);

check('Creating a playlist succeeds', $madePlaylist['status'] === 302, "got {$madePlaylist['status']}");

$playlistId = (int) $db->value('SELECT id FROM {playlists} WHERE title = ? LIMIT 1', ['Smoke Playlist']);
check('The playlist row exists', $playlistId > 0, 'nothing was created');
check(
    'It redirects straight to the edit screen',
    str_contains($madePlaylist['headers']['location'] ?? '', '/admin/playlists/' . $playlistId),
    'landed on ' . ($madePlaylist['headers']['location'] ?? 'nowhere')
);

$playlistEdit = getWithJar($baseUrl . '/admin/playlists/' . $playlistId, $jar);
check('Playlist edit screen renders', $playlistEdit['status'] === 200, "got {$playlistEdit['status']}");
check('It offers a video picker', str_contains($playlistEdit['body'], 'name="videos[]"'));

$addedToPlaylist = postWithJar($baseUrl . '/admin/playlists', [
    '_token' => csrfFrom($playlistEdit['body']),
    'action' => 'items',
    'id'     => (string) $playlistId,
    'videos' => [(string) $videoRow],
], $jar);

check('Adding a video succeeds', $addedToPlaylist['status'] === 302, "got {$addedToPlaylist['status']}");
check(
    'The video is now on the playlist',
    (int) $db->value(
        'SELECT COUNT(*) FROM {playlist_items} WHERE playlist_id = ? AND video_id = ?',
        [$playlistId, $videoRow]
    ) === 1,
    'the assignment did not stick'
);

/*
 * The difference from a series, proved rather than asserted in a comment: the
 * video is still in its series afterwards.
 */
check(
    'Adding to a playlist does not take it out of its series',
    (int) $db->value('SELECT series_id FROM {videos} WHERE id = ?', [$videoRow]) === $seriesId,
    'a playlist behaved like a series and stole the video'
);

$playlistSlug = (string) $db->value('SELECT slug FROM {playlists} WHERE id = ?', [$playlistId]);

$publicPlaylist = get($baseUrl . '/playlist/' . $playlistSlug);
check('The public playlist page renders', $publicPlaylist['status'] === 200, "got {$publicPlaylist['status']}");
check('It lists the video', str_contains($publicPlaylist['body'], 'A Test Video'));

$libraryWithPlaylists = get($baseUrl . '/');
check(
    'The library links to the playlist',
    str_contains($libraryWithPlaylists['body'], '/playlist/' . $playlistSlug),
    'the playlist exists but nothing on the site leads to it'
);

/* Renaming keeps the old address alive, as it does everywhere else. */
postWithJar($baseUrl . '/admin/playlists', [
    '_token'       => csrfFrom($playlistEdit['body']),
    'action'       => 'update',
    'id'           => (string) $playlistId,
    'title'        => 'Smoke Playlist',
    'slug'         => 'renamed-playlist',
    'is_published' => '1',
], $jar);

$oldPlaylistAddress = get($baseUrl . '/playlist/' . $playlistSlug);
check(
    'The old playlist address still resolves',
    $oldPlaylistAddress['status'] === 301,
    "got {$oldPlaylistAddress['status']} — a printed link would have broken"
);

/* Saved videos, from the page a viewer actually uses. */
$watchForSaving = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'The watch page offers save buttons',
    str_contains($watchForSaving['body'], 'name="list"') && str_contains($watchForSaving['body'], 'watch_later'),
    'there is no way for a viewer to save anything'
);

$savedIt = postWithJar($baseUrl . '/saved', [
    '_token' => csrfFrom($watchForSaving['body']),
    'video'  => (string) $videoRow,
    'list'   => 'favorite',
], $jar);

check('Saving succeeds', $savedIt['status'] === 302, "got {$savedIt['status']}");
check(
    'The video was saved',
    (int) $db->value(
        'SELECT COUNT(*) FROM {saved_videos} WHERE video_id = ? AND list = ?',
        [$videoRow, 'favorite']
    ) === 1,
    'nothing was written'
);

$savedPage = getWithJar($baseUrl . '/saved', $jar);
check('The saved page renders', $savedPage['status'] === 200, "got {$savedPage['status']}");
check('It lists the saved video', str_contains($savedPage['body'], 'A Test Video'));
check('It links from the site navigation', str_contains($savedPage['body'], 'href="/saved"'));

$watchAfterSaving = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'The button shows it is already saved',
    str_contains($watchAfterSaving['body'], 'aria-pressed="true"'),
    'a viewer cannot tell whether they already saved it'
);

/* The same button unsaves — one control, both directions. */
postWithJar($baseUrl . '/saved', [
    '_token' => csrfFrom($watchAfterSaving['body']),
    'video'  => (string) $videoRow,
    'list'   => 'favorite',
], $jar);

check(
    'Pressing it again unsaves',
    (int) $db->value('SELECT COUNT(*) FROM {saved_videos} WHERE video_id = ?', [$videoRow]) === 0,
    'the toggle only goes one way'
);

/* An unknown list must write nothing rather than defaulting to a real one. */
postWithJar($baseUrl . '/saved', [
    '_token' => csrfFrom($watchAfterSaving['body']),
    'video'  => (string) $videoRow,
    'list'   => 'bookmarks',
], $jar);

check(
    'An unknown list is refused',
    (int) $db->value('SELECT COUNT(*) FROM {saved_videos}') === 0,
    'a tampered form put a video on a list nobody asked for'
);

$noCsrfSave = postWithJar($baseUrl . '/saved', [
    'video' => (string) $videoRow,
    'list'  => 'favorite',
], $jar);
check('Saving without a CSRF token is refused', $noCsrfSave['status'] === 419, "got {$noCsrfSave['status']}");

/* Signed out, the saved page is not a way to read somebody else's bookmarks. */
$anonymousSaved = get($baseUrl . '/saved');
check(
    'A signed-out visitor cannot open the saved page',
    $anonymousSaved['status'] === 302 || $anonymousSaved['status'] === 403,
    "got {$anonymousSaved['status']}"
);

echo "\nSearch\n";

/*
 * Driven signed out, because that is the visitor who uses search most and the
 * one whose visibility rules matter. By now the seeded video is called
 * "A Test Video" and sits in "Smoke Series"; the speaker is attached here so
 * the directory match has something to find.
 */
$db->execute('UPDATE {videos} SET speaker_id = (SELECT id FROM {speakers} WHERE name = ?) WHERE id = ?', [
    'Smoke Speaker',
    $videoRow,
]);

$searchPage = get($baseUrl . '/search');
check('Search page renders', $searchPage['status'] === 200, "got {$searchPage['status']}");
check('It offers the series filter', str_contains($searchPage['body'], 'name="series"'));
check('It offers the speaker filter', str_contains($searchPage['body'], 'name="speaker"'));
check('It offers the year filter', str_contains($searchPage['body'], 'name="year"'));

$home = get($baseUrl . '/');
check(
    'The library search box points at the search page',
    str_contains($home['body'], 'action="/search"'),
    'searching from the home page would skip the filters entirely'
);

$byTitle = get($baseUrl . '/search?q=test');
check('A title word finds the video', str_contains($byTitle['body'], 'A Test Video'));

/*
 * The headline fix, end to end.
 *
 * "smoke" is in the SERIES title and "test" is in the VIDEO title, so nothing
 * contains the phrase "smoke test". The implementation this replaced put the
 * whole query into one LIKE and returned nothing here.
 */
$twoWords = get($baseUrl . '/search?q=smoke+test');
check(
    'Two words matching different fields still find it',
    str_contains($twoWords['body'], 'A Test Video'),
    'a multi-word search found nothing — the old single-LIKE behaviour is back'
);

$bySpeaker = get($baseUrl . '/search?q=smoke+speaker');
check('A speaker name finds their video', str_contains($bySpeaker['body'], 'A Test Video'));

$noMatch = get($baseUrl . '/search?q=leviticus');
check(
    'A word that is nowhere finds nothing',
    !str_contains($noMatch['body'], 'A Test Video') && str_contains($noMatch['body'], 'Nothing matched'),
    'the filter was ignored'
);

/* A wildcard typed by a visitor is text, not syntax. */
$wildcard = get($baseUrl . '/search?q=%25');
check(
    'A per-cent sign does not match everything',
    !str_contains($wildcard['body'], 'A Test Video'),
    'LIKE wildcards are reaching the query unescaped'
);

/*
 * The series and the speaker are offered as destinations, not just as hits.
 *
 * Searched by their own name rather than by the two-word query above: the
 * series match requires every term to appear in the SERIES, and "test" is in
 * the video's title, not the series'. That is the AND rule working, not a bug.
 */
$bySeries = get($baseUrl . '/search?q=smoke+series');
check(
    'A matching series is offered to jump to',
    str_contains($bySeries['body'], 'search-jump') && str_contains($bySeries['body'], 'Smoke Series'),
    'somebody searching a series name has to reassemble it from episodes'
);
check(
    'A matching speaker is offered to jump to',
    str_contains($bySpeaker['body'], '/speaker/smoke-speaker'),
    'searching a name should reach the person, not only what they said'
);

/* Filters narrow, and narrow to nothing when they should. */
$realSeriesId = (int) $db->value('SELECT id FROM {series} WHERE title = ? LIMIT 1', ['Smoke Series']);
$filtered = get($baseUrl . '/search?q=test&series=' . $realSeriesId);
check('Filtering by the right series keeps the result', str_contains($filtered['body'], 'A Test Video'));

$filteredOut = get($baseUrl . '/search?q=test&series=' . ($realSeriesId + 999));
check(
    'Filtering by another series excludes it',
    !str_contains($filteredOut['body'], 'A Test Video'),
    'the filter did nothing'
);

$wrongYear = get($baseUrl . '/search?q=test&year=1999');
check(
    'Filtering by a year with nothing in it excludes it',
    !str_contains($wrongYear['body'], 'A Test Video'),
    'the year filter did nothing'
);

/*
 * The failure that would matter most: search becoming the one listing that
 * forgets the rules.
 */
$db->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$videoRow]);
$leak = get($baseUrl . '/search?q=test');
$db->execute('UPDATE {videos} SET is_published = 1 WHERE id = ?', [$videoRow]);

check(
    'Search does not reveal an unpublished video',
    !str_contains($leak['body'], 'A Test Video'),
    'AN UNPUBLISHED VIDEO WAS VISIBLE IN SEARCH'
);

/* A bookmarked result stays a result. */
check(
    'A search is a linkable URL',
    str_contains($byTitle['body'], 'value="test"'),
    'the query is not echoed back, so the page cannot be shared or reloaded'
);

echo "\nNotices\n";

$noticeScreen = getWithJar($baseUrl . '/admin/announcements', $jar);
check('Notices screen renders', $noticeScreen['status'] === 200, "got {$noticeScreen['status']}");
check('Notices appears in the admin navigation', str_contains($adminHome['body'], '/admin/announcements'));
check(
    'It says the audience is not a security boundary',
    str_contains($noticeScreen['body'], 'Not a private channel'),
    'somebody will put a secret in a banner'
);

$publicNotice = postWithJar($baseUrl . '/admin/announcements', [
    '_token'      => csrfFrom($noticeScreen['body']),
    'action'      => 'create',
    'title'       => 'Heads up',
    'body'        => 'The smoke test is running.',
    'level'       => 'info',
    'audience'    => 'everyone',
    'dismissible' => '1',
], $jar);

check('Adding a notice succeeds', $publicNotice['status'] === 302, "got {$publicNotice['status']}");

$homeWithNotice = get($baseUrl . '/');
check(
    'The banner shows to a signed-out visitor',
    str_contains($homeWithNotice['body'], 'The smoke test is running'),
    'the notice exists and nothing renders it'
);
check('It offers a dismiss button', str_contains($homeWithNotice['body'], 'announcement-dismiss'));

/*
 * The check that matters. An admin-only notice reaching a stranger would be a
 * disclosure, and the screen invites administrators to write things like
 * "migration tonight, credentials rotating".
 */
$adminNotice = postWithJar($baseUrl . '/admin/announcements', [
    '_token'   => csrfFrom($noticeScreen['body']),
    'action'   => 'create',
    'body'     => 'Migration tonight at eleven.',
    'level'    => 'warning',
    'audience' => 'admins',
], $jar);

check('Adding an admin-only notice succeeds', $adminNotice['status'] === 302, "got {$adminNotice['status']}");

$anonHome = get($baseUrl . '/');
check(
    'An admin-only notice does not reach a stranger',
    !str_contains($anonHome['body'], 'Migration tonight'),
    'AN ADMIN-ONLY NOTICE WAS SHOWN TO AN ANONYMOUS VISITOR'
);

$adminHomePage = getWithJar($baseUrl . '/', $jar);
check(
    'and does reach an administrator',
    str_contains($adminHomePage['body'], 'Migration tonight'),
    'the notice is invisible to the person who wrote it'
);

/* A notice whose window has closed takes itself down, with nothing running. */
$expiredNotice = postWithJar($baseUrl . '/admin/announcements', [
    '_token'    => csrfFrom($noticeScreen['body']),
    'action'    => 'create',
    'body'      => 'This one has finished.',
    'audience'  => 'everyone',
    'starts_at' => date('Y-m-d\TH:i', time() - 7200),
    'ends_at'   => date('Y-m-d\TH:i', time() - 3600),
], $jar);

check('Adding a finished notice succeeds', $expiredNotice['status'] === 302, "got {$expiredNotice['status']}");
check(
    'A notice past its end date is not shown',
    !str_contains(get($baseUrl . '/')['body'], 'This one has finished'),
    'the end date did nothing'
);

/* A backwards window is refused rather than saved as a banner nobody sees. */
$noticeCountBefore = (int) $db->value('SELECT COUNT(*) FROM {announcements}');

postWithJar($baseUrl . '/admin/announcements', [
    '_token'    => csrfFrom($noticeScreen['body']),
    'action'    => 'create',
    'body'      => 'Impossible.',
    'starts_at' => date('Y-m-d\TH:i', time() + 86400 * 2),
    'ends_at'   => date('Y-m-d\TH:i', time() + 3600),
], $jar);

check(
    'A backwards window is refused',
    (int) $db->value('SELECT COUNT(*) FROM {announcements}') === $noticeCountBefore,
    'a banner nobody will ever see was stored'
);

/* Dismissal, through the rendered form, with a cookie jar of its own. */
$dismissJar = sys_get_temp_dir() . '/portal-smoke-dismiss-' . getmypid() . '.txt';
@unlink($dismissJar);

$noticeId = (int) $db->value('SELECT id FROM {announcements} WHERE audience = ? LIMIT 1', ['everyone']);

$dismissed = postWithJar($baseUrl . '/announcements/dismiss', ['id' => (string) $noticeId], $dismissJar);
check('Dismissing succeeds', $dismissed['status'] === 302, "got {$dismissed['status']}");

$afterDismiss = getWithJar($baseUrl . '/', $dismissJar);
check(
    'A dismissed notice stays dismissed',
    !str_contains($afterDismiss['body'], 'The smoke test is running'),
    'the banner came back'
);

$stillThere = get($baseUrl . '/');
check(
    'and is still shown to everybody else',
    str_contains($stillThere['body'], 'The smoke test is running'),
    'one visitor dismissing it took it down for the whole site'
);

@unlink($dismissJar);

/* Clean up, so what follows runs against a site with no banners. */
foreach ($db->all('SELECT id FROM {announcements}') as $row) {
    postWithJar($baseUrl . '/admin/announcements', [
        '_token' => csrfFrom($noticeScreen['body']),
        'action' => 'delete',
        'id'     => (string) $row['id'],
    ], $jar);
}

check(
    'Removing every notice clears the banners',
    !str_contains(get($baseUrl . '/')['body'], 'announcement'),
    'a banner survived its own deletion'
);

echo "\nHomepage rows\n";

/*
 * The default first: an install that has never touched this screen must keep
 * the homepage it already had. That is what every existing site looks like, and
 * turning the front page blank because a new table is empty would be the worst
 * possible upgrade.
 */
$homeBefore = get($baseUrl . '/');
check(
    'With no rows the library is unchanged',
    str_contains($homeBefore['body'], 'A Test Video'),
    'an empty table blanked the homepage'
);

$homeScreen = getWithJar($baseUrl . '/admin/homepage', $jar);
check('Homepage screen renders', $homeScreen['status'] === 200, "got {$homeScreen['status']}");
check('Homepage appears in the admin navigation', str_contains($adminHome['body'], '/admin/homepage'));
check('It offers the sources', str_contains($homeScreen['body'], 'name="source_type"'));
check(
    'It says an empty screen is safe',
    str_contains($homeScreen['body'], 'default'),
    'an editor cannot tell whether leaving it blank breaks the site'
);

/* A playlist row, pointing at the playlist made earlier. */
$addRow = postWithJar($baseUrl . '/admin/homepage', [
    '_token'          => csrfFrom($homeScreen['body']),
    'action'          => 'create',
    'title'           => 'Smoke Row',
    'source_type'     => 'playlist',
    'source_playlist' => (string) $playlistId,
    'max_items'       => '6',
], $jar);

check('Adding a row succeeds', $addRow['status'] === 302, "got {$addRow['status']}");
check(
    'The row was stored',
    (int) $db->value('SELECT COUNT(*) FROM {home_rows}') === 1,
    'nothing was written'
);

$homeAfter = get($baseUrl . '/');
check(
    'The curated row appears on the homepage',
    str_contains($homeAfter['body'], 'Smoke Row'),
    'the row exists and nothing renders it'
);
check('It shows the playlist contents', str_contains($homeAfter['body'], 'A Test Video'));
check(
    'It links to the full playlist',
    str_contains($homeAfter['body'], '/playlist/renamed-playlist'),
    'a row with no way through to the rest of it'
);

/*
 * A row is a pointer, not a copy. This is the claim that makes the whole design
 * worth having: curating the playlist curates the homepage.
 */
/*
 * A purpose-made video rather than whichever row happens to be second. By this
 * point the library also holds the upload section's placeholder, which is still
 * encoding — correctly excluded from every listing, so a check using it would
 * fail for a reason that has nothing to do with what is being tested.
 */
$secondVideo = $db->insert('videos', [
    'provider'     => 'bunny',
    'provider_id'  => 'smoke-home-row',
    'slug'         => 'a-second-video',
    'title'        => 'A Second Video',
    'status'       => 'ready',
    'is_published' => 1,
    'created_at'   => date('Y-m-d H:i:s'),
    'updated_at'   => date('Y-m-d H:i:s'),
]);

postWithJar($baseUrl . '/admin/playlists', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/admin/playlists/' . $playlistId, $jar)['body']),
    'action' => 'items',
    'id'     => (string) $playlistId,
    'videos' => [(string) $secondVideo],
], $jar);

check(
    'Editing the playlist edits the homepage',
    str_contains(get($baseUrl . '/')['body'], 'A Second Video'),
    'the row held a copy rather than a pointer'
);
check(
    'and the video it no longer holds is gone from the row',
    !str_contains(get($baseUrl . '/')['body'], 'A Test Video'),
    'removing something from the playlist left it on the homepage'
);

/* Put the original back and drop the extra video. */
postWithJar($baseUrl . '/admin/playlists', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/admin/playlists/' . $playlistId, $jar)['body']),
    'action' => 'items',
    'id'     => (string) $playlistId,
    'videos' => [(string) $videoRow],
], $jar);

$db->execute('DELETE FROM {videos} WHERE id = ?', [$secondVideo]);

/* A row pointing at nothing is refused rather than saved as an empty heading. */
$badRow = postWithJar($baseUrl . '/admin/homepage', [
    '_token'      => csrfFrom($homeScreen['body']),
    'action'      => 'create',
    'source_type' => 'category',
], $jar);

check('A row with no target is refused', $badRow['status'] === 302, "got {$badRow['status']}");
check(
    'and was not stored',
    (int) $db->value('SELECT COUNT(*) FROM {home_rows}') === 1,
    'an empty heading was saved'
);

/* Switching a row off takes it out without deleting it. */
$rowId = (int) $db->value('SELECT id FROM {home_rows} LIMIT 1');

postWithJar($baseUrl . '/admin/homepage', [
    '_token'          => csrfFrom($homeScreen['body']),
    'action'          => 'update',
    'id'              => (string) $rowId,
    'title'           => 'Smoke Row',
    'source_type'     => 'playlist',
    'source_playlist' => (string) $playlistId,
    'max_items'       => '6',
], $jar);

check(
    'Unticking "shown" hides the row',
    !str_contains(get($baseUrl . '/')['body'], 'Smoke Row'),
    'the row is still on the front page'
);
check(
    'and the row still exists',
    (int) $db->value('SELECT COUNT(*) FROM {home_rows}') === 1,
    'switching a row off deleted it'
);
check(
    'With every row off the library comes back',
    str_contains(get($baseUrl . '/')['body'], 'A Test Video'),
    'the homepage is now blank'
);

/* Remove it, so everything after this runs against the default homepage. */
postWithJar($baseUrl . '/admin/homepage', [
    '_token' => csrfFrom($homeScreen['body']),
    'action' => 'delete',
    'id'     => (string) $rowId,
], $jar);

check(
    'Removing a row leaves the playlist alone',
    (int) $db->value('SELECT COUNT(*) FROM {playlists} WHERE id = ?', [$playlistId]) === 1,
    'deleting a homepage row deleted content'
);

/* Featured is settable at last — it has been in the repository since Phase 1. */
$featuredEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('The edit screen offers the featured switch', str_contains($featuredEdit['body'], 'name="featured"'));
check('and the pin switch', str_contains($featuredEdit['body'], 'name="pinned"'));

echo "\nScheduling and premieres\n";

/*
 * The published_at column and the listing filter both shipped in Phase 1. What
 * did not was any form that could set a future date — so this is driven
 * entirely through the edit screen, which is the half that was missing.
 */
$scheduleEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('The edit screen offers a publication date', str_contains($scheduleEdit['body'], 'name="published_at"'));
check('It offers an end date', str_contains($scheduleEdit['body'], 'name="unpublish_at"'));
check('It offers the premiere switch', str_contains($scheduleEdit['body'], 'name="premiere"'));

$future = date('Y-m-d\TH:i', time() + 86400 * 3);
$past = date('Y-m-d\TH:i', time() - 3600);

$scheduleFields = [
    '_token'         => csrfFrom($scheduleEdit['body']),
    'id'             => (string) $videoRow,
    'action'         => 'save',
    'title'          => 'A Test Video',
    'categories'     => [(string) $categoryRow],
    'thumbnail_mode' => 'default',
    'watermark_mode' => 'default',
    '_whole_form'    => '1',
];

/* Scheduled, not a premiere: invisible until its date. */
$scheduled = postWithJar($baseUrl . '/admin/videos', $scheduleFields + ['published_at' => $future], $jar);
check('Scheduling succeeds', $scheduled['status'] === 302, "got {$scheduled['status']}");
check(
    'The date was stored',
    (string) $db->value('SELECT published_at FROM {videos} WHERE id = ?', [$videoRow]) !== '',
    'the field was accepted and dropped'
);

$libraryScheduled = get($baseUrl . '/');
check(
    'A scheduled video is not listed',
    !str_contains($libraryScheduled['body'], 'A Test Video'),
    'it went live early'
);

$watchScheduled = get($baseUrl . '/watch/' . $videoSlug);
check(
    'Its page is not reachable either',
    $watchScheduled['status'] === 404 || $watchScheduled['status'] === 302,
    "got {$watchScheduled['status']}"
);

/* Same date, marked as a premiere: listed, dated, and still not playable. */
postWithJar($baseUrl . '/admin/videos', $scheduleFields + [
    'published_at' => $future,
    'premiere'     => '1',
], $jar);

$libraryPremiere = get($baseUrl . '/');
check(
    'A premiere is listed before its date',
    str_contains($libraryPremiere['body'], 'A Test Video'),
    'the announcement never appeared'
);
check(
    'The card says when',
    str_contains($libraryPremiere['body'], 'Premieres'),
    'a badge with no date invites a click on something that will not play'
);

$watchPremiere = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check('A premiere page opens', $watchPremiere['status'] === 200, "got {$watchPremiere['status']}");

/*
 * The claim worth proving: the embed URL is never minted for a premiere, so
 * there is nothing to find with developer tools.
 *
 * It has to be checked from an ordinary approved viewer's seat. Signed out,
 * /watch redirects to sign-in and the page never renders — a check there would
 * pass against any implementation at all. As an administrator the player is
 * shown DELIBERATELY, so they can review something before it goes live. The
 * person the rule is about is the one in between, so this creates one.
 */
$viewerJar = sys_get_temp_dir() . '/portal-smoke-viewer-' . getmypid() . '.txt';
@unlink($viewerJar);

$db->execute(
    'INSERT INTO {users} (email, name, password_hash, authorized, role_id, created_at, updated_at)
     VALUES (?, ?, ?, 1, (SELECT id FROM {roles} WHERE slug = ?), NOW(), NOW())',
    ['viewer@smoke.test', 'A Viewer', password_hash('smoke-viewer-password-1234', PASSWORD_DEFAULT), 'viewer']
);

$viewerLogin = postWithJar($baseUrl . '/auth/login', [
    'email'    => 'viewer@smoke.test',
    'password' => 'smoke-viewer-password-1234',
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $viewerJar)['body']),
], $viewerJar);

check('An ordinary viewer can sign in', $viewerLogin['status'] === 302, "got {$viewerLogin['status']}");

$viewerPremiere = getWithJar($baseUrl . '/watch/' . $videoSlug, $viewerJar);
check(
    'A viewer can open a premiere page',
    $viewerPremiere['status'] === 200,
    "got {$viewerPremiere['status']} — the check below would prove nothing"
);
check(
    'A premiere does not carry a player for a viewer',
    !str_contains($viewerPremiere['body'], '<iframe'),
    'THE PLAYER WAS RENDERED FOR A VIDEO THAT HAS NOT BEEN RELEASED'
);
check(
    'It says when it premieres instead',
    str_contains($viewerPremiere['body'], 'Premieres'),
    'the page renders but explains nothing'
);

/* And an administrator still gets the player, which is the point of the split. */
check(
    'Someone who manages videos can still review it',
    str_contains($watchPremiere['body'], '<iframe'),
    'an editor cannot check a video before it goes live'
);

@unlink($viewerJar);

/* Feeds must not carry it: an episode announced before it can be downloaded. */
$feedPremiere = get($baseUrl . '/feed');
check(
    'A premiere is not in the podcast or RSS feed',
    !str_contains($feedPremiere['body'], 'A Test Video'),
    'every client would report a broken episode'
);

/* An end date in the past removes it for everybody. */
postWithJar($baseUrl . '/admin/videos', $scheduleFields + [
    'published_at' => date('Y-m-d\TH:i', time() - 86400 * 2),
    'unpublish_at' => $past,
], $jar);

$libraryExpired = get($baseUrl . '/');
check(
    'An expired video disappears',
    !str_contains($libraryExpired['body'], 'A Test Video'),
    'the end date did nothing'
);

/* A window that never opens is refused rather than silently accepted. */
$backwards = postWithJar($baseUrl . '/admin/videos', $scheduleFields + [
    'published_at' => date('Y-m-d\TH:i', time() + 86400 * 5),
    'unpublish_at' => date('Y-m-d\TH:i', time() + 86400),
], $jar);

check('A backwards window is refused', $backwards['status'] === 302, "got {$backwards['status']}");
check(
    'and the old dates are left alone',
    (string) $db->value('SELECT unpublish_at FROM {videos} WHERE id = ?', [$videoRow])
        !== date('Y-m-d H:i:00', time() + 86400),
    'an impossible schedule was stored'
);

/* Put it back, so everything after this runs against an ordinary video. */
postWithJar($baseUrl . '/admin/videos', $scheduleFields + [
    'published_at' => '',
    'unpublish_at' => '',
], $jar);

check(
    'Clearing the dates brings it back',
    str_contains(get($baseUrl . '/')['body'], 'A Test Video'),
    'the video is still hidden'
);

echo "\nFeeds and the sitemap\n";

/*
 * Fetched signed OUT throughout, because that is what a podcast client is. The
 * one failure that matters here is a feed becoming the path that ignores the
 * visibility rules — it is cached by aggregators and re-served to strangers, so
 * a leak here does not stay in one browser.
 */
$feed = get($baseUrl . '/feed');
check('The RSS feed renders', $feed['status'] === 200, "got {$feed['status']}");
check(
    'It is served as RSS',
    str_contains(strtolower($feed['headers']['content-type'] ?? ''), 'application/rss+xml'),
    'got "' . ($feed['headers']['content-type'] ?? 'nothing') . '" — a feed served as HTML is one no client will subscribe to'
);

$parsed = @simplexml_load_string($feed['body']);
check('The feed is well-formed XML', $parsed !== false, 'no client could parse it');
check('It lists the video', str_contains($feed['body'], 'A Test Video'));
check(
    'It declares its own address',
    str_contains($feed['body'], 'rel="self"'),
    'directories reject a feed without it'
);

$podcast = get($baseUrl . '/podcast');
check('The podcast feed renders', $podcast['status'] === 200, "got {$podcast['status']}");
check('The podcast feed is well-formed XML', @simplexml_load_string($podcast['body']) !== false);
check(
    'Episodes carry an enclosure',
    str_contains($podcast['body'], '<enclosure'),
    'a podcast feed without enclosures has no episodes'
);
check(
    'The enclosure points back at this site, not at the CDN',
    str_contains($podcast['body'], '/media/' . $videoSlug . '.mp4')
        && !str_contains($podcast['body'], 'b-cdn.net'),
    'a signed CDN URL in a feed is a broken episode by the time anybody downloads it'
);

/* The scoped feeds. */
$seriesFeed = get($baseUrl . '/feed/series/renamed-series');
check('A series feed renders', $seriesFeed['status'] === 200, "got {$seriesFeed['status']}");
check('It lists the episode', str_contains($seriesFeed['body'], 'A Test Video'));

$playlistFeed = get($baseUrl . '/feed/playlist/renamed-playlist');
check('A playlist feed renders', $playlistFeed['status'] === 200, "got {$playlistFeed['status']}");

$nonsenseFeed = get($baseUrl . '/feed/nonsense/x');
check('An unknown feed scope is a 404', $nonsenseFeed['status'] === 404, "got {$nonsenseFeed['status']}");

$missingFeed = get($baseUrl . '/feed/series/no-such-series');
check('A feed for something that does not exist is a 404', $missingFeed['status'] === 404, "got {$missingFeed['status']}");

/* The media redirect: signed on demand, and it re-checks visibility. */
$media = get($baseUrl . '/media/' . $videoSlug . '.mp4');
check('The media route redirects', $media['status'] === 302, "got {$media['status']}");
check(
    'It redirects to a signed URL',
    str_contains($media['headers']['location'] ?? '', 'token='),
    'went to ' . ($media['headers']['location'] ?? 'nowhere')
);
check(
    'The redirect is not cacheable',
    str_contains(strtolower($media['headers']['cache-control'] ?? ''), 'no-store'),
    'a cached redirect brings back the expiry problem this route exists to avoid'
);

/*
 * The claim the whole design rests on: unpublishing withdraws the download.
 * A signed URL already handed out cannot be recalled — a redirect can refuse.
 */
$db->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$videoRow]);
$mediaGone = get($baseUrl . '/media/' . $videoSlug . '.mp4');
$feedGone = get($baseUrl . '/feed');
$db->execute('UPDATE {videos} SET is_published = 1 WHERE id = ?', [$videoRow]);

check(
    'Unpublishing withdraws the download',
    $mediaGone['status'] === 404,
    "got {$mediaGone['status']} — a withdrawn video was still downloadable"
);
check(
    'Unpublishing takes it out of the feed',
    !str_contains($feedGone['body'], 'A Test Video'),
    'AN UNPUBLISHED VIDEO WAS IN A PUBLIC FEED'
);

/* Members-only content must never reach a feed, whoever is asking. */
$db->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$videoRow]);
$feedMembers = get($baseUrl . '/feed');
$feedAsAdmin = getWithJar($baseUrl . '/feed', $jar);
$mediaMembers = get($baseUrl . '/media/' . $videoSlug . '.mp4');
$db->execute('UPDATE {videos} SET member_only = 0 WHERE id = ?', [$videoRow]);

check(
    'A members-only video is not in the feed',
    !str_contains($feedMembers['body'], 'A Test Video'),
    'A MEMBERS-ONLY VIDEO WAS IN A PUBLIC FEED'
);
check(
    'And is still absent when an administrator fetches it',
    !str_contains($feedAsAdmin['body'], 'A Test Video'),
    'the feed varies by viewer, so an admin could warm a shared cache with private titles'
);
check(
    'A members-only video cannot be downloaded',
    $mediaMembers['status'] === 404,
    "got {$mediaMembers['status']}"
);

/*
 * Indexing is off until somebody says otherwise, and all three signals have to
 * agree — a sitemap inviting crawlers while every page says noindex is the kind
 * of contradiction nobody notices until the wrong page is in a search result.
 */
$sitemapOff = get($baseUrl . '/sitemap.xml');
check('The sitemap is absent while indexing is off', $sitemapOff['status'] === 404, "got {$sitemapOff['status']}");

$robotsOff = get($baseUrl . '/robots.txt');
check('robots.txt refuses everything while indexing is off', str_contains($robotsOff['body'], 'Disallow: /'));

$publicHome = get($baseUrl . '/');
check('Pages send noindex while indexing is off', str_contains($publicHome['body'], 'noindex'));
check('Feeds are still advertised', str_contains($publicHome['body'], 'application/rss+xml'));

/* Turn it on through the settings form, the way an owner would. */
$settingsScreen = getWithJar($baseUrl . '/admin/settings', $jar);
check('Settings offers the indexing switch', str_contains($settingsScreen['body'], 'name="allow_indexing"'));
check('Settings offers the podcast fields', str_contains($settingsScreen['body'], 'name="podcast_owner_email"'));

postWithJar($baseUrl . '/admin/settings', [
    '_token'              => csrfFrom($settingsScreen['body']),
    'site_name'           => 'Smoke Portal',
    'timezone'            => 'UTC',
    'allow_indexing'      => '1',
    'podcast_owner_name'  => 'Smoke Owner',
    'podcast_owner_email' => 'owner@smoke.test',
], $jar);

$sitemapOn = get($baseUrl . '/sitemap.xml');
check('The sitemap appears once indexing is on', $sitemapOn['status'] === 200, "got {$sitemapOn['status']}");
check('The sitemap is well-formed XML', @simplexml_load_string($sitemapOn['body']) !== false);
check('It lists the video', str_contains($sitemapOn['body'], '/watch/' . $videoSlug));

$robotsOn = get($baseUrl . '/robots.txt');
check('robots.txt now names the sitemap', str_contains($robotsOn['body'], '/sitemap.xml'));
check(
    'and still keeps crawlers away from share links',
    str_contains($robotsOn['body'], 'Disallow: /s/'),
    'a crawler that finds a share link would put a private page in a search index'
);

$publicHomeOn = get($baseUrl . '/');
check('Pages now allow indexing', str_contains($publicHomeOn['body'], 'index, follow'));

/* The owner details reach the feed a directory validates. */
$podcastOn = get($baseUrl . '/podcast');
check(
    'The podcast feed carries the owner Apple requires',
    str_contains($podcastOn['body'], 'owner@smoke.test'),
    'a submission would be rejected'
);

/* Put it back, so anything after this runs against a private site. */
postWithJar($baseUrl . '/admin/settings', [
    '_token'    => csrfFrom($settingsScreen['body']),
    'site_name' => 'Smoke Portal',
    'timezone'  => 'UTC',
], $jar);

check(
    'Turning indexing back off removes the sitemap again',
    get($baseUrl . '/sitemap.xml')['status'] === 404,
    'the switch only goes one way'
);

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
