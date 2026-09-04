<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;
use Portal\Support\Str;
use RuntimeException;

/**
 * Reads and writes user accounts.
 *
 * Every lookup goes through a normalized email. That single rule is what keeps
 * "the person who signed in" and "the person a share was addressed to" the same
 * person when one was typed with a capital letter.
 */
final class UserRepository
{
    private const SELECT = 'SELECT u.*, r.slug AS role_slug FROM {users} u LEFT JOIN {roles} r ON r.id = u.role_id';

    public function __construct(private readonly Db $db)
    {
    }

    public function find(int $id): ?User
    {
        $row = $this->db->first(self::SELECT . ' WHERE u.id = ? LIMIT 1', [$id]);
        return $row === null ? null : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $email = Str::normalizeEmail($email);
        if ($email === '') {
            return null;
        }
        $row = $this->db->first(self::SELECT . ' WHERE u.email = ? LIMIT 1', [$email]);
        return $row === null ? null : User::fromRow($row);
    }

    /**
     * Find or create the account for someone who just authenticated.
     *
     * New accounts are created with `authorized = 0` and the viewer role. That
     * is the fail-closed default the whole model rests on: signing in proves
     * who you are and grants nothing. An administrator decides the rest.
     *
     * The one exception is the very first account, which the installer creates
     * directly as an administrator — bootstrapping has to start somewhere.
     */
    public function findOrCreateFromAuth(AuthResult $auth): User
    {
        if (!$auth->ok) {
            throw new RuntimeException('Cannot create a user from a failed sign-in.');
        }

        $email = Str::normalizeEmail($auth->email);

        /*
         * Which account this sign-in belongs to, decided by IdentityLink.
         *
         * The subject is asked FIRST and the address only afterwards, which is
         * the whole of the fix. This used to match on email alone and rebind
         * auth_subject to whoever signed in last — so signing in with a second
         * provider took over the account holding that address, verified or not.
         */
        $identity = $auth->subject !== null ? $this->findIdentity($auth) : null;
        $existing = $identity !== null
            ? $this->find((int) $identity['user_id'])
            : $this->findByEmail($email);

        /*
         * A LOCAL PASSWORD PROVES THE ACCOUNT, so the link rule does not apply
         * to it.
         *
         * The rule exists because a remote provider ASSERTING an address is not
         * evidence the person owns it. A password typed here is different in
         * kind: LocalProvider has already checked it against that exact row,
         * and its subject — `local:<id>` — names the account rather than
         * describing somebody who might match one.
         *
         * Missing this refused every existing local account on the first
         * sign-in after the upgrade: the address is "taken" by the very person
         * typing the password, and a local account has no third party to mark
         * it verified. That is the break-glass path on a host with no shell,
         * so it would have been the worst possible thing to close — and the
         * smoke run caught it on the administrator's own sign-in.
         */
        $selfProven = str_starts_with((string) $auth->subject, 'local:');

        $decision = $selfProven
            ? IdentityLink::KNOWN
            : IdentityLink::decide($identity !== null, $existing !== null, $auth->emailVerified);

        if ($decision === IdentityLink::REFUSE) {
            /*
             * Refused rather than given a second account on the same address.
             * Two accounts sharing an address makes "who is this person"
             * unanswerable, and the duplicate would be the one nobody has
             * approved — so the refusal is also the honest answer.
             */
            throw new RuntimeException(IdentityLink::explain());
        }

        if ($existing !== null) {
            /*
             * Record the provider identity and refresh what the provider told
             * us. email_verified is only ever raised here, never lowered: an
             * admin may have verified an address by hand, and a provider that
             * omits the claim should not silently undo that.
             *
             * auth_claim is the opposite — overwritten outright, not COALESCEd.
             * A stale value there is worse than none: somebody removed from an
             * organization would keep the membership that lets them in, and the
             * gate would go on trusting a fact that stopped being true. The
             * latest word from the provider replaces the previous one,
             * including replacing it with nothing.
             */
            $this->db->execute(
                'UPDATE {users}
                    SET auth_provider = COALESCE(?, auth_provider),
                        auth_subject  = COALESCE(?, auth_subject),
                        name          = COALESCE(NULLIF(?, ""), name),
                        auth_claim    = ?,
                        email_verified = GREATEST(email_verified, ?),
                        last_seen_at  = NOW(),
                        updated_at    = NOW()
                  WHERE id = ?',
                [
                    $auth->subject !== null ? $this->providerFromSubject($auth) : null,
                    $auth->subject,
                    $auth->name ?? '',
                    $auth->claim,
                    $auth->emailVerified ? 1 : 0,
                    $existing->id,
                ]
            );

            $this->rememberIdentity($existing->id, $auth, $email);

            return $this->find($existing->id) ?? $existing;
        }

        $viewerRoleId = $this->roleId(Capability::ROLE_VIEWER);

        $id = $this->db->insert('users', [
            'email'          => $email,
            'name'           => $auth->name,
            'role_id'        => $viewerRoleId,
            'auth_provider'  => $this->providerFromSubject($auth),
            'auth_subject'   => $auth->subject,
            'auth_claim'     => $auth->claim,
            'email_verified' => $auth->emailVerified ? 1 : 0,
            'authorized'     => 0,
            'last_seen_at'   => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $user = $this->find($id);
        if ($user === null) {
            throw new RuntimeException('The account was created but could not be read back.');
        }

        $this->rememberIdentity($id, $auth, $email);

        return $user;
    }

    /**
     * The stored identity for this sign-in, if this person has been here before.
     *
     * Matched on (provider, subject) and never on the address: a subject is the
     * provider's own stable identifier, while an address changes — people are
     * renamed, and an organisation may reassign one that used to belong to
     * somebody else.
     *
     * @return array<string, mixed>|null
     */
    private function findIdentity(AuthResult $auth): ?array
    {
        try {
            return $this->db->first(
                'SELECT * FROM {user_identities} WHERE provider = ? AND subject = ?',
                [$this->providerNameFor($auth), (string) $auth->subject]
            );
        } catch (Throwable $e) {
            /*
             * Before migration 0030 has run — on the one request that applies
             * it — the table may not exist. Answering "not known" falls back to
             * matching by address, which is the behaviour that shipped before
             * and no worse than it; failing the sign-in outright would make an
             * upgrade look like an outage.
             */
            error_log('Portal: could not read sign-in identities: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Record that this identity signed in, creating the link if it is new.
     *
     * verified_at is raised and never lowered, like email_verified above: a
     * provider that omits the claim on one sign-in has not withdrawn a
     * verification it made before, and reading silence as a retraction would
     * quietly break the next attach for that person.
     */
    private function rememberIdentity(int $userId, AuthResult $auth, string $email): void
    {
        if ($auth->subject === null || $auth->subject === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');

        try {
            $this->db->execute(
                'INSERT INTO {user_identities}
                    (user_id, provider, subject, email, verified_at, created_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    email        = VALUES(email),
                    verified_at  = COALESCE(verified_at, VALUES(verified_at)),
                    last_seen_at = VALUES(last_seen_at)',
                [
                    $userId,
                    $this->providerNameFor($auth),
                    $auth->subject,
                    mb_substr($email, 0, 191),
                    $auth->emailVerified ? $now : null,
                    $now,
                    $now,
                ]
            );
        } catch (Throwable $e) {
            // Recording the link must not fail a sign-in that has already been
            // decided. The person is in; what is lost is the audit trail.
            error_log('Portal: could not record a sign-in identity: ' . $e->getMessage());
        }
    }

    /** 'local' for a password here, otherwise the remote provider. */
    private function providerNameFor(AuthResult $auth): string
    {
        return str_starts_with((string) $auth->subject, 'local:') ? 'local' : 'oidc';
    }

    /**
     * Every way this person can sign in.
     *
     * For an administrator answering "how does this person get in", which until
     * now had no answer beyond a single column that the last sign-in overwrote.
     *
     * @return list<array<string, mixed>>
     */
    public function identitiesFor(int $userId): array
    {
        try {
            return $this->db->all(
                'SELECT * FROM {user_identities} WHERE user_id = ? ORDER BY created_at',
                [$userId]
            );
        } catch (Throwable) {
            return [];
        }
    }

    private function providerFromSubject(AuthResult $auth): ?string
    {
        if ($auth->subject === null) {
            return null;
        }
        return str_starts_with($auth->subject, 'local:') ? 'local' : null;
    }

    /**
     * Create an account directly. Used by the installer and by admins adding
     * someone by hand.
     */
    public function create(
        string $email,
        ?string $name = null,
        string $roleSlug = Capability::ROLE_VIEWER,
        ?string $password = null,
        bool $authorized = false,
        ?string $authorizedBy = null
    ): User {
        $email = Str::normalizeEmail($email);
        if (!Str::isEmail($email)) {
            throw new RuntimeException("'{$email}' is not a valid email address.");
        }
        if ($this->findByEmail($email) !== null) {
            throw new RuntimeException("An account already exists for {$email}.");
        }

        // An account with no password is normal — that is every account that
        // signs in through a provider. An account WITH one has to have chosen
        // an acceptable one, and this is the only path that creates them.
        if ($password !== null) {
            self::assertAcceptable($password, null);
        }

        $now = date('Y-m-d H:i:s');

        $id = $this->db->insert('users', [
            'email'         => $email,
            'name'          => $name,
            'role_id'       => $this->roleId($roleSlug),
            'password_hash' => $password !== null ? LocalProvider::hashPassword($password) : null,
            'authorized'    => $authorized ? 1 : 0,
            'authorized_at' => $authorized ? $now : null,
            'authorized_by' => $authorized ? $authorizedBy : null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $user = $this->find($id);
        if ($user === null) {
            throw new RuntimeException('The account was created but could not be read back.');
        }

        return $user;
    }

    /**
     * Set or replace an account's password.
     *
     * Validated here rather than only at the form, because "the form checks it"
     * is true right up until the second caller. This method had no callers at
     * all until a change-password page existed, and the rule it enforces had
     * none either — so the one password this product ever asked a human to
     * choose went in unexamined.
     *
     * @throws RuntimeException when the password is not acceptable
     */
    public function setPassword(int $userId, string $password, ?int $minimum = null): void
    {
        self::assertAcceptable($password, $minimum);

        $this->db->execute(
            'UPDATE {users} SET password_hash = ?, updated_at = NOW() WHERE id = ?',
            [LocalProvider::hashPassword($password), $userId]
        );
    }

    /**
     * The one place that decides whether a password may be stored.
     *
     * Throwing rather than returning a list: every caller here is about to
     * write, and a caller that wants to SHOW the reasons calls
     * PasswordPolicy::problems() first and never reaches this. That keeps the
     * helpful path and the safe path from being the same code, which is what
     * lets the safe one be unconditional.
     *
     * @throws RuntimeException
     */
    private static function assertAcceptable(string $password, ?int $minimum): void
    {
        $problems = PasswordPolicy::problems($password, $minimum);

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }
    }

    public function setAuthorized(int $userId, bool $authorized, ?string $by = null): void
    {
        $this->db->execute(
            'UPDATE {users}
                SET authorized = ?, authorized_at = ?, authorized_by = ?, updated_at = NOW()
              WHERE id = ?',
            [$authorized ? 1 : 0, $authorized ? date('Y-m-d H:i:s') : null, $by, $userId]
        );

        /*
         * Only the approval fires. Withdrawing access is not the same event and
         * an integration told "authorized" for both would grant on a revoke —
         * so there is no hook here for the false case rather than one carrying
         * a flag somebody has to read correctly.
         */
        if ($authorized) {
            do_action('user_authorized', $userId, $by);
        }
    }

    public function setRole(int $userId, string $roleSlug): void
    {
        $this->db->execute(
            'UPDATE {users} SET role_id = ?, updated_at = NOW() WHERE id = ?',
            [$this->roleId($roleSlug), $userId]
        );
    }

    /**
     * Stamp last-seen, best effort.
     *
     * Explicitly swallows failures: this is telemetry, and a locked table
     * should never stop someone watching a video.
     */
    public function touchLastSeen(int $userId): void
    {
        try {
            $this->db->execute('UPDATE {users} SET last_seen_at = NOW() WHERE id = ?', [$userId]);
        } catch (\Throwable) {
            // Intentionally ignored.
        }
    }

    public function roleId(string $slug): ?int
    {
        $id = $this->db->value('SELECT id FROM {roles} WHERE slug = ? LIMIT 1', [$slug]);
        return $id === null ? null : (int) $id;
    }

    public function countAdmins(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {users} u JOIN {roles} r ON r.id = u.role_id WHERE r.slug = ?',
            [Capability::ROLE_ADMIN]
        );
    }

    /**
     * True when removing this person's admin role would leave the site with
     * none. The admin UI refuses that — it is an unrecoverable lockout on a
     * host with no shell access.
     */
    public function isLastAdmin(int $userId): bool
    {
        $user = $this->find($userId);
        return $user !== null && $user->isAdmin() && $this->countAdmins() <= 1;
    }
}
