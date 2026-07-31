<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Support\Str;

/**
 * The identity an auth provider established, or why it couldn't.
 *
 * `emailVerified` is carried through deliberately. Every predecessor app
 * documented "email_verified is not enforced" as a known gap: access decisions
 * compared the raw email claim, so anyone who could get an identity provider to
 * issue a token for an address they didn't control inherited that address's
 * access. Surfacing the flag here is what lets the app choose to require it.
 */
final class AuthResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $email = '',
        public readonly ?string $subject = null,
        public readonly bool $emailVerified = false,
        public readonly ?string $name = null,
        public readonly string $returnTo = '/',
        public readonly ?string $error = null,
    ) {
    }

    public static function success(
        string $email,
        ?string $subject = null,
        bool $emailVerified = false,
        ?string $name = null,
        string $returnTo = '/'
    ): self {
        return new self(
            ok: true,
            email: Str::normalizeEmail($email),
            subject: $subject,
            emailVerified: $emailVerified,
            name: $name,
            returnTo: $returnTo,
        );
    }

    public static function failure(string $error): self
    {
        return new self(ok: false, error: $error);
    }
}
