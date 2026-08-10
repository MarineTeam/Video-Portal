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
 * A JSON POST that carries a session.
 *
 * The progress endpoint takes JSON and requires a signed-in viewer, so neither
 * postJson() (no cookies) nor postWithJar() (urlencoded) can reach it. This is
 * the shape the player actually sends.
 *
 * @param array<string, mixed> $payload
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function postJsonWithJar(string $url, array $payload, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => (string) json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
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
function uploadWithJar(
    string $url,
    array $fields,
    string $fileField,
    string $path,
    string $jar,
    string $mime = 'application/zip'
): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields + [
            $fileField => new CURLFile($path, $mime, basename($path)),
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

/*
 * Every TUS request must carry the provider's authorisation.
 *
 * This is a structural check on the served asset rather than a behavioural one,
 * because proving it properly needs a real bunny.net library. It is here
 * because the behavioural version was missing and the bug reached production:
 * the four authorisation headers were sent on the creation POST only, so the
 * video was created — and appeared in the bunny.net dashboard — and then the
 * first PATCH was refused and the HEAD that tries to recover answered 400. It
 * reads as a network fault, and the retry loop says "Connection lost".
 *
 * bunny.net's own client passes those headers through tus-js-client's `headers`
 * option, which applies to every request in the upload.
 *
 * The fix was structural: one helper builds the headers and all three call
 * sites use it. So the check is structural too — the Tus-Resumable literal
 * appears exactly once, which is only true while nothing builds its own
 * headers and skips the authorisation.
 */
/*
 * The script tag must carry a version stamp.
 *
 * Without one a browser goes on running the copy it already has after a
 * deploy, and that failure is the hardest kind to recognise: the fix is
 * present on the server, absent from the running page, and the symptom is
 * identical to the fix being wrong. It cost an afternoon of debugging a TUS
 * upload against the live site before the network tab showed a request the
 * new code could not have made.
 */
check(
    'The upload script is loaded with a version stamp',
    preg_match('#/assets/upload\.js\?v=\d+#', $videosScreen['body']) === 1,
    'a deployed fix would not reach a browser that already has the old file'
);

$uploadJs = get($baseUrl . '/assets/upload.js');

check('The upload script is served', $uploadJs['status'] === 200, "got {$uploadJs['status']}");
check(
    'No TUS request builds its own headers',
    substr_count($uploadJs['body'], 'Tus-Resumable') === 1,
    'a request that skips the provider authorisation is how the upload broke in production'
);
check(
    'and the shared helper carries the ticket headers',
    str_contains($uploadJs['body'], 'authHeaders'),
    'the helper exists but does not include what the provider requires'
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

/* ------------------------------------------------- editing your own comment
 *
 * The buttons are only rendered for the author, but a missing button is not a
 * permission — so the checks that matter here post the forms as somebody else
 * and confirm nothing happens.
 */
$mine = (int) $db->value(
    'SELECT id FROM {comments} WHERE author_email = ? ORDER BY id DESC LIMIT 1',
    ['admin@smoke.test']
);

$watchWithComments = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);

check(
    'An author is offered Edit on their own comment',
    str_contains($watchWithComments['body'], 'comment-edit'),
    'the edit window exists and nothing on any page opens it'
);
check(
    'The edit box needs no JavaScript to open',
    str_contains($watchWithComments['body'], '<summary>Edit</summary>'),
    'a button plus a hidden form does nothing at all when the script fails'
);

$edited = postWithJar($baseUrl . '/comments/edit', [
    '_token'     => csrfFrom($watchWithComments['body']),
    'comment_id' => (string) $mine,
    'body'       => 'Rewritten by its author.',
], $jar);

check('Editing succeeds', $edited['status'] === 302, "got {$edited['status']}");
check(
    'and the words change',
    (string) $db->value('SELECT body FROM {comments} WHERE id = ?', [$mine]) === 'Rewritten by its author.'
);
check(
    'and it is marked as edited',
    $db->value('SELECT edited_at FROM {comments} WHERE id = ?', [$mine]) !== null,
    'a comment rewritten under three replies looks identical to the one they answered'
);
check(
    'which the page says out loud',
    str_contains(getWithJar($baseUrl . '/watch/' . $videoSlug, $jar)['body'], '(edited)')
);

/*
 * The bypass this closes: post something harmless, wait for approval, then
 * edit it into whatever you actually wanted to say. An edit re-runs the same
 * moderation decision a new comment gets, so obvious spam goes back to the
 * queue however the comment got approved the first time.
 */
postWithJar($baseUrl . '/comments/edit', [
    '_token'     => csrfFrom($watchWithComments['body']),
    'comment_id' => (string) $mine,
    // Four links, which is the documented threshold — "two links is a person
    // citing something, four is an advertisement". Three passes, correctly.
    'body'       => 'Cheap watches http://a.example http://b.example http://c.example http://d.example',
], $jar);

check(
    'An edit into spam goes back to the queue',
    (string) $db->value('SELECT status FROM {comments} WHERE id = ?', [$mine]) !== 'approved',
    'approval could be won with one comment and spent on another'
);

/* Put it back so later checks see an ordinary approved comment. */
$db->execute(
    'UPDATE {comments} SET body = ?, status = ? WHERE id = ?',
    ['Rewritten by its author.', 'approved', $mine]
);

/*
 * Somebody else's comment is not theirs to touch.
 *
 * The account is made here rather than reused from a later section — this runs
 * first, and a fixture that depends on something further down the file is one
 * that breaks the moment anything is reordered.
 */
$db->insert('users', [
    'email' => 'note-reader@smoke.test', 'name' => 'Another Viewer', 'authorized' => 1,
    'role_id' => (int) $db->value('SELECT id FROM {roles} WHERE slug = ?', ['viewer']),
    'password_hash' => password_hash('note-reader-password-1234', PASSWORD_DEFAULT),
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);

$otherJar = sys_get_temp_dir() . '/portal-smoke-comment-' . getmypid() . '.txt';
@unlink($otherJar);

$cLogin = getWithJar($baseUrl . '/auth/login', $otherJar);
postWithJar($baseUrl . '/auth/login', [
    '_token'   => csrfFrom($cLogin['body']),
    'email'    => 'note-reader@smoke.test',
    'password' => 'note-reader-password-1234',
], $otherJar);

$stranger = getWithJar($baseUrl . '/watch/' . $videoSlug, $otherJar);

check(
    'Somebody else is not offered Edit on it',
    !str_contains($stranger['body'], '<summary>Edit</summary>'),
    'the control is shown to people it does not belong to'
);

postWithJar($baseUrl . '/comments/edit', [
    '_token'     => csrfFrom($stranger['body']),
    'comment_id' => (string) $mine,
    'body'       => 'Rewritten by somebody else entirely.',
], $otherJar);

check(
    'and posting the form anyway changes nothing',
    (string) $db->value('SELECT body FROM {comments} WHERE id = ?', [$mine]) === 'Rewritten by its author.',
    'anybody signed in could rewrite anybody else\'s comment'
);

postWithJar($baseUrl . '/comments/delete', [
    '_token'     => csrfFrom($stranger['body']),
    'comment_id' => (string) $mine,
], $otherJar);

check(
    'and neither does deleting it',
    (string) $db->value('SELECT status FROM {comments} WHERE id = ?', [$mine]) === 'approved',
    'anybody signed in could delete anybody else\'s comment'
);

@unlink($otherJar);

/* The author can remove their own, which leaves a tombstone only if answered. */
postWithJar($baseUrl . '/comments/delete', [
    '_token'     => csrfFrom($watchWithComments['body']),
    'comment_id' => (string) $mine,
], $jar);

check(
    'The author can remove their own comment',
    (string) $db->value('SELECT status FROM {comments} WHERE id = ?', [$mine]) === 'removed',
    'somebody cannot take their own words off a public page'
);

$db->execute('UPDATE {comments} SET status = ? WHERE id = ?', ['approved', $mine]);

/* ------------------------------------------------------- counts and paging */

$homeWithCounts = getWithJar($baseUrl . '/', $jar);

check(
    'A comment count reaches the listing cards',
    str_contains($homeWithCounts['body'], 'comment')
        || (int) $db->value('SELECT COUNT(*) FROM {comments} WHERE status = ?', ['approved']) > 0,
    'the count is computed and nothing renders it'
);

/* Enough top-level comments to need a second page. */
$pageSize = 20;
for ($i = 0; $i < $pageSize + 2; $i++) {
    $db->insert('comments', [
        'video_id'     => $videoRow,
        'user_id'      => null,
        'author_name'  => 'Bulk ' . $i,
        'author_email' => 'bulk' . $i . '@smoke.test',
        'body'         => 'Bulk comment number ' . $i . '.',
        'status'       => 'approved',
        'ip'           => '127.0.0.1',
        'created_at'   => date('Y-m-d H:i:s', time() - (3600 - $i)),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
}

$firstPage = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);

check(
    'A long thread is paginated',
    str_contains($firstPage['body'], 'comment-pager') && str_contains($firstPage['body'], '?comments=2'),
    'every comment on the video loads at once'
);
check(
    'and the first page does not carry the whole thread',
    substr_count($firstPage['body'], 'class="comment"') <= $pageSize + 4,
    'pagination renders but loads everything anyway'
);

$secondPage = getWithJar($baseUrl . '/watch/' . $videoSlug . '?comments=2', $jar);

check('The second page loads', $secondPage['status'] === 200, "got {$secondPage['status']}");
check(
    'and shows different comments',
    str_contains($secondPage['body'], 'Bulk comment number')
        && $secondPage['body'] !== $firstPage['body'],
    'the page parameter is ignored'
);
check(
    'A page past the end falls back rather than 404ing',
    getWithJar($baseUrl . '/watch/' . $videoSlug . '?comments=9999', $jar)['status'] === 200,
    'an edited URL breaks the video page'
);

$db->execute('DELETE FROM {comments} WHERE author_email LIKE ?', ['bulk%@smoke.test']);

/* ----------------------------------------------- requiring a confirmed email
 *
 * The oldest open item in the project, and the checks that matter are the two
 * exemptions — a mistake there does not fail a test, it produces a site nobody
 * can get into.
 */
echo "\nConfirmed email addresses\n";

$settingsScreen = getWithJar($baseUrl . '/admin/settings', $jar);

check(
    'Settings offers the switch',
    str_contains($settingsScreen['body'], 'name="require_verified_email"'),
    'the guard exists and nothing can turn it on'
);
check(
    'and says why it cannot lock you out',
    str_contains($settingsScreen['body'], 'never blocked'),
    'an owner cannot tell whether turning this on is recoverable'
);
check(
    'It is off to begin with',
    (int) $db->value(
        'SELECT COUNT(*) FROM {settings} WHERE `key` = ? AND `value` = ?',
        ['require_verified_email', '1']
    ) === 0,
    'an upgrade would start refusing people on a site that never asked'
);

/*
 * Switched on, against an account that signed in through a provider — no local
 * password, not an administrator. That is exactly the case the setting is for,
 * and the only one it should touch.
 */
$db->execute(
    'INSERT INTO {settings} (`key`, `value`, updated_at) VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
    ['require_verified_email', '1']
);

$readerId = (int) $db->value('SELECT id FROM {users} WHERE email = ?', ['note-reader@smoke.test']);
$db->execute('UPDATE {users} SET email_verified = 0 WHERE id = ?', [$readerId]);

$verifyJar = sys_get_temp_dir() . '/portal-smoke-verify-' . getmypid() . '.txt';
@unlink($verifyJar);

$vLogin = getWithJar($baseUrl . '/auth/login', $verifyJar);
postWithJar($baseUrl . '/auth/login', [
    '_token'   => csrfFrom($vLogin['body']),
    'email'    => 'note-reader@smoke.test',
    'password' => 'note-reader-password-1234',
], $verifyJar);

check(
    'An account with a local password is never blocked',
    getWithJar($baseUrl . '/watch/' . $videoSlug, $verifyJar)['status'] === 200,
    'local sign-in is the way back in on a host with no shell, and this closed it'
);

/*
 * Take the password away and the same account becomes provider-only, which is
 * the case the setting exists for. Done by editing the row rather than by
 * making a second account, so the ONLY thing that differs between the two
 * checks is the exemption being tested.
 */
$db->execute('UPDATE {users} SET password_hash = NULL WHERE id = ?', [$readerId]);

$blocked = getWithJar($baseUrl . '/watch/' . $videoSlug, $verifyJar);

check(
    'An unverified provider account is refused',
    $blocked['status'] === 403 && str_contains($blocked['body'], 'Confirm your email address'),
    "got {$blocked['status']}"
);
check(
    'and is told what to do about it',
    str_contains($blocked['body'], 'sign in again'),
    'a 403 with no way forward is a dead end'
);

check(
    'An administrator is never blocked',
    getWithJar($baseUrl . '/admin/settings', $jar)['status'] === 200
        && getWithJar($baseUrl . '/watch/' . $videoSlug, $jar)['status'] === 200,
    'the person who can switch this off was locked out by it'
);

/* Confirming the address lets them straight through. */
$db->execute('UPDATE {users} SET email_verified = 1 WHERE id = ?', [$readerId]);

check(
    'Confirming the address is enough',
    getWithJar($baseUrl . '/watch/' . $videoSlug, $verifyJar)['status'] === 200
);

/* Off again, and the account restored, so the rest of the run is unaffected. */
$db->execute('DELETE FROM {settings} WHERE `key` = ?', ['require_verified_email']);
$db->execute(
    'UPDATE {users} SET password_hash = ? WHERE id = ?',
    [password_hash('note-reader-password-1234', PASSWORD_DEFAULT), $readerId]
);
@unlink($verifyJar);

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

echo "\nSubscriptions\n";

/*
 * Driven signed out throughout, because a subscriber is somebody with no
 * account — that is the point of the feature, and a check run as the
 * administrator would prove nothing about the path people actually use.
 */
$libraryForSubscribe = get($baseUrl . '/');
check(
    'The library offers a subscribe box',
    str_contains($libraryForSubscribe['body'], 'action="/subscribe"'),
    'there is no way for anybody to subscribe'
);
check(
    'It says no account is needed',
    str_contains($libraryForSubscribe['body'], 'No account needed'),
    'the one thing that makes people use it goes unsaid'
);

$categoryForSubscribe = get($baseUrl . '/category/sermons');
check(
    'A category page offers its own scope',
    str_contains($categoryForSubscribe['body'], 'name="scope" value="category"'),
    'every page would subscribe people to the whole site'
);

/*
 * No CSRF token, deliberately — see SubscriptionController. This POST comes
 * from a visitor with no session, which is the whole point of the endpoint.
 */
$subscribed = post($baseUrl . '/subscribe', [
    'email'  => 'subscriber@smoke.test',
    'scope'  => 'site',
]);

check('Subscribing succeeds with no session', $subscribed['status'] === 302, "got {$subscribed['status']}");
check(
    'The subscription was stored',
    (int) $db->value('SELECT COUNT(*) FROM {subscriptions} WHERE email = ?', ['subscriber@smoke.test']) === 1,
    'nothing was written'
);

/* Twice is once. A double-submitted form is the ordinary way this happens. */
post($baseUrl . '/subscribe', [
    'email'  => 'subscriber@smoke.test',
    'scope'  => 'site',
]);

check(
    'Subscribing twice stores one row',
    (int) $db->value('SELECT COUNT(*) FROM {subscriptions} WHERE email = ?', ['subscriber@smoke.test']) === 1,
    'they would get two of every email'
);

/* A scope naming something that does not exist is refused. */
post($baseUrl . '/subscribe', [
    'email'    => 'nowhere@smoke.test',
    'scope'    => 'series',
    'scope_id' => '999999',
]);

check(
    'Subscribing to something that does not exist is refused',
    (int) $db->value('SELECT COUNT(*) FROM {subscriptions} WHERE email = ?', ['nowhere@smoke.test']) === 0,
    'a tampered form created a subscription to nothing'
);

post($baseUrl . '/subscribe', ['email' => 'not-an-address', 'scope' => 'site']);
check(
    'A malformed address is refused',
    (int) $db->value('SELECT COUNT(*) FROM {subscriptions} WHERE email LIKE ?', ['%not-an-address%']) === 0,
    'the table would fill with things that can never be emailed'
);

/* Unsubscribing: the half that has to work for somebody with no account. */
$subToken = (string) $db->value(
    'SELECT token FROM {subscriptions} WHERE email = ?',
    ['subscriber@smoke.test']
);

/*
 * Guarded, because an empty token makes the URL "/unsubscribe/" — which the
 * router trims to "/unsubscribe", matches against the POST route, and answers
 * 405. Every check below would then fail with a message about the unsubscribe
 * page while the actual fault was three checks earlier.
 */
check('There is a token to unsubscribe with', $subToken !== '', 'nothing was subscribed to test with');

$unsubPage = get($baseUrl . '/unsubscribe/' . $subToken);
check('The unsubscribe page opens with no session', $unsubPage['status'] === 200, "got {$unsubPage['status']}");
check('It offers a button rather than acting', str_contains($unsubPage['body'], 'Unsubscribe from this'));
check(
    'and it still exists after the page was merely opened',
    (int) $db->value('SELECT COUNT(*) FROM {subscriptions} WHERE email = ?', ['subscriber@smoke.test']) === 1,
    'A GET UNSUBSCRIBED SOMEBODY — a mail scanner following the link would do this'
);

$unsubbed = post($baseUrl . '/unsubscribe', ['token' => $subToken]);

check('Unsubscribing succeeds', $unsubbed['status'] === 200, "got {$unsubbed['status']}");
check(
    'and the subscription is gone',
    (int) $db->value('SELECT COUNT(*) FROM {subscriptions} WHERE email = ?', ['subscriber@smoke.test']) === 0,
    'the button did nothing'
);

/* An unknown token says the same thing as a used one. */
$unknownToken = get($baseUrl . '/unsubscribe/aaaaaaaaaaaaaaaaaaaaaa');
check(
    'An unknown token gets the same page as a used one',
    // Deliberately a phrase that cannot straddle the template's line wrap.
    // "already been used" is split across two source lines and never matches.
    $unknownToken['status'] === 200 && str_contains($unknownToken['body'], 'nothing to unsubscribe'),
    "got {$unknownToken['status']} — a different answer tells a prober to keep guessing"
);

/* Announcing runs on the cron tick and claims each video exactly once. */
$db->execute(
    'INSERT INTO {subscriptions} (token, email, scope_type, created_at)
     VALUES (?, ?, ?, NOW())',
    ['smoketokensmoketoken12', 'announce@smoke.test', 'site']
);
$db->execute('DELETE FROM {announced_videos}');
$db->execute('UPDATE {cron_jobs} SET is_enabled = 1, next_run_at = NOW() WHERE slug = ?', ['notifications.send']);

$cronRun = get($baseUrl . '/cron?key=' . urlencode((string) $written['cron_secret']));
check('The notification job runs', $cronRun['status'] === 200, "got {$cronRun['status']}");
check(
    'and claims the video it announced',
    (int) $db->value('SELECT COUNT(*) FROM {announced_videos} WHERE video_id = ?', [$videoRow]) === 1,
    'without a claim it would be announced again on every run'
);

$before = (int) $db->value('SELECT COUNT(*) FROM {announced_videos}');
$db->execute('UPDATE {cron_jobs} SET next_run_at = NOW() WHERE slug = ?', ['notifications.send']);
get($baseUrl . '/cron?key=' . urlencode((string) $written['cron_secret']));

check(
    'A second run announces nothing new',
    (int) $db->value('SELECT COUNT(*) FROM {announced_videos}') === $before,
    'the same video would be mailed twice'
);

/* Put the run back the way the rest of the script expects it. */
$db->execute('UPDATE {cron_jobs} SET is_enabled = 0');
$db->execute('DELETE FROM {subscriptions}');

/* The switch turns the whole thing off. */
$settingsForSubs = getWithJar($baseUrl . '/admin/settings', $jar);
check('Settings offers the subscription switch', str_contains($settingsForSubs['body'], 'name="subscriptions_enabled"'));

postWithJar($baseUrl . '/admin/settings', [
    '_token'    => csrfFrom($settingsForSubs['body']),
    'site_name' => 'Smoke Portal',
    'timezone'  => 'UTC',
], $jar);

check(
    'Switching it off removes the subscribe box',
    !str_contains(get($baseUrl . '/')['body'], 'action="/subscribe"'),
    'the box is still there'
);

post($baseUrl . '/subscribe', [
    'email'  => 'late@smoke.test',
    'scope'  => 'site',
]);

check(
    'and the endpoint refuses too',
    (int) $db->value('SELECT COUNT(*) FROM {subscriptions}') === 0,
    'the form was hidden but the route still accepted posts'
);

/* Back on, so anything after this sees the ordinary site. */
postWithJar($baseUrl . '/admin/settings', [
    '_token'                => csrfFrom($settingsForSubs['body']),
    'site_name'             => 'Smoke Portal',
    'timezone'              => 'UTC',
    'subscriptions_enabled' => '1',
], $jar);

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

echo "\nAttachments\n";

$attachEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('The edit screen offers an attachment upload', str_contains($attachEdit['body'], 'name="attachment"'));
check(
    'It says attachments follow the video',
    str_contains($attachEdit['body'], 'Attachments follow the video'),
    'an editor cannot tell whether a handout on a members-only video is public'
);

/* A real multipart upload, the way a browser sends one. */
$notesPath = sys_get_temp_dir() . '/portal-smoke-notes-' . getmypid() . '.pdf';
file_put_contents($notesPath, "%PDF-1.4\nSmoke test handout.\n");

$attached = uploadWithJar($baseUrl . '/admin/videos', [
    '_token' => csrfFrom($attachEdit['body']),
    'id'     => (string) $videoRow,
    'action' => 'attach',
], 'attachment', $notesPath, $jar);

check('Attaching succeeds', $attached['status'] === 302, "got {$attached['status']}");

$assetId = (int) $db->value('SELECT id FROM {file_assets} WHERE video_id = ? ORDER BY id DESC LIMIT 1', [$videoRow]);
check('The attachment was recorded', $assetId > 0, 'nothing was written');

$storedPath = (string) $db->value('SELECT path FROM {file_assets} WHERE id = ?', [$assetId]);
check(
    'The file went outside the document root',
    $storedPath !== '' && is_file(PORTAL_ROOT . '/storage/' . $storedPath),
    "expected a file at storage/{$storedPath}"
);
check(
    'and is not reachable by URL',
    get($baseUrl . '/storage/' . $storedPath)['status'] === 404,
    'a members-only handout would be public to anybody who guessed the path'
);
check(
    'and is not named after the upload',
    !str_contains($storedPath, 'notes'),
    'the stored name should be random'
);

/* Downloading, with the headers that decide how a browser treats it. */
$download = getWithJar($baseUrl . '/asset/' . $assetId . '/notes.pdf', $jar);
check('Downloading succeeds', $download['status'] === 200, "got {$download['status']}");
check('It carries the file', str_contains($download['body'], 'Smoke test handout'));
check(
    'It is served as a PDF from the allowlist',
    str_contains($download['headers']['content-type'] ?? '', 'application/pdf'),
    'got "' . ($download['headers']['content-type'] ?? 'nothing') . '"'
);
check(
    'It is sent as an attachment',
    str_contains($download['headers']['content-disposition'] ?? '', 'attachment'),
    'an inline file can surprise somebody'
);
check(
    'and the browser is told not to sniff it',
    ($download['headers']['x-content-type-options'] ?? '') === 'nosniff',
    'a file that looks like HTML would render in this origin'
);
check(
    'It is not cacheable by a shared cache',
    str_contains($download['headers']['cache-control'] ?? '', 'private'),
    'one viewer copy could be served to a stranger'
);

/* The claim the whole design rests on: the video governs the file. */
$publicDownload = get($baseUrl . '/asset/' . $assetId . '/notes.pdf');
check(
    'A public video s handout reaches a stranger',
    $publicDownload['status'] === 200,
    "got {$publicDownload['status']}"
);

$db->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$videoRow]);
$membersDownload = get($baseUrl . '/asset/' . $assetId . '/notes.pdf');
$db->execute('UPDATE {videos} SET member_only = 0 WHERE id = ?', [$videoRow]);

check(
    'A members-only video s handout does not',
    $membersDownload['status'] === 404,
    "got {$membersDownload['status']} — A PRIVATE HANDOUT WAS DOWNLOADABLE"
);

$db->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$videoRow]);
$unpublishedDownload = get($baseUrl . '/asset/' . $assetId . '/notes.pdf');
$db->execute('UPDATE {videos} SET is_published = 1 WHERE id = ?', [$videoRow]);

check(
    'Unpublishing takes the handout with it',
    $unpublishedDownload['status'] === 404,
    "got {$unpublishedDownload['status']}"
);

/* Executable types are refused. */
$shellPath = sys_get_temp_dir() . '/portal-smoke-shell-' . getmypid() . '.php';
file_put_contents($shellPath, "<?php echo 'pwned';");

uploadWithJar($baseUrl . '/admin/videos', [
    '_token' => csrfFrom($attachEdit['body']),
    'id'     => (string) $videoRow,
    'action' => 'attach',
], 'attachment', $shellPath, $jar);

check(
    'A .php cannot be attached',
    (int) $db->value('SELECT COUNT(*) FROM {file_assets} WHERE original_name LIKE ?', ['%.php']) === 0,
    'A SHELL WAS UPLOADED'
);

@unlink($shellPath);

/* The watch page lists it. */
$watchWithFiles = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'The file is listed under the video',
    str_contains($watchWithFiles['body'], 'id="attachments-heading"'),
    'attached and invisible'
);
check('It links to the download', str_contains($watchWithFiles['body'], '/asset/' . $assetId . '/'));

/* Removing takes the file with it. */
postWithJar($baseUrl . '/admin/videos', [
    '_token' => csrfFrom($attachEdit['body']),
    'id'     => (string) $videoRow,
    'action' => 'detach',
    'asset'  => (string) $assetId,
], $jar);

check(
    'Removing an attachment deletes the file too',
    (int) $db->value('SELECT COUNT(*) FROM {file_assets} WHERE id = ?', [$assetId]) === 0
        && !is_file(PORTAL_ROOT . '/storage/' . $storedPath),
    'the row went and the file stayed'
);
check(
    'and the download stops working',
    get($baseUrl . '/asset/' . $assetId . '/notes.pdf')['status'] === 404,
    'a removed attachment is still downloadable'
);

@unlink($notesPath);

echo "\nAnalytics\n";

$analytics = getWithJar($baseUrl . '/admin/analytics', $jar);
check('Analytics screen renders', $analytics['status'] === 200, "got {$analytics['status']}");
check('Analytics appears in the admin navigation', str_contains($adminHome['body'], '/admin/analytics'));
check(
    'It says what a view is',
    str_contains($analytics['body'], 'counted once per session'),
    'a number nobody can interpret is worse than no number'
);
check(
    'and that there is no per-person history',
    // Stops before the template's line wrap. "…who watched what" spans a break
    // and never matches — the second time that has caught me out here.
    str_contains($analytics['body'], 'no record here of who watched'),
    'the privacy position is a decision, not an accident, and should be stated'
);

/*
 * Views come from the progress endpoint the player already posts to, so this
 * drives that rather than inventing a route. The once-per-session rule is the
 * claim worth proving and the only way to prove it is a real session.
 */
$db->execute('DELETE FROM {video_views}');

$progress = postJsonWithJar($baseUrl . '/api/progress', [
    'videoId'  => $videoRow,
    'position' => 30,
    'duration' => 125,
], $jar);

check('Posting progress succeeds', $progress['status'] === 200, "got {$progress['status']}");
check(
    'and it counts as a view',
    (int) $db->value('SELECT COALESCE(SUM(views), 0) FROM {video_views} WHERE video_id = ?', [$videoRow]) === 1,
    'nothing was counted'
);

/* The player posts every ten seconds. Each one must not be a view. */
for ($i = 0; $i < 3; $i++) {
    postJsonWithJar($baseUrl . '/api/progress', [
        'videoId'  => $videoRow,
        'position' => 40 + ($i * 10),
        'duration' => 125,
    ], $jar);
}

check(
    'Further progress in the same session does not count again',
    (int) $db->value('SELECT COALESCE(SUM(views), 0) FROM {video_views} WHERE video_id = ?', [$videoRow]) === 1,
    'an hour-long sermon would report hundreds of views'
);

/* Reaching the end adds a completion, and still not a second view. */
postJsonWithJar($baseUrl . '/api/progress', [
    'videoId'  => $videoRow,
    'position' => 125,
    'duration' => 125,
], $jar);

check(
    'Finishing records a completion',
    (int) $db->value('SELECT COALESCE(SUM(completions), 0) FROM {video_views} WHERE video_id = ?', [$videoRow]) === 1,
    'nothing recorded that it was watched to the end'
);
check(
    'and still not a second view',
    (int) $db->value('SELECT COALESCE(SUM(views), 0) FROM {video_views} WHERE video_id = ?', [$videoRow]) === 1,
    'finishing a video reported a second viewer'
);

/*
 * The export.
 *
 * The check that carries weight is the last one: a title an editor typed is
 * the second column, and a spreadsheet reads a cell starting `=` as a formula.
 * So a title is renamed to one here and the file is inspected for it — nothing
 * about the export is compromised, but the spreadsheet is what turns the value
 * into code, and this is where it has to be defused.
 */
$db->execute(
    'UPDATE {videos} SET title = ? WHERE id = ?',
    ['=HYPERLINK("https://evil.example","Click")', $videoRow]
);

$export = getWithJar($baseUrl . '/admin/analytics.csv?days=30', $jar);

check('The analytics export downloads', $export['status'] === 200, "got {$export['status']}");
check(
    'It is offered as a file rather than shown',
    str_contains($export['headers']['content-disposition'] ?? '', 'attachment')
        && str_contains($export['headers']['content-disposition'] ?? '', '.csv'),
    'a CSV rendered in the browser is a page built from whatever editors typed'
);
check(
    'and the browser is told not to sniff it',
    ($export['headers']['x-content-type-options'] ?? '') === 'nosniff'
);
check(
    'It carries the daily rows',
    str_contains($export['body'], 'Date,Video,Address,Views,Finished')
        && str_contains($export['body'], date('Y-m-d')),
    'the export is empty or has the wrong shape'
);
check(
    'A title that is a formula cannot execute in a spreadsheet',
    !str_contains($export['body'], ',=HYPERLINK') && str_contains($export['body'], "'=HYPERLINK"),
    'opening the export would run a formula built from a title somebody typed'
);
check(
    'The download link is on the screen',
    str_contains($analytics['body'], '/admin/analytics.csv'),
    'an export nobody can reach is not an export'
);

$db->execute('UPDATE {videos} SET title = ? WHERE id = ?', ['A Test Video', $videoRow]);

/* Under ten seconds is somebody clicking away, not a view. */
$db->execute('DELETE FROM {video_views}');
$briefJar = sys_get_temp_dir() . '/portal-smoke-brief-' . getmypid() . '.txt';
@unlink($briefJar);

postWithJar($baseUrl . '/auth/login', [
    'email'    => 'admin@smoke.test',
    'password' => 'smoke-test-password-1234',
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $briefJar)['body']),
], $briefJar);

postJsonWithJar($baseUrl . '/api/progress', [
    'videoId'  => $videoRow,
    'position' => 4,
    'duration' => 125,
], $briefJar);

check(
    'Four seconds in is not a view',
    (int) $db->value('SELECT COALESCE(SUM(views), 0) FROM {video_views}') === 0,
    'clicking away would be counted as watching'
);

@unlink($briefJar);

/* A different session is a different view. */
$secondJar = sys_get_temp_dir() . '/portal-smoke-second-' . getmypid() . '.txt';
@unlink($secondJar);

postWithJar($baseUrl . '/auth/login', [
    'email'    => 'admin@smoke.test',
    'password' => 'smoke-test-password-1234',
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $secondJar)['body']),
], $secondJar);

postJsonWithJar($baseUrl . '/api/progress', [
    'videoId'  => $videoRow,
    'position' => 30,
    'duration' => 125,
], $secondJar);

check(
    'A separate session counts separately',
    (int) $db->value('SELECT COALESCE(SUM(views), 0) FROM {video_views} WHERE video_id = ?', [$videoRow]) === 1,
    'the marker leaked between sessions'
);

@unlink($secondJar);

$analyticsAfter = getWithJar($baseUrl . '/admin/analytics', $jar);
check(
    'The video appears on the analytics screen',
    str_contains($analyticsAfter['body'], 'A Test Video'),
    'counted and never shown'
);

$narrowWindow = getWithJar($baseUrl . '/admin/analytics?days=7', $jar);
check('The period can be narrowed', $narrowWindow['status'] === 200, "got {$narrowWindow['status']}");

$absurdWindow = getWithJar($baseUrl . '/admin/analytics?days=999999', $jar);
check(
    'An unoffered period falls back rather than scanning everything',
    $absurdWindow['status'] === 200 && str_contains($absurdWindow['body'], 'Last 30 days'),
    "got {$absurdWindow['status']}"
);

echo "\nChapters\n";

$chapterEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('The edit screen offers a chapter list', str_contains($chapterEdit['body'], 'name="chapters"'));

$savedChapters = postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($chapterEdit['body']),
    'id'       => (string) $videoRow,
    'action'   => 'chapters',
    'chapters' => "Chapters:\n0:00 Welcome\n2:15 The reading from Psalm 1:1\n14:30 Questions",
], $jar);

check('Saving chapters succeeds', $savedChapters['status'] === 302, "got {$savedChapters['status']}");
check(
    'Three were stored, and the heading line was skipped',
    (int) $db->value('SELECT COUNT(*) FROM {chapters} WHERE video_id = ?', [$videoRow]) === 3,
    'a heading above the list cost somebody their chapters'
);
check(
    'A time inside a title stayed a title',
    (string) $db->value('SELECT title FROM {chapters} WHERE video_id = ? AND start_at = 135', [$videoRow])
        === 'The reading from Psalm 1:1',
    'a scripture reference was read as a marker'
);

$chapterEditAfter = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check(
    'The box shows the list in the shape it was typed',
    str_contains($chapterEditAfter['body'], '2:15 The reading'),
    'changing one title would mean rebuilding the list'
);

$watchWithChapters = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check('Chapters appear under the video', str_contains($watchWithChapters['body'], 'id="chapters-heading"'));
check('They are listed', str_contains($watchWithChapters['body'], 'Questions'));
check(
    'Each one links to its moment',
    str_contains($watchWithChapters['body'], '?t=870'),
    'a chapter nobody can click is a caption'
);

/* Text with no timestamps at all is a format mistake, and says so. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($chapterEditAfter['body']),
    'id'       => (string) $videoRow,
    'action'   => 'chapters',
    'chapters' => "Welcome\nThe reading\nQuestions",
], $jar);

check(
    'A list with no timestamps changes nothing',
    (int) $db->value('SELECT COUNT(*) FROM {chapters} WHERE video_id = ?', [$videoRow]) === 3,
    'a mistyped list silently wiped the real one'
);

/* Emptying the box is how somebody removes them. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($chapterEditAfter['body']),
    'id'       => (string) $videoRow,
    'action'   => 'chapters',
    'chapters' => '',
], $jar);

check(
    'Emptying the box removes them',
    (int) $db->value('SELECT COUNT(*) FROM {chapters} WHERE video_id = ?', [$videoRow]) === 0,
    'there is no way to take chapters off a video'
);
check(
    'and the video page stops showing the section',
    !str_contains(getWithJar($baseUrl . '/watch/' . $videoSlug, $jar)['body'], 'id="chapters-heading"'),
    'an empty chapter heading is left behind'
);

/*
 * The moment a chapter link actually asks for.
 *
 * Chapters and transcript lines are both ordinary links to ?t=seconds. With
 * scripting off the page reloads and the player starts there; the JS only
 * removes the reload. Either way the server has to read the parameter, so that
 * is what these check — the part that works without a browser.
 */
/* Within the seeded video's 125 seconds — a moment past the end is its own
   check below, and using one here would pass for the wrong reason. */
$atMoment = getWithJar($baseUrl . '/watch/' . $videoSlug . '?t=60', $jar);
check(
    'A ?t= link sets the start position',
    str_contains($atMoment['body'], 'data-start-at="60"'),
    'the link reloads the page and starts from the beginning'
);

$noMoment = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check('Without one the start position is zero', str_contains($noMoment['body'], 'data-start-at="0"'));

$absurdMoment = getWithJar($baseUrl . '/watch/' . $videoSlug . '?t=999999', $jar);
check(
    'A moment past the end is ignored',
    str_contains($absurdMoment['body'], 'data-start-at="0"'),
    'a seek past the end behaves differently in every browser'
);

$negativeMoment = getWithJar($baseUrl . '/watch/' . $videoSlug . '?t=-5', $jar);
check('A negative moment is ignored', str_contains($negativeMoment['body'], 'data-start-at="0"'));

$nonsenseMoment = getWithJar($baseUrl . '/watch/' . $videoSlug . '?t=notatime', $jar);
check('A moment that is not a number is ignored', str_contains($nonsenseMoment['body'], 'data-start-at="0"'));

echo "\nTranscripts\n";

$transcriptEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('The edit screen offers a transcript import', str_contains($transcriptEdit['body'], 'name="transcript"'));
check('It accepts a file as well as a paste', str_contains($transcriptEdit['body'], 'name="transcript_file"'));
check(
    'It says why a transcript is weighted low in search',
    str_contains($transcriptEdit['body'], 'weighted well below a title'),
    'an editor cannot tell whether importing one will wreck their search results'
);

$vtt = "WEBVTT\n\n"
     . "00:00:01.000 --> 00:00:04.000\nWelcome to the recording.\n\n"
     . "00:00:04.000 --> 00:00:09.000\nToday we are talking about perseverance.\n\n"
     . "00:00:09.000 --> 00:00:14.000\nA word that appears nowhere else on this site.\n";

$imported = postWithJar($baseUrl . '/admin/videos', [
    '_token'            => csrfFrom($transcriptEdit['body']),
    'id'                => (string) $videoRow,
    'action'            => 'transcript',
    'transcript'        => $vtt,
    'transcript_source' => 'the smoke test',
], $jar);

check('Importing succeeds', $imported['status'] === 302, "got {$imported['status']}");
check(
    'Three lines were stored',
    (int) $db->value('SELECT cue_count FROM {transcripts} WHERE video_id = ?', [$videoRow]) === 3,
    'the parse produced a different number of cues than the file contains'
);
check(
    'and the cues went in too',
    (int) $db->value('SELECT COUNT(*) FROM {transcript_cues} WHERE video_id = ?', [$videoRow]) === 3,
    'the summary row and the cues disagree'
);

$transcriptAfter = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('The panel reports what was imported', str_contains($transcriptAfter['body'], 'the smoke test'));

/* The panel on the watch page — the half a viewer actually sees. */
$watchWithTranscript = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'The transcript appears under the video',
    str_contains($watchWithTranscript['body'], 'id="transcript"'),
    'imported and invisible'
);
check('It shows the lines', str_contains($watchWithTranscript['body'], 'Today we are talking about perseverance'));
check(
    'Each line links to its moment',
    str_contains($watchWithTranscript['body'], '?t=9#transcript'),
    'a transcript nobody can navigate is a wall of text'
);

/*
 * The point of indexing one: finding the recording where somebody said a
 * particular thing, when nothing else on the video mentions it.
 */
$spokenSearch = get($baseUrl . '/search?q=perseverance');
check(
    'A word only said aloud finds the video',
    str_contains($spokenSearch['body'], 'A Test Video'),
    'the transcript is stored and not searched'
);

$timestampSearch = get($baseUrl . '/search?q=00%3A00%3A04');
check(
    'Timestamps are not searchable',
    !str_contains($timestampSearch['body'], 'A Test Video'),
    'every transcript would match a query like "2026"'
);

/* Importing again replaces rather than appends. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'     => csrfFrom($transcriptAfter['body']),
    'id'         => (string) $videoRow,
    'action'     => 'transcript',
    'transcript' => "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nA completely different take.\n",
], $jar);

check(
    'Importing again replaces the old one',
    (int) $db->value('SELECT COUNT(*) FROM {transcript_cues} WHERE video_id = ?', [$videoRow]) === 1,
    'two takes of one recording are now interleaved'
);

/* Something that is not a subtitle file is refused, and changes nothing. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'     => csrfFrom($transcriptAfter['body']),
    'id'         => (string) $videoRow,
    'action'     => 'transcript',
    'transcript' => "Here is a paragraph somebody pasted by mistake.\n\nAnd another.",
], $jar);

check(
    'A file with no timestamps is refused',
    (int) $db->value('SELECT COUNT(*) FROM {transcript_cues} WHERE video_id = ?', [$videoRow]) === 1,
    'prose was stored as a transcript'
);

/* Removing it takes both tables with it. */
postWithJar($baseUrl . '/admin/videos', [
    '_token' => csrfFrom($transcriptAfter['body']),
    'id'     => (string) $videoRow,
    'action' => 'transcript-delete',
], $jar);

check(
    'Removing a transcript clears both tables',
    (int) $db->value('SELECT COUNT(*) FROM {transcripts} WHERE video_id = ?', [$videoRow]) === 0
        && (int) $db->value('SELECT COUNT(*) FROM {transcript_cues} WHERE video_id = ?', [$videoRow]) === 0,
    'the cues outlived the transcript'
);
check(
    'and the video stops matching what was said in it',
    !str_contains(get($baseUrl . '/search?q=perseverance')['body'], 'A Test Video'),
    'search still matches a transcript that has been removed'
);

/* ------------------------------------------------------------------ captions
 *
 * Captions have no local table to assert against — they live at the video
 * provider, because the player is an iframe on the provider's domain and
 * nothing stored here could put text on the screen. So these checks are about
 * the two things this side genuinely owns: that the panel is reachable and
 * says what it does, and that a file is validated BEFORE anything is sent.
 *
 * This install's bunny.net credentials are placeholders, so an upload that
 * passes validation reaches the network and fails there. That failing is the
 * point of the last check: it is the only outcome that distinguishes "refused
 * here" from "sent", and a handler nothing calls would look identical to a
 * working one in every check above it.
 */
echo "\nCaptions\n";

$captionEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);

check(
    'The edit screen offers a caption upload',
    str_contains($captionEdit['body'], 'name="caption_file"'),
    'the panel that would make captions reachable is not on the page'
);
check(
    'with a language to pick',
    str_contains($captionEdit['body'], 'name="caption_language"')
        && str_contains($captionEdit['body'], 'Portuguese (Brazil)')
);
check(
    'and it says where captions actually live',
    str_contains($captionEdit['body'], 'stored at your video provider'),
    'somebody removing a caption in the provider dashboard has no idea why it vanished here'
);

/*
 * The transcript was deleted just above, so the "use the transcript instead"
 * option should be gone. Worth checking in both states: an option that renders
 * unconditionally would offer a conversion with nothing to convert, and the
 * failure lands as a confusing error at submit time.
 */
check(
    'The transcript option is absent when there is no transcript',
    !str_contains($captionEdit['body'], 'name="caption_from_transcript"'),
    'the form offers to convert a transcript this video does not have'
);

$captionVtt = "WEBVTT\n\n"
            . "00:00:01.250 --> 00:00:04.500\nWelcome to the recording.\n\n"
            . "00:00:04.500 --> 00:00:09.750 align:start position:10%\nAnd the second line.\n";

$captionPath = sys_get_temp_dir() . '/portal-smoke-captions-' . getmypid() . '.vtt';
file_put_contents($captionPath, $captionVtt);

/* A language code that is not one is refused before any of this is read. */
uploadWithJar($baseUrl . '/admin/videos', [
    '_token'           => csrfFrom($captionEdit['body']),
    'id'               => (string) $videoRow,
    'action'           => 'caption',
    'caption_language' => '../../etc/passwd',
], 'caption_file', $captionPath, $jar, 'text/vtt');

$afterBadLanguage = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);

check(
    'A language code that is not one is refused',
    str_contains($afterBadLanguage['body'], 'not a language code'),
    'the tag becomes a URL path at the provider, so this one has to be refused here'
);

/* Nothing to upload at all — no file, no transcript. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'           => csrfFrom($afterBadLanguage['body']),
    'id'               => (string) $videoRow,
    'action'           => 'caption',
    'caption_language' => 'en',
], $jar);

$afterNothing = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);

check(
    'A caption upload with nothing in it is refused',
    str_contains($afterNothing['body'], 'No timed lines could be read'),
    'an empty upload would reach the provider and become a caption track with no cues'
);

/* Prose in a .vtt is refused for the same reason. */
$prosePath = sys_get_temp_dir() . '/portal-smoke-not-captions-' . getmypid() . '.vtt';
file_put_contents($prosePath, "Here is a paragraph somebody pasted by mistake.\n\nAnd another.\n");

uploadWithJar($baseUrl . '/admin/videos', [
    '_token'           => csrfFrom($afterNothing['body']),
    'id'               => (string) $videoRow,
    'action'           => 'caption',
    'caption_language' => 'en',
], 'caption_file', $prosePath, $jar, 'text/vtt');

$afterProse = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);

check(
    'A file with no timings is refused',
    str_contains($afterProse['body'], 'No timed lines could be read'),
    'prose would have been stored as a caption track'
);

/*
 * And a real one gets past validation and reaches the provider. With
 * placeholder credentials that call fails, which is what proves it was made —
 * every check above this one would pass just as happily against a handler
 * nothing ever called.
 */
uploadWithJar($baseUrl . '/admin/videos', [
    '_token'           => csrfFrom($afterProse['body']),
    'id'               => (string) $videoRow,
    'action'           => 'caption',
    'caption_language' => 'EN',
    'caption_label'    => 'English',
], 'caption_file', $captionPath, $jar, 'text/vtt');

$afterReal = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);

check(
    'A real caption file gets past validation and is sent',
    str_contains($afterReal['body'], 'bunny.net'),
    'a valid file was refused locally, so the upload path is never exercised at all'
);
check(
    'and the failure is a message on the screen, not an error page',
    $afterReal['status'] === 200,
    'an editor who hits a provider outage loses the form they were filling in'
);

@unlink($captionPath);
@unlink($prosePath);

/*
 * Put a transcript back, so the conversion option can be checked in its other
 * state. Left in place afterwards would change what the revision checks below
 * are counting, so it goes again immediately.
 */
postWithJar($baseUrl . '/admin/videos', [
    '_token'     => csrfFrom($afterReal['body']),
    'id'         => (string) $videoRow,
    'action'     => 'transcript',
    'transcript' => "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nSomething said.\n",
], $jar);

$withTranscript = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);

check(
    'The transcript option appears once there is a transcript',
    str_contains($withTranscript['body'], 'name="caption_from_transcript"'),
    'the option is decoration rather than a real condition'
);
check(
    'and it says what converting one costs',
    str_contains($withTranscript['body'], 'nearest second'),
    'captions built from a transcript can sit a second early and nobody is told why'
);

postWithJar($baseUrl . '/admin/videos', [
    '_token' => csrfFrom($withTranscript['body']),
    'id'     => (string) $videoRow,
    'action' => 'transcript-delete',
], $jar);

echo "\nRevision history\n";

/*
 * Driven through the edit form, because the snapshot is taken by the admin
 * handler rather than by the repository — deliberately, so the provider sync
 * does not bury one editorial change under a hundred machine writes. A test at
 * the repository level would prove nothing about that split.
 */
/*
 * Counted relative to a baseline rather than from zero. Earlier sections
 * already edited this video several times, so an absolute count would be
 * asserting the length of the whole script rather than anything about
 * revisions — and would break every time a check was added above.
 */
$revisionsBefore = (int) $db->value(
    'SELECT COUNT(*) FROM {revisions} WHERE subject_type = ? AND subject_id = ?',
    ['video', $videoRow]
);

$historyBefore = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('The edit screen has a history panel', str_contains($historyBefore['body'], 'History'));

$revisionFields = [
    '_token'         => csrfFrom($historyBefore['body']),
    'id'             => (string) $videoRow,
    'action'         => 'save',
    'categories'     => [(string) $categoryRow],
    'thumbnail_mode' => 'default',
    'watermark_mode' => 'default',
    '_whole_form'    => '1',
];

postWithJar($baseUrl . '/admin/videos', $revisionFields + ['title' => 'A Renamed Video'], $jar);

check(
    'Saving records the previous version',
    (int) $db->value('SELECT COUNT(*) FROM {revisions} WHERE subject_type = ? AND subject_id = ?',
        ['video', $videoRow]) === $revisionsBefore + 1,
    'nothing was snapshotted'
);
check(
    'and it holds the title as it was BEFORE the edit',
    str_contains(
        (string) $db->value(
            'SELECT data FROM {revisions} WHERE subject_type = ? AND subject_id = ? ORDER BY id DESC LIMIT 1',
            ['video', $videoRow]
        ),
        'A Test Video'
    ),
    'the snapshot was taken after the write, so restoring would change nothing'
);

/*
 * Captured NOW, while it is unambiguous which version this is.
 *
 * By subject, because the newest row in the whole table belongs to whichever
 * thing was edited last — a series, from a section far above. And before the
 * idle saves below, because each of those adds a version too and the newest
 * would then be the state we are already in, making Restore a no-op that reads
 * as a broken button.
 */
$revisionId = (int) $db->value(
    'SELECT id FROM {revisions} WHERE subject_type = ? AND subject_id = ? ORDER BY id DESC LIMIT 1',
    ['video', $videoRow]
);

$historyAfter = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check('The panel lists the version', str_contains($historyAfter['body'], 'Saved by'));
check(
    'and says what restoring would change',
    str_contains($historyAfter['body'], 'A Renamed Video') && str_contains($historyAfter['body'], 'A Test Video'),
    'a version with no description of what it does is one nobody will press'
);

/*
 * Two more saves that change nothing.
 *
 * The first still records, correctly: the newest stored version is the state
 * from BEFORE the rename, so a snapshot of the current state is genuinely new.
 * The second has nothing left to add, and that is where the guard bites —
 * without it, an editor who opens a form and presses Save all afternoon would
 * flush the real history out one row at a time.
 */
postWithJar($baseUrl . '/admin/videos', $revisionFields + ['title' => 'A Renamed Video'], $jar);
$afterFirstIdleSave = (int) $db->value(
    'SELECT COUNT(*) FROM {revisions} WHERE subject_type = ? AND subject_id = ?',
    ['video', $videoRow]
);

postWithJar($baseUrl . '/admin/videos', $revisionFields + ['title' => 'A Renamed Video'], $jar);

check(
    'Repeated identical saves stop recording',
    (int) $db->value('SELECT COUNT(*) FROM {revisions} WHERE subject_type = ? AND subject_id = ?',
        ['video', $videoRow]) === $afterFirstIdleSave,
    'an idle Save would eventually flush the real history out'
);

$restored = postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($historyAfter['body']),
    'id'       => (string) $videoRow,
    'action'   => 'restore-revision',
    'revision' => (string) $revisionId,
], $jar);

/*
 * The status alone proves nothing: the refusal path also answers 302, because
 * back() is how both outcomes return. So the assertion is on the data.
 */
check('Restoring answers with a redirect', $restored['status'] === 302, "got {$restored['status']}");
check(
    'and the old title is back',
    (string) $db->value('SELECT title FROM {videos} WHERE id = ?', [$videoRow]) === 'A Test Video',
    'the button did nothing'
);
/*
 * The claim is that an undo can itself be undone — not that a row was added.
 *
 * Counting was the wrong way to check it: the state before this restore was
 * already the newest stored version, so the dedup correctly declined to store
 * a second copy. The version needed to go forward again is still there, which
 * is the property that matters.
 */
check(
    'The state before the restore is still recoverable',
    (int) $db->value(
        'SELECT COUNT(*) FROM {revisions}
          WHERE subject_type = ? AND subject_id = ? AND data LIKE ?',
        ['video', $videoRow, '%A Renamed Video%']
    ) > 0,
    'an undo that cannot be undone'
);

/* A revision id belonging to something else is a tampered form. */
$otherCategoryRevision = $db->insert('revisions', [
    'subject_type' => 'category',
    'subject_id'   => $categoryRow,
    'data'         => '{"name":"Hijacked"}',
    'changed_by'   => 'smoke',
    'created_at'   => date('Y-m-d H:i:s'),
]);

postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($historyAfter['body']),
    'id'       => (string) $videoRow,
    'action'   => 'restore-revision',
    'revision' => (string) $otherCategoryRevision,
], $jar);

check(
    'A revision belonging to something else is refused',
    (string) $db->value('SELECT title FROM {videos} WHERE id = ?', [$videoRow]) === 'A Test Video',
    'a tampered form restored one thing onto another'
);

$db->execute('DELETE FROM {revisions} WHERE id = ?', [$otherCategoryRevision]);

/* Categories keep history too. */
$categoryEditForHistory = getWithJar($baseUrl . '/admin/categories/' . $categoryRow, $jar);

postWithJar($baseUrl . '/admin/categories', [
    '_token'         => csrfFrom($categoryEditForHistory['body']),
    'id'             => (string) $categoryRow,
    'action'         => 'update',
    'name'           => 'Renamed Sermons',
    'is_published'   => '1',
    'thumbnail_mode' => 'default',
], $jar);

check(
    'Categories are versioned too',
    (int) $db->value(
        'SELECT COUNT(*) FROM {revisions} WHERE subject_type = ? AND subject_id = ?',
        ['category', $categoryRow]
    ) > 0,
    'only videos have history'
);

/* Put the name back, so later checks see the category they expect. */
postWithJar($baseUrl . '/admin/categories', [
    '_token'         => csrfFrom($categoryEditForHistory['body']),
    'id'             => (string) $categoryRow,
    'action'         => 'update',
    'name'           => 'Sermons',
    'is_published'   => '1',
    'thumbnail_mode' => 'default',
], $jar);

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

/* ------------------------------------------------------------------- notes
 *
 * The reachability half — a panel on the watch page and a page that collects
 * what it wrote — plus the one claim that has no other check: a note belongs to
 * exactly one account, and there is no way to reach somebody else's.
 */
echo "\nNotes\n";

$watchForNotes = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);

check(
    'The watch page offers a notes box',
    str_contains($watchForNotes['body'], 'id="note-body"'),
    'the table exists and nothing can write to it'
);
check(
    'It says who can read them',
    str_contains($watchForNotes['body'], 'Only you can read these'),
    'somebody deciding whether to write something down is deciding it right there'
);
check(
    'and offers to stamp the current time',
    str_contains($watchForNotes['body'], 'id="note-timestamp"'),
    'a sermon notes box with no timestamps is a textarea'
);

$noteSaved = postJsonWithJar($baseUrl . '/notes', [
    'videoId' => $videoRow,
    'body'    => "12:30 The second point\nWorth coming back to.",
], $jar);

check('Saving a note succeeds', $noteSaved['status'] === 200, "got {$noteSaved['status']}");
check(
    'and it is stored against the right account',
    (int) $db->value(
        'SELECT COUNT(*) FROM {video_notes} WHERE video_id = ? AND user_id = (SELECT id FROM {users} WHERE email = ?)',
        [$videoRow, 'admin@smoke.test']
    ) === 1
);

$notesPage = getWithJar($baseUrl . '/notes', $jar);
check('The notes page renders', $notesPage['status'] === 200, "got {$notesPage['status']}");
check('It shows what was written', str_contains($notesPage['body'], 'Worth coming back to.'));
check(
    'and links back to the video',
    str_contains($notesPage['body'], '/watch/' . $videoSlug),
    'a note nobody can trace back to its video is half a note'
);

/* Saving again replaces rather than accumulating. */
postJsonWithJar($baseUrl . '/notes', ['videoId' => $videoRow, 'body' => 'Rewritten.'], $jar);

check(
    'Saving again replaces the note',
    (int) $db->value('SELECT COUNT(*) FROM {video_notes} WHERE video_id = ?', [$videoRow]) === 1
        && str_contains(getWithJar($baseUrl . '/notes', $jar)['body'], 'Rewritten.'),
    'the autosave is accumulating rows'
);

/*
 * The check with no other cover. Notes have no capability and no admin screen,
 * so if the scoping were wrong nothing else in the suite would notice.
 */
// Made earlier, in the comments section.
$noteOtherEmail = 'note-reader@smoke.test';

$otherJar = sys_get_temp_dir() . '/portal-smoke-notes-' . getmypid() . '.txt';
@unlink($otherJar);

$noteLogin = getWithJar($baseUrl . '/auth/login', $otherJar);
postWithJar($baseUrl . '/auth/login', [
    '_token'   => csrfFrom($noteLogin['body']),
    'email'    => $noteOtherEmail,
    'password' => 'note-reader-password-1234',
], $otherJar);

check(
    'Another account sees none of it',
    !str_contains(getWithJar($baseUrl . '/notes', $otherJar)['body'], 'Rewritten.'),
    'notes are readable by somebody who did not write them'
);
check(
    'and the box on the same video is empty for them',
    !str_contains(getWithJar($baseUrl . '/watch/' . $videoSlug, $otherJar)['body'], 'Rewritten.'),
    'the watch page hands one person another person\'s note'
);

/* Emptying the box removes it, which is how somebody deletes one. */
postJsonWithJar($baseUrl . '/notes', ['videoId' => $videoRow, 'body' => ''], $jar);

check(
    'Emptying the box removes the note',
    (int) $db->value('SELECT COUNT(*) FROM {video_notes} WHERE video_id = ?', [$videoRow]) === 0
);

/* Signed out, there is nowhere to put one. */
check(
    'A signed-out visitor cannot save a note',
    in_array(postJson($baseUrl . '/notes', ['videoId' => $videoRow, 'body' => 'x'])['status'], [302, 401, 403], true),
    'notes can be written by anybody, against anybody'
);

@unlink($otherJar);

/* --------------------------------------------------------------- live streams
 *
 * The state is never stored, so the interesting checks are the ones that move
 * a stream between states by changing only its timestamps — no job runs, and
 * nothing here presses a "go live" button.
 */
echo "\nLive streams\n";

$liveScreen = getWithJar($baseUrl . '/admin/live', $jar);
check('The live screen renders', $liveScreen['status'] === 200, "got {$liveScreen['status']}");
check(
    'It says plainly that this site does not host the stream',
    str_contains($liveScreen['body'], 'does not host the stream'),
    'an admin expecting to press go-live finds out at ten to eleven on a Sunday'
);
check(
    'and explains the badge expires on its own',
    str_contains($liveScreen['body'], 'nobody believes'),
    'a forgotten stream says LIVE for a month'
);
check(
    'It appears in the admin navigation',
    str_contains(getWithJar($baseUrl . '/admin', $jar)['body'], '/admin/live')
);

/* A URL that would execute in an iframe src is refused. */
foreach ([
    'javascript:alert(1)'                        => 'a javascript address',
    'data:text/html,<script>alert(1)</script>'   => 'a data address',
    'http://example.com/embed/1'                 => 'an insecure address',
] as $badUrl => $what) {
    postWithJar($baseUrl . '/admin/live', [
        '_token'    => csrfFrom($liveScreen['body']),
        'action'    => 'create',
        'title'     => 'Refused ' . $what,
        'embed_url' => $badUrl,
    ], $jar);

    check(
        "A stream at {$what} is refused",
        (int) $db->value('SELECT COUNT(*) FROM {live_streams} WHERE embed_url = ?', [$badUrl]) === 0,
        'that address would go straight into an iframe src'
    );
}

/* A real one, scheduled for the future. */
postWithJar($baseUrl . '/admin/live', [
    '_token'    => csrfFrom($liveScreen['body']),
    'action'    => 'create',
    'title'     => 'Sunday Service',
    'embed_url' => 'https://www.youtube.com/embed/smoke-test',
    'starts_at' => date('Y-m-d\TH:i', time() + 7200),
], $jar);

$streamId = (int) $db->value('SELECT id FROM {live_streams} WHERE title = ?', ['Sunday Service']);
check('A well-formed stream is accepted', $streamId > 0);

$streamSlug = (string) $db->value('SELECT slug FROM {live_streams} WHERE id = ?', [$streamId]);

$liveIndex = get($baseUrl . '/live');
check('The public live page renders', $liveIndex['status'] === 200, "got {$liveIndex['status']}");
check('It lists the stream', str_contains($liveIndex['body'], 'Sunday Service'));
check(
    'A scheduled stream is not shown as live',
    !str_contains($liveIndex['body'], 'Live now'),
    'the badge is on before the stream is'
);

$streamPage = get($baseUrl . '/live/' . $streamSlug);
check('The stream page renders', $streamPage['status'] === 200, "got {$streamPage['status']}");
check(
    'and does not load somebody else\'s frame before it starts',
    !str_contains($streamPage['body'], 'youtube.com/embed/smoke-test'),
    'every early visitor makes a request to the broadcaster on our behalf'
);

/*
 * Move the start into the past. Nothing else changes — no job, no button —
 * which is the entire claim about how going live works here.
 */
$db->execute('UPDATE {live_streams} SET starts_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id = ?', [$streamId]);

$nowLive = get($baseUrl . '/live/' . $streamSlug);
check(
    'Passing its start time is all it takes to go live',
    str_contains($nowLive['body'], 'youtube.com/embed/smoke-test'),
    'a stream that has started is not showing'
);
check(
    'and the listing says so',
    str_contains(get($baseUrl . '/live')['body'], 'Live now')
);

/* Ending it by hand beats the schedule. */
postWithJar($baseUrl . '/admin/live', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/admin/live', $jar)['body']),
    'id'     => (string) $streamId,
    'action' => 'end',
], $jar);

check(
    'Marking it ended takes the frame away at once',
    !str_contains(get($baseUrl . '/live/' . $streamSlug)['body'], 'youtube.com/embed/smoke-test'),
    'a stream that finished early keeps broadcasting until its planned end'
);

/* And putting it back on restores it, because streams get ended by mistake. */
postWithJar($baseUrl . '/admin/live', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/admin/live', $jar)['body']),
    'id'     => (string) $streamId,
    'action' => 'resume',
], $jar);

check(
    'Putting it back on works',
    str_contains(get($baseUrl . '/live/' . $streamSlug)['body'], 'youtube.com/embed/smoke-test'),
    'one mis-click costs the rest of the broadcast'
);

/* The safety net: a stream with no end stops claiming to be live. */
$db->execute(
    'UPDATE {live_streams} SET starts_at = DATE_SUB(NOW(), INTERVAL 48 HOUR), ends_at = NULL WHERE id = ?',
    [$streamId]
);

check(
    'A stream nobody ended stops saying live',
    !str_contains(get($baseUrl . '/live')['body'], 'Live now'),
    'a badge nobody believes on the week it is true'
);

/* Once there is a recording, the stream page hands over to it. */
$db->execute('UPDATE {live_streams} SET video_id = ? WHERE id = ?', [$videoRow, $streamId]);

$handover = get($baseUrl . '/live/' . $streamSlug);
check(
    'A stream with a recording sends people to it',
    $handover['status'] === 302 && str_contains($handover['headers']['location'] ?? '', '/watch/' . $videoSlug),
    "got {$handover['status']}"
);

/*
 * Members-only streams are invisible to a stranger.
 *
 * The schedule is put back to something LIVE first. Left as it was — 48 hours
 * old with no end — the safety net has already ended it, and both checks below
 * would pass without membership deciding anything: the stranger sees nothing
 * because it is over, and so does everybody else.
 */
$db->execute(
    'UPDATE {live_streams}
        SET member_only = 1, video_id = NULL, ended_at = NULL,
            starts_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
      WHERE id = ?',
    [$streamId]
);

check(
    'A members-only stream is a 404 for a stranger',
    get($baseUrl . '/live/' . $streamSlug)['status'] === 404,
    'telling somebody a members-only thing exists is itself a leak'
);
check(
    'and is absent from the public listing',
    !str_contains(get($baseUrl . '/live')['body'], 'Sunday Service')
);
check(
    'but an approved viewer still sees it',
    str_contains(getWithJar($baseUrl . '/live', $jar)['body'], 'Sunday Service')
);

/* --------------------------------------------------------------- web push
 *
 * Nothing here is delivered — a real notification needs a real push service and
 * a real browser. What these checks are for is everything on this side of that:
 * the plugin loads, the service worker is served from the ROOT with the header
 * that lets it control the whole site, and a subscription that could never be
 * delivered to is refused when it arrives rather than every night forever.
 */
echo "\nWeb push\n";

$pushPlugins = getWithJar($baseUrl . '/admin/plugins', $jar);
check('Push is listed', str_contains($pushPlugins['body'], 'Push notifications'));

$pushActivated = postWithJar($baseUrl . '/admin/plugins', [
    '_token' => csrfFrom($pushPlugins['body']),
    'slug'   => 'push',
    'action' => 'activate',
], $jar);

check('Activating push succeeds', $pushActivated['status'] === 302, "got {$pushActivated['status']}");
check(
    'and it stayed active after the redirect',
    (int) $db->value('SELECT is_active FROM {plugins} WHERE slug = ?', ['push']) === 1,
    'it was deactivated again, which means it threw on load — check the error log'
);
check(
    'Its tables were created by activation',
    (int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$db->prefix() . 'push_subscriptions']) === 1
);

/*
 * The whole back catalogue is claimed at install. Without that, switching this
 * on for a library that has been up a year would fire every video at every
 * browser that had ever subscribed.
 */
check(
    'Existing videos count as already pushed',
    (int) $db->value('SELECT COUNT(*) FROM {pushed_videos}') > 0,
    'turning the plugin on would announce the entire back catalogue'
);

/*
 * The service worker. It has to be at the ROOT — a worker can only control
 * pages at or below its own path — and it needs the header that widens its
 * scope, without which it registers successfully and receives nothing.
 */
$worker = get($baseUrl . '/push-sw.js');
check('The service worker is served', $worker['status'] === 200, "got {$worker['status']}");
check(
    'as JavaScript',
    str_contains($worker['headers']['content-type'] ?? '', 'javascript'),
    'a service worker served as text/html is refused by every browser'
);
check(
    'with the header that lets it control the whole site',
    ($worker['headers']['service-worker-allowed'] ?? '') === '/',
    'the worker would register and then receive nothing'
);
check(
    'and it handles both a push and a click on one',
    str_contains($worker['body'], "addEventListener('push'")
        && str_contains($worker['body'], "addEventListener('notificationclick'"),
    'a notification nobody can click leads nowhere'
);

$pushAdmin = getWithJar($baseUrl . '/admin/push', $jar);
check('The push settings screen renders', $pushAdmin['status'] === 200, "got {$pushAdmin['status']}");
check(
    'It says there are no keys yet',
    str_contains($pushAdmin['body'], 'No keys yet'),
    'an admin cannot tell whether anything could be sent'
);
check(
    'and warns that this needs https',
    str_contains($pushAdmin['body'], 'Only over https'),
    'somebody will report the feature broken on an http site'
);
check(
    'and that members-only videos are never pushed',
    str_contains($pushAdmin['body'], 'not theirs to hold'),
    'the payload passes through somebody else\'s server and nobody is told'
);
check(
    'Plugin pages appear in the admin navigation',
    str_contains(getWithJar($baseUrl . '/admin', $jar)['body'], '/admin/push')
);

/*
 * The subscribe endpoint. A subscription with a key of the wrong length would
 * otherwise be picked up by every future run, fail, and count as a failure —
 * so it is refused at the door, where there is still somebody to tell.
 */
$goodKey = rtrim(strtr(base64_encode("\x04" . random_bytes(64)), '+/', '-_'), '=');
$goodAuth = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

$refused = postJson($baseUrl . '/push/subscribe', [
    'endpoint' => 'https://push.example.com/wpush/smoke-bad',
    'keys'     => ['p256dh' => 'nonsense', 'auth' => $goodAuth],
]);

check('A malformed subscription is refused', $refused['status'] === 400, "got {$refused['status']}");
check(
    'and nothing was stored',
    (int) $db->value('SELECT COUNT(*) FROM {push_subscriptions}') === 0
);

$accepted = postJson($baseUrl . '/push/subscribe', [
    'endpoint' => 'https://push.example.com/wpush/smoke-good',
    'keys'     => ['p256dh' => $goodKey, 'auth' => $goodAuth],
]);

check('A well-formed subscription is accepted', $accepted['status'] === 200, "got {$accepted['status']}");
check(
    'and stored once',
    (int) $db->value('SELECT COUNT(*) FROM {push_subscriptions}') === 1
);

/* A browser re-subscribing is the same subscriber, not a second one. */
postJson($baseUrl . '/push/subscribe', [
    'endpoint' => 'https://push.example.com/wpush/smoke-good',
    'keys'     => ['p256dh' => $goodKey, 'auth' => $goodAuth],
]);

check(
    'Re-subscribing does not create a second subscriber',
    (int) $db->value('SELECT COUNT(*) FROM {push_subscriptions}') === 1,
    'every notification would be sent twice'
);

$gone = postJson($baseUrl . '/push/unsubscribe', [
    'endpoint' => 'https://push.example.com/wpush/smoke-good',
]);

check('Unsubscribing works', $gone['status'] === 200, "got {$gone['status']}");
check(
    'and removes the subscription',
    (int) $db->value('SELECT COUNT(*) FROM {push_subscriptions}') === 0
);
check(
    'Unsubscribing something unknown is still fine',
    postJson($baseUrl . '/push/unsubscribe', ['endpoint' => 'https://push.example.com/nope'])['status'] === 200,
    'a browser would retry forever'
);

/* Uninstalling takes the tables with it; deactivating must not. */
postWithJar($baseUrl . '/admin/plugins', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/admin/plugins', $jar)['body']),
    'slug'   => 'push',
    'action' => 'deactivate',
], $jar);

check(
    'Deactivating keeps the subscriptions',
    (int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$db->prefix() . 'push_subscriptions']) === 1,
    'turning it off threw away everybody who had subscribed'
);
check(
    'and the service worker stops being served',
    get($baseUrl . '/push-sw.js')['status'] === 404,
    'a deactivated plugin is still answering requests'
);

/* -------------------------------------------------------- sequential unlock
 *
 * The claim that matters is not "a message appears" but "no embed URL is on
 * the page" — a lock that only hides a player is one anybody can walk past
 * with developer tools, and it would look identical in a test that checked for
 * the message.
 *
 * Driven as an ordinary signed-in viewer, because an editor bypasses the lock
 * by design and the administrator this script signs in as would see nothing.
 */
echo "\nSequential unlock\n";

$now = date('Y-m-d H:i:s');

$courseId = $db->insert('series', [
    'slug' => 'a-course', 'title' => 'A Course', 'sequential' => 1,
    'is_published' => 1, 'created_at' => $now, 'updated_at' => $now,
]);

$episodeIds = [];
foreach (['one', 'two'] as $index => $name) {
    $episodeIds[$name] = $db->insert('videos', [
        'provider' => 'bunny', 'provider_id' => 'smoke-course-' . $name,
        'slug' => 'course-episode-' . $name, 'title' => 'Episode ' . ucfirst($name),
        'status' => 'ready', 'is_published' => 1, 'duration' => 100,
        'series_id' => $courseId, 'series_position' => $index,
        'created_at' => $now, 'updated_at' => $now,
    ]);
}

/* A viewer who is not an editor. */
$viewerEmail = 'course-viewer@smoke.test';
$viewerId = $db->insert('users', [
    'email' => $viewerEmail, 'name' => 'Course Viewer',
    'authorized' => 1, 'role_id' => (int) $db->value('SELECT id FROM {roles} WHERE slug = ?', ['viewer']),
    'password_hash' => password_hash('course-viewer-password-1234', PASSWORD_DEFAULT),
    'created_at' => $now, 'updated_at' => $now,
]);

$viewerJar = sys_get_temp_dir() . '/portal-smoke-course-' . getmypid() . '.txt';
@unlink($viewerJar);

$loginPage = getWithJar($baseUrl . '/auth/login', $viewerJar);
postWithJar($baseUrl . '/auth/login', [
    '_token'   => csrfFrom($loginPage['body']),
    'email'    => $viewerEmail,
    'password' => 'course-viewer-password-1234',
], $viewerJar);

$firstEpisode = getWithJar($baseUrl . '/watch/course-episode-one', $viewerJar);
check(
    'The first episode plays',
    $firstEpisode['status'] === 200 && str_contains($firstEpisode['body'], '<iframe'),
    'nobody could ever start the course'
);

$secondEpisode = getWithJar($baseUrl . '/watch/course-episode-two', $viewerJar);

check(
    'The second episode is held back',
    str_contains($secondEpisode['body'], 'Watch in order'),
    'the lock is not applied at all'
);
check(
    'and no player URL is on the page',
    !str_contains($secondEpisode['body'], 'iframe.mediadelivery.net')
        && !str_contains($secondEpisode['body'], 'token='),
    'a signed URL was minted for a locked video, so the lock is decoration'
);
check(
    'It names the episode to watch first, and links to it',
    str_contains($secondEpisode['body'], 'Episode One')
        && str_contains($secondEpisode['body'], '/watch/course-episode-one'),
    '"locked" with no way forward is a dead end'
);

/* Finishing the first opens the second. */
$db->execute(
    'INSERT INTO {watch_progress}
        (user_id, video_id, position_seconds, duration_seconds, completed_at, updated_at)
     VALUES (?, ?, 100, 100, NOW(), NOW())',
    [$viewerId, $episodeIds['one']]
);

$secondAfter = getWithJar($baseUrl . '/watch/course-episode-two', $viewerJar);
check(
    'Finishing the first opens the second',
    str_contains($secondAfter['body'], '<iframe') && !str_contains($secondAfter['body'], 'Watch in order'),
    'the course cannot be progressed through at all'
);

/* An editor is never locked out — reviewing episode nine must not need eight. */
$db->execute('DELETE FROM {watch_progress} WHERE user_id = ?', [$viewerId]);

check(
    'An editor is not locked out',
    str_contains(getWithJar($baseUrl . '/watch/course-episode-two', $jar)['body'], '<iframe'),
    'nobody can review a course without watching it in full first'
);

/* And a series that is not sequential locks nothing. */
$db->execute('UPDATE {series} SET sequential = 0 WHERE id = ?', [$courseId]);

check(
    'A series that is not sequential locks nothing',
    !str_contains(getWithJar($baseUrl . '/watch/course-episode-two', $viewerJar)['body'], 'Watch in order'),
    'every series on the site is now a course'
);

/* The admin toggle has to exist, or none of the above is reachable. */
$seriesEdit = getWithJar($baseUrl . '/admin/series/' . $courseId, $jar);
check(
    'The series screen offers the toggle',
    str_contains($seriesEdit['body'], 'name="sequential"'),
    'the column exists and no form can set it'
);
check(
    'and says what inserting an episode later will do',
    str_contains($seriesEdit['body'], 'immediately before'),
    'an editor cannot predict what adding an episode does to people mid-course'
);

@unlink($viewerJar);

/* ---------------------------------------------------------------- scripture
 *
 * The parser is pinned by unit tests and the queries by integration tests.
 * What neither can tell you is whether a person can get from an edit form to a
 * browse page — which is the whole feature, and the shape of defect this
 * project keeps finding.
 */
echo "\nScripture\n";

$scriptureEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);

check(
    'The edit screen offers a scripture field',
    str_contains($scriptureEdit['body'], 'name="scripture"'),
    'the index can only ever be filled by the description'
);

/*
 * Saved through the REAL form, with a description that also carries a
 * reference — so both sources are exercised at once and the two rules can be
 * seen not to fight.
 */
postWithJar($baseUrl . '/admin/videos', [
    '_token'      => csrfFrom($scriptureEdit['body']),
    'id'          => (string) $videoRow,
    '_whole_form' => '1',
    'action'      => 'save',
    'title'       => 'A Test Video',
    'slug'        => $videoSlug,
    'description' => 'A sermon drawing on Romans 8:28-30.',
    'scripture'   => 'Micah 6:8',
], $jar);

check(
    'A typed reference is stored',
    (int) $db->value(
        'SELECT COUNT(*) FROM {scripture_refs} WHERE video_id = ? AND book = ? AND source = ?',
        [$videoRow, 'micah', 'manual']
    ) === 1,
    'the field renders but nothing reads it'
);
check(
    'and one in the description is found without being typed',
    (int) $db->value(
        'SELECT COUNT(*) FROM {scripture_refs} WHERE video_id = ? AND book = ? AND source = ?',
        [$videoRow, 'romans', 'parsed']
    ) === 1,
    'descriptions are never read, so the back catalogue can never be indexed'
);

$afterScripture = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check(
    'The edit screen shows what the description contributed, separately',
    str_contains($afterScripture['body'], 'Also found in the description'),
    'an editor cannot tell which references clearing the box would remove'
);

/* The browse pages, which are the point of storing any of this. */
$scriptureIndex = get($baseUrl . '/scripture');
check('The scripture index renders', $scriptureIndex['status'] === 200, "got {$scriptureIndex['status']}");
check('It lists a book that has something under it', str_contains($scriptureIndex['body'], 'Romans'));
check(
    'and groups by testament',
    str_contains($scriptureIndex['body'], 'New Testament'),
    'seventy-three books in one list is not a page anybody scans'
);
check(
    'A book with nothing under it is not listed',
    !str_contains($scriptureIndex['body'], 'Habakkuk'),
    'sixty-eight empty books bury the five worth clicking'
);

$bookPage = get($baseUrl . '/scripture/romans');
check('A book page renders', $bookPage['status'] === 200, "got {$bookPage['status']}");
check('It lists the video', str_contains($bookPage['body'], 'A Test Video'));
check(
    'and offers the chapters that have content',
    str_contains($bookPage['body'], '/scripture/romans/8'),
    'a book page with no way into a chapter is a dead end'
);

$chapterPage = get($baseUrl . '/scripture/romans/8');
check('A chapter page renders', $chapterPage['status'] === 200, "got {$chapterPage['status']}");
check('It lists the video', str_contains($chapterPage['body'], 'A Test Video'));

check(
    'A chapter with nothing in it is empty rather than showing the library',
    !str_contains(get($baseUrl . '/scripture/romans/2')['body'], 'A Test Video'),
    'an empty id list was ignored and the whole library listed instead'
);
check(
    'A chapter the book does not have is a 404',
    get($baseUrl . '/scripture/romans/99')['status'] === 404
);
check(
    'A book that does not exist is a 404',
    get($baseUrl . '/scripture/hesitations')['status'] === 404
);

/*
 * The route order check. Both patterns match "/scripture/romans/8" and the
 * unconstrained {book} one would swallow it — the same collision that made
 * /comments/report resolve as a video called "report" in Phase 4.
 */
check(
    'The chapter route is not swallowed by the book route',
    str_contains($chapterPage['body'], 'Romans 8'),
    'the chapter number was read as a book name'
);

/* And the watch page has to link out, or nothing leads to any of this. */
$watchScripture = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'The watch page shows the passages',
    str_contains($watchScripture['body'], 'Micah 6:8'),
    'references are stored and never shown'
);
check(
    'and links them to everything else on that chapter',
    str_contains($watchScripture['body'], '/scripture/micah/6'),
    'a reference printed as text is a dead end'
);

/* ----------------------------------------------------------------- webhooks
 *
 * Nothing here is delivered. The scheduled jobs are switched off for this run,
 * and pointing a real POST at anything would put a network round trip inside a
 * single-threaded test server. What these checks are for is the half that is
 * ours: that the screen is reachable, that a URL the server must not be pointed
 * at is refused, and — the one that matters — that an ordinary edit really does
 * put something in the queue.
 */
echo "\nWebhooks\n";

$hookScreen = getWithJar($baseUrl . '/admin/webhooks', $jar);

check('Webhooks screen renders', $hookScreen['status'] === 200, "got {$hookScreen['status']}");
check(
    'It appears in the admin navigation',
    str_contains(getWithJar($baseUrl . '/admin', $jar)['body'], '/admin/webhooks'),
    'a screen only somebody who read the source could find'
);
check(
    'It says deliveries are queued rather than immediate',
    str_contains($hookScreen['body'], 'queued, not immediate'),
    'somebody will otherwise report the feature as broken when it is merely waiting for cron'
);
check(
    'and documents how to verify a signature',
    str_contains($hookScreen['body'], 'X-Portal-Signature'),
    'a signature nobody is told how to check is a signature nobody checks'
);

/*
 * The refusals. An admin typed the URL, so this is not about a malicious
 * admin — it is that a delivery goes out FROM this server, so an internal
 * address turns a settings form into a way to reach things that are not
 * meant to be reachable from outside.
 */
foreach ([
    'http://example.com/hook'                  => 'plain http',
    'https://127.0.0.1/hook'                   => 'loopback',
    'https://169.254.169.254/latest/meta-data/' => 'the cloud metadata service',
    'https://user:pass@example.com/hook'       => 'credentials in the URL',
    'file:///etc/passwd'                       => 'a file URL',
] as $badUrl => $what) {
    postWithJar($baseUrl . '/admin/webhooks', [
        '_token' => csrfFrom($hookScreen['body']),
        'action' => 'create',
        'url'    => $badUrl,
    ], $jar);

    check(
        "An endpoint at {$what} is refused",
        (int) $db->value('SELECT COUNT(*) FROM {webhooks} WHERE url = ?', [$badUrl]) === 0,
        'the server can be pointed at ' . $what
    );
}

/* A real one is accepted, and its secret is shown exactly once. */
$created = postWithJar($baseUrl . '/admin/webhooks', [
    '_token'   => csrfFrom($hookScreen['body']),
    'action'   => 'create',
    'url'      => 'https://example.com/hooks/smoke',
    'events'   => ['video.updated'],
    'description' => 'The smoke test',
], $jar);

check('A public https endpoint is accepted', $created['status'] === 302, "got {$created['status']}");

$hookId = (int) $db->value('SELECT id FROM {webhooks} WHERE url = ?', ['https://example.com/hooks/smoke']);
check('and it was stored', $hookId > 0);

$secret = (string) $db->value('SELECT secret FROM {webhooks} WHERE id = ?', [$hookId]);
/*
 * The Location header is an ABSOLUTE url — redirect() runs the path through
 * Config::url() so emailed and cross-origin redirects are never relative — so
 * it is followed as-is. Prefixing $baseUrl to it produces a doubled URL, which
 * fetches nothing and then fails the NEXT check too, because the page it
 * returns has no CSRF token in it.
 */
$afterCreate = getWithJar(
    $created['headers']['location'] ?? ($baseUrl . '/admin/webhooks'),
    $jar
);

check(
    'The signing secret is shown once, on the way back',
    $secret !== '' && str_contains($afterCreate['body'], $secret),
    'an endpoint whose secret was never shown cannot verify anything'
);
check(
    'and not again on the next visit',
    !str_contains(getWithJar($baseUrl . '/admin/webhooks', $jar)['body'], $secret),
    'every visit reprints every secret'
);
check(
    'It only subscribed to what was ticked',
    (string) $db->value('SELECT events FROM {webhooks} WHERE id = ?', [$hookId]) === 'video.updated'
);

/*
 * The check the whole feature rests on: an ordinary edit, through the real
 * form, has to put something in the queue. Everything above would pass just as
 * happily against a set of hooks nothing ever fires — which is exactly what
 * happened to comment reporting in Phase 4.
 */
$queuedBefore = (int) $db->value('SELECT COUNT(*) FROM {webhook_deliveries}');

$videoEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
postWithJar($baseUrl . '/admin/videos', [
    '_token'      => csrfFrom($videoEdit['body']),
    'id'          => (string) $videoRow,
    '_whole_form' => '1',
    'action'      => 'save',
    'title'       => 'A Test Video',
    'slug'        => $videoSlug,
], $jar);

check(
    'Editing a video queues a delivery',
    (int) $db->value('SELECT COUNT(*) FROM {webhook_deliveries}') === $queuedBefore + 1,
    'the events are registered but nothing fires them'
);
check(
    'The queued payload names the event and the video',
    (function () use ($db): bool {
        $payload = (string) $db->value(
            'SELECT payload FROM {webhook_deliveries} ORDER BY id DESC LIMIT 1'
        );
        $decoded = json_decode($payload, true);

        return is_array($decoded)
            && ($decoded['event'] ?? '') === 'video.updated'
            && isset($decoded['data']['id'], $decoded['occurredAt']);
    })(),
    'the body is not the shape a receiver was promised'
);

/*
 * An endpoint subscribed to one event must not receive another. Tested through
 * a real second event rather than by reading the column back, because the
 * column being right says nothing about whether anything consults it.
 */
$beforeUnwanted = (int) $db->value('SELECT COUNT(*) FROM {webhook_deliveries}');
postWithJar($baseUrl . '/admin/webhooks', [
    '_token' => csrfFrom($afterCreate['body']),
    'id'     => (string) $hookId,
    'action' => 'disable',
], $jar);

check(
    'Switching it off stops it being queued for',
    (function () use ($baseUrl, $jar, $db, $videoRow, $videoSlug, $beforeUnwanted): bool {
        $edit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
        postWithJar($baseUrl . '/admin/videos', [
            '_token'      => csrfFrom($edit['body']),
            'id'          => (string) $videoRow,
            '_whole_form' => '1',
            'action'      => 'save',
            'title'       => 'A Test Video',
            'slug'        => $videoSlug,
        ], $jar);

        return (int) $db->value('SELECT COUNT(*) FROM {webhook_deliveries}') === $beforeUnwanted;
    })(),
    'a switched-off endpoint is still collecting work'
);

/* And removing it takes the history with it. */
postWithJar($baseUrl . '/admin/webhooks', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/admin/webhooks', $jar)['body']),
    'id'     => (string) $hookId,
    'action' => 'delete',
], $jar);

check(
    'Removing an endpoint removes its queue too',
    (int) $db->value('SELECT COUNT(*) FROM {webhooks} WHERE id = ?', [$hookId]) === 0
        && (int) $db->value('SELECT COUNT(*) FROM {webhook_deliveries} WHERE webhook_id = ?', [$hookId]) === 0,
    'deliveries survived the endpoint they were addressed to'
);

/*
 * The cron rows. notifications.send has only ever been created by the
 * INSTALLER, so every site installed before Phase 4 has no row for it — and a
 * job with no row is never due, so subscriptions on those sites have been
 * silently sending nothing. This install is fresh, so the check that means
 * something is that every job this version defines has a row at all.
 */
foreach (['sessions.purge', 'videos.sync', 'shares.cleanup', 'notifications.send',
          'webhooks.deliver', 'webhooks.cleanup'] as $job) {
    check(
        "The {$job} job has a row",
        (int) $db->value('SELECT COUNT(*) FROM {cron_jobs} WHERE slug = ?', [$job]) === 1,
        'a job with no row is never due, so it does nothing, silently, forever'
    );
}

/* ------------------------------------------------------------ query monitor
 *
 * Activated here rather than with the other bundled plugins, and turned off
 * again immediately, because while it is on it prints the SQL of every request
 * into the footer of every page — which would give an earlier check asserting
 * that some string is ABSENT a whole new place to find it.
 *
 * The two claims worth proving are that it renders at all, and that it does not
 * render for anybody else. Everything it shows is a description of the database
 * to whoever reads it.
 */
echo "\nQuery monitor\n";

$qmPlugins = getWithJar($baseUrl . '/admin/plugins', $jar);
check('Query Monitor is listed', str_contains($qmPlugins['body'], 'Query Monitor'));

$qmActivated = postWithJar($baseUrl . '/admin/plugins', [
    '_token' => csrfFrom($qmPlugins['body']),
    'slug'   => 'query-monitor',
    'action' => 'activate',
], $jar);

check('Activating it succeeds', $qmActivated['status'] === 302, "got {$qmActivated['status']}");
check(
    'and it stayed active after the redirect',
    (int) $db->value('SELECT is_active FROM {plugins} WHERE slug = ?', ['query-monitor']) === 1,
    'it was deactivated again, which means it threw on load — check the error log'
);

/*
 * addCapability had no consumer anywhere in the codebase until this plugin, so
 * this is the first evidence it works at all — including that activation is
 * where it runs, rather than on every request.
 */
check(
    'It registered its own capability',
    (string) $db->value(
        'SELECT owner_plugin FROM {capabilities} WHERE slug = ?',
        ['view_query_monitor']
    ) === 'query-monitor',
    'the capability the panel checks does not exist, so nobody could ever be granted it'
);

$qmAdmin = getWithJar($baseUrl . '/admin/videos', $jar);

check(
    'The panel renders on an admin screen',
    str_contains($qmAdmin['body'], 'id="query-monitor"'),
    'the admin layout had no hook point at all before this; if this fails, admin_footer is not firing'
);
check(
    'It reports the query count and the timings',
    str_contains($qmAdmin['body'], 'queries · ') && str_contains($qmAdmin['body'], 'ms SQL'),
    'the panel rendered but measured nothing'
);
check(
    'and names the statements the page actually ran',
    str_contains($qmAdmin['body'], 'SELECT'),
    'a query monitor showing no queries is measuring the wrong request'
);

$qmPublic = getWithJar($baseUrl . '/', $jar);
check(
    'It renders on the public site too, for a permitted viewer',
    str_contains($qmPublic['body'], 'id="query-monitor"')
);

/* ------------------------------------------------------ what pages actually cost
 *
 * Using the monitor for the thing it was built for, rather than only checking
 * that it renders. It reports the count in its own headline, so these read it
 * back and put a ceiling on it.
 *
 * The ceilings are generous — this is a guard against a query PER CARD, which
 * is the mistake the batched thumbnail modes, the batched comment counts and
 * the batched transcript lookups all exist to avoid. Every one of those was
 * added because somebody could have written the per-row version, and nothing
 * until now would have noticed if a later change reintroduced it.
 *
 * A failure here is not "the page is slow". It is "this page now scales with
 * how much content the site has", which is the shape that only hurts once
 * somebody has a real library.
 */
$queryCount = static function (string $html): ?int {
    // The monitor's own headline: "12 queries · 3.4ms SQL · …"
    return preg_match('/(\d+)\s+quer(?:y|ies)\s+·/', $html, $m) === 1 ? (int) $m[1] : null;
};

$homeBefore = $queryCount($qmPublic['body']);

check(
    'The monitor reports a count that can be read back',
    $homeBefore !== null,
    'the headline format changed, so everything below it is measuring nothing'
);

/*
 * The claim is not "the homepage uses few queries" — with two videos seeded,
 * a per-card query would still land under any ceiling worth writing, and the
 * check would pass while measuring nothing.
 *
 * The claim worth making is that the count does not GROW with the content.
 * So: measure, add thirty videos, measure again. A listing that costs a query
 * per card moves by thirty; one that batches moves by nearly nothing.
 *
 * This is the property every batched lookup in the codebase exists to protect
 * — thumbnail modes, comment counts, transcript existence, scripture refs —
 * and until now nothing would have noticed a later change reintroducing the
 * per-row version.
 */
$searchBefore = $queryCount(getWithJar($baseUrl . '/search?q=scale', $jar)['body']);

$bulkStart = (int) $db->value('SELECT COALESCE(MAX(id), 0) FROM {videos}');
$now = date('Y-m-d H:i:s');

for ($i = 0; $i < 30; $i++) {
    $db->insert('videos', [
        'provider' => 'bunny', 'provider_id' => 'scale-' . $i,
        'slug' => 'scale-video-' . $i, 'title' => 'Scale Video ' . $i,
        'status' => 'ready', 'is_published' => 1, 'duration' => 100,
        'created_at' => $now, 'updated_at' => $now,
    ]);
}

$homeAfter = $queryCount(getWithJar($baseUrl . '/', $jar)['body']);

check(
    'The homepage cost does not grow with the library',
    $homeBefore !== null && $homeAfter !== null && ($homeAfter - $homeBefore) <= 5,
    sprintf(
        '%s queries with 2 videos, %s with 32 — a listing that costs a query per card',
        var_export($homeBefore, true),
        var_export($homeAfter, true)
    )
);

$searchAfter = $queryCount(getWithJar($baseUrl . '/search?q=scale', $jar)['body']);

/*
 * Measured the same way as the homepage rather than against a ceiling. An
 * earlier version compared search to a fixed budget and passed under a
 * deliberately reintroduced per-card query — the number grew from 23 to 34 and
 * the ceiling was 39, so the check watched it happen and said nothing.
 */
check(
    'Nor does search',
    $searchBefore !== null && $searchAfter !== null && ($searchAfter - $searchBefore) <= 5,
    sprintf(
        '%s queries over 0 results, %s over 30 — a result list that costs a query per row',
        var_export($searchBefore, true),
        var_export($searchAfter, true)
    )
);

echo sprintf(
    "    (homepage %s -> %s, search %s -> %s, for 2 -> 32 videos)\n",
    var_export($homeBefore, true),
    var_export($homeAfter, true),
    var_export($searchBefore, true),
    var_export($searchAfter, true)
);

$db->execute('DELETE FROM {videos} WHERE id > ?', [$bulkStart]);

$qmWatch = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
$watchQueries = $queryCount($qmWatch['body']);

/*
 * A watch page is a fixed number of panels rather than a list, so a ceiling is
 * the right shape here — but it is deliberately not much above the real number,
 * because the failure this catches is a new panel that queries per row.
 */
check(
    'A watch page stays within its budget',
    $watchQueries !== null && $watchQueries < 60,
    'watch page used ' . var_export($watchQueries, true) . ' queries'
);

/*
 * The check that matters. A stranger must see none of this — not the SQL, not
 * the counts, not the bar. Rendered by the same hook on the same page, so the
 * only thing standing between the two responses is the capability test.
 */
$qmStranger = get($baseUrl . '/');
check(
    'A signed-out visitor sees no panel',
    !str_contains($qmStranger['body'], 'id="query-monitor"'),
    'the query monitor is describing the database to the public'
);
check(
    'and no SQL of any kind',
    !str_contains($qmStranger['body'], 'SELECT '),
    'statements leaked into a page a stranger can load'
);

/* Off again, and the panel goes with it. */
postWithJar($baseUrl . '/admin/plugins', [
    '_token' => csrfFrom($qmAdmin['body']),
    'slug'   => 'query-monitor',
    'action' => 'deactivate',
], $jar);

check(
    'Deactivating removes the panel',
    !str_contains(getWithJar($baseUrl . '/admin/videos', $jar)['body'], 'id="query-monitor"'),
    'a deactivated plugin is still rendering'
);

/* ------------------------------------------------------- schema visibility
 *
 * The upgrade path is "git pull, and the next request migrates". Until this
 * existed, a migration that failed on the host was caught, logged, and
 * invisible — so the site went on serving a half-applied schema, which does
 * not look broken, it looks like features that mysteriously do not work.
 *
 * The check that matters is the second one: the banner has to APPEAR. A
 * warning that is only ever absent is indistinguishable from one that is
 * broken.
 */
echo "\nSchema visibility\n";

$healthyDash = getWithJar($baseUrl . '/admin', $jar);

check(
    'A healthy install shows no schema warning',
    !str_contains($healthyDash['body'], 'The database is not up to date'),
    'a banner that is always there is a banner nobody reads'
);

/*
 * A missing migration is RE-APPLIED rather than reported, and that is the
 * point of the upgrade path.
 *
 * Deleting the newest schema_version row is what a half-applied upgrade leaves
 * behind — and the next request notices, re-runs it, and moves on. So the
 * banner is deliberately not what this proves; the self-healing is. An earlier
 * version of these checks looked for the banner here and failed against
 * entirely correct behaviour: it was chasing a window that closes before the
 * page renders.
 *
 * The pending-migration REPORTING is pinned by SchemaHealthTest, which can
 * observe that state without a request in the way.
 */
$newestMigration = (string) $db->value('SELECT version FROM {schema_version} ORDER BY version DESC LIMIT 1');
$db->execute('DELETE FROM {schema_version} WHERE version = ?', [$newestMigration]);

$recoveredDash = getWithJar($baseUrl . '/admin', $jar);

check(
    'A missing migration is re-applied on the next request',
    (string) $db->value(
        'SELECT version FROM {schema_version} WHERE version = ?',
        [$newestMigration]
    ) === $newestMigration,
    'the upgrade path is "git pull and the next request migrates", and it did not'
);
check(
    'and the site does not complain about a problem it already fixed',
    !str_contains($recoveredDash['body'], 'The database is not up to date'),
    'a warning that outlives its problem trains people to ignore it'
);

/* A recorded failure is surfaced too, not only a missing migration. */
$db->execute(
    'INSERT INTO {settings} (`key`, `value`, updated_at) VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
    ['last_migration_error', 'SQLSTATE[42000]: Row size too large']
);

$failedDash = getWithJar($baseUrl . '/admin', $jar);

check(
    'A recorded migration failure is shown',
    str_contains($failedDash['body'], 'The database is not up to date')
        && str_contains($failedDash['body'], 'Row size too large'),
    'the error went to a log nobody on a shared host can read'
);
check(
    'and it says a backup is the way back',
    str_contains($failedDash['body'], 'restoring a backup'),
    'there are no down-migrations and nobody is told'
);

$db->execute('DELETE FROM {settings} WHERE `key` = ?', ['last_migration_error']);

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
