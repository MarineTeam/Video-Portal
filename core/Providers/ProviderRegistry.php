<?php

declare(strict_types=1);

namespace Portal\Providers;

use Portal\Auth\Auth0Provider;
use Portal\Auth\AuthProvider;
use Portal\Auth\LocalProvider;
use Portal\Auth\OidcProvider;
use Portal\Auth\Session;
use Portal\Config;
use Portal\Db;
use Portal\Mail\MailProvider;
use Portal\Mail\PhpMailProvider;
use Portal\Mail\ResendProvider;
use Portal\Mail\SmtpProvider;
use Portal\Support\Crypto;
use Portal\Video\BunnyStreamProvider;
use Portal\Video\VideoProvider;
use RuntimeException;
use Throwable;

/**
 * Knows every available provider, which one is active, and how to build it.
 *
 * The one non-obvious rule enforced here: a provider is never made active
 * without passing its own test() first. That is what turns "switchable
 * providers" from a liability into a feature — the failure mode we are guarding
 * against is an admin changing the email provider, seeing no error, and only
 * discovering weeks later that no share notification has been delivered since.
 */
final class ProviderRegistry
{
    public const KIND_AUTH  = 'auth';
    public const KIND_VIDEO = 'video';
    public const KIND_MAIL  = 'mail';

    /** @var array<string, array<string, class-string>> */
    private array $available = [
        self::KIND_AUTH => [
            'auth0' => Auth0Provider::class,
            'local' => LocalProvider::class,
            'oidc'  => OidcProvider::class,
        ],
        self::KIND_VIDEO => [
            'bunny' => BunnyStreamProvider::class,
        ],
        self::KIND_MAIL => [
            'resend'    => ResendProvider::class,
            'smtp'      => SmtpProvider::class,
            'php_mail'  => PhpMailProvider::class,
        ],
    ];

    /** @var array<string, object> Built instances, keyed by kind. */
    private array $instances = [];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Crypto $crypto,
        private readonly Session $session,
    ) {
    }

    /**
     * Let a plugin contribute a provider.
     *
     * @param class-string $class
     */
    public function register(string $kind, string $class): void
    {
        if (!is_subclass_of($class, Provider::class)) {
            throw new RuntimeException("{$class} does not implement the Provider interface.");
        }
        /** @var class-string<Provider> $class */
        $this->available[$kind][$class::slug()] = $class;
    }

    /**
     * @return array<string, class-string<Provider>>
     */
    public function availableFor(string $kind): array
    {
        /** @var array<string, class-string<Provider>> */
        return $this->available[$kind] ?? [];
    }

    /**
     * Descriptions for the installer dropdown and the admin screen.
     *
     * @return list<array{slug: string, label: string, description: string, missingExtensions: list<string>}>
     */
    public function describe(string $kind): array
    {
        $out = [];
        foreach ($this->availableFor($kind) as $slug => $class) {
            $missing = [];
            foreach ($class::requiredExtensions() as $extension) {
                if (!extension_loaded($extension)) {
                    $missing[] = $extension;
                }
            }
            $out[] = [
                'slug'              => $slug,
                'label'             => $class::label(),
                'description'       => $class::description(),
                'missingExtensions' => $missing,
            ];
        }
        return $out;
    }

    /** @return list<SettingField> */
    public function fieldsFor(string $kind, string $slug): array
    {
        $class = $this->availableFor($kind)[$slug] ?? null;
        return $class === null ? [] : $class::fields();
    }

    // ------------------------------------------------------------ persistence

    /**
     * Stored credentials for one provider, decrypted.
     *
     * @return array<string, string>
     */
    public function credentials(string $kind, string $slug): array
    {
        try {
            $blob = $this->db->value(
                'SELECT credentials FROM {providers} WHERE kind = ? AND slug = ?',
                [$kind, $slug]
            );
        } catch (Throwable $e) {
            error_log("Portal: could not read {$kind}/{$slug} credentials: " . $e->getMessage());
            return [];
        }

        if (!is_string($blob) || $blob === '') {
            return [];
        }

        $plain = $this->crypto->decrypt($blob);
        if ($plain === null) {
            // Almost always a changed app_key. Say so loudly in the log; the
            // admin screen surfaces it as "credentials unreadable, re-enter".
            error_log(
                "Portal: could not decrypt {$kind}/{$slug} credentials. "
                . 'The app_key in config.php has probably changed.'
            );
            return [];
        }

        $decoded = json_decode($plain, true);
        if (!is_array($decoded)) {
            return [];
        }

        $credentials = [];
        foreach ($decoded as $key => $value) {
            if (is_scalar($value)) {
                $credentials[(string) $key] = (string) $value;
            }
        }
        return $credentials;
    }

    /**
     * Save credentials without activating.
     *
     * Blank secret fields keep their stored value: the admin form never renders
     * a secret back to the browser, so an empty submission means "unchanged",
     * not "clear it". Treating it as a clear would let someone wipe the bunny
     * API key by editing the CDN hostname on the same form.
     *
     * @param array<string, string> $submitted
     */
    public function saveCredentials(string $kind, string $slug, array $submitted): void
    {
        $class = $this->availableFor($kind)[$slug] ?? null;
        if ($class === null) {
            throw new RuntimeException("Unknown {$kind} provider '{$slug}'.");
        }

        $existing = $this->credentials($kind, $slug);
        $merged = $existing;

        foreach ($class::fields() as $field) {
            $value = $submitted[$field->key] ?? null;

            if ($value === null) {
                continue;
            }
            $value = trim((string) $value);

            if ($field->isSecret() && $value === '') {
                continue; // keep what's stored
            }

            $merged[$field->key] = $value;
        }

        $encrypted = $this->crypto->encrypt(
            json_encode($merged, JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        $this->db->execute(
            'INSERT INTO {providers} (kind, slug, credentials, is_active, created_at, updated_at)
             VALUES (?, ?, ?, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE credentials = VALUES(credentials), updated_at = NOW()',
            [$kind, $slug, $encrypted]
        );

        $this->forget($kind);
    }

    /**
     * Build a provider without activating it — used by the "Test connection"
     * button so an admin can check credentials before committing to them.
     *
     * @param array<string, string> $overrides
     */
    public function build(string $kind, string $slug, array $overrides = []): Provider
    {
        $class = $this->availableFor($kind)[$slug] ?? null;
        if ($class === null) {
            throw new RuntimeException("Unknown {$kind} provider '{$slug}'.");
        }

        $credentials = $overrides === []
            ? $this->credentials($kind, $slug)
            : array_merge($this->credentials($kind, $slug), array_filter(
                $overrides,
                static fn ($v): bool => $v !== '' && $v !== null
            ));

        return $this->construct($class, $credentials);
    }

    /** @param class-string $class */
    private function construct(string $class, array $credentials): Provider
    {
        /** @var Provider */
        return match (true) {
            // Auth providers need more than credentials: OIDC has to build
            // callback URLs from config and stash state in the session; local
            // accounts need the database.
            is_a($class, OidcProvider::class, true)  => new $class($credentials, $this->config, $this->session),
            is_a($class, LocalProvider::class, true) => new $class($credentials, $this->db),
            default                                  => new $class($credentials),
        };
    }

    /**
     * Make a provider the active one for its kind.
     *
     * @param bool $requireTest false only for the installer, which has already
     *                          run the test as its own wizard step
     */
    public function activate(string $kind, string $slug, bool $requireTest = true): TestResult
    {
        $provider = $this->build($kind, $slug);

        $result = $requireTest ? $this->safeTest($provider) : TestResult::pass('Activated without testing.');

        if ($requireTest && !$result->ok) {
            return $result;
        }

        $this->db->transaction(function () use ($kind, $slug, $result): void {
            $this->db->execute('UPDATE {providers} SET is_active = 0 WHERE kind = ?', [$kind]);
            $this->db->execute(
                'INSERT INTO {providers}
                    (kind, slug, is_active, last_tested_at, last_test_ok, last_test_message, created_at, updated_at)
                 VALUES (?, ?, 1, NOW(), ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    is_active = 1,
                    last_tested_at = NOW(),
                    last_test_ok = VALUES(last_test_ok),
                    last_test_message = VALUES(last_test_message),
                    updated_at = NOW()',
                [$kind, $slug, $result->ok ? 1 : 0, mb_substr($result->message, 0, 500)]
            );
        });

        $this->forget($kind);

        return $result;
    }

    /** A provider's test() must never take the page down, whatever it does. */
    public function safeTest(Provider $provider): TestResult
    {
        try {
            return $provider->test();
        } catch (Throwable $e) {
            return TestResult::fail('The test threw an unexpected error.', $e->getMessage());
        }
    }

    public function activeSlug(string $kind): ?string
    {
        try {
            $slug = $this->db->value(
                'SELECT slug FROM {providers} WHERE kind = ? AND is_active = 1 LIMIT 1',
                [$kind]
            );
        } catch (Throwable) {
            return null;
        }
        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    // --------------------------------------------------------------- accessors

    public function auth(): AuthProvider
    {
        /** @var AuthProvider */
        return $this->active(self::KIND_AUTH);
    }

    public function video(): VideoProvider
    {
        /** @var VideoProvider */
        return $this->active(self::KIND_VIDEO);
    }

    public function mail(): MailProvider
    {
        /** @var MailProvider */
        return $this->active(self::KIND_MAIL);
    }

    /**
     * True when email is usable. Callers check this before offering to notify
     * anyone, so the UI never shows a "send email" checkbox that silently
     * does nothing.
     */
    public function mailConfigured(): bool
    {
        try {
            return $this->mail()->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Which required credentials this provider is missing.
     *
     * Selecting a provider and configuring one are different things, and until
     * now nothing could tell them apart without making a network call. The
     * dashboard printed the slug of whatever was selected, which reads as "this
     * works" and was frequently a lie: a site with Resend chosen and no API key
     * silently dropped every share link, approval request and subscription
     * email, and said nothing anywhere.
     *
     * Derived from `fields()`, so it costs one already-cached read and no
     * outbound request — which is what makes it usable on a page render, unlike
     * `test()`. It answers "could this possibly work", not "does it": a wrong
     * API key passes this and fails `test()`, and that is the honest division.
     * Only `test()` can tell you the credential is correct, and only by asking
     * the service.
     *
     * @return list<string> field LABELS, in the order the form shows them
     */
    public function missingCredentials(string $kind, ?string $slug = null): array
    {
        try {
            $slug ??= $this->activeSlug($kind);
            if ($slug === null) {
                return [];
            }

            $stored = $this->credentials($kind, $slug);
            $missing = [];

            foreach ($this->fieldsFor($kind, $slug) as $field) {
                if (!$field->required) {
                    continue;
                }

                /*
                 * A bool with a stored "0" is set, not missing — so this tests
                 * the string, not truthiness. Getting that wrong would report a
                 * deliberately-off switch as an unconfigured provider.
                 */
                if (trim($stored[$field->key] ?? '') === '') {
                    $missing[] = $field->label;
                }
            }

            return $missing;
        } catch (Throwable $e) {
            /*
             * Fails QUIET, unlike a permission check. This drives a warning
             * banner; if the registry cannot be read, the dashboard has bigger
             * problems already on it, and inventing a second alarm out of an
             * unrelated fault helps nobody.
             */
            error_log('Portal: could not check ' . $kind . ' credentials: ' . $e->getMessage());
            return [];
        }
    }

    private function active(string $kind): Provider
    {
        if (isset($this->instances[$kind])) {
            /** @var Provider */
            return $this->instances[$kind];
        }

        $slug = $this->activeSlug($kind);
        if ($slug === null) {
            throw new RuntimeException(
                "No {$kind} provider is active. Choose one on the Providers screen in the admin area."
            );
        }

        return $this->instances[$kind] = $this->build($kind, $slug);
    }

    /** Drop the cached instance so the next call rebuilds with fresh credentials. */
    public function forget(string $kind): void
    {
        unset($this->instances[$kind]);
    }

    /**
     * Defaults the installer preselects.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            self::KIND_AUTH  => 'auth0',
            self::KIND_VIDEO => 'bunny',
            self::KIND_MAIL  => 'resend',
        ];
    }
}
