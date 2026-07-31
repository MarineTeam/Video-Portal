<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Support\Str;

/**
 * An authenticated person.
 *
 * Note what this object does NOT have: a `can()` method. Permission resolution
 * needs the database and the category tree, so it lives in Capabilities. Giving
 * User a convenience `can()` would mean either passing a resolver into every
 * User, or a hidden global lookup — and the second is how permission checks end
 * up silently succeeding in tests that never wired up the database.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly ?string $name = null,
        public readonly ?string $roleSlug = null,
        public readonly bool $authorized = false,
        public readonly bool $emailVerified = false,
        public readonly ?string $authProvider = null,
        public readonly ?string $authSubject = null,
        public readonly bool $hasPassword = false,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:            (int) $row['id'],
            email:         Str::normalizeEmail((string) $row['email']),
            name:          isset($row['name']) && $row['name'] !== null ? (string) $row['name'] : null,
            roleSlug:      isset($row['role_slug']) && $row['role_slug'] !== null ? (string) $row['role_slug'] : null,
            authorized:    (bool) ($row['authorized'] ?? false),
            emailVerified: (bool) ($row['email_verified'] ?? false),
            authProvider:  isset($row['auth_provider']) && $row['auth_provider'] !== null ? (string) $row['auth_provider'] : null,
            authSubject:   isset($row['auth_subject']) && $row['auth_subject'] !== null ? (string) $row['auth_subject'] : null,
            hasPassword:   isset($row['password_hash']) && (string) $row['password_hash'] !== '',
        );
    }

    /**
     * The administrator short-circuit.
     *
     * Deliberately a role-slug comparison and nothing else. Making "admin" a
     * capability someone could hold would let a person with MANAGE_PERMISSIONS
     * grant it to themselves, which is exactly the escalation the separation
     * exists to prevent.
     */
    public function isAdmin(): bool
    {
        return $this->roleSlug === Capability::ROLE_ADMIN;
    }

    public function displayName(): string
    {
        return $this->name !== null && trim($this->name) !== '' ? $this->name : $this->email;
    }
}
