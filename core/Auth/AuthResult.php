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
        /**
         * Would simply starting again probably work?
         *
         * True for a sign-in that failed because its state is unknown — a
         * stale page, a back button, a tab left open across a sign-out. The
         * person did nothing wrong and there is nothing for them to fix, so
         * the caller can quietly begin a fresh sign-in instead of presenting
         * an error they cannot act on.
         *
         * False for a genuine refusal: the provider said no, the token would
         * not verify, consent was declined.
         */
        public readonly bool $retryable = false,
        /**
         * The value of the one claim this site was configured to look for.
         *
         * One claim, not the whole set. Storing everything an identity provider
         * says about somebody is a decision nobody made — a token can carry
         * group memberships, a phone number, a photo — so the site names a
         * claim and gets that claim's value.
         *
         * Null means the provider did not assert it at all, which is a
         * different situation from asserting a value nobody accepts: one is
         * usually a missing scope or a setting at the provider, the other is a
         * person in the wrong organization. They need different fixes, so they
         * are kept apart all the way to the message.
         */
        public readonly ?string $claim = null,
    ) {
    }

    public static function success(
        string $email,
        ?string $subject = null,
        bool $emailVerified = false,
        ?string $name = null,
        string $returnTo = '/',
        ?string $claim = null
    ): self {
        return new self(
            ok: true,
            email: Str::normalizeEmail($email),
            subject: $subject,
            emailVerified: $emailVerified,
            name: $name,
            returnTo: $returnTo,
            claim: $claim,
        );
    }

    public static function failure(string $error): self
    {
        return new self(ok: false, error: $error);
    }

    /** A failure that starting over would probably resolve. */
    public static function retryable(string $error): self
    {
        return new self(ok: false, error: $error, retryable: true);
    }
}
