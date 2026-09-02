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

/**
 * How long any single request may take.
 *
 * Generous on purpose. The sign-in and change-password endpoints hash a
 * password, which is CPU-bound BY DESIGN, and PHP's built-in server is
 * single-threaded — so on a machine doing anything else, one of those requests
 * legitimately takes tens of seconds. At the old 15 seconds curl gave up first
 * and returned status 0, and the run then reported things like "the refusal was
 * cosmetic": a confident claim about a security property, produced by a busy
 * laptop.
 *
 * A timeout this long cannot mask a hang either, because a genuinely hung
 * request still fails — it just takes a minute to say so, which is a fair price
 * for never again diagnosing a defect that was really a busy CPU.
 */
const SMOKE_TIMEOUT = 90;

/**
 * Transport failures, counted separately from assertion failures.
 *
 * A request that never completed is not evidence about the application, and
 * the run says so at the end rather than letting the reader work it out from
 * a page of unrelated-looking failures.
 */
$transportFailures = [];

/**
 * True while the readiness probe is still waiting for the server to answer.
 *
 * The probe IS a loop of failing requests — that is how it knows the server is
 * not up yet — so recording them made a slow start indistinguishable from a
 * request lost mid-run. A run where every one of 845 checks passed still
 * printed "THIS RUN IS NOT EVIDENCE ABOUT THE APPLICATION" because the server
 * had taken three-quarters of a second to come up.
 *
 * That is worse than it sounds. The banner exists to stop somebody believing a
 * page of imaginary defects; a banner that also fires on an ordinary cold start
 * is one people learn to scroll past, and the day it is right is the day it is
 * ignored. Expected failures are not evidence of anything and are not counted.
 */
$startingUp = true;

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
 * Record a request that never got an answer, and say what curl said.
 *
 * Status 0 means the response never arrived — a timeout, a refused connection,
 * a killed server. Every check downstream of one is then measuring the wreckage
 * rather than the application: a lost session turns every later admin request
 * into a 302, and forty checks report forty different imaginary defects.
 *
 * Noted at the point it happens, so the log names the cause once rather than
 * leaving it to be inferred from the symptoms.
 */
function noteTransportFailure(string $url, string $error): void
{
    global $transportFailures, $startingUp;

    // The readiness probe's own retries are how it works, not a fault.
    if ($startingUp) {
        return;
    }

    $transportFailures[] = $url . ' — ' . ($error !== '' ? $error : 'no response');
    echo "  !!    NO RESPONSE from {$url} — {$error}\n";
}

/**
 * Forget that anybody has tried to sign in recently.
 *
 * The application throttles sign-in to ten attempts per IP per fifteen
 * minutes, which is a real protection working exactly as intended. This script
 * signs in as a dozen different people from 127.0.0.1 inside a few minutes, so
 * without this the LAST sections to be written fail on the throttle rather
 * than on anything they test.
 *
 * That failure is worse than an ordinary one because of how it reads: the
 * first check in a section reports "an unapproved person cannot sign in",
 * which is a confident statement about a feature that is in fact fine. Called
 * before each sign-in late in the run, so a section's result depends on the
 * section and not on how many sections precede it.
 */
function clearLoginThrottle(Db $db): void
{
    /*
     * Every row, not the login ones.
     *
     * RateLimit stores hash('sha256', $bucket) rather than the bucket itself,
     * deliberately — a bucket built from an email address would otherwise put
     * that address in a table nobody thinks of as holding personal data. The
     * consequence is that the keys are unrecognisable, and the obvious version
     * of this function — DELETE ... WHERE bucket LIKE 'login:%' — matched
     * nothing at all. A DELETE that matches nothing succeeds, so it looked like
     * it worked and the throttle stayed exactly where it was.
     *
     * This is a scratch database that exists for one run, and no check in this
     * file depends on a throttle surviving between sections.
     */
    $db->execute('DELETE FROM {rate_limits}');
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
function getWithJar(string $url, string $jar, array $headers = []): array
{
    return withJar($url, $jar, null, $headers);
}

/**
 * @param array<string, string|list<string>> $fields
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function postWithJar(string $url, array $fields, string $jar, array $headers = []): array
{
    // http_build_query turns list values into videos[0]=..., which PHP parses
    // back into the array the form would have sent.
    return withJar($url, $jar, http_build_query($fields), $headers);
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
        CURLOPT_TIMEOUT        => SMOKE_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => (string) json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status === 0) {
        noteTransportFailure((string) $url, curl_error($ch));
    }
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
        CURLOPT_TIMEOUT        => SMOKE_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status === 0) {
        noteTransportFailure((string) $url, curl_error($ch));
    }
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status'  => $status,
        'body'    => substr($raw, $headerSize),
        'headers' => parseHeaders(substr($raw, 0, $headerSize)),
    ];
}

/** @return array{status: int, body: string, headers: array<string, string>} */
function withJar(string $url, string $jar, ?string $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => SMOKE_TIMEOUT,
        // Only what a caller asks for. A Referer matters where the handler
        // redirects back to where the person came from, which curl does not
        // send on its own.
        CURLOPT_HTTPHEADER     => $headers,
        // Deliberately not following: the status code IS the assertion.
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status === 0) {
        noteTransportFailure((string) $url, curl_error($ch));
    }
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
        CURLOPT_TIMEOUT        => SMOKE_TIMEOUT,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status === 0) {
        noteTransportFailure((string) $url, curl_error($ch));
    }
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
        CURLOPT_TIMEOUT        => SMOKE_TIMEOUT,
    ]);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status === 0) {
        noteTransportFailure((string) $url, curl_error($ch));
    }
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
    $strays = [PORTAL_PLUGINS . '/smoketest', PORTAL_PLUGINS . '/evil', PORTAL_PLUGINS . '/smokecron'];
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

    /*
     * The server log survives a failing run.
     *
     * It holds the stack trace behind every 500, and deleting it unconditionally
     * meant that a check reporting "got 500" gave no way to find out WHY without
     * running the whole thing again with the deletion commented out. That cost
     * two diagnoses in one afternoon.
     *
     * On a clean run it goes, because nothing in it is worth keeping.
     */
    global $failed;

    if (isset($serverLog) && is_file($serverLog)) {
        if ((int) $failed > 0) {
            echo "\nServer log kept for the failures above:\n  {$serverLog}\n";
        } else {
            @unlink($serverLog);
        }
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
foreach ([PORTAL_PLUGINS . '/smoketest', PORTAL_PLUGINS . '/evil', PORTAL_PLUGINS . '/smokecron'] as $stale) {
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

/*
 * Wait until it ANSWERS, not until the port is open.
 *
 * The socket probe this replaced proved only that something had bound the
 * port, which PHP's built-in server does before it can serve anything. On a
 * cold checkout — a release worktree, where none of the files are in the OS
 * cache yet — the first real request arrived too early and curl reported
 * status 0. That surfaced as "GET / returns 200 — got 0" at the top of a
 * release verification: four failures that look like a broken homepage and are
 * nothing of the kind.
 *
 * The probe is a real request, so it also absorbs the cost of the first one:
 * migrations run on first hit, and making a CHECK pay for that is how a slow
 * upgrade turns into a mysterious timeout.
 */
$ready = false;
for ($attempt = 0; $attempt < 60; $attempt++) {
    usleep(250_000);
    if (get($baseUrl . '/')['status'] > 0) {
        $ready = true;
        break;
    }
}

if (!$ready) {
    fwrite(STDERR, "The server never answered a request. See {$serverLog}\n");
    exit(1);
}

// From here on, a request that gets no answer is a real finding.
$startingUp = false;

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

/* --------------------------------------------------------- share passphrase
 *
 * A third, orthogonal lock: something you KNOW, on top of however the link
 * already identifies you. The property that matters is that adding it did not
 * open an enumeration hole — a wrong passphrase has to be indistinguishable
 * from an id that was never real.
 */
$lockedShare = $shares->create($videoRow, 'locked@smoke.test', [
    'accessMode' => 'gate',
    'passphrase' => 'the blue door',
]);

$lockedPage = get($baseUrl . '/s/' . $lockedShare->id);
check(
    'A passphrase-protected link asks for the passphrase',
    $lockedPage['status'] === 200 && str_contains($lockedPage['body'], 'name="passphrase"'),
    "got {$lockedPage['status']}"
);
check(
    'and asks for it BEFORE the email gate',
    !str_contains($lockedPage['body'], 'name="email"'),
    'the outer lock is meant to be the passphrase; reaching the gate first skips it'
);
check(
    'and the prompt names neither the video nor the recipient',
    !str_contains($lockedPage['body'], 'locked@smoke.test')
        && !str_contains($lockedPage['body'], 'A Test Video'),
    'opening a misdirected link must not reveal what it was for'
);
check(
    'and the hash never reaches the page',
    !str_contains($lockedPage['body'], '$2y$') && !str_contains($lockedPage['body'], '$argon'),
    'the one column that must never leave the server'
);

/*
 * The anti-enumeration property, which is the whole reason a wrong passphrase
 * gives no feedback. Compared byte for byte against the page an invented id
 * produces.
 */
$wrongPass = post($baseUrl . '/s/' . $lockedShare->id . '/unlock', [

    'passphrase' => 'not the passphrase',
]);
check(
    'A wrong passphrase is refused',
    $wrongPass['status'] === 404,
    "got {$wrongPass['status']}"
);
check(
    'and is byte-identical to a link that never existed',
    $wrongPass['body'] === $unknownPage['body'],
    'saying "wrong passphrase" would confirm the id is real'
);

/* The right one opens it, and the browser is not asked again. */
$rightPass = postWithJar($baseUrl . '/s/' . $lockedShare->id . '/unlock', [
    'passphrase' => 'the blue door',
], $lockJar = sys_get_temp_dir() . '/portal-smoke-lock-' . getmypid() . '.txt');
check(
    'The right passphrase is accepted',
    $rightPass['status'] === 302,
    "got {$rightPass['status']}"
);

$afterUnlock = getWithJar($baseUrl . '/s/' . $lockedShare->id, $lockJar);
check(
    'and the link then behaves as it normally would',
    str_contains($afterUnlock['body'], 'name="email"'),
    'past the passphrase, a gate-mode link should ask for the address'
);

/*
 * The unlock is scoped to ONE link. A cookie that opened every protected share
 * would make the first passphrase somebody is given a master key.
 */
$otherLocked = $shares->create($videoRow, 'other@smoke.test', [
    'accessMode' => 'gate',
    'passphrase' => 'a different one',
]);
check(
    'Unlocking one link does not unlock another',
    str_contains(getWithJar($baseUrl . '/s/' . $otherLocked->id, $lockJar)['body'], 'name="passphrase"'),
    'the unlock cookie is not scoped to its own link'
);

/* A link with no passphrase cannot be "unlocked" into anything. */
$noPassUnlock = post($baseUrl . '/s/' . $gateShare->id . '/unlock', [
    'passphrase' => 'anything at all',
]);
check(
    'Unlocking a link that has no passphrase is refused',
    $noPassUnlock['status'] === 404,
    "got {$noPassUnlock['status']}"
);

@unlink($lockJar);

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
check('It offers the download setting', str_contains($videoEdit['body'], 'name="download_mode"'));
check(
    'and says which way that inherits, which is a four-level chain',
    str_contains($videoEdit['body'], 'Inherit — currently blocked'),
    'nothing has been turned on, so the honest answer is blocked'
);

$categoryRow = (int) $db->value('SELECT id FROM {categories} LIMIT 1');

$categoryEdit = getWithJar($baseUrl . '/admin/categories/' . $categoryRow, $jar);
check('Category edit screen renders', $categoryEdit['status'] === 200, "got {$categoryEdit['status']}");
check('It offers the thumbnail setting', str_contains($categoryEdit['body'], 'name="thumbnail_mode"'));
check('It offers the download setting', str_contains($categoryEdit['body'], 'name="download_mode"'));

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

/* ------------------------------------------------------ plugin cron jobs
 *
 * `PluginContext::addCronJob()` has existed since Phase 1. It put the handler
 * in a map on PluginManager, and NOTHING ever wrote a {cron_jobs} row for it —
 * while runDue() selects FROM that table. So a plugin's scheduled job was
 * registered, resolvable by slug, and never once due. No plugin cron job in
 * this product has ever run.
 *
 * The same defect shipped for `notifications.send` in Phase 4 and was recorded
 * then as "a job with no row is never due, so it does nothing, silently,
 * forever". The core half was fixed; the plugin half was not.
 *
 * Driven over HTTP because the claim is about a real request: the row has to
 * appear, the job has to become due, and the handler has to actually run.
 */
echo "\nPlugin cron jobs\n";

$cronPluginDir = PORTAL_PLUGINS . '/smokecron';
@mkdir($cronPluginDir, 0775, true);
file_put_contents($cronPluginDir . '/plugin.php', <<<'PHP'
<?php
/**
 * Plugin Name: Smoke Cron
 * Slug: smokecron
 * Version: 1.0.0
 * Description: Registers a scheduled job, so the run can be observed.
 */

/** @var \Portal\Plugins\PluginContext $plugin */
$plugin->addCronJob('tick', 60, static function (): string {
    return 'the plugin job ran';
});
PHP);

$cronPluginsScreen = getWithJar($baseUrl . '/admin/plugins', $jar);
postWithJar($baseUrl . '/admin/plugins', [
    '_token' => csrfFrom($cronPluginsScreen['body']),
    'slug'   => 'smokecron',
    'action' => 'activate',
], $jar);

check(
    'A plugin declaring a cron job activates',
    (int) ($db->value('SELECT is_active FROM {plugins} WHERE slug = ?', ['smokecron']) ?? -1) === 1,
    'it threw on load — check the server log'
);

/*
 * The jobs this script disabled at seed time stay disabled; only the plugin's
 * is enabled here. videos.sync calls the video provider over HTTPS and PHP's
 * built-in server is single-threaded, so letting it run freezes the whole run.
 */
$cronRun = get($baseUrl . '/cron?key=' . urlencode((string) $written['cron_secret']));

check('The cron endpoint runs', $cronRun['status'] === 200, "got {$cronRun['status']}");

$pluginJobSlug = (string) ($db->value(
    'SELECT slug FROM {cron_jobs} WHERE slug LIKE ?',
    ['%smokecron%']
) ?? '');

check(
    'A plugin cron job gets a row, so it can become due',
    $pluginJobSlug !== '',
    'registered in memory and invisible to the runner — it could never fire'
);
check(
    'and the row is enabled',
    (int) ($db->value('SELECT is_enabled FROM {cron_jobs} WHERE slug = ?', [$pluginJobSlug]) ?? 0) === 1,
    'a row that exists and is off is the same as no row'
);
check(
    'and the handler actually ran',
    (string) ($db->value('SELECT last_message FROM {cron_jobs} WHERE slug = ?', [$pluginJobSlug]) ?? '')
        === 'the plugin job ran',
    'the row appeared but nothing executed — the slug the runner looks up does not match the one it stored'
);

/*
 * An admin switching a job off must stay switched off. ensureJob() runs on
 * every tick, so ON DUPLICATE KEY UPDATE would turn it back on every few
 * minutes, with the screen showing it enabled and nothing saying why.
 */
$db->execute('UPDATE {cron_jobs} SET is_enabled = 0 WHERE slug = ?', [$pluginJobSlug]);
get($baseUrl . '/cron?key=' . urlencode((string) $written['cron_secret']));

check(
    'Disabling a plugin job sticks across the next tick',
    (int) ($db->value('SELECT is_enabled FROM {cron_jobs} WHERE slug = ?', [$pluginJobSlug]) ?? 1) === 0,
    'the registration re-enabled a job an admin had turned off'
);

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
check(
    'It offers the download capability',
    str_contains($permsScreen['body'], 'download_content'),
    'this is the WHO half of a download, so a capability nobody can grant is half a feature'
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
check(
    'It offers the download setting',
    str_contains($seriesEdit['body'], 'name="download_mode"'),
    'the series is the level people actually reach for — a course is the unit somebody wants offline'
);

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

foreach (['watermark', 'geo', 'comments', 'ratings', 'reactions', 'playback', 'whats-new', 'popular'] as $slug) {
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
 * And appear where a person can actually see them.
 *
 * Plugin screens are children of the Plugins section, and only the section you
 * are in shows its children — so "the link is in the HTML" and "somebody can
 * find the link" stopped being the same claim the moment the menu grouped.
 * Opening /admin/plugins has to be the thing that reveals them, or the check
 * above is measuring a link that is permanently display:none.
 */
$pluginsSection = getWithJar($baseUrl . '/admin/plugins', $jar);
check(
    'and the Plugins screen is the one that opens their section',
    preg_match('~<a [^>]*href="/admin/plugins"[^>]*aria-current="page"~', $pluginsSection['body']) === 1
        && str_contains($pluginsSection['body'], '/admin/watermark'),
    'the plugin pages are in the markup but nothing a person clicks unfolds them'
);

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

/* ---------------------------------------------------------------- reactions
 *
 * The last item on the plan's Phase 4 line — "ratings/reactions" — of which
 * only ratings shipped.
 *
 * The integration tests pin the unique key, which is where the difference
 * between the two actually lives. What only a real request can tell you is
 * whether the widget reaches the page at all: a plugin that fatals on load is
 * caught, logged, and silently deactivated, which looks exactly like a plugin
 * that works and has nothing to say.
 */
echo "\nReactions\n";

$reactPage = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);

check(
    'The reaction row appears under the video',
    str_contains($reactPage['body'], 'id="reactions"'),
    'the plugin activated and its hook never fired'
);
check(
    'and offers the buttons in words, not only pictures',
    str_contains($reactPage['body'], 'Amen') && str_contains($reactPage['body'], 'This moved me'),
    'an emoji with no label is unreadable to a screen reader'
);

$reactToken = csrfFrom($reactPage['body']);

postWithJar($baseUrl . '/reactions/' . $videoRow, ['_token' => $reactToken, 'kind' => 'amen'], $jar);
postWithJar($baseUrl . '/reactions/' . $videoRow, ['_token' => $reactToken, 'kind' => 'thankful'], $jar);

/*
 * THE claim, and the whole reason this is not a second rating system: a rating
 * replaces, a reaction accumulates. Somebody may mean both things at once.
 */
check(
    'One person can leave several kinds at once',
    (int) $db->value('SELECT COUNT(*) FROM {reactions} WHERE video_id = ?', [$videoRow]) === 2,
    'the second reaction replaced the first — that is a rating, not a reaction'
);

$afterReact = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'and the page shows them as pressed',
    substr_count($afterReact['body'], 'aria-pressed="true"') === 2,
    'nothing tells a person which ones are already theirs'
);

/* Pressing the same button again takes it back — the only way to undo one. */
postWithJar($baseUrl . '/reactions/' . $videoRow, [
    '_token' => csrfFrom($afterReact['body']),
    'kind'   => 'amen',
], $jar);

check(
    'Pressing the same one again removes it',
    (int) $db->value('SELECT COUNT(*) FROM {reactions} WHERE video_id = ? AND kind = ?', [$videoRow, 'amen']) === 0,
    'a reaction left by mistake could never be taken back'
);
check(
    'and leaves the others alone',
    (int) $db->value('SELECT COUNT(*) FROM {reactions} WHERE video_id = ?', [$videoRow]) === 1,
    'undoing one reaction cleared the lot'
);

$noCsrfReact = postWithJar($baseUrl . '/reactions/' . $videoRow, ['kind' => 'amen'], $jar);
check('Reacting without a CSRF token is refused', $noCsrfReact['status'] === 419, "got {$noCsrfReact['status']}");

/* A made-up kind is ignored rather than stored. */
postWithJar($baseUrl . '/reactions/' . $videoRow, [
    '_token' => csrfFrom(getWithJar($baseUrl . '/watch/' . $videoSlug, $jar)['body']),
    'kind'   => 'shrug',
], $jar);

check(
    'An unrecognised reaction is ignored',
    (int) $db->value('SELECT COUNT(*) FROM {reactions} WHERE kind = ?', ['shrug']) === 0,
    'a hand-made request wrote a kind no button offers'
);

/* A signed-out visitor reads the counts and is given nothing to press. */
$reactAnon = get($baseUrl . '/watch/' . $videoSlug);
check(
    'A signed-out visitor is not offered the buttons',
    !str_contains($reactAnon['body'], 'action="/reactions/'),
    'an anonymous reaction is one anybody can leave as often as they clear a cookie'
);

$db->execute('DELETE FROM {reactions} WHERE video_id = ?', [$videoRow]);

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

/*
 * Sent the way the real form sends it: every checkbox on the screen, with the
 * one being switched off simply absent. `_whole_form` is what licenses the
 * handler to read absence as "unticked" — without it these fields are left
 * alone, which is what makes a partial save safe.
 */
postWithJar($baseUrl . '/admin/settings', [
    '_token'      => csrfFrom($settingsForSubs['body']),
    '_whole_form' => '1',
    'site_name'   => 'Smoke Portal',
    'timezone'    => 'UTC',
    // On by default, so a whole-form save has to carry it or it goes off.
    'allow_access_requests' => '1',
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
    '_whole_form'           => '1',
    'site_name'             => 'Smoke Portal',
    'timezone'              => 'UTC',
    'subscriptions_enabled' => '1',
    'allow_access_requests' => '1',
], $jar);

/*
 * And the reason `_whole_form` exists at all.
 *
 * A POST that changes one text field must not switch off a policy it never
 * mentioned. This went unnoticed for as long as every checkbox on the screen
 * defaulted to OFF — writing '0' for an absent box matched the default, so the
 * damage was invisible. The first setting that defaulted to ON turned every
 * partial save into a silent disable, which is the same defect Phase 4 found
 * on the video form.
 */
postWithJar($baseUrl . '/admin/settings', [
    '_token'    => csrfFrom($settingsForSubs['body']),
    'site_name' => 'Smoke Portal Renamed',
    'timezone'  => 'UTC',
], $jar);

check(
    'A partial settings save leaves checkboxes it did not mention alone',
    (string) $db->value('SELECT `value` FROM {settings} WHERE `key` = ?', ['subscriptions_enabled']) === '1'
        && (string) $db->value('SELECT `value` FROM {settings} WHERE `key` = ?', ['allow_access_requests']) === '1',
    'saving the site name turned off a setting nobody touched'
);
check(
    'and still saves what it did mention',
    (string) $db->value('SELECT `value` FROM {settings} WHERE `key` = ?', ['site_name']) === 'Smoke Portal Renamed',
    'ignoring absent fields must not mean ignoring present ones'
);

/* Put the name back for anything downstream that reads it. */
postWithJar($baseUrl . '/admin/settings', [
    '_token'    => csrfFrom($settingsForSubs['body']),
    'site_name' => 'Smoke Portal',
    'timezone'  => 'UTC',
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

/* ---------------------------------------------------------------- playback
 *
 * Skip-to-the-sermon and up-next. The policy tests pin where the button goes;
 * only a real request can tell you whether the plugin loads and its hook fires
 * — a plugin that throws on load is caught, logged and silently deactivated,
 * which looks exactly like one that works and has nothing to say.
 *
 * Placed here because it needs the chapters the section above just created.
 */
echo "\nPlayback\n";

/* The stored chapters are Welcome / The reading… / Questions, so the default
   titles match nothing. Renamed to what a service recording really looks like. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($chapterEditAfter['body']),
    'id'       => (string) $videoRow,
    'action'   => 'chapters',
    'chapters' => "0:00 Welcome\n2:15 Notices\n15:00 Sermon: Romans 8",
], $jar);

$watchSkip = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);

check(
    'The skip button appears when a chapter matches',
    str_contains($watchSkip['body'], 'data-pb-seek="900"'),
    'the plugin activated and its hook never fired'
);
check(
    'and it names where it goes',
    str_contains($watchSkip['body'], 'Skip to Sermon: Romans 8'),
    '"skip intro" is inaccurate and rude about the part somebody led'
);
check(
    'and it works without JavaScript',
    str_contains($watchSkip['body'], 'href="?t=900"'),
    'a button that only exists for people whose scripts loaded'
);
check(
    'and the script that upgrades it is served',
    get($baseUrl . '/plugin-asset/playback/playback.js')['status'] === 200,
    'the widget is on the page and its behaviour is a 404'
);

/*
 * A chapter at 0:00 is never the target, whatever it is called — a button that
 * seeks to the start of what you are already watching reads as broken.
 */
postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($chapterEditAfter['body']),
    'id'       => (string) $videoRow,
    'action'   => 'chapters',
    'chapters' => "0:00 Sermon\n10:00 Questions",
], $jar);

check(
    'A chapter at the start is not offered as a skip',
    !str_contains(getWithJar($baseUrl . '/watch/' . $videoSlug, $jar)['body'], 'data-pb-seek'),
    'the button would jump somebody to 0:00 and appear to do nothing'
);

/*
 * Up next, on a series made for the purpose.
 *
 * The first version borrowed $videoRow and the run's existing series, and got
 * two things wrong at once. It restored series_id to NULL rather than to what
 * it had been, which broke a podcast-feed check three hundred lines later — a
 * failure with no visible connection to the thing that caused it. And it
 * asserted on the episode's TITLE, which appears elsewhere on the page, so the
 * check passed while the card itself was missing.
 *
 * Two purpose-made videos in a purpose-made series: nothing shared to restore,
 * and the assertions can name the card rather than a string that happens to be
 * near it.
 */
$pbNow = date('Y-m-d H:i:s');
$pbSeries = (int) $db->insert('series', [
    'slug' => 'pb-series', 'title' => 'Playback Series',
    'is_published' => 1, 'created_at' => $pbNow, 'updated_at' => $pbNow,
]);

$pbFirst = (int) $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-pb-1',
    'slug' => 'pb-episode-one', 'title' => 'Playback Episode One',
    'status' => 'ready', 'is_published' => 1, 'duration' => 60,
    'series_id' => $pbSeries, 'series_position' => 0,
    'created_at' => $pbNow, 'updated_at' => $pbNow,
]);
$pbNext = (int) $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-pb-2',
    'slug' => 'pb-episode-two', 'title' => 'Playback Episode Two',
    'status' => 'ready', 'is_published' => 1, 'duration' => 60,
    'series_id' => $pbSeries, 'series_position' => 1,
    'created_at' => $pbNow, 'updated_at' => $pbNow,
]);

$watchNext = getWithJar($baseUrl . '/watch/pb-episode-one', $jar);

check(
    'Up next offers the following episode',
    str_contains($watchNext['body'], 'class="pb-next-title"')
        && str_contains($watchNext['body'], '/watch/pb-episode-two'),
    'a series that stops at the end of every episode is a series nobody finishes'
);
check(
    'and it is hidden until the video ends',
    preg_match('~<div class="pb-next"[^>]*\shidden~', $watchNext['body']) === 1,
    'a permanent "up next" card under a video somebody just started'
);
check(
    'and the last episode offers nothing',
    !str_contains(getWithJar($baseUrl . '/watch/pb-episode-two', $jar)['body'], 'class="pb-next-title"'),
    'the end of a series pointed somewhere'
);

/*
 * THE visibility property. forSeries() does not filter member-only or the
 * schedule window, so a plugin using it would name a members-only episode to a
 * signed-out visitor. This goes through the ordinary listing query instead.
 */
$db->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$pbNext]);

check(
    'A members-only next episode is not named to a stranger',
    !str_contains(get($baseUrl . '/watch/pb-episode-one')['body'], 'pb-episode-two'),
    'up next became a second way to see what the listing hides'
);

$db->execute('DELETE FROM {videos} WHERE id IN (?, ?)', [$pbFirst, $pbNext]);
$db->execute('DELETE FROM {series} WHERE id = ?', [$pbSeries]);

/* The settings screen, and that it is linked. */
$pbAdmin = getWithJar($baseUrl . '/admin/playback', $jar);
check('The playback settings page renders', $pbAdmin['status'] === 200, "got {$pbAdmin['status']}");
check(
    'and it says both work without JavaScript',
    str_contains($pbAdmin['body'], 'work without it'),
    'a feature that needs scripting should say what happens without it'
);

/* Switching one off leaves the other alone — the reason they are one plugin. */
postWithJar($baseUrl . '/admin/playback', [
    '_token'       => csrfFrom($pbAdmin['body']),
    'next_enabled' => '1',
    'skip_titles'  => 'Sermon',
    'next_countdown' => '10',
], $jar);

postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($chapterEditAfter['body']),
    'id'       => (string) $videoRow,
    'action'   => 'chapters',
    'chapters' => "0:00 Welcome\n15:00 Sermon",
], $jar);

check(
    'Turning skip off removes the button',
    !str_contains(getWithJar($baseUrl . '/watch/' . $videoSlug, $jar)['body'], 'data-pb-seek'),
    'the switch is decorative'
);

/* Back on, so nothing downstream sees a half-configured plugin. */
postWithJar($baseUrl . '/admin/playback', [
    '_token'         => csrfFrom(getWithJar($baseUrl . '/admin/playback', $jar)['body']),
    'skip_enabled'   => '1',
    'next_enabled'   => '1',
    'skip_titles'    => 'Sermon, Message, Teaching, Talk',
    'next_countdown' => '10',
], $jar);

/* Restore the chapter fixture the checks below expect. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($chapterEditAfter['body']),
    'id'       => (string) $videoRow,
    'action'   => 'chapters',
    'chapters' => "Chapters:\n0:00 Welcome\n2:15 The reading from Psalm 1:1\n14:30 Questions",
], $jar);

echo "\nChapters, continued\n";

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
 * What the MP4 columns on the video row are for, driven end to end.
 *
 * This install has the real bunny.net provider with placeholder credentials,
 * so every lookup fails — which is exactly the environment that makes these
 * checks honest. A cached answer has to work with the API unreachable; that is
 * the whole point of caching it, and it is also the only way to tell a cached
 * answer apart from a fresh one here.
 *
 * The heights are chosen so the two paths cannot be confused: 480 is not what
 * the could-not-ask fallback signs. That signs the configured cap, 720. So a
 * redirect to play_480p.mp4 could only have come from the row.
 */
$db->execute(
    "UPDATE {videos} SET has_mp4 = 1, mp4_heights = '360,480', mp4_checked_at = NOW() WHERE id = ?",
    [$videoRow]
);
$mediaCached = get($baseUrl . '/media/' . $videoSlug . '.mp4');

check(
    'A cached rendition list is used without asking the provider',
    str_contains($mediaCached['headers']['location'] ?? '', '/play_480p.mp4'),
    'went to ' . ($mediaCached['headers']['location'] ?? 'nowhere')
        . ' — 720p means the cache was ignored and the unreachable-API fallback ran'
);

/* A recorded "no" is a specific, actionable answer rather than a blank 404. */
$db->execute(
    "UPDATE {videos} SET has_mp4 = 0, mp4_heights = '', mp4_checked_at = NOW() WHERE id = ?",
    [$videoRow]
);
$mediaNoFallback = get($baseUrl . '/media/' . $videoSlug . '.mp4');

check(
    'A video the provider said has no MP4 is refused',
    $mediaNoFallback['status'] === 404,
    "got {$mediaNoFallback['status']}"
);
check(
    'And the refusal names the setting and says it is not retroactive',
    str_contains($mediaNoFallback['body'], 'MP4 Fallback')
        && str_contains($mediaNoFallback['body'], 'retroactive'),
    'the four causes of a missing MP4 need four different fixes, so the message has to say which'
);

/*
 * And the rule the whole cache rests on: an unasked row must not be READ as a
 * no, and a provider that could not be reached must not leave one behind.
 *
 * Every row on every upgrading install looks exactly like this one.
 */
$db->execute(
    'UPDATE {videos} SET has_mp4 = 0, mp4_heights = ?, mp4_checked_at = NULL WHERE id = ?',
    ['', $videoRow]
);
$mediaUnasked = get($baseUrl . '/media/' . $videoSlug . '.mp4');

check(
    'An unasked video is not refused on the strength of a column default',
    $mediaUnasked['status'] === 302,
    "got {$mediaUnasked['status']} — has_mp4 = 0 was read as an answer nobody gave"
);
check(
    'A provider that could not be reached records no verdict',
    $db->value('SELECT mp4_checked_at FROM {videos} WHERE id = ?', [$videoRow]) === null,
    'a failed lookup stamped the row, so nothing would ever ask again'
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
check(
    'Settings offers the download default',
    str_contains($settingsScreen['body'], 'name="downloads_enabled"'),
    'the bottom of the inheritance chain has to be reachable from a screen'
);
check('Settings offers the podcast fields', str_contains($settingsScreen['body'], 'name="podcast_owner_email"'));

/*
 * Whole-form, because this ticks a checkbox. Without the marker the handler
 * leaves every checkbox alone — which is what makes a partial save safe, and
 * means a save that intends to CHANGE one has to say so.
 */
postWithJar($baseUrl . '/admin/settings', [
    '_token'              => csrfFrom($settingsScreen['body']),
    '_whole_form'         => '1',
    'site_name'           => 'Smoke Portal',
    'timezone'            => 'UTC',
    'allow_indexing'      => '1',
    // On by default, so a whole-form save has to carry them or they go off.
    'subscriptions_enabled' => '1',
    'allow_access_requests' => '1',
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

/*
 * Put it back, so anything after this runs against a private site. Whole-form
 * again, with allow_indexing absent — which is how a browser says "unticked".
 */
postWithJar($baseUrl . '/admin/settings', [
    '_token'      => csrfFrom($settingsScreen['body']),
    '_whole_form' => '1',
    'site_name'   => 'Smoke Portal',
    'timezone'    => 'UTC',
    'subscriptions_enabled' => '1',
    'allow_access_requests' => '1',
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
 *
 * The plugin no longer serves one of its own: a scope has exactly one active
 * worker, so registering a second script at `/` silently replaces the first
 * while reporting success. It contributes to the site's worker instead, and
 * these checks follow it there.
 */
$worker = get($baseUrl . '/sw.js');
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
check(
    'and the plugin added to the worker rather than replacing it',
    str_contains($worker['body'], "'/offline'"),
    'a contribution that drops the core half is the collision this move exists to prevent'
);

/*
 * A worker must never be cached by anything in between.
 *
 * Browsers largely bypass their own HTTP cache for a worker script, so a
 * cacheable one looks fine in testing — but a CDN does not, and a stale worker
 * is the one file a new deploy cannot replace. This shipped as
 * `public, max-age=300` and a live host was found serving a worker that did
 * not match its own source.
 */
check(
    'and it is served uncacheable',
    str_contains(strtolower($worker['headers']['cache-control'] ?? ''), 'no-cache'),
    'a CDN caching the worker leaves a copy no deploy can replace'
);

/*
 * And the admin screen says whether the worker carries the push handlers,
 * because a push delivered to a worker with no push listener does nothing at
 * all — no notification, no error, no record.
 */
$pushWorkerState = getWithJar($baseUrl . '/admin/push', $jar);
check(
    'The push screen reports whether the worker carries its handlers',
    str_contains($pushWorkerState['body'], 'includes the push handlers'),
    'the most silent failure in this plugin had no indicator anywhere'
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
 * The subscribe control, in the header rather than floating in the footer.
 *
 * It spent its first release at the bottom of the page, revealed only once
 * navigator.serviceWorker.ready had resolved — which waits for a worker to
 * install, activate AND claim the page. On a first visit that is seconds; on a
 * failed registration it is never. So it appeared late or not at all, which
 * reads as "sometimes the link is there". Nothing about deciding whether to
 * OFFER a subscription needs the worker, so it is rendered up front now and
 * hidden again only if this browser turns out to be subscribed already.
 *
 * Keys have to exist for the control to render at all — with none there is
 * nothing to subscribe TO, and offering a button would be offering a failure.
 * So one is staged here and put back exactly as it was: the checks above
 * assert "No keys yet", and leaving a key behind would break them on the next
 * run rather than this one.
 */
$pushSettingsBefore = $db->value('SELECT settings FROM {plugins} WHERE slug = ?', ['push']);
$db->execute(
    'UPDATE {plugins} SET settings = ? WHERE slug = ?',
    [json_encode(['public_key' => 'smoke-public-key', 'private_key' => 'smoke-private-key']), 'push']
);

$pushHome = get($baseUrl . '/');
check(
    'The subscribe control is in the header',
    str_contains($pushHome['body'], 'id="push-subscribe-button"'),
    'a control people have to scroll to the bottom to find is one they do not find'
);
check(
    'and it is shown without waiting for the service worker',
    str_contains($pushHome['body'], 'button.hidden = false;'),
    'gating visibility on serviceWorker.ready is what made it intermittent'
);
check(
    'and it says what it does without relying on the icon',
    str_contains($pushHome['body'], 'aria-label="Notify me about new videos"'),
    'a bare bell glyph is not a label'
);

/*
 * A toggle rather than a control that disappears on success.
 *
 * Hiding it once subscribed left no way to tell "on" from "broken", no way to
 * turn notifications off, and /push/unsubscribe — shipped with the plugin —
 * with no caller anywhere in the product.
 */
check(
    'and it can turn notifications off again',
    str_contains($pushHome['body'], '/push/unsubscribe'),
    'the endpoint has existed since the plugin shipped and nothing ever called it'
);
check(
    'and it reports its state rather than vanishing',
    str_contains($pushHome['body'], 'aria-pressed')
        && str_contains($pushHome['body'], 'Notifications on'),
    'a control that disappears when it succeeds cannot be used twice'
);

$db->execute(
    'UPDATE {plugins} SET settings = ? WHERE slug = ?',
    [$pushSettingsBefore, 'push']
);
check(
    'and with no keys configured it is not offered at all',
    !str_contains(get($baseUrl . '/')['body'], 'id="push-subscribe-button"'),
    'a button with nothing to subscribe to fails for everybody, in the console only'
);

/*
 * This browser's own subscriptions, listed rather than counted.
 *
 * A bare count cannot show the state that actually goes wrong on a live host:
 * two rows, one bound to a service worker that no longer exists. The push
 * service accepts messages for the dead one and delivers nothing, so a test
 * reports success and nothing arrives — with no way to see the stale row.
 */
$pushMine = getWithJar($baseUrl . '/admin/push', $jar);
check(
    'The push screen has a section for your own subscriptions',
    str_contains($pushMine['body'], '<h2>Yours</h2>'),
    'a count cannot show a stale subscription, let alone remove one'
);
check(
    'and says so plainly when this browser has none',
    str_contains($pushMine['body'], 'This browser is not subscribed'),
    'an empty section reads as broken rather than as empty'
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
/*
 * The worker itself survives — it is core's now — but a deactivated plugin
 * must stop contributing to it. Checking that the worker still SERVES as well
 * as that the push half is gone, because "it 404s" would also satisfy a naive
 * assertion and would mean the whole site had lost its worker.
 */
$afterOff = get($baseUrl . '/sw.js');
check(
    'and its handlers leave the shared service worker',
    $afterOff['status'] === 200 && !str_contains($afterOff['body'], "addEventListener('push'"),
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
/*
 * No player for the locked video — asserted as the absence of a player, not
 * as the absence of the string "token=".
 *
 * That broader clause was a proxy, and it drifted. It held while a locked page
 * carried nothing signed at all; the moment the page grew a "More like this"
 * section it started matching the CDN token on a THUMBNAIL — for a different
 * video, one this viewer is allowed to see, of the kind every listing on the
 * site already shows. The lock was intact and the check said otherwise.
 *
 * A proxy assertion fails this way eventually: it passes for a reason nobody
 * wrote down, and when the reason stops holding it reports a defect that is not
 * there. The claim worth making is the one the section above makes positively —
 * a playable page has an <iframe>, so a locked one must not.
 */
check(
    'and no player is on the page',
    !str_contains($secondEpisode['body'], '<iframe')
        && !str_contains($secondEpisode['body'], 'iframe.mediadelivery.net'),
    'a signed URL was minted for a locked video, so the lock is decoration'
);
check(
    'It names the episode to watch first, and links to it',
    str_contains($secondEpisode['body'], 'Episode One')
        && str_contains($secondEpisode['body'], '/watch/course-episode-one'),
    '"locked" with no way forward is a dead end'
);

/*
 * And it still suggests something to watch. This is also what accounts for the
 * signed CDN tokens on a locked page — they are thumbnails for videos this
 * viewer may see, which is why the check above asks about the player rather
 * than about signing.
 */
check(
    'A locked page still offers other videos',
    str_contains($secondEpisode['body'], 'More like this'),
    'a locked episode is a dead end again'
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

/* ------------------------------------------------------------- navigation
 *
 * The sidebar is the only thing in the admin area that every screen renders,
 * so a mistake in it is a mistake on every page at once.
 *
 * Three claims, and the first two could not be made about the flat bar it
 * replaced. "Where am I" was answered by comparing the navigation key to the
 * screen name, which meant that on the four edit screens — the ones you spend
 * the most time on — the comparison matched nothing and the whole navigation
 * went dark. "How do I get to the trash" was answered by a line on the videos
 * screen that only appeared once something was already in it.
 *
 * The highlight is read out of the markup rather than looked for at a fixed
 * offset, because the assertion worth making is "exactly one link says it is
 * the current page, and it is the right one" — a check for a substring would
 * pass just as happily with six of them.
 */
echo "\nNavigation\n";

/** @return list<string> every href carrying aria-current, in document order */
$currentLinks = static function (string $html): array {
    // Deliberately tolerant about attribute order: a section heading carries a
    // class before its href and a child does not, and a pattern that only
    // matched one of those would silently stop seeing half the navigation.
    preg_match_all('~<a [^>]*href="([^"]*)"[^>]*aria-current="page"~', $html, $matches);
    return $matches[1];
};

$navDash = getWithJar($baseUrl . '/admin', $jar);

check(
    'The admin area renders a grouped sidebar',
    str_contains($navDash['body'], 'id="adminmenu"')
        && str_contains($navDash['body'], 'class="submenu"'),
    'the sections are what keep twenty-six links readable'
);
check(
    'The dashboard knows it is the dashboard',
    $currentLinks($navDash['body']) === ['/admin'],
    'nothing on the page says where you are'
);

$navVideos = getWithJar($baseUrl . '/admin/videos', $jar);
check(
    'The video library highlights Videos',
    $currentLinks($navVideos['body']) === ['/admin/videos'],
    'got: ' . implode(', ', $currentLinks($navVideos['body']))
);

$navEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);
check(
    'The edit screen still opens',
    $navEdit['status'] === 200,
    "got {$navEdit['status']} — the check below would prove nothing"
);
check(
    'Editing a video still highlights Videos',
    $currentLinks($navEdit['body']) === ['/admin/videos'],
    'this is the regression the grouping was built to fix — got: '
        . implode(', ', $currentLinks($navEdit['body']))
);
check(
    'and the section it lives in is open',
    str_contains($navEdit['body'], 'class="section current"'),
    'the children are hidden until their section is current, so this is what makes them visible'
);

/*
 * The trash used to be reachable only from a conditional line on the videos
 * screen, so somebody who had just deleted something and navigated away had no
 * way back to it. Checked from a screen in a different section entirely.
 */
check(
    'The trash is in the navigation from anywhere',
    str_contains(getWithJar($baseUrl . '/admin/settings', $jar)['body'], '/admin/videos/trash'),
    'it was findable only by someone who already knew where to look'
);

/*
 * A section nobody in this seat can use is absent, not disabled. Driven as a
 * person holding exactly one capability, because the administrator this script
 * signs in as can see everything and would prove nothing.
 */
$navRoleAt = date('Y-m-d H:i:s');
$navRoleId = (int) $db->insert('roles', [
    'slug' => 'nav-editor', 'name' => 'Nav Editor', 'is_system' => 0,
    'created_at' => $navRoleAt,
]);
$db->execute(
    'INSERT INTO {role_capabilities} (role_id, capability_id)
     SELECT ?, id FROM {capabilities} WHERE slug = ?',
    [$navRoleId, 'manage_videos']
);

$db->insert('users', [
    'email' => 'nav-editor@smoke.test', 'name' => 'Nav Editor',
    'authorized' => 1, 'role_id' => $navRoleId,
    'password_hash' => password_hash('nav-editor-password-1234', PASSWORD_DEFAULT),
    'created_at' => $navRoleAt, 'updated_at' => $navRoleAt,
]);

$navJar = sys_get_temp_dir() . '/portal-smoke-nav-' . getmypid() . '.txt';
@unlink($navJar);

clearLoginThrottle($db);
$navLogin = postWithJar($baseUrl . '/auth/login', [
    'email'    => 'nav-editor@smoke.test',
    'password' => 'nav-editor-password-1234',
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $navJar)['body']),
], $navJar);

check('A single-capability editor can sign in', $navLogin['status'] === 302, "got {$navLogin['status']}");

$navLimited = getWithJar($baseUrl . '/admin/videos', $navJar);

check(
    'and reaches the admin area on one capability',
    $navLimited['status'] === 200,
    "got {$navLimited['status']} — the checks below would prove nothing"
);
check(
    'Their sidebar carries the section they can use',
    str_contains($navLimited['body'], '/admin/videos'),
    'a person with manage_videos cannot find the videos'
);
check(
    'and drops the sections they cannot',
    !str_contains($navLimited['body'], '/admin/settings')
        && !str_contains($navLimited['body'], '/admin/users')
        && !str_contains($navLimited['body'], '/admin/permissions'),
    'a link that leads to a 403 reads as a broken site rather than a boundary'
);
/*
 * Content survives on manage_videos alone, but only the children that
 * capability covers. Notices needs manage_settings and Categories needs
 * manage_categories, so both have to be gone from a section that is otherwise
 * present — which is the case a section-level check alone would miss.
 */
check(
    'Filtering reaches inside a section, not just around it',
    !str_contains($navLimited['body'], '/admin/announcements')
        && !str_contains($navLimited['body'], '/admin/categories'),
    'the section was filtered but its children were not'
);

@unlink($navJar);

/* -------------------------------------------------- provider statistics
 *
 * The plugin exists to be a CALLER. VideoProvider::statistics() has been on
 * the interface and implemented since Phase 1 and nothing in the codebase ever
 * invoked it, which is the same defect class as a repository method with full
 * coverage and no form behind it: every unit test passes and no person can
 * reach the feature.
 *
 * So these checks drive the rendered page. The unit tests cover the judgement
 * it makes; only a real request proves the judgement is on a screen.
 *
 * The provider here is the fake one this script installs with, so statistics()
 * returns zeroes — which is precisely the ambiguous case the plugin is built to
 * be honest about, and therefore the one worth asserting on.
 */
echo "\nProvider statistics\n";

$statsPluginsPage = getWithJar($baseUrl . '/admin/plugins', $jar);
check(
    'Provider statistics is listed as a plugin',
    str_contains($statsPluginsPage['body'], 'Provider Statistics'),
    'the bundled plugin was not discovered — check the .gitignore allowlist'
);

$statsActivated = postWithJar($baseUrl . '/admin/plugins', [
    '_token' => csrfFrom($statsPluginsPage['body']),
    'slug'   => 'provider-stats',
    'action' => 'activate',
], $jar);

check('Activating provider-stats succeeds', $statsActivated['status'] === 302, "got {$statsActivated['status']}");
check(
    'and it stays active after the redirect',
    (int) $db->value('SELECT is_active FROM {plugins} WHERE slug = ?', ['provider-stats']) === 1,
    'it threw on load and was silently deactivated — check the error log'
);

$statsPage = getWithJar($baseUrl . '/admin/provider-stats', $jar);
check('The provider statistics screen renders', $statsPage['status'] === 200, "got {$statsPage['status']}");
check(
    'It shows both sources side by side',
    str_contains($statsPage['body'], 'Plays, per bunny.net')
        && str_contains($statsPage['body'], 'Plays, per this site'),
    'one number alone is the analytics screen again'
);
check(
    'and explains why the two disagree',
    str_contains($statsPage['body'], 'being ahead is normal'),
    'a gap with no explanation reads as a bug in whichever number is smaller'
);

/*
 * Both branches of the honesty logic, staged rather than hoped for.
 *
 * This is the reason the screen is worth having, so both states have to be
 * driven through a real request — a branch that only a unit test ever reaches
 * is one nothing proves is on a page. The earlier checks in this script have
 * already recorded plays, so the quiet case has to be made rather than found.
 *
 * Nothing after this section reads {video_views}; the analytics checks are far
 * upstream and have already run.
 */
$db->execute('DELETE FROM {video_views}');

$statsQuiet = getWithJar($baseUrl . '/admin/provider-stats', $jar);
check(
    'With nothing on either side it refuses to guess',
    str_contains($statsQuiet['body'], 'Nothing to compare'),
    'zeroes from a failed call would be presented as a real reading'
);

/* Now give this site plays the provider will not know about. */
$db->execute(
    'INSERT INTO {video_views} (video_id, day, views, completions) VALUES (?, CURDATE(), ?, ?)
     ON DUPLICATE KEY UPDATE views = VALUES(views), completions = VALUES(completions)',
    [$videoRow, 40, 9]
);

$statsWithLocal = getWithJar($baseUrl . '/admin/provider-stats', $jar);
check(
    'A silent provider beside recorded plays is called out as a failed read',
    str_contains($statsWithLocal['body'], 'returned nothing for this window'),
    'the one inference that separates a dead API from an idle library'
);
check(
    'and the same page no longer says it cannot tell',
    !str_contains($statsWithLocal['body'], 'Nothing to compare'),
    'both verdicts on one page means neither is being chosen'
);
check(
    'and it points at the screen that would fix it',
    str_contains($statsWithLocal['body'], '/admin/providers'),
    'a diagnosis with no next step'
);

/* The period selector has to actually change the window. */
$statsWeek = getWithJar($baseUrl . '/admin/provider-stats?days=7', $jar);
check(
    'The period selector works',
    $statsWeek['status'] === 200 && str_contains($statsWeek['body'], 'href="/admin/provider-stats?days=30"'),
    "got {$statsWeek['status']}"
);

/*
 * It is a report, so it is gated on VIEW_ANALYTICS rather than on the
 * permission to install software. Driven from the single-capability account the
 * navigation section created, which holds manage_videos and nothing else — so
 * it reaches the admin area and must still be refused this page. Signed in
 * afresh rather than reusing that jar, because the navigation section deletes
 * its own cookies and a missing jar would answer 302 and read as a pass.
 */
$statsJar = sys_get_temp_dir() . '/portal-smoke-stats-' . getmypid() . '.txt';
@unlink($statsJar);

clearLoginThrottle($db);
$statsLogin = postWithJar($baseUrl . '/auth/login', [
    'email'    => 'nav-editor@smoke.test',
    'password' => 'nav-editor-password-1234',
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $statsJar)['body']),
], $statsJar);

check('The single-capability editor signs in again', $statsLogin['status'] === 302, "got {$statsLogin['status']}");
check(
    'and still reaches the admin area',
    getWithJar($baseUrl . '/admin/videos', $statsJar)['status'] === 200,
    'the 403 below would prove nothing if they were locked out entirely'
);

$statsForbidden = getWithJar($baseUrl . '/admin/provider-stats', $statsJar);
check(
    'but is refused the statistics report',
    $statsForbidden['status'] === 403,
    "got {$statsForbidden['status']} — a report on the whole library behind no permission at all"
);
check(
    'and it is not offered to them in the navigation',
    !str_contains(getWithJar($baseUrl . '/admin/videos', $statsJar)['body'], '/admin/provider-stats'),
    'a link that leads to a 403 reads as a broken site rather than a boundary'
);

@unlink($statsJar);

/* ------------------------------------------------------- access requests
 *
 * The gap this closes is the purest instance of the pattern in the whole
 * project: approving people has worked since Phase 1, the dashboard has
 * counted the ones waiting since Phase 1, and there has never been a way for
 * one of them to say anything. The page told them to go find a human.
 *
 * So every check here drives the page an unapproved person actually lands on.
 * A repository test can prove submit() works; only this can prove somebody can
 * reach it.
 */
echo "\nAccess requests\n";

$askEmail = 'asking@smoke.test';
$askPassword = 'asking-for-access-1234';
$askUserId = $db->insert('users', [
    'email' => $askEmail, 'name' => 'Hopeful Person',
    'authorized' => 0,
    'role_id' => (int) $db->value('SELECT id FROM {roles} WHERE slug = ?', ['viewer']),
    'password_hash' => password_hash($askPassword, PASSWORD_DEFAULT),
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);

$askJar = sys_get_temp_dir() . '/portal-smoke-ask-' . getmypid() . '.txt';
@unlink($askJar);

clearLoginThrottle($db);
$askLoginPage = getWithJar($baseUrl . '/auth/login', $askJar);
$askLogin = postWithJar($baseUrl . '/auth/login', [
    '_token'   => csrfFrom($askLoginPage['body']),
    'email'    => $askEmail,
    'password' => $askPassword,
], $askJar);

/*
 * The reason, not just the number.
 *
 * A sign-in failure here has three possible causes — the throttle, no local
 * provider, or the credentials — and they are indistinguishable from a status
 * code. Reporting "got 401" sent the person reading it (me) guessing twice.
 */
check(
    'An unapproved person can sign in',
    $askLogin['status'] === 302,
    "got {$askLogin['status']}: " . (
        preg_match('~<div class="notice">(.*?)</div>~s', $askLogin['body'], $m)
            ? trim(html_entity_decode(strip_tags($m[1])))
            : 'no error message on the page'
    )
);

$pendingPage = getWithJar($baseUrl . '/watch/' . $videoSlug, $askJar);
check(
    'and is refused the library',
    $pendingPage['status'] === 403,
    "got {$pendingPage['status']} — an unapproved account got in"
);
check(
    'with a page that offers a way to ask',
    str_contains($pendingPage['body'], 'action="/request-access"'),
    'this page used to end at "let whoever invited you know"'
);

/* The form is a POST that writes and sends mail, so it needs a token. */
$noTokenAsk = postWithJar($baseUrl . '/request-access', ['note' => 'no token here'], $askJar);
check(
    'Asking without a CSRF token is refused',
    $noTokenAsk['status'] === 419 || $noTokenAsk['status'] === 403,
    "got {$noTokenAsk['status']}"
);
check(
    'and nothing was recorded',
    (int) $db->value('SELECT COUNT(*) FROM {access_requests}') === 0,
    'a request was written by a form nobody on this site rendered'
);

$askToken = csrfFrom($pendingPage['body']);
$asked = postWithJar($baseUrl . '/request-access', [
    '_token' => $askToken,
    'note'   => "I'm on the Thursday team — Sam asked me to sign up.",
], $askJar, ['Referer: ' . $baseUrl . '/watch/' . $videoSlug]);

check('Asking for access is accepted', $asked['status'] === 302, "got {$asked['status']}");

/*
 * And lands back on the page that refused them, not the homepage.
 *
 * Somebody who clicks a button and ends up somewhere else with no
 * acknowledgement concludes it did not work and clicks again — and the second
 * click is the one that is silently ignored, because a person may ask once. The
 * confirmation is what stops the fire-once guard from reading as a broken
 * button.
 */
check(
    'and returns them to the page that refused them',
    str_contains($asked['headers']['location'] ?? '', '/watch/' . $videoSlug),
    'got: ' . ($asked['headers']['location'] ?? 'no Location header')
);
check(
    'and the request is recorded',
    (int) $db->value('SELECT COUNT(*) FROM {access_requests} WHERE user_id = ?', [$askUserId]) === 1,
    'the button did nothing'
);

$afterAsking = getWithJar($baseUrl . '/watch/' . $videoSlug, $askJar);
check(
    'The page now says the request has been sent',
    str_contains($afterAsking['body'], 'Your request has been sent'),
    'somebody who asked cannot tell whether it worked, so they will ask again'
);
check(
    'and no longer offers the form',
    !str_contains($afterAsking['body'], 'action="/request-access"'),
    'a form that resubmits is a form that will be resubmitted'
);

/*
 * The fire-once guard, from the outside. Asking again must edit the note and
 * must not create a second row — this is what stops a button shown to any
 * stranger who can authenticate from becoming a mail relay.
 */
postWithJar($baseUrl . '/request-access', [
    '_token' => $askToken,
    'note'   => 'Second attempt with a different message.',
], $askJar);

check(
    'Asking twice is still one request',
    (int) $db->value('SELECT COUNT(*) FROM {access_requests}') === 1,
    'each click would be a row, and an email'
);

/* The administrator sees it where they already go to decide. */
$peopleScreen = getWithJar($baseUrl . '/admin/users', $jar);
check(
    'The People screen shows that they asked',
    str_contains($peopleScreen['body'], 'Asked for access'),
    'the note is stored where nobody looks'
);
check(
    'and shows what they said, escaped',
    str_contains($peopleScreen['body'], 'Second attempt with a different message.'),
    'the message is the thing that answers "should I approve this person"'
);

/*
 * Last seen.
 *
 * The column an admin needs for the other half of the decision: the note says
 * why somebody wants in, this says whether they are still around. The value was
 * written on every authorized request since Phase 1 and displayed nowhere.
 *
 * Back-dated to a fixed age rather than asserted against whatever the run
 * happens to have produced, because "it shows something" passes just as well
 * against a column of empty cells.
 */
check(
    'The People screen has a Last seen column',
    str_contains($peopleScreen['body'], '<th>Last seen</th>'),
    'the value has been recorded since Phase 1 and shown nowhere'
);

$db->execute(
    'UPDATE {users} SET last_seen_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s', time() - (3 * 86400)), $askUserId]
);
$seenScreen = getWithJar($baseUrl . '/admin/users', $jar);
check(
    'and it counts the days since somebody was here',
    str_contains($seenScreen['body'], '3 days ago'),
    'a back-dated account is not being rendered from its real stamp'
);

/*
 * NULL is a different answer from "a long time ago", and it is the one an admin
 * scans for. Rendering it as 1 Jan 1970 would look like data.
 */
$db->execute('UPDATE {users} SET last_seen_at = NULL WHERE id = ?', [$askUserId]);
$neverScreen = getWithJar($baseUrl . '/admin/users', $jar);
check(
    'and says Never rather than inventing a date',
    str_contains($neverScreen['body'], 'Never') && !str_contains($neverScreen['body'], '1 Jan 1970'),
    'an account nobody has ever seen is being given an epoch date'
);

// Put it back. A stamp left NULL here is not read by anything later, but a
// fixture that does not restore what it changed is how this suite has broken a
// check three hundred lines further down before.
$db->execute('UPDATE {users} SET last_seen_at = NOW() WHERE id = ?', [$askUserId]);

/* Approving answers the question, so the question goes away. */
$approve = postWithJar($baseUrl . '/admin/users', [
    '_token' => csrfFrom($peopleScreen['body']),
    'id'     => $askUserId,
    'action' => 'authorize',
], $jar);

check('Approving them succeeds', $approve['status'] === 302, "got {$approve['status']}");
check(
    'and clears the request',
    (int) $db->value('SELECT COUNT(*) FROM {access_requests} WHERE user_id = ?', [$askUserId]) === 0,
    'the request would sit beside an account that already has access'
);
check(
    'and they can now watch',
    getWithJar($baseUrl . '/watch/' . $videoSlug, $askJar)['status'] === 200,
    'approval did not take effect'
);

/*
 * Switched off, the form is gone AND the route refuses — a hidden form that
 * still accepts a POST is a setting that only works on people who do not look.
 */
$db->execute(
    'INSERT INTO {settings} (`key`, `value`, updated_at) VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
    ['allow_access_requests', '0']
);
$db->execute('UPDATE {users} SET authorized = 0 WHERE id = ?', [$askUserId]);
$db->execute('DELETE FROM {access_requests} WHERE user_id = ?', [$askUserId]);

$offPage = getWithJar($baseUrl . '/watch/' . $videoSlug, $askJar);
check(
    'With requests switched off the form is gone',
    !str_contains($offPage['body'], 'action="/request-access"'),
    'an invitation-only site is still inviting people to knock'
);
check(
    'and the page still explains the refusal',
    str_contains($offPage['body'], 'not approved yet'),
    'switching a feature off should not blank the page it lived on'
);

$offPost = postWithJar($baseUrl . '/request-access', [
    '_token' => csrfFrom($offPage['body']) ?: $askToken,
    'note'   => 'submitting anyway',
], $askJar);
check(
    'and the route refuses a request submitted anyway',
    $offPost['status'] === 403,
    "got {$offPost['status']} — hiding a form is not switching it off"
);

$db->execute('DELETE FROM {settings} WHERE `key` = ?', ['allow_access_requests']);

/*
 * The upgrade window.
 *
 * Deploying is `git pull`, and the new code serves requests from the moment it
 * lands — the migrator runs on the first request, not before it. So there is a
 * real interval where this page's code exists and its table does not, and the
 * people hitting it are precisely the ones waiting to be approved.
 *
 * The fallback that covers it is a try/catch, which is the kind of guard that
 * silently stops being exercised. Staged by removing the table, which is what
 * that interval looks like from the page's point of view.
 */
$db->execute('DROP TABLE {access_requests}');

$midUpgrade = getWithJar($baseUrl . '/watch/' . $videoSlug, $askJar);
check(
    'The pending page survives its table not existing yet',
    $midUpgrade['status'] === 403 && str_contains($midUpgrade['body'], 'not approved yet'),
    "got {$midUpgrade['status']} — an upgrade would turn a 403 into a 500 for everyone waiting"
);
check(
    'and falls back to telling them who to contact',
    str_contains($midUpgrade['body'], 'let whoever invited you know'),
    'the page renders but says nothing useful'
);

/* Put it back the way the migrator would, and confirm it did. */
$db->execute('DELETE FROM {schema_version} WHERE version = ?', ['0018']);
getWithJar($baseUrl . '/admin', $jar);

check(
    'and the next request re-applies the migration',
    (int) $db->value(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?',
        ['access_requests']
    ) === 1,
    'the table did not come back, so the checks above left the site broken'
);

@unlink($askJar);

/* ----------------------------------------------------------- deploy stamp
 *
 * The opcode cache is cleared when the deployed code moves. Neither half of
 * that is observable from here — a cleared cache looks like a working site and
 * so does a stale one — so what is checked is the part that CAN go wrong
 * loudly: the cost.
 *
 * A check on every request that writes a row on every request is worse than
 * the problem it solves, and this project has made that exact mistake once
 * already with the migration-failure flag.
 */
echo "\nDeploy detection\n";

get($baseUrl . '/');

$stampRow = $db->first('SELECT `value`, updated_at FROM {settings} WHERE `key` = ?', ['deploy_stamp']);
check(
    'A deploy stamp is recorded',
    $stampRow !== null && (string) $stampRow['value'] !== '',
    'nothing was recorded, so a deploy can never be noticed'
);

get($baseUrl . '/');
get($baseUrl . '/auth/login');
get($baseUrl . '/');

$stampAfter = $db->first('SELECT `value`, updated_at FROM {settings} WHERE `key` = ?', ['deploy_stamp']);
check(
    'and ordinary requests do not rewrite it',
    $stampAfter !== null
        && (string) $stampAfter['updated_at'] === (string) ($stampRow['updated_at'] ?? ''),
    'every page load carries a settings write to say nothing changed'
);

/*
 * And it does notice. Touching a sentinel is what `git pull` does to it —
 * mtime only, which is the signal, and nothing git tracks.
 */
touch(PORTAL_ROOT . '/vendor/composer/installed.php', time() + 5);

get($baseUrl . '/');

$stampMoved = $db->first('SELECT `value`, updated_at FROM {settings} WHERE `key` = ?', ['deploy_stamp']);
check(
    'A changed file is noticed on the next request',
    $stampMoved !== null && (string) $stampMoved['value'] !== (string) ($stampAfter['value'] ?? ''),
    'a deploy would go unnoticed and the stale bytecode would stay'
);

/* Settled again straight afterwards, rather than re-firing every request. */
$settled = $db->first('SELECT updated_at FROM {settings} WHERE `key` = ?', ['deploy_stamp']);
get($baseUrl . '/');
check(
    'and it settles rather than firing repeatedly',
    (string) ($db->value('SELECT updated_at FROM {settings} WHERE `key` = ?', ['deploy_stamp']))
        === (string) ($settled['updated_at'] ?? ''),
    'one deploy would keep clearing the cache on every request that followed'
);

/* ----------------------------------------------------------- passwords
 *
 * Two things that were built and unreachable. LocalProvider::validatePassword()
 * had no callers, so the only password this product asks a person to choose —
 * the administrator's, at install — was accepted whatever it was. And
 * UserRepository::setPassword() had no callers either, which means there was no
 * way to change a password at all: on a host with no shell, the break-glass
 * credential could never be rotated.
 *
 * Driven through the real page, because a rule with a test and no caller is
 * exactly the state this is fixing.
 */
/* ------------------------------------------------------------- account area
 *
 * The site has announced videos by email since Phase 4 and by push since
 * Phase 5, and kept no record of either. An email is in a mailbox this app
 * cannot read and a push is gone the moment it is dismissed — or never arrived
 * at all — so there has never been any way for a member to find out what the
 * site told them.
 *
 * Driven through the real pages: the record is only worth having if somebody
 * can reach it, and the password form spent its first release reachable only
 * by typing its URL.
 */
echo "\nAccount area\n";

$acctEmail = 'admin@smoke.test';
$db->execute('DELETE FROM {notifications} WHERE recipient_email = ?', [$acctEmail]);

$acctPage = getWithJar($baseUrl . '/account', $jar);
check('The account page renders', $acctPage['status'] === 200, "got {$acctPage['status']}");
check(
    'and it is linked from the site header',
    str_contains(get($baseUrl . '/')['body'], '/account') || str_contains($acctPage['body'], '/account/notifications'),
    'an account area reachable only by typing its URL is the defect this repeats'
);

/*
 * The one screen the server cannot fill in. Everything on it lives in Cache
 * Storage in the visitor's browser, so what is checked here is that the shell
 * arrives, is reachable, and explains itself — an empty list on a second
 * device looks like a bug in every other screen in this product.
 */
$acctDownloads = getWithJar($baseUrl . '/account/downloads', $jar);
check('The offline downloads page renders', $acctDownloads['status'] === 200, "got {$acctDownloads['status']}");
check(
    'and it is linked from the account area',
    str_contains($acctPage['body'], '/account/downloads'),
    'a page reachable only by typing its URL is the defect this project repeats'
);
check(
    'and it says the list belongs to this browser, not the site',
    str_contains($acctDownloads['body'], 'kept by this browser'),
    'a per-device list that does not say so is reported as data loss'
);
check(
    'and it loads the script that fills it in',
    str_contains($acctDownloads['body'], '/assets/offline.js'),
    'a shell with nothing to fill it is a permanently empty page'
);

$inbox = getWithJar($baseUrl . '/account/notifications', $jar);
check('The notifications page renders', $inbox['status'] === 200, "got {$inbox['status']}");
check(
    'and an empty one says so rather than looking broken',
    str_contains($inbox['body'], 'Nothing yet'),
    'an empty list with no explanation reads as a failure'
);

/*
 * Recorded the way the sender records it, then read back through the page.
 * Writing the row directly is the point: this proves the READING half, and the
 * writing half is covered where the sending happens.
 */
$log = new Portal\Content\NotificationLog($db);
$log->record($acctEmail, Portal\Content\NotificationLog::EMAIL, 'SMOKE Announced Video', '/watch/' . $videoSlug);
$log->record($acctEmail, Portal\Content\NotificationLog::PUSH, 'SMOKE Pushed Video', '/watch/' . $videoSlug);

$inbox = getWithJar($baseUrl . '/account/notifications', $jar);
check(
    'A recorded notification is listed',
    str_contains($inbox['body'], 'SMOKE Announced Video'),
    'the record exists and the page does not show it'
);
check(
    'and a push is listed beside an email',
    str_contains($inbox['body'], 'SMOKE Pushed Video'),
    'the record is meant to be complete regardless of channel'
);
check(
    'and the unread count reaches the header',
    (int) $db->value(
        'SELECT COUNT(*) FROM {notifications} WHERE recipient_email = ? AND read_at IS NULL',
        [$acctEmail]
    ) === 2,
    'both rows should be unread'
);

/* Marking one read leaves the other alone. */
$inboxToken = csrfFrom($inbox['body']);
$firstId = (int) $db->value(
    'SELECT id FROM {notifications} WHERE recipient_email = ? ORDER BY id DESC LIMIT 1',
    [$acctEmail]
);
postWithJar($baseUrl . '/account/notifications', [
    '_token' => $inboxToken,
    'action' => 'read',
    'id'     => $firstId,
], $jar);
check(
    'Marking one read marks exactly one',
    (int) $db->value(
        'SELECT COUNT(*) FROM {notifications} WHERE recipient_email = ? AND read_at IS NULL',
        [$acctEmail]
    ) === 1,
    'the action is meant to be per row'
);

/*
 * The property that matters: somebody else's row is untouchable through this
 * form. Ids are sequential, so guessing one is trivial.
 */
$log->record('someone-else@example.com', Portal\Content\NotificationLog::EMAIL, 'SMOKE Not Yours');
$theirId = (int) $db->value(
    'SELECT id FROM {notifications} WHERE recipient_email = ?',
    ['someone-else@example.com']
);
postWithJar($baseUrl . '/account/notifications', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/account/notifications', $jar)['body']),
    'action' => 'delete',
    'id'     => $theirId,
], $jar);
check(
    "Deleting cannot reach another account's notification",
    (int) $db->value('SELECT COUNT(*) FROM {notifications} WHERE id = ?', [$theirId]) === 1,
    'the id alone was enough to destroy a stranger\'s row'
);
check(
    'and theirs is not listed on your page',
    !str_contains(getWithJar($baseUrl . '/account/notifications', $jar)['body'], 'SMOKE Not Yours'),
    'the listing is not scoped to the signed-in address'
);

/* Clearing takes yours and only yours. */
postWithJar($baseUrl . '/account/notifications', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/account/notifications', $jar)['body']),
    'action' => 'clear',
], $jar);
check(
    'Clearing removes your notifications',
    (int) $db->value('SELECT COUNT(*) FROM {notifications} WHERE recipient_email = ?', [$acctEmail]) === 0,
    'clear did nothing'
);
check(
    'and leaves everybody else alone',
    (int) $db->value('SELECT COUNT(*) FROM {notifications} WHERE id = ?', [$theirId]) === 1,
    'clear was not scoped to the signed-in address'
);

$acctAnon = get($baseUrl . '/account/notifications');
check(
    'A signed-out visitor cannot open the account area',
    in_array($acctAnon['status'], [302, 401, 403], true),
    "got {$acctAnon['status']}"
);

$db->execute('DELETE FROM {notifications}');

/*
 * Two things that were built and unreachable. LocalProvider::validatePassword()
 * had no callers, so the only password this product asks a person to choose —
 * the administrator's, at install — was accepted whatever it was. And
 * UserRepository::setPassword() had no callers either, which means there was no
 * way to change a password at all: on a host with no shell, the break-glass
 * credential could never be rotated.
 *
 * Driven through the real page, because a rule with a test and no caller is
 * exactly the state this is fixing.
 */
echo "\nPasswords\n";

$pwPage = getWithJar($baseUrl . '/account/password', $jar);
check('The change-password page renders', $pwPage['status'] === 200, "got {$pwPage['status']}");
check(
    'It asks for the current password first',
    str_contains($pwPage['body'], 'name="current_password"'),
    'anybody with a borrowed session could change the password without knowing it'
);
check(
    'and it is linked from the admin sidebar',
    str_contains(getWithJar($baseUrl . '/admin', $jar)['body'], '/account/password'),
    'a page nothing links to is one only somebody who read the source can find'
);

$pwToken = csrfFrom($pwPage['body']);

/* The current password has to be right. */
$wrongCurrent = postWithJar($baseUrl . '/account/password', [
    '_token'           => $pwToken,
    'current_password' => 'not-the-right-one-at-all',
    'new_password'     => 'a perfectly fine new passphrase',
    'confirm_password' => 'a perfectly fine new passphrase',
], $jar);

check(
    'A wrong current password is refused',
    str_contains($wrongCurrent['body'], 'not your current password'),
    'knowing the old password is the only thing separating this from a takeover'
);
check(
    'and the password did not change',
    postWithJar($baseUrl . '/auth/login', [
        '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', sys_get_temp_dir() . '/portal-smoke-pwcheck-' . getmypid() . '.txt')['body']),
        'email'    => 'admin@smoke.test',
        'password' => 'smoke-test-password-1234',
    ], sys_get_temp_dir() . '/portal-smoke-pwcheck-' . getmypid() . '.txt')['status'] === 302,
    'the refusal was cosmetic'
);

/* The rule that had no caller. */
$tooWeak = postWithJar($baseUrl . '/account/password', [
    '_token'           => $pwToken,
    'current_password' => 'smoke-test-password-1234',
    'new_password'     => 'short',
    'confirm_password' => 'short',
], $jar);

check(
    'A password that is too short is refused',
    str_contains($tooWeak['body'], '12 characters'),
    'the rule exists in code and nothing applies it — which was true until now'
);

$common = postWithJar($baseUrl . '/account/password', [
    '_token'           => $pwToken,
    'current_password' => 'smoke-test-password-1234',
    'new_password'     => 'administrator',
    'confirm_password' => 'administrator',
], $jar);

check(
    'and so is one everybody tries first',
    str_contains($common['body'], 'too common'),
    'length alone does not save a password that is on every list'
);

$mismatch = postWithJar($baseUrl . '/account/password', [
    '_token'           => $pwToken,
    'current_password' => 'smoke-test-password-1234',
    'new_password'     => 'a perfectly fine new passphrase',
    'confirm_password' => 'a different fine passphrase',
], $jar);

check(
    'A typo in the confirmation is caught',
    str_contains($mismatch['body'], 'do not match'),
    'a mistyped new password locks somebody out of their own account'
);

/* And the change itself. */
$changed = postWithJar($baseUrl . '/account/password', [
    '_token'           => $pwToken,
    'current_password' => 'smoke-test-password-1234',
    'new_password'     => 'a perfectly fine new passphrase',
    'confirm_password' => 'a perfectly fine new passphrase',
], $jar);

check('Changing the password succeeds', $changed['status'] === 302, "got {$changed['status']}");
check(
    'and the session survives it',
    getWithJar($baseUrl . '/admin', $jar)['status'] === 200,
    'the person who just proved they know both passwords was thrown out'
);

$newJar = sys_get_temp_dir() . '/portal-smoke-newpw-' . getmypid() . '.txt';
@unlink($newJar);
clearLoginThrottle($db);

check(
    'The new password works',
    postWithJar($baseUrl . '/auth/login', [
        '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $newJar)['body']),
        'email'    => 'admin@smoke.test',
        'password' => 'a perfectly fine new passphrase',
    ], $newJar)['status'] === 302,
    'the change reported success and did nothing'
);

$oldJar = sys_get_temp_dir() . '/portal-smoke-oldpw-' . getmypid() . '.txt';
@unlink($oldJar);
clearLoginThrottle($db);

check(
    'and the old one does not',
    postWithJar($baseUrl . '/auth/login', [
        '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $oldJar)['body']),
        'email'    => 'admin@smoke.test',
        'password' => 'smoke-test-password-1234',
    ], $oldJar)['status'] === 401,
    'the old password still opens the account'
);

/*
 * Put it back, because everything after this signs in with the original.
 */
postWithJar($baseUrl . '/account/password', [
    '_token'           => csrfFrom(getWithJar($baseUrl . '/account/password', $jar)['body']),
    'current_password' => 'a perfectly fine new passphrase',
    'new_password'     => 'smoke-test-password-1234',
    'confirm_password' => 'smoke-test-password-1234',
], $jar);

clearLoginThrottle($db);
@unlink($newJar);
@unlink($oldJar);
@unlink(sys_get_temp_dir() . '/portal-smoke-pwcheck-' . getmypid() . '.txt');

/* ------------------------------------------------------- more like this
 *
 * The theme has rendered this section since Phase 1 and the controller passed
 * it an empty array every time, so it has never appeared on a page. The
 * fifteenth instance of the pattern, and the one where the presentation was
 * already finished.
 *
 * The check that matters is not "a section appears" — it is that the section
 * cannot show something the viewer is not allowed to see. Relatedness picks
 * candidates and the ordinary listing query decides visibility, so this proves
 * the second half is actually in the path.
 */
echo "\nMore like this\n";

$relatedNow = date('Y-m-d H:i:s');
$relSeries = $db->insert('series', [
    'slug' => 'related-series', 'title' => 'A Related Series',
    'is_published' => 1, 'created_at' => $relatedNow, 'updated_at' => $relatedNow,
]);

$relIds = [];
foreach (['visible' => 1, 'sibling' => 1] as $name => $published) {
    $relIds[$name] = $db->insert('videos', [
        'provider' => 'bunny', 'provider_id' => 'smoke-related-' . $name,
        'slug' => 'related-' . $name, 'title' => 'Related ' . ucfirst($name),
        'status' => 'ready', 'is_published' => $published, 'duration' => 60,
        'series_id' => $relSeries,
        'published_at' => $relatedNow, 'created_at' => $relatedNow, 'updated_at' => $relatedNow,
    ]);
}

/* Same series, but not for the public: one unpublished, one members-only. */
$relIds['unpublished'] = $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-related-unpublished',
    'slug' => 'related-unpublished', 'title' => 'Related Unpublished',
    'status' => 'ready', 'is_published' => 0, 'duration' => 60,
    'series_id' => $relSeries,
    'created_at' => $relatedNow, 'updated_at' => $relatedNow,
]);
$relIds['members'] = $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-related-members',
    'slug' => 'related-members', 'title' => 'Related Members Only',
    'status' => 'ready', 'is_published' => 1, 'member_only' => 1, 'duration' => 60,
    'series_id' => $relSeries,
    'published_at' => $relatedNow, 'created_at' => $relatedNow, 'updated_at' => $relatedNow,
]);

$relatedPage = getWithJar($baseUrl . '/watch/related-visible', $jar);

check('A video with siblings renders', $relatedPage['status'] === 200, "got {$relatedPage['status']}");
check(
    'The "More like this" section appears',
    str_contains($relatedPage['body'], 'More like this'),
    'the theme has rendered this since Phase 1 and never had anything to show'
);
check(
    'and it offers the sibling episode',
    str_contains($relatedPage['body'], '/watch/related-sibling'),
    'the strongest signal there is — the next part of what you are watching'
);

/*
 * The one that would be a real defect. Both of these share a series with the
 * video being watched, so relatedness ranks them; only the listing query keeps
 * them out.
 */
check(
    'An unpublished sibling is not offered',
    !str_contains($relatedPage['body'], '/watch/related-unpublished'),
    'relatedness is deciding visibility instead of the listing query'
);

$anonRelated = get($baseUrl . '/watch/related-visible');
check(
    'and a members-only sibling is not offered to a signed-out visitor',
    !str_contains($anonRelated['body'], '/watch/related-members'),
    'a members-only video reached a public page through the related list'
);

/*
 * Cost is deliberately not asserted here. It runs on the busiest page in the
 * product and the signals are gathered in one statement for that reason — but
 * this script talks to the server over HTTP and counts queries on its OWN
 * connection, so anything it measured would be about the test and not the
 * page. The query monitor is what can see that, and it can see it live.
 */

/* Cleaned up so later sections see the library they expect. */
foreach ($relIds as $id) {
    $db->execute('DELETE FROM {videos} WHERE id = ?', [$id]);
}
$db->execute('DELETE FROM {series} WHERE id = ?', [$relSeries]);

/* ------------------------------------------------------- library export
 *
 * Settings have been exportable since Phase 3 and the content never has, which
 * on a host with no shell means the catalogue exists only inside the database.
 *
 * NDJSON, streamed. The checks are about the format holding together and the
 * gate being real — a file that parses is the whole value of a backup, and an
 * export of every unpublished video is not something to leave open.
 */
echo "\nLibrary export\n";

$exported = getWithJar($baseUrl . '/admin/settings/content', $jar);

check('The library export downloads', $exported['status'] === 200, "got {$exported['status']}");
check(
    'as NDJSON, offered as a file',
    str_contains($exported['headers']['content-type'] ?? '', 'ndjson')
        && str_contains($exported['headers']['content-disposition'] ?? '', 'attachment'),
    'a browser would render it as a page instead of saving it'
);

$exportLines = array_values(array_filter(explode("\n", trim($exported['body']))));

check(
    'Every line is valid JSON on its own',
    $exportLines !== [] && array_reduce(
        $exportLines,
        static fn (bool $ok, string $line): bool => $ok && json_decode($line, true) !== null,
        true
    ),
    'the point of one-object-per-line is that a truncated file is still readable'
);

$exportTypes = [];
foreach ($exportLines as $line) {
    $row = json_decode($line, true);
    if (is_array($row) && isset($row['type'])) {
        $exportTypes[(string) $row['type']] = true;
    }
}

check(
    'It starts with a meta line naming the version',
    ($first = json_decode($exportLines[0] ?? '{}', true)) && ($first['type'] ?? '') === 'meta'
        && ($first['version'] ?? '') !== '',
    'a file with no provenance is one nobody can tell the age of'
);
check(
    'and carries the catalogue',
    isset($exportTypes['video'], $exportTypes['category']),
    'an export missing the content is not a backup: got ' . implode(', ', array_keys($exportTypes))
);

/*
 * Dependency order. Anything reading this in one pass has to have seen a
 * category before the video that refers to it, or it has to buffer the whole
 * file — which is the problem the format exists to avoid, one step downstream.
 */
$firstVideoAt = null;
$lastCategoryAt = null;
foreach ($exportLines as $i => $line) {
    $row = json_decode($line, true);
    $type = is_array($row) ? ($row['type'] ?? '') : '';
    if ($type === 'category') {
        $lastCategoryAt = $i;
    }
    if ($type === 'video' && $firstVideoAt === null) {
        $firstVideoAt = $i;
    }
}

check(
    'Categories come before the videos that refer to them',
    $lastCategoryAt !== null && $firstVideoAt !== null && $lastCategoryAt < $firstVideoAt,
    'a one-pass reader would meet a video before the category it names'
);

/* Transcripts are the opt-in half, because they can dwarf everything else. */
check(
    'Transcripts are left out by default',
    !isset($exportTypes['transcript']),
    'the default download is fifty times bigger than it needs to be'
);

/*
 * Staged rather than assumed. The transcripts section earlier in this run
 * deletes the one it made and asserts the count is zero, so by here the library
 * genuinely has none — and a check that read that as "the feature is broken"
 * would be testing the state of the fixture, not the export.
 */
$db->execute(
    'INSERT INTO {transcripts} (video_id, body, source, cue_count, created_at, updated_at)
     VALUES (?, ?, ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE body = VALUES(body)',
    [$videoRow, 'A transcript kept only long enough to be exported.', 'smoke', 1]
);

$withText = getWithJar($baseUrl . '/admin/settings/content?transcripts=1', $jar);
check(
    'and included when asked for',
    str_contains($withText['body'], '"type":"transcript"'),
    'the button that promises transcripts does not deliver them'
);
check(
    'with the text in it',
    str_contains($withText['body'], 'kept only long enough to be exported'),
    'a transcript record with no transcript in it'
);

$db->execute('DELETE FROM {transcripts} WHERE video_id = ?', [$videoRow]);

/* ------------------------------------------------------------- restoring it
 *
 * The export shipped saying it was "a record, not a restore", because writing
 * an importer needed answers to real questions. Those are answered now, and the
 * whole feature only means anything as a ROUND TRIP — so this uploads the file
 * the previous checks just downloaded, through the actual form.
 */
$libraryFile = sys_get_temp_dir() . '/portal-smoke-library-' . getmypid() . '.ndjson';
file_put_contents($libraryFile, $exported['body']);

$settingsScreen = getWithJar($baseUrl . '/admin/settings', $jar);

check(
    'The settings screen offers to restore a library',
    str_contains($settingsScreen['body'], 'name="library"'),
    'a backup nothing can read back is a record, not a backup'
);

/*
 * Imported into the site it came from, so EVERYTHING collides. That is the
 * check worth having: the safety property is that nothing is overwritten, and
 * the strongest way to prove it is to import a file over its own source and
 * find the library unchanged.
 */
$before = (int) $db->value('SELECT COUNT(*) FROM {videos}');
$beforeTitle = (string) $db->value('SELECT title FROM {videos} WHERE id = ?', [$videoRow]);

$restored = uploadWithJar(
    $baseUrl . '/admin/settings/content/import',
    ['_token' => csrfFrom($settingsScreen['body'])],
    'library',
    $libraryFile,
    $jar,
    'application/x-ndjson'
);

check('Restoring a library is accepted', $restored['status'] === 302, "got {$restored['status']}");
check(
    'and importing over the same site adds nothing',
    (int) $db->value('SELECT COUNT(*) FROM {videos}') === $before,
    'every video was duplicated — the conflict check did not match'
);
check(
    'and changes nothing that was already here',
    (string) $db->value('SELECT title FROM {videos} WHERE id = ?', [$videoRow]) === $beforeTitle,
    'THE safety property: an import overwrote a library it was supposed to leave alone'
);
check(
    'and says what it skipped',
    str_contains(getWithJar($baseUrl . '/admin/settings', $jar)['body'], 'already here'),
    'a report of nothing done reads as the feature being broken'
);

/*
 * Now the restore that matters: into a site missing something. One category is
 * deleted and the same file imported again, which is the shape of recovering
 * from a mistake.
 */
$db->execute('DELETE FROM {categories} WHERE slug = ?', ['restore-me']);
$db->insert('categories', [
    'slug' => 'restore-me', 'name' => 'Restore Me', 'path' => '/', 'depth' => 0,
    'position' => 970, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);

$withNew = getWithJar($baseUrl . '/admin/settings/content', $jar);
file_put_contents($libraryFile, $withNew['body']);

$db->execute('DELETE FROM {categories} WHERE slug = ?', ['restore-me']);

uploadWithJar(
    $baseUrl . '/admin/settings/content/import',
    ['_token' => csrfFrom(getWithJar($baseUrl . '/admin/settings', $jar)['body'])],
    'library',
    $libraryFile,
    $jar,
    'application/x-ndjson'
);

$restoredId = (int) ($db->value('SELECT id FROM {categories} WHERE slug = ?', ['restore-me']) ?? 0);

check(
    'A deleted category comes back',
    $restoredId > 0,
    'the import reported success and restored nothing'
);
check(
    'and its path is rebuilt for its NEW id',
    (string) $db->value('SELECT path FROM {categories} WHERE id = ?', [$restoredId]) === '/' . $restoredId . '/',
    'a copied path points at the old site’s tree, and descendant lookups are a LIKE on that prefix'
);

/* A settings export is not a library, and saying so beats importing zero things. */
$wrongFile = sys_get_temp_dir() . '/portal-smoke-wrongfile-' . getmypid() . '.json';
file_put_contents($wrongFile, '{"settings":{"site_name":"Not a library"}}');

uploadWithJar(
    $baseUrl . '/admin/settings/content/import',
    ['_token' => csrfFrom(getWithJar($baseUrl . '/admin/settings', $jar)['body'])],
    'library',
    $wrongFile,
    $jar,
    'application/json'
);

check(
    'The wrong file is named as the wrong file',
    str_contains(getWithJar($baseUrl . '/admin/settings', $jar)['body'], 'Download the library'),
    '"imported 0 videos" reads as the feature being broken rather than the file being wrong'
);
check(
    'and it changed nothing',
    (int) $db->value('SELECT COUNT(*) FROM {videos}') === $before,
    'an unrelated file was allowed to write'
);

@unlink($libraryFile);
@unlink($wrongFile);
$db->execute('DELETE FROM {categories} WHERE slug = ?', ['restore-me']);

/*
 * The gate. This carries every unpublished and members-only video in the
 * library, so it is a site-owner action — checked from the single-capability
 * seat, which holds manage_videos and nothing else.
 */
clearLoginThrottle($db);
$exportJar = sys_get_temp_dir() . '/portal-smoke-export-' . getmypid() . '.txt';
@unlink($exportJar);

postWithJar($baseUrl . '/auth/login', [
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $exportJar)['body']),
    'email'    => 'nav-editor@smoke.test',
    'password' => 'nav-editor-password-1234',
], $exportJar);

check(
    'Somebody without manage_settings cannot download the library',
    getWithJar($baseUrl . '/admin/settings/content', $exportJar)['status'] === 403,
    'every unpublished video in the library behind an editor-level permission'
);

@unlink($exportJar);

/* -------------------------------------------------------- bulk actions
 *
 * The sharing screens have had these since Phase 2 and the video library never
 * has, so a site with four hundred videos publishes them one at a time.
 *
 * The checks that matter are the permission one — a bulk endpoint is where
 * somebody eventually forgets — and the one about adding a category ADDING it,
 * because a bulk button that silently replaced a video's taxonomy would be the
 * partial-save defect again, somewhere it destroys more than a flag.
 */
echo "\nBulk actions\n";

$bulkNow = date('Y-m-d H:i:s');
$bulkIds = [];
foreach (['one', 'two', 'three'] as $name) {
    $bulkIds[] = $db->insert('videos', [
        'provider' => 'bunny', 'provider_id' => 'smoke-bulk-' . $name,
        'slug' => 'bulk-' . $name, 'title' => 'Bulk ' . ucfirst($name),
        'status' => 'ready', 'is_published' => 0, 'duration' => 30,
        'created_at' => $bulkNow, 'updated_at' => $bulkNow,
    ]);
}

$bulkCategory = (int) $db->value('SELECT id FROM {categories} ORDER BY id LIMIT 1');
$db->execute(
    'INSERT INTO {video_categories} (video_id, category_id, is_primary, position) VALUES (?, ?, 1, 0)',
    [$bulkIds[0], $bulkCategory]
);

$bulkScreen = getWithJar($baseUrl . '/admin/videos', $jar);
check(
    'The video list offers a bulk bar',
    str_contains($bulkScreen['body'], 'name="selected[]"')
        && str_contains($bulkScreen['body'], 'name="bulk" value="publish"'),
    'the library still has to be published one row at a time'
);

$bulkToken = csrfFrom($bulkScreen['body']);

$published = postWithJar($baseUrl . '/admin/videos', [
    '_token'   => $bulkToken,
    'bulk'     => 'publish',
    'selected' => array_map('strval', $bulkIds),
], $jar);

check('Bulk publish is accepted', $published['status'] === 302, "got {$published['status']}");
check(
    'and every selected video is published',
    (int) $db->value(
        'SELECT COUNT(*) FROM {videos} WHERE id IN (' . implode(',', $bulkIds) . ') AND is_published = 1'
    ) === count($bulkIds),
    'the button reported success and changed nothing'
);
check(
    'and nothing outside the selection moved',
    (int) $db->value('SELECT is_published FROM {videos} WHERE id = ?', [$videoRow]) === 1,
    'a bulk action reached a video nobody ticked'
);

/*
 * Adding a category adds it. The first of these already sits in one, so if the
 * bulk action replaced rather than appended, that row would lose it — and a
 * taxonomy quietly deleted is not something anybody notices until much later.
 */
$second = (int) $db->value('SELECT id FROM {categories} ORDER BY id DESC LIMIT 1');

postWithJar($baseUrl . '/admin/videos', [
    '_token'        => $bulkToken,
    'bulk'          => 'categorise',
    'bulk_category' => (string) $second,
    'selected'      => [(string) $bulkIds[0]],
], $jar);

check(
    'Adding a category keeps the ones already there',
    (int) $db->value(
        'SELECT COUNT(*) FROM {video_categories} WHERE video_id = ?',
        [$bulkIds[0]]
    ) >= ($bulkCategory === $second ? 1 : 2),
    'the bulk button replaced the taxonomy instead of adding to it'
);

/* ---------------------------------------------------------- bulk tagging
 *
 * Tags could only be applied one video at a time, so labelling a back
 * catalogue meant opening every video in it. The bulk bar already had the
 * pattern; this is the same button for the same reason.
 */
$db->execute('DELETE FROM {taggables}');
$db->execute('DELETE FROM {tags}');

// One of them already carries a tag, so the ADD-not-replace rule has something
// to preserve — a check where every video starts empty cannot tell the two
// behaviours apart.
$tagged = postWithJar($baseUrl . '/admin/videos', [
    '_token'      => csrfFrom(getWithJar($baseUrl . '/admin/videos/' . $bulkIds[0], $jar)['body']),
    'action'      => 'save',
    'id'          => (string) $bulkIds[0],
    '_whole_form' => '1',
    'title'       => 'Bulk First',
    'tags'        => 'Existing',
], $jar);

check('A video can be tagged singly first', $tagged['status'] === 302, "got {$tagged['status']}");

$bulkScreenTags = getWithJar($baseUrl . '/admin/videos', $jar);

check(
    'The bulk bar offers tagging',
    str_contains($bulkScreenTags['body'], 'name="bulk_tags"'),
    'labelling a back catalogue means opening every video in it'
);
check(
    'and suggests tags that already exist',
    str_contains($bulkScreenTags['body'], 'id="tag-choices"'),
    'bulk is the fastest possible way to spread a near-duplicate across a library'
);

$bulkTagged = postWithJar($baseUrl . '/admin/videos', [
    '_token'    => csrfFrom($bulkScreenTags['body']),
    'bulk'      => 'tag',
    'bulk_tags' => 'Advent, Prayer',
    'selected'  => array_map('strval', $bulkIds),
], $jar);

check('Bulk tagging is accepted', $bulkTagged['status'] === 302, "got {$bulkTagged['status']}");
check(
    'and every selected video carries the new tags',
    (int) $db->value(
        'SELECT COUNT(DISTINCT taggable_id) FROM {taggables}
          WHERE taggable_type = "video" AND taggable_id IN (' . implode(',', $bulkIds) . ')'
    ) === count($bulkIds),
    'the button reported success and tagged nothing'
);
check(
    'and the one that was already tagged kept its tag',
    (int) $db->value(
        'SELECT COUNT(*) FROM {taggables} tg
           JOIN {tags} t ON t.id = tg.tag_id
          WHERE tg.taggable_id = ? AND t.slug = ?',
        [$bulkIds[0], 'existing']
    ) === 1,
    'bulk REPLACED the tags instead of adding — which wipes labelling nobody was looking at'
);

/* Nothing usable typed is refused, rather than clearing anything. */
$emptyTags = postWithJar($baseUrl . '/admin/videos', [
    '_token'    => csrfFrom($bulkScreenTags['body']),
    'bulk'      => 'tag',
    'bulk_tags' => '  !!! , ??? ',
    'selected'  => array_map('strval', $bulkIds),
], $jar);

check('Tagging with nothing usable is refused', $emptyTags['status'] === 302, "got {$emptyTags['status']}");
check(
    'and it changed nothing',
    (int) $db->value(
        'SELECT COUNT(*) FROM {taggables} tg JOIN {tags} t ON t.id = tg.tag_id WHERE t.slug = ?',
        ['existing']
    ) === 1,
    'a refused bulk action still touched the library'
);

$db->execute('DELETE FROM {taggables}');
$db->execute('DELETE FROM {tags}');

/* An empty selection is refused rather than treated as "all of them". */
$noneSelected = postWithJar($baseUrl . '/admin/videos', [
    '_token' => $bulkToken,
    'bulk'   => 'unpublish',
], $jar);

check(
    'An empty selection does nothing',
    (int) $db->value(
        'SELECT COUNT(*) FROM {videos} WHERE id IN (' . implode(',', $bulkIds) . ') AND is_published = 1'
    ) === count($bulkIds),
    'submitting with nothing ticked unpublished the library'
);

/* An unknown action must not fall through to something that acts. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'   => $bulkToken,
    'bulk'     => 'obliterate',
    'selected' => array_map('strval', $bulkIds),
], $jar);

check(
    'An unrecognised bulk action does nothing',
    (int) $db->value(
        'SELECT COUNT(*) FROM {videos} WHERE id IN (' . implode(',', $bulkIds) . ') AND deleted_at IS NULL'
    ) === count($bulkIds),
    'an unknown action fell through to a default that acted'
);

/*
 * The permission. Publishing is PUBLISH_CONTENT and not MANAGE_VIDEOS — the
 * single-capability editor holds the latter, so they may reach this screen and
 * must still be refused this button.
 */
clearLoginThrottle($db);
$bulkJar = sys_get_temp_dir() . '/portal-smoke-bulk-' . getmypid() . '.txt';
@unlink($bulkJar);

postWithJar($baseUrl . '/auth/login', [
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $bulkJar)['body']),
    'email'    => 'nav-editor@smoke.test',
    'password' => 'nav-editor-password-1234',
], $bulkJar);

$editorBulk = postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom(getWithJar($baseUrl . '/admin/videos', $bulkJar)['body']),
    'bulk'     => 'publish',
    'selected' => array_map('strval', $bulkIds),
], $bulkJar);

check(
    'Somebody who may edit but not publish is refused the bulk button',
    $editorBulk['status'] === 403,
    "got {$editorBulk['status']} — bulk publishing granted a permission the single-row button withholds"
);

/* And bulk trash works, which is also how these get cleaned up. */
postWithJar($baseUrl . '/admin/videos', [
    '_token'   => $bulkToken,
    'bulk'     => 'trash',
    'selected' => array_map('strval', $bulkIds),
], $jar);

check(
    'Bulk trash moves them all',
    (int) $db->value(
        'SELECT COUNT(*) FROM {videos} WHERE id IN (' . implode(',', $bulkIds) . ') AND deleted_at IS NOT NULL'
    ) === count($bulkIds),
    'the selection was not trashed'
);

foreach ($bulkIds as $id) {
    $db->execute('DELETE FROM {videos} WHERE id = ?', [$id]);
}
@unlink($bulkJar);

/* ---------------------------------------------------- category ordering
 *
 * CategoryRepository::reorder() has existed since Phase 1 with no caller, so
 * the `position` column the schema describes as "for manual ordering" has never
 * been orderable — every tree on every install sits in creation order.
 *
 * The repository half is covered by CategoryOrderTest, where the stored rows
 * can be read directly. These drive the buttons, because a move() nothing
 * presses is the thing being fixed.
 */
echo "\nCategory ordering\n";

$orderParent = $db->insert('categories', [
    'slug' => 'order-parent', 'name' => 'Order Parent', 'path' => '/', 'depth' => 0,
    'position' => 900, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);
$orderKids = [];
foreach (['first', 'second'] as $i => $name) {
    $orderKids[$name] = $db->insert('categories', [
        'slug' => 'order-' . $name, 'name' => 'Order ' . ucfirst($name),
        'parent_id' => $orderParent, 'path' => '/' . $orderParent . '/', 'depth' => 1,
        'position' => ($i + 1) * 10,
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

$categoryScreen = getWithJar($baseUrl . '/admin/categories', $jar);
check(
    'The categories screen offers ordering buttons',
    str_contains($categoryScreen['body'], 'name="action" value="up"'),
    'the position column has never been reachable from a screen'
);

$orderToken = csrfFrom($categoryScreen['body']);

postWithJar($baseUrl . '/admin/categories', [
    '_token' => $orderToken,
    'id'     => (string) $orderKids['second'],
    'action' => 'up',
], $jar);

check(
    'Moving one up reorders its siblings',
    (int) $db->value('SELECT position FROM {categories} WHERE id = ?', [$orderKids['second']])
        < (int) $db->value('SELECT position FROM {categories} WHERE id = ?', [$orderKids['first']]),
    'the button did nothing'
);

/*
 * At the end it says so rather than reporting a change. A button that appears
 * to do nothing is one somebody presses repeatedly.
 */
$atTheEnd = postWithJar($baseUrl . '/admin/categories', [
    '_token' => $orderToken,
    'id'     => (string) $orderKids['second'],
    'action' => 'up',
], $jar);

check(
    'and pressing it at the end says so',
    str_contains(getWithJar($baseUrl . '/admin/categories', $jar)['body'], 'already at the end'),
    'no feedback, so the button reads as broken'
);

/* The parent must not have been dragged into its children's ordering. */
check(
    'A move inside a parent leaves the parent alone',
    (int) $db->value('SELECT position FROM {categories} WHERE id = ?', [$orderParent]) === 900,
    'reordering children reached up and moved their parent'
);

foreach ($orderKids as $id) {
    $db->execute('DELETE FROM {categories} WHERE id = ?', [$id]);
}
$db->execute('DELETE FROM {categories} WHERE id = ?', [$orderParent]);

/* ------------------------------------------------------------- on air
 *
 * liveNow() has existed since Phase 5 with no caller and /live has never been
 * linked from anywhere, so a stream could be going out while every page on the
 * site looked exactly as it does on a Tuesday.
 *
 * The check that matters is the members-only one. A banner is the loudest thing
 * on the page, and announcing a private stream to a signed-out visitor would
 * leak both its existence and its title — which is the same rule the live page
 * already follows by answering 404 rather than 403.
 */
echo "\nOn air\n";

$liveStart = date('Y-m-d H:i:s', time() - 600);
$liveEnd = date('Y-m-d H:i:s', time() + 3600);

$publicStream = $db->insert('live_streams', [
    'slug' => 'smoke-on-air', 'title' => 'Sunday Service, Live',
    'embed_url' => 'https://example.test/embed', 'is_published' => 1, 'member_only' => 0,
    'starts_at' => $liveStart, 'ends_at' => $liveEnd,
    'created_at' => $liveStart, 'updated_at' => $liveStart,
]);

$homeLive = get($baseUrl . '/');
check(
    'A live stream puts a banner on the site',
    str_contains($homeLive['body'], 'live-banner')
        && str_contains($homeLive['body'], 'Sunday Service, Live'),
    'a stream can be going out with nothing on the site saying so'
);
check(
    'and it links to the stream',
    str_contains($homeLive['body'], '/live/smoke-on-air'),
    'the banner announces something with no way to reach it'
);
check(
    'and Live appears in the navigation',
    str_contains($homeLive['body'], '>Live now<') || str_contains($homeLive['body'], '/live"'),
    '/live has never been linked from anywhere'
);

/* It reaches an inside page too, not only the homepage. */
check(
    'The banner is on every page, not just the homepage',
    str_contains(get($baseUrl . '/watch/' . $videoSlug)['body'], 'live-banner')
        || str_contains(get($baseUrl . '/search')['body'], 'live-banner'),
    'somebody arriving from a search engine mid-service is told nothing'
);

/* The leak check. */
$db->execute('UPDATE {live_streams} SET member_only = 1 WHERE id = ?', [$publicStream]);

$anonHome = get($baseUrl . '/');
check(
    'A members-only stream is not announced to a signed-out visitor',
    !str_contains($anonHome['body'], 'Sunday Service, Live'),
    'the banner leaked the existence and the title of a private stream'
);
check(
    'but an approved viewer is told',
    str_contains(getWithJar($baseUrl . '/', $jar)['body'], 'Sunday Service, Live'),
    'the stream is invisible to the people it is for'
);

/* Nothing live means no banner and no navigation entry. */
$db->execute('DELETE FROM {live_streams} WHERE id = ?', [$publicStream]);

$quiet = get($baseUrl . '/');
check(
    'With nothing scheduled there is no banner',
    !str_contains($quiet['body'], 'live-banner'),
    'a permanent live banner is one nobody reads on the week it is true'
);
check(
    'and no Live link to an empty page',
    !str_contains($quiet['body'], '>Live now<'),
    'a link to "nothing scheduled" is one people stop clicking'
);

/* --------------------------------------------------------------- signup
 *
 * `allow_signup` has been a field on the Services screen since Phase 1 and
 * allowsSignup() was read by nothing, so an administrator could switch on "let
 * visitors create their own account" and no such thing existed.
 *
 * Two checks carry the weight: the switch has to actually gate it, and the form
 * must not become an oracle for which addresses have accounts — which is the
 * thing the magic-link gate has been built around avoiding since Phase 2, and
 * which a registration form asks more directly.
 */
echo "\nSignup\n";

check(
    'With the switch off there is no registration page',
    get($baseUrl . '/auth/register')['status'] === 404,
    'a setting somebody deliberately left off is being advertised'
);
check(
    'and the sign-in page does not offer one',
    !str_contains(get($baseUrl . '/auth/login')['body'], '/auth/register'),
    'a link to a page that 404s is worse than no link'
);

/* Switch it on the way the Services screen would. */
$localCreds = $db->value("SELECT credentials FROM {providers} WHERE kind = 'auth' AND slug = 'local'");
$db->execute(
    "UPDATE {providers} SET credentials = ? WHERE kind = 'auth' AND slug = 'local'",
    [(new \Portal\Support\Crypto((string) $written['app_key']))->encrypt(
        json_encode(['allow_signup' => '1', 'min_password_length' => '12'], JSON_UNESCAPED_SLASHES) ?: '{}'
    )]
);

check(
    'and the sign-in page links to it',
    str_contains(get($baseUrl . '/auth/login')['body'], '/auth/register'),
    'the only way in is to know the address'
);

/*
 * Fetched WITH the jar. The token is derived from the session id, so one taken
 * from a cookie-less GET belongs to a session the POST does not carry — which
 * is a 419 on every submission and six failures that look like the feature.
 */
$signupJar = sys_get_temp_dir() . '/portal-smoke-signup-' . getmypid() . '.txt';
@unlink($signupJar);

$signupPage = getWithJar($baseUrl . '/auth/register', $signupJar);
check('With it on the page appears', $signupPage['status'] === 200, "got {$signupPage['status']}");

$signupToken = csrfFrom($signupPage['body']);

/* The password rule applies here too. */
$weak = postWithJar($baseUrl . '/auth/register', [
    '_token'   => $signupToken,
    'email'    => 'newcomer@smoke.test',
    'password' => 'short',
], $signupJar);

check(
    'A weak password is refused at signup',
    str_contains($weak['body'], '12 characters'),
    'the rule is enforced on the change-password page and not here'
);
check(
    'and no account was made',
    (int) $db->value('SELECT COUNT(*) FROM {users} WHERE email = ?', ['newcomer@smoke.test']) === 0,
    'a refused registration created the account anyway'
);

$created = postWithJar($baseUrl . '/auth/register', [
    '_token'   => csrfFrom($signupPage['body']),
    'email'    => 'newcomer@smoke.test',
    'name'     => 'A Newcomer',
    'password' => 'a perfectly fine passphrase',
], $signupJar);

check('Signing up succeeds', $created['status'] === 200, "got {$created['status']}");

/*
 * Existence first, THEN the flag. Asking only whether authorized is zero is
 * satisfied by there being no account at all — (int) null is 0 — so the check
 * passes hardest exactly when the feature is most broken.
 */
$newcomerRow = $db->first('SELECT id, authorized FROM {users} WHERE email = ?', ['newcomer@smoke.test']);
check(
    'and creates an account nobody has approved',
    $newcomerRow !== null && (int) $newcomerRow['authorized'] === 0,
    $newcomerRow === null ? 'no account was created at all' : 'it was created already approved'
);
/*
 * Not signed in — asserted as "still anonymous", not as "no cookie".
 *
 * A session cookie IS set here, because verifying the CSRF token touches the
 * session, and it happens on both branches equally so it distinguishes
 * nothing. What must not differ is whether the response leaves somebody
 * AUTHENTICATED: signing in the created case and not the existing one is the
 * observable difference that would undo the identical body below.
 *
 * A signed-in unapproved account gets 403 and the pending page. An anonymous
 * visitor gets redirected to sign in. That is the distinction worth checking.
 */
check(
    'and does not sign them in',
    getWithJar($baseUrl . '/watch/' . $videoSlug, $signupJar)['status'] === 302,
    'registering authenticated somebody, which tells a prober which branch ran'
);

/*
 * The oracle check. An address that already exists must produce the same page
 * as one that does not — same status, same body — or this form answers "does
 * this person have an account here" for anybody who asks.
 */
$again = postWithJar($baseUrl . '/auth/register', [
    '_token'   => csrfFrom($signupPage['body']),
    'email'    => 'newcomer@smoke.test',
    'password' => 'a different fine passphrase',
], $signupJar);

/*
 * Identical AND successful. Comparing the two responses alone is satisfied by
 * both of them failing the same way — two 419s are byte-identical — so the
 * check would be at its happiest when nothing worked at all.
 */
check(
    'Registering a known address answers identically',
    $again['status'] === 200
        && $again['status'] === $created['status']
        && $again['body'] === $created['body'],
    'the form tells a stranger which addresses have accounts here'
);
check(
    'and does not touch the existing account',
    (int) $db->value('SELECT COUNT(*) FROM {users} WHERE email = ?', ['newcomer@smoke.test']) === 1,
    'a second registration overwrote somebody else\'s account'
);

/* The new account can sign in, and lands on the pending page with the ask. */
clearLoginThrottle($db);
$newcomerLogin = postWithJar($baseUrl . '/auth/login', [
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $signupJar)['body']),
    'email'    => 'newcomer@smoke.test',
    'password' => 'a perfectly fine passphrase',
], $signupJar);

check('The new account can sign in', $newcomerLogin['status'] === 302, "got {$newcomerLogin['status']}");
check(
    'and lands where it can ask for access',
    str_contains(getWithJar($baseUrl . '/watch/' . $videoSlug, $signupJar)['body'], 'action="/request-access"'),
    'sign up, then nothing — the three steps do not join up'
);

/* Put the provider back so nothing downstream sees signup enabled. */
$db->execute(
    "UPDATE {providers} SET credentials = ? WHERE kind = 'auth' AND slug = 'local'",
    [$localCreds]
);
$db->execute('DELETE FROM {users} WHERE email = ?', ['newcomer@smoke.test']);
@unlink($signupJar);

/* ------------------------------------------------------------------- tags
 *
 * `{tags}` and `{taggables}` have been in the schema since Phase 1 and nothing
 * ever touched them. The plan listed tags in the content model and the tables
 * were created, which is exactly what made the gap invisible: the schema said
 * the feature existed. Found by auditing columns against the code.
 *
 * Driven through the real form and the real page, because a repository with
 * full coverage and no form behind it is the defect this project has now found
 * sixteen times.
 */
echo "\nTags\n";

$tagEdit = getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar);

check(
    'The video edit screen offers a tag field',
    str_contains($tagEdit['body'], 'name="tags"'),
    'the tables exist and nothing can put anything in them'
);

$tagSaved = postWithJar($baseUrl . '/admin/videos', [
    '_token'      => csrfFrom($tagEdit['body']),
    'action'      => 'save',
    'id'          => (string) $videoRow,
    '_whole_form' => '1',
    'title'       => 'A Test Video',
    'categories'  => [(string) $categoryRow],
    'tags'        => 'Prayer, Advent, prayer',
], $jar);

check('Saving tags succeeds', $tagSaved['status'] === 302, "got {$tagSaved['status']}");
check(
    'and the duplicate spelling collapsed to one tag',
    (int) $db->value('SELECT COUNT(*) FROM {tags}') === 2,
    '"Prayer" and "prayer" became two tags, each linking to half the content'
);
check(
    'and the video carries both',
    (int) $db->value(
        'SELECT COUNT(*) FROM {taggables} WHERE taggable_type = "video" AND taggable_id = ?',
        [$videoRow]
    ) === 2,
    'the tags were created but attached to nothing'
);
check(
    'and the field comes back filled in',
    str_contains(getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar)['body'], 'Prayer'),
    'a field that saves and renders empty is one that deletes on the next save'
);

/* The public half: a tag has to be reachable, or it is a label nobody can use. */
$watchTagged = getWithJar($baseUrl . '/watch/' . $videoSlug, $jar);
check(
    'The watch page lists the tags',
    str_contains($watchTagged['body'], 'href="/tag/prayer"'),
    'tagged content with no link out is a filing system nobody can open'
);

$tagPage = get($baseUrl . '/tag/prayer');
check('The tag page renders', $tagPage['status'] === 200, "got {$tagPage['status']}");
check(
    'and lists the video',
    str_contains($tagPage['body'], 'A Test Video'),
    'the page rendered and the filter matched nothing'
);

check(
    'An unknown tag is a 404, not an empty page',
    get($baseUrl . '/tag/no-such-tag')['status'] === 404,
    'an empty page invites guessing slugs; a tag with nothing on it cannot exist here'
);

/*
 * The property that matters: a tag page is a listing like any other and must
 * not become a second route to content the ordinary rules hide. Tagging an
 * unpublished video must not publish it.
 */
$hiddenTagged = (int) $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-tag-hidden',
    'slug' => 'tagged-but-draft', 'title' => 'Tagged But Draft',
    'status' => 'ready', 'is_published' => 0, 'duration' => 10,
    'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
]);
$db->execute(
    'INSERT INTO {taggables} (tag_id, taggable_type, taggable_id)
     SELECT id, "video", ? FROM {tags} WHERE slug = ?',
    [$hiddenTagged, 'prayer']
);

check(
    'A tag page cannot surface an unpublished video',
    !str_contains(get($baseUrl . '/tag/prayer')['body'], 'Tagged But Draft'),
    'TAGGING PUBLISHED IT — the tag filter bypassed the listing rules'
);

/*
 * Removed through the repository, not with a raw DELETE.
 *
 * {taggables} is polymorphic, so it can carry no foreign key to {videos} and
 * no cascade — deleting the row by hand leaves the tag row behind, which keeps
 * the tag alive as a link to a page with nothing on it. forceDelete() clears
 * it, and driving the real path is what proves that.
 *
 * The first version of this used a raw DELETE and the check below failed,
 * which is how the missing cleanup was found at all.
 */
(new Portal\Content\VideoRepository($db, new Portal\Content\CategoryRepository($db)))
    ->forceDelete($hiddenTagged);

check(
    'and deleting a video takes its tag rows with it',
    (int) $db->value('SELECT COUNT(*) FROM {taggables} WHERE taggable_id = ?', [$hiddenTagged]) === 0,
    'no foreign key can do this, so it is code — and code is the half that gets forgotten'
);

/* ------------------------------------------------------- the tag vocabulary
 *
 * Tags shipped one commit before this screen did, and every repository method
 * behind it — withCounts, rename, delete, all, forItems — had its own passing
 * tests and no caller anywhere. The seventeenth instance of this project's
 * pattern, committed by the same hand that had spent the day finding the other
 * sixteen, which is the whole argument for auditing rather than remembering.
 *
 * So these drive the screen, not the repository.
 */
$tagAdmin = getWithJar($baseUrl . '/admin/tags', $jar);

check('The tag screen renders', $tagAdmin['status'] === 200, "got {$tagAdmin['status']}");
check(
    'and lists the tags with how much each carries',
    str_contains($tagAdmin['body'], 'Prayer') && str_contains($tagAdmin['body'], '1 item'),
    'a vocabulary screen that does not say what is used is one nobody can tidy with'
);
check(
    'and it is reachable from the sidebar',
    str_contains(getWithJar($baseUrl . '/admin/videos', $jar)['body'], '/admin/tags'),
    'a screen only findable by someone who read the source'
);
check(
    'and the video list shows each row its tags',
    str_contains(getWithJar($baseUrl . '/admin/videos', $jar)['body'], 'row-tags'),
    'how a library is labelled should be visible at a glance, not one video at a time'
);
check(
    'and the edit form suggests tags that already exist',
    str_contains(getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar)['body'], 'tag-choices'),
    'without seeing what exists, people invent "prayers" beside "prayer"'
);

/*
 * Renaming onto an existing tag MERGES. This is the behaviour the screen warns
 * about, so it had better be the behaviour it has.
 */
$tagMerged = postWithJar($baseUrl . '/admin/tags', [
    '_token' => csrfFrom($tagAdmin['body']),
    'action' => 'rename',
    'id'     => (string) $db->value('SELECT id FROM {tags} WHERE slug = ?', ['advent']),
    'name'   => 'Prayer',
], $jar);

check('Renaming a tag is accepted', $tagMerged['status'] === 302, "got {$tagMerged['status']}");
check(
    'and renaming onto an existing tag merged them',
    (int) $db->value('SELECT COUNT(*) FROM {tags}') === 1,
    'a unique-key failure instead of a merge leaves two spellings nobody can combine'
);
check(
    'and the video carries the surviving tag exactly once',
    (int) $db->value(
        'SELECT COUNT(*) FROM {taggables} WHERE taggable_type = "video" AND taggable_id = ?',
        [$videoRow]
    ) === 1,
    'the merge duplicated a row or left the old one behind'
);

/* Clearing the field removes the tags, and the tag itself stops existing. */
$tagCleared = postWithJar($baseUrl . '/admin/videos', [
    '_token'      => csrfFrom(getWithJar($baseUrl . '/admin/videos/' . $videoRow, $jar)['body']),
    'action'      => 'save',
    'id'          => (string) $videoRow,
    '_whole_form' => '1',
    'title'       => 'A Test Video',
    'categories'  => [(string) $categoryRow],
    'tags'        => '',
], $jar);

check('Clearing the tag field succeeds', $tagCleared['status'] === 302, "got {$tagCleared['status']}");
check(
    'and the tags nothing carries any more are gone',
    (int) $db->value('SELECT COUNT(*) FROM {tags}') === 0,
    'the vocabulary only grows, and every stale label is a link to an empty page'
);

/* ------------------------------------------------- failed videos, and recovery
 *
 * The sync job used to read one page of a hundred videos and mark everything
 * else failed, so on a library of any size there are rows saying "Failed" about
 * videos that are perfectly fine. The job is fixed, but the rows it already
 * wrote are still there, and nothing corrected them.
 *
 * Two halves, both driven through the screen: FINDING them (a status filter,
 * because scrolling a large library is not a way to find anything) and CLEARING
 * them (asking the provider about one video and believing the answer).
 */
echo "\nFailed videos\n";

$failedAt = date('Y-m-d H:i:s');
$failedId = (int) $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-failed-1',
    'slug' => 'wrongly-condemned', 'title' => 'Wrongly Condemned',
    'status' => 'failed', 'is_published' => 1, 'duration' => 42,
    'created_at' => $failedAt, 'updated_at' => $failedAt,
]);

$allVideos = getWithJar($baseUrl . '/admin/videos', $jar);

check(
    'The video list offers a status filter',
    str_contains($allVideos['body'], 'href="/admin/videos?status=failed"'),
    'a failed video is findable only by scrolling, which on a real library means not at all'
);
check(
    'and the failed tab carries its count',
    str_contains($allVideos['body'], 'Failed (1)'),
    'a tab with no number gives nobody a reason to press it — and not knowing is the whole problem'
);

$failedOnly = getWithJar($baseUrl . '/admin/videos?status=failed', $jar);

check(
    'Filtering to failed shows the failed video',
    str_contains($failedOnly['body'], 'Wrongly Condemned'),
    "got {$failedOnly['status']}"
);
check(
    'and hides the ones that are fine',
    !str_contains($failedOnly['body'], 'A Test Video'),
    'the filter rendered but did not filter'
);

/*
 * The safety property, driven publicly: a failed video must not appear on the
 * public site whatever the query string says. `status` narrows an admin listing
 * and can never widen a visitor's.
 */
check(
    'and a failed video stays off the public site',
    !str_contains(get($baseUrl . '/?status=failed')['body'], 'Wrongly Condemned'),
    'a query parameter put a broken video on the homepage'
);

/*
 * Re-check, against a provider that cannot be reached.
 *
 * This install carries placeholder bunny.net credentials, so getVideo() makes a
 * real outbound call that fails. That is not a limitation of the check — it is
 * the single most important of the four outcomes, and the only one this
 * environment can stage honestly.
 *
 * "We could not ask" and "it is gone" look identical to a caught exception and
 * lead to opposite actions. What must be true here is that NOTHING was written:
 * a network failure may not condemn a video, and may not quietly mark a healthy
 * one as broken. The provider-said-404 and provider-said-ready paths are
 * covered in ContentTest, where the provider can be made to answer.
 *
 * The first version of these checks asserted the 404 outcome, on the mistaken
 * belief that this script installs a fake provider. It installs the real one.
 */
check(
    'A video that is not ready offers a re-check button',
    str_contains($failedOnly['body'], 'value="recheck"'),
    'no way to ask the provider, so a wrongly-failed video stays failed forever'
);
check(
    'and a ready video does not',
    !str_contains(getWithJar($baseUrl . '/admin/videos?status=ready', $jar)['body'], 'value="recheck"'),
    'a button on every row to serve the rows that have nothing to say'
);

/*
 * Moved to `processing` before the re-check, so the outcome is a real
 * TRANSITION.
 *
 * Re-checking a video that is already failed and asserting it is still failed
 * passes just as happily when the handler does nothing at all — the exact shape
 * of vacuous assertion this project keeps finding. processing -> failed can
 * only happen if the provider was asked and the answer was written.
 */
$db->execute('UPDATE {videos} SET status = ? WHERE id = ?', ['processing', $failedId]);

$rechecked = postWithJar($baseUrl . '/admin/videos', [
    '_token' => csrfFrom($failedOnly['body']),
    'action' => 'recheck',
    'id'     => (string) $failedId,
], $jar);

check('Re-checking a video is accepted', $rechecked['status'] === 302, "got {$rechecked['status']}");
check(
    'and an unreachable provider changes nothing',
    (string) $db->value('SELECT status FROM {videos} WHERE id = ?', [$failedId]) === 'processing',
    'a network failure was treated as a verdict about the video'
);
check(
    'and nothing was written to the audit log',
    (int) $db->value('SELECT COUNT(*) FROM {audit_log} WHERE action = ?', ['video.recheck']) === 0,
    'an attempt that changed nothing was recorded as though it had'
);
check(
    'and the screen says it could not ask, rather than giving a verdict',
    str_contains(getWithJar($baseUrl . '/admin/videos?status=processing', $jar)['body'], 'Could not reach'),
    'the one message that must not be rounded off into "this video is gone"'
);

$db->execute('DELETE FROM {videos} WHERE id = ?', [$failedId]);

/* ------------------------------------------------------------ scoped grants
 *
 * Phase 1's verification list, item 8: "Grant manage_videos scoped to one
 * category; confirm edit works inside it and 403s outside it."
 *
 * Until now it 403'd in BOTH directions. Scoped grants have been storable since
 * Phase 1 — the permissions screen has had a "Limited to" dropdown all along —
 * and no check anywhere passed a scope, so `Capabilities::resolve()` was only
 * ever asked the site-wide question, which it answers false for a grant on a
 * category. The holder could enter the admin area, because canSeeAdmin() has
 * always matched a grant regardless of its scope, and was then refused every
 * screen inside it.
 *
 * This runs over real HTTP because that is the only thing here that can tell
 * "the resolver is correct" from "a person can do this". CapabilitiesTest has
 * covered the resolver since Phase 1 and passed throughout the whole period the
 * feature did not work.
 */
echo "\nScoped grants\n";

$scopeAt = date('Y-m-d H:i:s');

$scopeInside = (int) $db->insert('categories', [
    'slug' => 'scope-inside', 'name' => 'Scope Inside', 'path' => '/', 'depth' => 0,
    'position' => 950, 'created_at' => $scopeAt, 'updated_at' => $scopeAt,
]);
$scopeOutside = (int) $db->insert('categories', [
    'slug' => 'scope-outside', 'name' => 'Scope Outside', 'path' => '/', 'depth' => 0,
    'position' => 951, 'created_at' => $scopeAt, 'updated_at' => $scopeAt,
]);
/* A child of the granted category, to prove inheritance over HTTP too. */
$scopeChild = (int) $db->insert('categories', [
    'slug' => 'scope-child', 'name' => 'Scope Child', 'parent_id' => $scopeInside,
    'path' => '/' . $scopeInside . '/', 'depth' => 1, 'position' => 10,
    'created_at' => $scopeAt, 'updated_at' => $scopeAt,
]);

$scopeVideos = [];
foreach (['inside' => $scopeChild, 'outside' => $scopeOutside] as $where => $categoryId) {
    $scopeVideos[$where] = (int) $db->insert('videos', [
        'provider' => 'bunny', 'provider_id' => 'smoke-scope-' . $where,
        'slug' => 'scope-' . $where, 'title' => 'Scope ' . ucfirst($where),
        'status' => 'ready', 'is_published' => 1, 'duration' => 60,
        'created_at' => $scopeAt, 'updated_at' => $scopeAt,
    ]);
    $db->insert('video_categories', [
        'video_id' => $scopeVideos[$where], 'category_id' => $categoryId, 'is_primary' => 1,
    ]);
}

/*
 * The viewer role, so the account holds nothing site-wide. An editor role would
 * make every check below pass for the wrong reason.
 */
$db->insert('users', [
    'email' => 'scoped@smoke.test', 'name' => 'Scoped Editor',
    'authorized' => 1,
    'role_id' => (int) $db->value('SELECT id FROM {roles} WHERE slug = ?', ['viewer']),
    'password_hash' => password_hash('scoped-editor-password-1234', PASSWORD_DEFAULT),
    'created_at' => $scopeAt, 'updated_at' => $scopeAt,
]);
$scopedUserId = (int) $db->value('SELECT id FROM {users} WHERE email = ?', ['scoped@smoke.test']);

$db->execute(
    'INSERT INTO {grants} (subject_type, subject_id, email, capability_id, scope_type, scope_id, created_at)
     VALUES ("user", ?, "", (SELECT id FROM {capabilities} WHERE slug = ?), "category", ?, NOW())',
    [$scopedUserId, 'manage_videos', $scopeInside]
);

$scopeJar = sys_get_temp_dir() . '/portal-smoke-scope-' . getmypid() . '.txt';
@unlink($scopeJar);

clearLoginThrottle($db);
$scopeLogin = postWithJar($baseUrl . '/auth/login', [
    'email'    => 'scoped@smoke.test',
    'password' => 'scoped-editor-password-1234',
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $scopeJar)['body']),
], $scopeJar);

check('A category-scoped editor can sign in', $scopeLogin['status'] === 302, "got {$scopeLogin['status']}");

/*
 * The listing first, because it is the only route to the videos they hold. If
 * this 403s, every check below is unreachable in practice and the ones that
 * pass would be measuring nothing.
 */
$scopeList = getWithJar($baseUrl . '/admin/videos', $scopeJar);
check(
    'and reaches the video list on a scoped grant alone',
    $scopeList['status'] === 200,
    "got {$scopeList['status']} — a grant they cannot act on is a grant that does not exist"
);
check(
    'and their sidebar has the section it lives in',
    str_contains($scopeList['body'], '/admin/videos'),
    'the admin area opened onto nothing'
);
/*
 * Screens with no scoped form must NOT appear. A playlist and a live stream are
 * not values of grants.scope_type, so there is no such thing as a grant on one
 * and the link would land on a 403 — which reads as a broken site rather than a
 * boundary. This is the check that stops canAnywhere() being applied blindly.
 */
check(
    'while screens that have no scoped form stay hidden',
    !str_contains($scopeList['body'], '/admin/playlists')
        && !str_contains($scopeList['body'], '/admin/live'),
    'a link that 403s for everybody holding only a scope'
);

/*
 * The claim itself, both directions.
 *
 * Every CSRF token below comes from the listing, which is asserted 200 just
 * above, rather than from whichever page the check is about. A token read out
 * of a page that 404'd is empty, and the POST then answers 419 — so a routing
 * mistake would be reported here as a CSRF failure, which is the wrong place to
 * start looking.
 */
$editInside = getWithJar($baseUrl . '/admin/videos/' . $scopeVideos['inside'], $scopeJar);
check(
    'Editing a video inside the granted category is allowed',
    $editInside['status'] === 200,
    "got {$editInside['status']} — item 8 of the Phase 1 verification list"
);
check(
    'and inheritance carried it down to a sub-category',
    $editInside['status'] === 200 && str_contains($editInside['body'], 'Scope Inside'),
    'the video sits in a CHILD of the granted category; a grant that stops at one level is not inheritance'
);

$editOutside = getWithJar($baseUrl . '/admin/videos/' . $scopeVideos['outside'], $scopeJar);
check(
    'and a video outside it is refused',
    $editOutside['status'] === 403,
    "got {$editOutside['status']} — 200 means the scope is decorative"
);

/*
 * Reading is not the boundary that matters; saving is. Checked separately
 * because the edit screen and the save handler are different methods, and the
 * one that writes is the one worth being sure about.
 */
$scopeSaveOutside = postWithJar($baseUrl . '/admin/videos', [
    '_token'      => csrfFrom($scopeList['body']),
    'action'      => 'save',
    'id'          => (string) $scopeVideos['outside'],
    '_whole_form' => '1',
    'title'       => 'Renamed by somebody without permission',
], $scopeJar);

check(
    'Saving a video outside the scope is refused',
    $scopeSaveOutside['status'] === 403,
    "got {$scopeSaveOutside['status']}"
);
check(
    'and the refusal actually prevented the write',
    (string) $db->value('SELECT title FROM {videos} WHERE id = ?', [$scopeVideos['outside']]) === 'Scope Outside',
    'a 403 that still saved — the status is not the assertion'
);

/*
 * `categories[]` is sent deliberately. `_whole_form` means "this form carries
 * everything", so omitting it CLEARS the video's categories — and a video with
 * no category has left the granted scope, which the checks further down would
 * then report as the scope refusing a video it holds.
 *
 * That is how the destination check below was found: this POST silently moved
 * the video out of scope, and the bulk check at the end failed for a reason
 * that had nothing to do with bulk.
 */
$scopeSaveInside = postWithJar($baseUrl . '/admin/videos', [
    '_token'       => csrfFrom($scopeList['body']),
    'action'       => 'save',
    'id'           => (string) $scopeVideos['inside'],
    '_whole_form'  => '1',
    'title'        => 'Renamed from inside the scope',
    'categories'   => [(string) $scopeChild],
], $scopeJar);

check(
    'Saving a video inside the scope is allowed',
    $scopeSaveInside['status'] === 302,
    "got {$scopeSaveInside['status']}"
);
check(
    'and the change was written',
    (string) $db->value('SELECT title FROM {videos} WHERE id = ?', [$scopeVideos['inside']])
        === 'Renamed from inside the scope',
    'a 302 that saved nothing reads as success'
);

/*
 * The one way a scope can be used to reach OUTSIDE itself: hold one category,
 * and move a video you hold into somebody else's. Filing needs permission on
 * where it is going, not only on the thing being filed — the same rule as
 * reparenting a category and as the bulk categorise button.
 */
$scopeReloc = postWithJar($baseUrl . '/admin/videos', [
    '_token'      => csrfFrom($scopeList['body']),
    'action'      => 'save',
    'id'          => (string) $scopeVideos['inside'],
    '_whole_form' => '1',
    'title'       => 'Renamed from inside the scope',
    'categories'  => [(string) $scopeChild, (string) $scopeOutside],
], $scopeJar);

check(
    'Filing a video into a category outside the scope is refused',
    $scopeReloc['status'] === 403,
    "got {$scopeReloc['status']} — a scope you can move things out of is not a boundary"
);
check(
    'and the video stayed where it was',
    (int) $db->value(
        'SELECT COUNT(*) FROM {video_categories} WHERE video_id = ? AND category_id = ?',
        [$scopeVideos['inside'], $scopeOutside]
    ) === 0,
    'the refusal came after the write'
);

/*
 * Publishing is a separate capability, and the scoped grant did not include it.
 * A scope that quietly widened the capabilities it carries would be worse than
 * no scope at all.
 */
$scopePublish = postWithJar($baseUrl . '/admin/videos', [
    '_token' => csrfFrom($scopeList['body']),
    'action' => 'publish',
    'id'     => (string) $scopeVideos['inside'],
], $scopeJar);

check(
    'A scope does not confer capabilities it was not granted',
    $scopePublish['status'] === 403,
    "got {$scopePublish['status']} — manage_videos in a category is not publish_content in it"
);

/*
 * Bulk is the endpoint where this is easiest to get wrong, because one press
 * covers many objects. It must do exactly what pressing every single-row button
 * would have done: act on the rows they hold, refuse the rest, and say so.
 */
$scopeBulk = postWithJar($baseUrl . '/admin/videos', [
    '_token'   => csrfFrom($scopeList['body']),
    'bulk'     => 'trash',
    'selected' => [(string) $scopeVideos['inside'], (string) $scopeVideos['outside']],
], $scopeJar);

check('A bulk action from a scoped editor is accepted', $scopeBulk['status'] === 302, "got {$scopeBulk['status']}");
check(
    'and it trashed the video inside the scope',
    $db->value('SELECT deleted_at FROM {videos} WHERE id = ?', [$scopeVideos['inside']]) !== null,
    'the rows they DO hold were refused along with the rest'
);
check(
    'and left the one outside it alone',
    $db->value('SELECT deleted_at FROM {videos} WHERE id = ?', [$scopeVideos['outside']]) === null,
    'bulk is a way around the per-object check — the whole library, not one row'
);

/* ------------------------------------------------------- member sharing
 *
 * share_content is the members' half of sharing: hand out a link to one video
 * you can already watch, and revoke your own. Deliberately NOT manage_shares,
 * which reaches every link on the site.
 *
 * Reuses the scoped editor above because the property worth proving is the
 * scoping — a grant on one category must not become permission to share the
 * whole library. The bulk action just trashed the inside video, so it is put
 * back first.
 */
echo "\nMember sharing\n";

$db->execute(
    'UPDATE {videos} SET deleted_at = NULL, is_published = 1, status = "ready" WHERE id IN (?, ?)',
    [$scopeVideos['inside'], $scopeVideos['outside']]
);

$db->execute(
    'INSERT INTO {grants} (subject_type, subject_id, email, capability_id, scope_type, scope_id, created_at)
     VALUES ("user", ?, "", (SELECT id FROM {capabilities} WHERE slug = ?), "category", ?, NOW())',
    [$scopedUserId, 'share_content', $scopeInside]
);

$shareJar = sys_get_temp_dir() . '/portal-smoke-membershare-' . getmypid() . '.txt';
@unlink($shareJar);
clearLoginThrottle($db);
postWithJar($baseUrl . '/auth/login', [
    'email'    => 'scoped@smoke.test',
    'password' => 'scoped-editor-password-1234',
    '_token'   => csrfFrom(getWithJar($baseUrl . '/auth/login', $shareJar)['body']),
], $shareJar);

$insideSlug = (string) $db->value('SELECT slug FROM {videos} WHERE id = ?', [$scopeVideos['inside']]);
$outsideSlug = (string) $db->value('SELECT slug FROM {videos} WHERE id = ?', [$scopeVideos['outside']]);

$insideWatch = getWithJar($baseUrl . '/watch/' . $insideSlug, $shareJar);
check(
    'The share panel appears on a video inside the grant',
    str_contains($insideWatch['body'], 'action="/share/create"'),
    'a capability with no way to use it is the defect this repeats'
);

$outsideWatch = getWithJar($baseUrl . '/watch/' . $outsideSlug, $shareJar);
check(
    'and not on one outside it',
    !str_contains($outsideWatch['body'], 'action="/share/create"'),
    'a grant on one category is not permission to share the library'
);

/* Creating inside the scope works. */
$makeShare = postWithJar($baseUrl . '/share/create', [
    '_token'      => csrfFrom($insideWatch['body']),
    'video_id'    => (string) $scopeVideos['inside'],
    'email'       => 'friend@smoke.test',
    'access_mode' => 'gate',
    'hours'       => '48',
], $shareJar);
check('A member can create a share inside their scope', $makeShare['status'] === 302, "got {$makeShare['status']}");
check(
    'and the link is recorded as theirs',
    (int) $db->value(
        'SELECT COUNT(*) FROM {shares} WHERE created_by = ? AND video_id = ?',
        ['scoped@smoke.test', $scopeVideos['inside']]
    ) === 1,
    'created_by is what their own list and the admin list both read'
);

/*
 * The boundary. A hidden form is not a permission check, so this posts
 * directly for the video the panel refused to offer.
 */
$outsideShare = postWithJar($baseUrl . '/share/create', [
    '_token'   => csrfFrom($insideWatch['body']),
    'video_id' => (string) $scopeVideos['outside'],
    'email'    => 'friend@smoke.test',
], $shareJar);
check(
    'Sharing a video outside the scope is refused',
    $outsideShare['status'] === 403,
    "got {$outsideShare['status']}"
);
check(
    'and nothing was written',
    (int) $db->value(
        'SELECT COUNT(*) FROM {shares} WHERE video_id = ? AND created_by = ?',
        [$scopeVideos['outside'], 'scoped@smoke.test']
    ) === 0,
    'the refusal was cosmetic'
);

/* Their own list, and revoking from it. */
$myLinks = getWithJar($baseUrl . '/account/shared-links', $shareJar);
check(
    'Their shared links are listed on their account',
    $myLinks['status'] === 200 && str_contains($myLinks['body'], 'friend@smoke.test'),
    "got {$myLinks['status']}"
);

$mineId = (string) $db->value(
    'SELECT id FROM {shares} WHERE created_by = ? LIMIT 1',
    ['scoped@smoke.test']
);
postWithJar($baseUrl . '/share/revoke', [
    '_token' => csrfFrom($myLinks['body']),
    'id'     => $mineId,
], $shareJar);
check(
    'They can revoke a link they made',
    $db->value('SELECT revoked_at FROM {shares} WHERE id = ?', [$mineId]) !== null,
    'revoking your own link is the point of the list'
);

/*
 * And cannot revoke somebody else's. The id is the only thing the form
 * carries, and it names a stranger's link just as well as your own.
 */
$adminMade = (string) $db->value(
    'SELECT id FROM {shares} WHERE revoked_at IS NULL AND (created_by IS NULL OR created_by <> ?) LIMIT 1',
    ['scoped@smoke.test']
);
postWithJar($baseUrl . '/share/revoke', [
    '_token' => csrfFrom(getWithJar($baseUrl . '/account/shared-links', $shareJar)['body']),
    'id'     => $adminMade,
], $shareJar);
check(
    "They cannot revoke somebody else's link",
    $adminMade !== '' && $db->value('SELECT revoked_at FROM {shares} WHERE id = ?', [$adminMade]) === null,
    'the id alone was enough to switch off a link they did not make'
);

/* ------------------------------------------------------ offline downloads
 *
 * Two independent gates, and the whole design is that BOTH have to say yes:
 * download_content says WHO may take a copy, DownloadPolicy says WHAT may be
 * taken. Either one alone is not permission, and the checks below turn each on
 * separately to prove the other still refuses.
 *
 * Reuses the scoped user from member sharing above, because the property most
 * worth proving is the same one: a grant on ONE category is not permission to
 * download the library. It is more consequential here — a share link expires
 * and can be revoked, a downloaded file can be neither.
 */
echo "\nOffline downloads\n";

$dlInside = getWithJar($baseUrl . '/watch/' . $insideSlug, $shareJar);
check(
    'No download link before anything is granted',
    !str_contains($dlInside['body'], '/download/' . $insideSlug . '.mp4'),
    'a control that 403s reads as a broken site rather than a setting'
);

$dlRefused = getWithJar($baseUrl . '/download/' . $insideSlug . '.mp4', $shareJar);
check(
    'and the route refuses without the capability',
    $dlRefused['status'] === 403,
    "got {$dlRefused['status']} — a hidden link is not a permission check"
);

/*
 * The capability alone. Nothing has said this video may be downloaded, so the
 * answer must still be no — this is the check that would pass just as happily
 * against an implementation that forgot the content policy entirely.
 */
$db->execute(
    'INSERT INTO {grants} (subject_type, subject_id, email, capability_id, scope_type, scope_id, created_at)
     VALUES ("user", ?, "", (SELECT id FROM {capabilities} WHERE slug = ?), "category", ?, NOW())',
    [$scopedUserId, 'download_content', $scopeInside]
);

$dlCapOnly = getWithJar($baseUrl . '/download/' . $insideSlug . '.mp4', $shareJar);
check(
    'The capability alone is not enough',
    $dlCapOnly['status'] === 403,
    "got {$dlCapOnly['status']} — permission to download is not permission to download THIS"
);
check(
    'and the refusal says it is the content setting, not the person',
    str_contains($dlCapOnly['body'], 'turned off for this video'),
    'two refusals that read alike send somebody to the wrong screen'
);

/* Now the content half, on the video itself. */
$db->execute("UPDATE {videos} SET download_mode = 'allow' WHERE id = ?", [$scopeVideos['inside']]);

$dlAllowed = getWithJar($baseUrl . '/watch/' . $insideSlug, $shareJar);
check(
    'With both halves granted the link appears',
    str_contains($dlAllowed['body'], '/download/' . $insideSlug . '.mp4'),
    'a capability with no way to use it is the defect this project keeps repeating'
);

$dlGo = getWithJar($baseUrl . '/download/' . $insideSlug . '.mp4', $shareJar);
check(
    'and the download redirects to a signed URL',
    $dlGo['status'] === 302 && str_contains($dlGo['headers']['location'] ?? '', 'token='),
    "got {$dlGo['status']}, went to " . ($dlGo['headers']['location'] ?? 'nowhere')
);
check(
    'and it is not cacheable',
    str_contains(strtolower($dlGo['headers']['cache-control'] ?? ''), 'no-store'),
    'a cached redirect outlives the signature it carries'
);
check(
    'and the download is in the audit log',
    (int) $db->value(
        'SELECT COUNT(*) FROM {audit_log} WHERE action = ? AND target_id = ?',
        ['video.download', (string) $scopeVideos['inside']]
    ) === 1,
    'a download outlives the session, so who took a copy has to be answerable later'
);

/*
 * The boundary, posted directly. The video OUTSIDE the grant is set to allow
 * downloads, so the only thing refusing is the scope on the capability.
 */
$db->execute("UPDATE {videos} SET download_mode = 'allow' WHERE id = ?", [$scopeVideos['outside']]);

$dlOutside = getWithJar($baseUrl . '/download/' . $outsideSlug . '.mp4', $shareJar);
check(
    'A video outside the grant is refused even when it allows downloads',
    $dlOutside['status'] === 403,
    "got {$dlOutside['status']} — a grant on one category reached the whole library"
);

/*
 * A blocking series closes an episode its own video row opened, which is the
 * only ordering in the chain that is not obvious. The video says allow; the
 * series says block; the video wins — so the video is set back to inherit
 * first, and THEN the series decides.
 */
$db->execute("UPDATE {videos} SET download_mode = 'default' WHERE id = ?", [$scopeVideos['inside']]);
$db->execute(
    "UPDATE {categories} SET download_mode = 'allow' WHERE id = ?",
    [$scopeInside]
);

$dlByCategory = getWithJar($baseUrl . '/download/' . $insideSlug . '.mp4', $shareJar);
check(
    'A category can allow downloads for everything in it',
    $dlByCategory['status'] === 302,
    "got {$dlByCategory['status']}"
);

/*
 * The series is STAGED rather than taken from whatever the fixture happens to
 * have. The first version of this read `series_id` off the video and skipped
 * when it was null — which is how a check that never runs comes to look
 * exactly like one that passed. It was null, and the level this section exists
 * to prove went unexercised while the section reported green.
 */
$dlPriorSeries = $db->value('SELECT series_id FROM {videos} WHERE id = ?', [$scopeVideos['inside']]);
$dlSeriesId = $db->insert('series', [
    'slug'          => 'download-order-' . bin2hex(random_bytes(3)),
    'title'         => 'Download ordering',
    'download_mode' => 'block',
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);
$db->execute('UPDATE {videos} SET series_id = ? WHERE id = ?', [$dlSeriesId, $scopeVideos['inside']]);

$dlBlocked = getWithJar($baseUrl . '/download/' . $insideSlug . '.mp4', $shareJar);
check(
    'and a blocking series overrides an allowing category',
    $dlBlocked['status'] === 403,
    "got {$dlBlocked['status']} — the series sits above the categories"
);

/* And the video's own setting outranks the series that just blocked it. */
$db->execute("UPDATE {videos} SET download_mode = 'allow' WHERE id = ?", [$scopeVideos['inside']]);
$dlVideoWins = getWithJar($baseUrl . '/download/' . $insideSlug . '.mp4', $shareJar);
check(
    'and the video itself outranks the series',
    $dlVideoWins['status'] === 302,
    "got {$dlVideoWins['status']} — most-specific-first stops at the video"
);

$db->execute(
    "UPDATE {videos} SET series_id = ?, download_mode = 'default' WHERE id = ?",
    [$dlPriorSeries, $scopeVideos['inside']]
);
$db->execute('DELETE FROM {series} WHERE id = ?', [$dlSeriesId]);

/*
 * The JSON half, which is what actually saves a video for offline viewing.
 *
 * It exists because fetch() following a cross-origin 302 will not say where it
 * landed, and putting the file in Cache Storage needs the URL. That makes it a
 * second door to the same file, so the checks below are the same boundary
 * checks as above — a JSON endpoint that hands out a signed URL for something
 * the redirect refuses is the whole file, without the refusal.
 */
$dlJson = getWithJar(
    $baseUrl . '/download/' . $insideSlug . '.json',
    $shareJar,
    ['Accept: application/json']
);
$dlMeta = json_decode($dlJson['body'], true);

check('The offline endpoint answers', $dlJson['status'] === 200, "got {$dlJson['status']}");
check(
    'and carries a signed URL and a cache key',
    is_array($dlMeta)
        && str_contains((string) ($dlMeta['url'] ?? ''), 'token=')
        && str_starts_with((string) ($dlMeta['cacheKey'] ?? ''), '/offline-video/'),
    'the browser cannot save what it cannot address: ' . substr($dlJson['body'], 0, 120)
);
check(
    'and the cache key names the video rather than the slug',
    ($dlMeta['cacheKey'] ?? '') === '/offline-video/' . $scopeVideos['inside'] . '.mp4',
    'a renamed video must not orphan a file somebody already saved'
);

$dlJsonOutside = getWithJar(
    $baseUrl . '/download/' . $outsideSlug . '.json',
    $shareJar,
    ['Accept: application/json']
);
check(
    'The offline endpoint refuses outside the grant, like the redirect',
    $dlJsonOutside['status'] === 403,
    "got {$dlJsonOutside['status']} — a second door with a weaker lock"
);
check(
    'and refuses as JSON rather than a sign-in page',
    str_contains($dlJsonOutside['body'], '"error"'),
    'an HTML page returned to fetch() parses as a broken feature, not a refusal'
);

/* Signed out, the route is a sign-in redirect rather than a file. */
$dlAnon = get($baseUrl . '/download/' . $insideSlug . '.mp4');
check(
    'Downloading signed out is refused',
    $dlAnon['status'] === 302 && !str_contains($dlAnon['headers']['location'] ?? '', 'b-cdn.net'),
    'went to ' . ($dlAnon['headers']['location'] ?? 'nowhere')
);

/* Put the fixtures back for anything downstream. */
$db->execute(
    "UPDATE {videos} SET download_mode = 'default' WHERE id IN (?, ?)",
    [$scopeVideos['inside'], $scopeVideos['outside']]
);
$db->execute("UPDATE {categories} SET download_mode = 'default' WHERE id = ?", [$scopeInside]);

@unlink($shareJar);
@unlink($scopeJar);

/* ------------------------------------------------------------ what's new
 *
 * The first plugin that decorates a LISTING rather than the watch page, which
 * makes it the first test of the badge slot on the card partial. Until that
 * existed a plugin could add a key to a card through `video_list` and nothing
 * would ever render it.
 *
 * Placed here because it needs videos with controlled publication dates and
 * nothing downstream reads them.
 */
echo "\nWhat's new\n";

$wnPage = getWithJar($baseUrl . '/admin/whats-new', $jar);
check("The what's new settings page renders", $wnPage['status'] === 200, "got {$wnPage['status']}");
check(
    'and it says signed-out visitors get nothing',
    str_contains($wnPage['body'], 'Signed-in people only'),
    'a limit nobody is told about reads as the feature being broken'
);

/*
 * A label nothing else on the page uses.
 *
 * "New" appears in half a dozen places in this application — a button, a
 * heading, a status. A check counting occurrences of it would be counting the
 * rest of the page, which is the mistake the up-next checks made with a video
 * title and got away with until the layout moved.
 */
$wnSaved = postWithJar($baseUrl . '/admin/whats-new', [
    '_token'       => csrfFrom($wnPage['body']),
    'label'        => 'SMOKEFRESH',
    'horizon_days' => '30',
], $jar);
check('Saving the label succeeds', $wnSaved['status'] === 302, "got {$wnSaved['status']}");

/*
 * Both pinned, so both are certainly on page one.
 *
 * The listing is newest-first and this database has a run's worth of videos in
 * it by now; a fixture backdated two months would otherwise fall off the end,
 * and a check that cannot find the card it is asking about passes for the wrong
 * reason. Both are deleted at the end of the section.
 */
$wnNow = time();
$wnOld = (int) $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-wn-1',
    'slug' => 'wn-older', 'title' => 'Catalogued Long Ago',
    'status' => 'ready', 'is_published' => 1, 'duration' => 60, 'pinned' => 1,
    'created_at' => date('Y-m-d H:i:s', $wnNow - (60 * 86400)),
    'updated_at' => date('Y-m-d H:i:s', $wnNow - (60 * 86400)),
]);
$wnNew = (int) $db->insert('videos', [
    'provider' => 'bunny', 'provider_id' => 'smoke-wn-2',
    'slug' => 'wn-newer', 'title' => 'Added This Week',
    'status' => 'ready', 'is_published' => 1, 'duration' => 60, 'pinned' => 1,
    'created_at' => date('Y-m-d H:i:s', $wnNow - 3600),
    'updated_at' => date('Y-m-d H:i:s', $wnNow - 3600),
]);

/*
 * The card a slug belongs to, rather than the whole page.
 *
 * The first version of these checks counted occurrences of the label across the
 * homepage and asserted exactly one. That was wrong about the fixture, not
 * about the plugin: this database is created from scratch at the start of the
 * run, so EVERY video in it was published minutes ago and every one of them is
 * legitimately new. The check was counting the rest of the library.
 *
 * Asking which card carries the badge is the claim that was meant, and it holds
 * whatever else is on the page.
 */
$wnCardFor = static function (string $body, string $slug): string {
    foreach (explode('<article', $body) as $chunk) {
        if (str_contains($chunk, '/watch/' . $slug . '"')) {
            return $chunk;
        }
    }

    return '';
};

$adminUserId = (int) $db->value('SELECT id FROM {users} WHERE email = ?', ['admin@smoke.test']);

/*
 * A FIRST visit badges nothing, and the row proving it happened is written.
 *
 * Both halves matter. Nothing badged is what stops a new account seeing a
 * library where every card says the same word; the row is what makes the
 * second visit work at all.
 */
$db->execute('DELETE FROM {whats_new_visits} WHERE user_id = ?', [$adminUserId]);

$wnFirst = getWithJar($baseUrl . '/', $jar);
check('The library still renders with the plugin active', $wnFirst['status'] === 200, "got {$wnFirst['status']}");
check(
    'A first visit badges nothing',
    !str_contains($wnFirst['body'], 'SMOKEFRESH'),
    'every card on a new account\'s first page would carry the same badge'
);
check(
    'and the visit was recorded',
    (int) $db->value('SELECT COUNT(*) FROM {whats_new_visits} WHERE user_id = ?', [$adminUserId]) === 1,
    'nothing was written, so the second visit has nothing to compare against'
);

/*
 * Now plant a marker a week back — the same state as somebody who was last here
 * last Tuesday — and check the badge lands on the newer video and only on it.
 */
$db->execute(
    'UPDATE {whats_new_visits} SET marker_at = ?, seen_at = ? WHERE user_id = ?',
    [
        date('Y-m-d H:i:s', $wnNow - (7 * 86400)),
        date('Y-m-d H:i:s', $wnNow - (7 * 86400)),
        $adminUserId,
    ]
);

$wnReturn = getWithJar($baseUrl . '/', $jar);
$wnNewCard = $wnCardFor($wnReturn['body'], 'wn-newer');
$wnOldCard = $wnCardFor($wnReturn['body'], 'wn-older');

check(
    'Coming back badges what was published since',
    $wnNewCard !== '' && str_contains($wnNewCard, '>SMOKEFRESH<'),
    $wnNewCard === ''
        ? 'the card is not on the page at all — the fixture, not the plugin'
        : 'the marker rolled and nothing was marked — the badge slot or the filter'
);
check(
    'and badges nothing older than the marker',
    $wnOldCard !== '' && !str_contains($wnOldCard, 'SMOKEFRESH'),
    $wnOldCard === ''
        ? 'the older card is not on the page, so this check proved nothing'
        : 'a video from two months ago is not new since last week'
);

/*
 * Reloading during the same visit keeps them. The marker rolls on a gap, not on
 * a request — badges that vanish on the first reload read as the feature
 * flickering rather than as a session ending.
 */
check(
    'and a reload during the same visit keeps them',
    str_contains($wnCardFor(getWithJar($baseUrl . '/', $jar)['body'], 'wn-newer'), '>SMOKEFRESH<'),
    'the marker moved to now, and every badge went with it'
);

check(
    'A signed-out visitor gets no badges',
    !str_contains(get($baseUrl . '/')['body'], 'SMOKEFRESH'),
    'the only identity available is a cookie, and a marker on one resets itself'
);

/* ------------------------------------------------------------ most watched
 *
 * A homepage row built from view counts. The check that matters most is not
 * the row: it is that the ordinary library listing is still underneath it.
 *
 * Until this shipped, "there are rows" and "somebody arranged this front page"
 * were the same fact, and the theme replaced the listing whenever rows existed.
 * A plugin adding one row would have deleted the homepage of every site that
 * never opened the row builder — which is the usual install.
 */
echo "\nMost watched\n";

$popPage = getWithJar($baseUrl . '/admin/popular', $jar);
check('The most-watched settings page renders', $popPage['status'] === 200, "got {$popPage['status']}");
check(
    'and it says when the row stays hidden',
    str_contains($popPage['body'], 'is not a ranking'),
    'a row that silently never appears reads as a broken plugin'
);

$popSaved = postWithJar($baseUrl . '/admin/popular', [
    '_token'   => csrfFrom($popPage['body']),
    'title'    => 'SMOKEWATCHED',
    'days'     => '30',
    'count'    => '8',
    'position' => 'first',
], $jar);
check('Saving the row settings succeeds', $popSaved['status'] === 302, "got {$popSaved['status']}");

/*
 * Four videos, created oldest-first, watched in the OPPOSITE order to the way
 * the library lists them.
 *
 * That opposition is the whole point. The listing sorts newest first, so a row
 * that fetched the right ids and then trusted the listing's own ORDER BY would
 * come back exactly reversed — and a row labelled "most watched" sorted by
 * publication date is a different claim under the same heading.
 */
$db->execute('DELETE FROM {video_views}');

$popIds = [];
foreach ([['pop-one', 40], ['pop-two', 30], ['pop-three', 20], ['pop-four', 10]] as $i => [$slug, $views]) {
    $stamp = date('Y-m-d H:i:s', $wnNow - ((4 - $i) * 3600));

    $popIds[$slug] = (int) $db->insert('videos', [
        'provider' => 'bunny', 'provider_id' => 'smoke-pop-' . $i,
        'slug' => $slug, 'title' => 'Popular ' . $i,
        'status' => 'ready', 'is_published' => 1, 'duration' => 60,
        'created_at' => $stamp, 'updated_at' => $stamp,
    ]);

    $db->execute(
        'INSERT INTO {video_views} (video_id, day, views, completions) VALUES (?, CURDATE(), ?, 0)',
        [$popIds[$slug], $views]
    );
}

$popHome = getWithJar($baseUrl . '/', $jar);
check('The homepage renders with the row', $popHome['status'] === 200, "got {$popHome['status']}");
check('The row appears', str_contains($popHome['body'], 'SMOKEWATCHED'), 'the filter never fired');

$at = static fn (string $slug): int => (int) strpos($popHome['body'], '/watch/' . $slug);
check(
    'and it is ordered by views, not by the listing',
    $at('pop-one') > 0 && $at('pop-one') < $at('pop-two')
        && $at('pop-two') < $at('pop-three')
        && $at('pop-three') < $at('pop-four'),
    'the ids were right and the order came from the library — newest first, which is backwards here'
);

/*
 * THE regression this guards. The library is still below the row.
 *
 * wn-older has no views, so it cannot be in the row; finding it on the page
 * means the flat listing rendered.
 */
check(
    'and the library listing is still underneath it',
    str_contains($popHome['body'], '/watch/wn-older'),
    'a plugin row replaced the front page of every site that never curated one'
);
/*
 * A members-only video can be the most watched thing on a site — its audience
 * is the people who watch most — and a stranger must not be told its name.
 */
$db->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$popIds['pop-one']]);

$popAnon = get($baseUrl . '/');
check(
    'A members-only video is not named to a stranger',
    !str_contains($popAnon['body'], '/watch/pop-one'),
    'the row became a second way to see what the listing hides'
);
check(
    'and the row is still shown to them without it',
    str_contains($popAnon['body'], 'SMOKEWATCHED')
        && str_contains($popAnon['body'], '/watch/pop-two'),
    'one restricted video should not take the whole row with it'
);

/* Below the minimum, the row is not shown at all. */
$db->execute('DELETE FROM {video_views} WHERE video_id IN (?, ?)', [$popIds['pop-two'], $popIds['pop-three']]);

check(
    'A row of one is not shown',
    !str_contains(get($baseUrl . '/')['body'], 'SMOKEWATCHED'),
    'the only video anybody opened, presented as though a crowd had chosen it'
);
check(
    'and the library is still there without it',
    str_contains(get($baseUrl . '/')['body'], '/watch/wn-older'),
    'the row disappearing took the homepage with it'
);

/* Clear up: nothing downstream should inherit these. */
$db->execute('DELETE FROM {video_views}');
$db->execute('DELETE FROM {videos} WHERE id IN (' . implode(',', array_map('intval', [
    $wnOld, $wnNew, ...array_values($popIds),
])) . ')');

/* -------------------------------------------------------- maintenance mode
 *
 * Deployment here is `git pull` on a live host, and pending migrations run on
 * the first request afterwards — whichever visitor that happens to be. This is
 * the switch that shows them a notice instead.
 *
 * Every check below that matters is about NOT being locked out. A switch that
 * closes the site is only safe if the ways back in are proved, so they are
 * driven rather than reasoned about.
 */
echo "\nMaintenance mode\n";

/*
 * Set through the real form, not with an INSERT.
 *
 * The first version wrote the row directly and fataled on a NOT NULL
 * updated_at -- which was a fair warning: a setting written by hand is a
 * setting whose FORM nobody proved. Driving the checkbox proves both.
 *
 * _whole_form is required, and it means absent checkboxes are cleared, so the
 * two that default ON are sent back explicitly.
 */
$settingsPage = getWithJar($baseUrl . '/admin/settings', $jar);
$closeSite = static function (array $extra) use ($baseUrl, $jar, $settingsPage): array {
    return postWithJar($baseUrl . '/admin/settings', [
        '_token'      => csrfFrom($settingsPage['body']),
        '_whole_form' => '1',
        'site_name'   => 'Smoke Portal',
        'timezone'    => 'UTC',
        'subscriptions_enabled' => '1',
        'allow_access_requests' => '1',
    ] + $extra, $jar);
};

$closed503 = $closeSite(['maintenance_mode' => '1']);
check('The maintenance switch saves', $closed503['status'] === 302, "got {$closed503['status']}");

$closed = get($baseUrl . '/');

check('A visitor gets the notice', str_contains($closed['body'], 'Back shortly'), "got {$closed['status']}");
check(
    'and it answers 503, not 200',
    $closed['status'] === 503,
    'a 200 tells search engines this IS the page, and a deploy quietly deindexes the site'
);
check(
    'and says when to come back',
    isset($closed['headers']['retry-after']),
    'a 503 with no Retry-After leaves a crawler guessing'
);
check(
    'and asks not to be indexed',
    str_contains($closed['body'], 'noindex'),
    'belt and braces beside the 503'
);
check(
    'A watch page is closed too',
    get($baseUrl . '/watch/' . $videoSlug)['status'] === 503,
    'closing only the homepage is not closing the site'
);

/*
 * The ways back in. Each of these is a separate guarantee in the policy, and
 * each one failing turns the switch into a door that only FTP can open.
 */
check(
    'Sign-in stays open',
    get($baseUrl . '/auth/login')['status'] === 200,
    'THE LOCKOUT: the rule is "admins get through", which needs a session — and a session needs sign-in'
);
check(
    'The admin area stays open',
    getWithJar($baseUrl . '/admin', $jar)['status'] === 200,
    'the screen that turns this off must never be behind it'
);
check(
    'and an admin sees the public site as normal',
    getWithJar($baseUrl . '/', $jar)['status'] === 200,
    'a site they can administer but not look at gives no way to check the deploy worked'
);
check(
    'Cron keeps running',
    get($baseUrl . '/cron?key=' . urlencode((string) $written['cron_secret']))['status'] === 200,
    'scheduled work should not stop because somebody is deploying'
);
/*
 * The ROUTED asset, not a file in public/assets.
 *
 * Files under /assets are served by the web server and never reach PHP, so
 * they could not be blocked by this guard and prove nothing about it. The
 * theme stylesheet goes through AssetController, which is behind the global
 * middleware — so it is the one that would actually go dark.
 *
 * The first version of this check asked for /assets/app.css, which does not
 * exist at all: it failed with a 404 and read as the guard blocking assets.
 */
check(
    'The theme stylesheet still loads',
    get($baseUrl . '/theme-asset/default/theme.css')['status'] === 200,
    'a notice that cannot load its stylesheet reads as a broken site, not a deliberate one'
);

/* And the admin is told, on every screen, that the site is shut. */
check(
    'The admin area says the site is closed',
    str_contains(getWithJar($baseUrl . '/admin/videos', $jar)['body'], 'closed to visitors'),
    'the one setting invisible to the person who set it — they are exempt, so nothing else would say'
);

/* A custom message reaches the page. */
$closeSite(['maintenance_mode' => '1', 'maintenance_message' => 'Back at four.']);
check(
    'A custom message is shown',
    str_contains(get($baseUrl . '/')['body'], 'Back at four.'),
    'the field saves and the page ignores it'
);

/* Off again, and the site comes back. */
$closeSite([]);

$reopened = get($baseUrl . '/');
check('Switching it off reopens the site', $reopened['status'] === 200, "got {$reopened['status']}");
check(
    'and the notice is gone',
    !str_contains($reopened['body'], 'Back shortly'),
    'a switch that cannot be switched back is not a switch'
);

/* ---------------------------------------------------------------- installable
 *
 * A manifest, an icon, one service worker, and the page shown with no network.
 * Before this the site could not be installed at all: the only worker was the
 * push plugin's, which has no fetch handler and caches nothing.
 *
 * What can be checked here is the plumbing — that the files are served, with
 * the right types and headers, and that the worker's rules are the conservative
 * ones. Installing the app and losing the network need a real browser, and that
 * limitation is stated rather than implied by a green run.
 */
echo "\nInstallable app\n";

$manifest = get($baseUrl . '/manifest.webmanifest');
check('The manifest is served', $manifest['status'] === 200, "got {$manifest['status']}");
check(
    'and as a manifest, not as JSON',
    str_contains(strtolower($manifest['headers']['content-type'] ?? ''), 'application/manifest+json'),
    'browsers do read this one strictly'
);

$decodedManifest = json_decode($manifest['body'], true);
check(
    'and it parses',
    is_array($decodedManifest),
    'a manifest that does not parse is no manifest at all'
);
check(
    'and asks for standalone, which is what makes it installable',
    ($decodedManifest['display'] ?? '') === 'standalone',
    'without this there is no install prompt'
);
check(
    'and carries the site name rather than a hardcoded one',
    ($decodedManifest['name'] ?? '') === 'Smoke Test Portal',
    'this is a white-label product; every install must not claim the same name'
);
/*
 * The icon rules Chrome actually enforces, which is where the first version of
 * this got it wrong: it declared one SVG at sizes:"any", and Chrome Android
 * then offers "Create shortcut" instead of "Install". The check needs a
 * DECLARED 192 and a DECLARED 512, with at least one of purpose "any".
 */
$icons = (array) ($decodedManifest['icons'] ?? []);
$sizesDeclared = array_map(static fn (array $i): string => (string) ($i['sizes'] ?? ''), $icons);

check(
    'and declares a 192px icon',
    in_array('192x192', $sizesDeclared, true),
    'Chrome will not mint an installable app without one'
);
check(
    'and declares a 512px icon',
    in_array('512x512', $sizesDeclared, true),
    'Chrome will not mint an installable app without one'
);
check(
    'and at least one is purpose "any"',
    array_filter($icons, static fn (array $i): bool => str_contains((string) ($i['purpose'] ?? 'any'), 'any')) !== [],
    'a maskable-only set fails the installability check'
);
check(
    'and none of them is an SVG',
    array_filter($icons, static fn (array $i): bool => str_contains((string) ($i['type'] ?? ''), 'svg')) === [],
    'an SVG entry at sizes:any is what broke WebAPK installation (crbug 40925759)'
);

/*
 * And the files behind those declarations are real PNGs of the right size.
 * A manifest that names an icon which 404s, or which is not the size it
 * claims, fails the same check while looking correct in the JSON.
 */
foreach ([192, 512] as $iconSize) {
    $png = get($baseUrl . '/icon-' . $iconSize . '.png');
    check(
        "The {$iconSize}px icon is served",
        $png['status'] === 200,
        "got {$png['status']}"
    );
    check(
        "and is a real {$iconSize}x{$iconSize} PNG",
        str_starts_with($png['body'], "\x89PNG")
            && ($info = @getimagesizefromstring($png['body'])) !== false
            && $info[0] === $iconSize && $info[1] === $iconSize,
        'the manifest declares a size; the file has to be it'
    );
}

$icon = get($baseUrl . '/icon.svg');
check(
    'The SVG is still served for the browser tab',
    $icon['status'] === 200 && str_contains(strtolower($icon['headers']['content-type'] ?? ''), 'image/svg'),
    "got {$icon['status']} — it is the favicon, just not a manifest icon"
);

$sw = get($baseUrl . '/sw.js');
check('The service worker is served', $sw['status'] === 200, "got {$sw['status']}");
check(
    'as JavaScript',
    str_contains(strtolower($sw['headers']['content-type'] ?? ''), 'javascript'),
    'a worker served as text/plain is refused by the browser'
);
check(
    'and allowed to control the whole site',
    ($sw['headers']['service-worker-allowed'] ?? '') === '/',
    'without this header the worker registers successfully and controls only /sw.js'
);

/*
 * The safety property, checked on the bytes actually served rather than on the
 * builder: this worker must never cache a page. Every page here can be
 * personalised or access-gated.
 */
check(
    'and it caches exactly one thing, the offline page',
    substr_count($sw['body'], 'cache.add') === 1 && str_contains($sw['body'], "'/offline'"),
    'a worker caching content can serve one person\'s page to the next'
);
check(
    'and leaves anything that is not a navigation alone',
    str_contains($sw['body'], "request.mode !== 'navigate'"),
    'intercepting API calls and posts is how a worker loses somebody\'s data'
);

/*
 * Offline video, checked on the served bytes.
 *
 * The behaviour itself needs a browser and is not claimed here. What CAN be
 * proved from the wire is that the range machinery reached the device at all —
 * a worker that lost it during a deploy would still install, still serve the
 * offline page, and still pass every check above, while every saved video
 * became unseekable.
 */
check(
    'and it serves saved videos from the device',
    str_contains($sw['body'], '/offline-video/'),
    'without this a saved file has no same-origin URL a player can use'
);
check(
    'and answers byte ranges itself',
    str_contains($sw['body'], 'status: 206') && str_contains($sw['body'], 'Content-Range'),
    'a range answered with the whole body cannot be seeked — Safari refuses it outright'
);
check(
    'and its video cache is not swept when the worker updates',
    !str_contains($sw['body'], "caches.delete('portal-offline-videos-v1')"),
    'a worker update must not discard hundreds of megabytes somebody chose to keep'
);

/*
 * The push plugin's contribution is checked in the Push section above, where
 * it is still active — by this point it has been deactivated, and asserting it
 * here would be asserting against a plugin that is switched off.
 */

$offline = get($baseUrl . '/offline');
check('The offline page is served', $offline['status'] === 200, "got {$offline['status']}");
check(
    'and it needs no session and names nobody',
    !str_contains($offline['body'], 'admin@smoke.test') && !str_contains($offline['body'], 'Sign out'),
    'this page is stored on the device and shown to whoever opens the app next'
);
check(
    'and it can list what is saved on the device',
    str_contains($offline['body'], 'portal-offline-videos-v1'),
    'the only page that renders with no network is the only place the list is useful'
);
check(
    'while still holding no content of its own',
    !str_contains($offline['body'], 'A Test Video'),
    'a precached page carrying a title is a title shown to whoever opens the app next'
);

$home = get($baseUrl . '/');
check(
    'The site links to the manifest',
    str_contains($home['body'], 'rel="manifest"'),
    'no manifest link means no install prompt, however many workers are registered'
);
check(
    'and asks for it with credentials',
    str_contains($home['body'], 'crossorigin="use-credentials"'),
    'Chrome fetches a manifest without cookies by default, so anything in front '
    . 'of the site judges it as anonymous and can answer with a challenge page'
);
check(
    'and registers the worker',
    str_contains($home['body'], "register('/sw.js'"),
    'a worker nothing registers does nothing'
);

echo "\nRouting\n";

$notFound = get($baseUrl . '/no-such-page');
check('Unknown path returns 404', $notFound['status'] === 404, "got {$notFound['status']}");

$badMethod = (function () use ($baseUrl): array {
    $ch = curl_init($baseUrl . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_TIMEOUT => SMOKE_TIMEOUT,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status === 0) {
        noteTransportFailure($baseUrl . '/ (DELETE)', curl_error($ch));
    }
    curl_close($ch);
    return ['status' => $status, 'raw' => $raw];
})();
check('Wrong method returns 405', $badMethod['status'] === 405, "got {$badMethod['status']}");
check('405 carries an Allow header', stripos($badMethod['raw'], 'Allow:') !== false);

// ------------------------------------------------------------------- results

echo "\n";
echo str_repeat('-', 50) . "\n";
printf("%d passed, %d failed\n", $passed, $failed);

/*
 * If any request never got a response, say so LOUDLY and separately.
 *
 * A run with a transport failure in it is not evidence about the application.
 * One lost response is enough to invalidate everything after it: the change-
 * password endpoint signs every session out and issues a new one, so a reply
 * that never arrives leaves the cookie jar holding a session that no longer
 * exists, and every later admin request answers 302. That reads as dozens of
 * unrelated features breaking at once.
 *
 * This happened twice while building the video re-check, both times because
 * something else on the machine was busy — password hashing is CPU-bound by
 * design and the built-in server is single-threaded. The first diagnosis was
 * wrong both times, because the checks reported what they always report.
 */
if ($transportFailures !== []) {
    echo "\n";
    echo str_repeat('!', 50) . "\n";
    printf("%d request(s) never got a response:\n", count($transportFailures));
    foreach ($transportFailures as $failure) {
        echo '  ' . $failure . "\n";
    }
    echo "\nTHIS RUN IS NOT EVIDENCE ABOUT THE APPLICATION.\n";
    echo "A lost response invalidates every check after it — a change-password\n";
    echo "reply that never arrives leaves the cookie jar holding a dead session,\n";
    echo "and everything downstream answers 302. Re-run on an idle machine\n";
    echo "before believing any failure above.\n";
}

exit($failed === 0 ? 0 : 1);
