<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;
use Portal\Http\Request;
use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Support\Str;
use Throwable;

/**
 * Email and password accounts stored in this application's own database.
 *
 * A first-class option, not a fallback. Auth0 and OIDC both require the site to
 * be reachable over HTTPS at a stable public URL before anyone can even sign in
 * once — which is precisely the moment someone is trying to get their install
 * working. Local accounts make the app usable immediately, and the provider can
 * be switched later without losing any content.
 *
 * Passwords use argon2id where available, falling back to bcrypt. Both are
 * handled by password_hash/password_verify so the cost parameters and salting
 * are PHP's problem rather than ours.
 */
final class LocalProvider implements AuthProvider
{
    /** @param array<string, string> $credentials */
    public function __construct(
        private readonly array $credentials,
        private readonly Db $db,
    ) {
    }

    public static function slug(): string
    {
        return 'local';
    }

    public static function label(): string
    {
        return 'Local accounts (email and password)';
    }

    public static function description(): string
    {
        return 'Accounts live in this site\'s own database. No external service, works immediately.';
    }

    public static function requiredExtensions(): array
    {
        return [];
    }

    public static function fields(): array
    {
        return [
            SettingField::bool(
                'allow_signup',
                'Let visitors create their own account',
                'Off by default. Even when on, a new account cannot watch anything until an administrator authorizes it.',
                default: false
            ),
            SettingField::number(
                'min_password_length',
                'Minimum password length',
                'Twelve or more is a sensible floor for an account that can administer the site.',
                required: false,
                default: '12'
            ),
        ];
    }

    public function isLocal(): bool
    {
        return true;
    }

    public function allowsSignup(): bool
    {
        return in_array(strtolower(trim($this->credentials['allow_signup'] ?? '0')), ['1', 'true', 'on', 'yes'], true);
    }

    public function minPasswordLength(): int
    {
        $length = (int) ($this->credentials['min_password_length'] ?? 12);
        return max(8, $length);
    }

    public function loginUrl(string $returnTo = '/'): string
    {
        return '/auth/login?returnTo=' . rawurlencode(Request::sanitizeReturnTo($returnTo));
    }

    public function logoutUrl(string $returnTo = '/'): ?string
    {
        // No remote session to end; the app clears its own and redirects.
        return null;
    }

    /**
     * Verify a submitted email and password.
     *
     * Deliberately uniform failure messaging: "that email and password do not
     * match" for both an unknown address and a wrong password. Distinguishing
     * them turns the login form into an account-enumeration oracle.
     */
    public function handleCallback(Request $request): AuthResult
    {
        $email = Str::normalizeEmail($request->input('email') ?? '');
        $password = (string) ($request->post['password'] ?? '');
        $returnTo = $request->safeReturnTo();

        if ($email === '' || $password === '') {
            return AuthResult::failure('Enter your email address and password.');
        }

        try {
            $row = $this->db->first(
                'SELECT id, email, name, password_hash, email_verified
                   FROM {users} WHERE email = ? LIMIT 1',
                [$email]
            );
        } catch (Throwable $e) {
            error_log('Portal: local login lookup failed: ' . $e->getMessage());
            return AuthResult::failure('Could not sign you in right now. Please try again.');
        }

        $hash = is_array($row) ? (string) ($row['password_hash'] ?? '') : '';

        if ($hash === '') {
            // Spend comparable time on a miss so response timing does not
            // reveal whether the address exists.
            password_verify($password, '$2y$12$' . str_repeat('.', 53));
            return AuthResult::failure('That email address and password do not match.');
        }

        if (!password_verify($password, $hash)) {
            return AuthResult::failure('That email address and password do not match.');
        }

        // Transparently upgrade the hash if PHP's default has moved on.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            try {
                $this->db->execute(
                    'UPDATE {users} SET password_hash = ?, updated_at = NOW() WHERE id = ?',
                    [self::hashPassword($password), (int) $row['id']]
                );
            } catch (Throwable) {
                // Not worth failing the login over.
            }
        }

        return AuthResult::success(
            email: (string) $row['email'],
            subject: 'local:' . $row['id'],
            // A locally-created account has no third party to vouch for the
            // address, so this reflects whatever the admin recorded.
            emailVerified: (bool) ($row['email_verified'] ?? false),
            name: isset($row['name']) ? (string) $row['name'] : null,
            returnTo: $returnTo,
        );
    }

    public static function hashPassword(string $password): string
    {
        // PASSWORD_DEFAULT follows PHP's recommendation, currently bcrypt but
        // moving to argon2id. Prefer argon2id explicitly where compiled in.
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * @return list<string> reasons the password is unacceptable, empty if fine
     */
    public function validatePassword(string $password): array
    {
        $problems = [];

        if (strlen($password) < $this->minPasswordLength()) {
            $problems[] = sprintf('Use at least %d characters.', $this->minPasswordLength());
        }

        // A length floor plus a blocklist of the obvious catches far more real
        // weak passwords than composition rules do, and annoys people less.
        $common = ['password', 'password123', '123456789', 'qwertyuiop', 'letmein', 'welcome123', 'administrator'];
        if (in_array(strtolower($password), $common, true)) {
            $problems[] = 'That password is too common — pick something else.';
        }

        return $problems;
    }

    public function test(): TestResult
    {
        try {
            $admins = (int) $this->db->value(
                'SELECT COUNT(*) FROM {users} WHERE password_hash IS NOT NULL AND password_hash <> ""'
            );
        } catch (Throwable $e) {
            return TestResult::fail('Could not read the users table.', $e->getMessage());
        }

        $algorithm = defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt';

        if ($admins === 0) {
            return TestResult::pass(
                'Ready. No local accounts exist yet — the installer will create the first one.',
                "Passwords will be hashed with {$algorithm}."
            );
        }

        return TestResult::pass(
            sprintf('Ready. %d local account(s) exist.', $admins),
            "Passwords are hashed with {$algorithm}."
        );
    }
}
