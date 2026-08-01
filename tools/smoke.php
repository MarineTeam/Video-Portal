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
        'headers' => [],
    ];
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
    if (isset($serverLog) && is_file($serverLog)) {
        @unlink($serverLog);
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

foreach (['watermark', 'geo'] as $slug) {
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

@unlink($jar);

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
