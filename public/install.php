<?php
/**
 * The install wizard.
 *
 * Standalone by necessity: it runs before config.php exists, before the
 * database exists, and before the container can be built. It therefore does its
 * own routing and session handling rather than going through the front
 * controller.
 *
 * Renames itself to install.php.installed on success.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use Portal\Auth\Session;
use Portal\Config;
use Portal\Db;
use Portal\Http\Request;
use Portal\Install\Installer;
use Portal\Install\InstallerView;
use Portal\Install\RequirementChecker;
use Portal\Providers\ProviderRegistry;
use Portal\Providers\SettingField;
use Portal\Support\Crypto;
use Portal\Support\Str;

$config = new Config();
$installer = new Installer($config);
$view = new InstallerView();
$checker = new RequirementChecker();

/*
 * Refuse to run on a working installation. A live installer lets anyone who
 * finds the URL repoint the database and take the site over.
 */
if ($installer->isInstalled()) {
    http_response_code(403);
    echo $view->render(Installer::STEP_REQUIREMENTS, [
        'checks' => [],
        'canProceed' => false,
        'error' => 'Video Portal is already installed. Delete public/install.php from the server.',
    ]);
    exit;
}

/*
 * Wizard state lives in a session, not hidden fields: credentials pass through
 * several steps, and round-tripping an API key through the browser on every
 * Next click is exposure with no benefit.
 */
session_name('portal_install');
session_start();

$state = $_SESSION['install'] ?? [];
$request = Request::capture();

$step = $request->input('step') ?? $request->query('step') ?? Installer::STEP_REQUIREMENTS;
$action = $request->input('action') ?? '';
$isPost = $request->method === 'POST';

$data = [];

/*
 * A registry is needed to describe the provider options. The database does not
 * exist yet, so it is wired with a throwaway key and a Db that is never used
 * for reads — build() and test() only need the credentials passed in.
 */
$makeRegistry = static function () use ($config): ProviderRegistry {
    $db = new Db('mysql:host=127.0.0.1;dbname=__none__', '', '', '');
    return new ProviderRegistry($db, $config, new Crypto(Crypto::generateKey()), new Session($db));
};

switch ($step) {
    // ------------------------------------------------------------ requirements
    case Installer::STEP_REQUIREMENTS:
        if ($isPost && $checker->canProceed()) {
            header('Location: ?step=' . Installer::STEP_DATABASE);
            exit;
        }

        $data = [
            'checks'     => $checker->all(),
            'canProceed' => $checker->canProceed(),
        ];
        break;

    // ---------------------------------------------------------------- database
    case Installer::STEP_DATABASE:
        $values = $state['database'] ?? [
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_prefix' => '',
        ];

        if ($isPost) {
            $values = [
                'db_host'   => $request->input('db_host') ?? '127.0.0.1',
                'db_port'   => $request->input('db_port') ?? '3306',
                'db_name'   => $request->input('db_name') ?? '',
                'db_user'   => $request->input('db_user') ?? '',
                'db_pass'   => (string) ($request->post['db_pass'] ?? ''),
                'db_prefix' => $request->input('db_prefix') ?? '',
            ];

            $test = $installer->testDatabase($values);

            if ($test['ok'] && $action === 'continue') {
                $state['database'] = $values;
                $_SESSION['install'] = $state;
                header('Location: ?step=' . Installer::STEP_SITE);
                exit;
            }

            // Remember a successful test so Continue survives the reload.
            if ($test['ok']) {
                $state['database'] = $values;
                $_SESSION['install'] = $state;
            }

            $data['test'] = $test;
        }

        $data['values'] = $values;
        break;

    // -------------------------------------------------------------------- site
    case Installer::STEP_SITE:
        $values = $state['site'] ?? [
            'site_name' => 'Video Portal',
            'base_url'  => guessBaseUrl($request),
            'timezone'  => 'UTC',
        ];

        if ($isPost) {
            $values = [
                'site_name' => $request->input('site_name') ?? '',
                'base_url'  => rtrim(trim($request->input('base_url') ?? ''), '/'),
                'timezone'  => $request->input('timezone') ?? 'UTC',
            ];

            $problem = null;
            if (trim($values['site_name']) === '') {
                $problem = 'Your site needs a name.';
            } elseif ($values['base_url'] === '') {
                $problem = 'The site address is required.';
            } elseif (filter_var($values['base_url'], FILTER_VALIDATE_URL) === false) {
                $problem = 'That site address is not a valid URL. Include https://';
            } elseif (!in_array($values['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                $problem = 'That is not a recognised timezone.';
            }

            if ($problem === null) {
                $state['site'] = $values;
                $_SESSION['install'] = $state;
                header('Location: ?step=' . Installer::STEP_PROVIDERS);
                exit;
            }

            $data['error'] = $problem;
        }

        $data['values'] = $values;
        break;

    // --------------------------------------------------------------- providers
    case Installer::STEP_PROVIDERS:
        $registry = $makeRegistry();

        $selected = $state['providerSlugs'] ?? ProviderRegistry::defaults();
        $credentials = $state['providerCredentials'] ?? [];
        $tests = $state['providerTests'] ?? [];

        if ($isPost) {
            foreach ((array) ($request->post['provider'] ?? []) as $kind => $slug) {
                if (is_string($kind) && is_string($slug)) {
                    // Changing the service invalidates the previous test.
                    if (($selected[$kind] ?? '') !== $slug) {
                        unset($tests[$kind]);
                    }
                    $selected[$kind] = $slug;
                }
            }

            foreach ((array) ($request->post['credentials'] ?? []) as $kind => $fields) {
                if (is_string($kind) && is_array($fields)) {
                    $clean = [];
                    foreach ($fields as $key => $value) {
                        if (is_string($key) && is_scalar($value)) {
                            $clean[$key] = trim((string) $value);
                        }
                    }
                    $credentials[$kind] = $clean;
                }
            }

            if (str_starts_with($action, 'test:')) {
                $kind = substr($action, 5);
                if (isset($selected[$kind])) {
                    $provider = $registry->build($kind, $selected[$kind], $credentials[$kind] ?? []);
                    $result = $registry->safeTest($provider);
                    $tests[$kind] = [
                        'ok'      => $result->ok,
                        'message' => $result->message,
                        'detail'  => $result->detail,
                    ];
                }
            }

            $state['providerSlugs'] = $selected;
            $state['providerCredentials'] = $credentials;
            $state['providerTests'] = $tests;
            $_SESSION['install'] = $state;

            if ($action === 'continue' && allProvidersPassed($tests)) {
                header('Location: ?step=' . Installer::STEP_ADMIN);
                exit;
            }
        }

        $kinds = [];
        foreach ([
            ProviderRegistry::KIND_AUTH => [
                'title' => 'Sign-in',
                'description' => 'How people prove who they are. Local accounts work immediately and '
                    . 'need no external service; Auth0 and OpenID Connect need this site to be '
                    . 'reachable over HTTPS first.',
            ],
            ProviderRegistry::KIND_VIDEO => [
                'title' => 'Video hosting',
                'description' => 'Where videos are stored and streamed from.',
            ],
            ProviderRegistry::KIND_MAIL => [
                'title' => 'Email',
                'description' => 'Used for share links and sign-in links. If your host blocks outbound '
                    . 'HTTPS, SMTP is usually the one that works.',
            ],
        ] as $kind => $info) {
            $kinds[$kind] = $info + [
                'options' => $registry->describe($kind),
                'fields'  => $registry->fieldsFor($kind, $selected[$kind] ?? ''),
            ];
        }

        $data = [
            'kinds'      => $kinds,
            'selected'   => $selected,
            'values'     => $credentials,
            'tests'      => $tests,
            'allPassed'  => allProvidersPassed($tests),
        ] + $data;
        break;

    // ------------------------------------------------------------------- admin
    case Installer::STEP_ADMIN:
        $authSlug = $state['providerSlugs'][ProviderRegistry::KIND_AUTH] ?? 'local';
        $needsPassword = $authSlug === 'local';

        $values = $state['admin'] ?? [];

        if ($isPost) {
            $values = [
                'name'  => $request->input('name') ?? '',
                'email' => Str::normalizeEmail($request->input('email') ?? ''),
            ];
            $password = (string) ($request->post['password'] ?? '');
            $confirm  = (string) ($request->post['password_confirm'] ?? '');

            $problem = null;
            if (!Str::isEmail($values['email'])) {
                $problem = 'That does not look like a valid email address.';
            } elseif ($needsPassword) {
                if (strlen($password) < 12) {
                    $problem = 'Use a password of at least 12 characters.';
                } elseif ($password !== $confirm) {
                    $problem = 'The two passwords do not match.';
                }
            }

            if ($problem === null) {
                $state['admin'] = $values + ['password' => $needsPassword ? $password : ''];

                $result = $installer->install([
                    'database'  => $state['database'] ?? [],
                    'site'      => $state['site'] ?? [],
                    'admin'     => $state['admin'],
                    'providers' => buildProviderState($state),
                ]);

                if ($result->ok) {
                    // The session held the database password and API keys.
                    $_SESSION = [];
                    session_destroy();

                    echo $view->render(Installer::STEP_FINISH, ['result' => $result]);
                    exit;
                }

                $data['error'] = $result->message;
                $data['errorDetail'] = $result->detail;
            } else {
                $data['error'] = $problem;
            }
        }

        $data += [
            'values'        => $values,
            'needsPassword' => $needsPassword,
            'authLabel'     => $authSlug === 'auth0' ? 'Auth0' : 'OpenID Connect',
        ];
        break;

    default:
        header('Location: ?step=' . Installer::STEP_REQUIREMENTS);
        exit;
}

echo $view->render($step, $data);

// ---------------------------------------------------------------- helpers

/**
 * A sensible default for the site address.
 *
 * This is the ONE place the request host is legitimately consulted, because
 * it is a pre-filled suggestion the person then confirms — not a value trusted
 * at runtime. Everything afterwards reads it from config.php.
 */
function guessBaseUrl(Request $request): string
{
    $scheme = $request->isSecure() ? 'https' : 'http';
    $host = (string) ($request->server['HTTP_HOST'] ?? 'localhost');

    // Strip the installer's own path segment.
    $script = (string) ($request->server['SCRIPT_NAME'] ?? '');
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '/' ) {
        $base = '';
    }

    return $scheme . '://' . $host . $base;
}

/** @param array<string, array{ok: bool}> $tests */
function allProvidersPassed(array $tests): bool
{
    foreach ([ProviderRegistry::KIND_AUTH, ProviderRegistry::KIND_VIDEO, ProviderRegistry::KIND_MAIL] as $kind) {
        if (empty($tests[$kind]['ok'])) {
            return false;
        }
    }
    return true;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, array{slug: string, credentials: array<string, string>}>
 */
function buildProviderState(array $state): array
{
    $out = [];
    foreach (($state['providerSlugs'] ?? []) as $kind => $slug) {
        $out[$kind] = [
            'slug'        => (string) $slug,
            'credentials' => $state['providerCredentials'][$kind] ?? [],
        ];
    }
    return $out;
}
