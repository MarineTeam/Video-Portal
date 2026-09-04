<?php

declare(strict_types=1);

namespace Portal\Auth;

/**
 * Whether a sign-in may be attached to an account that already exists.
 *
 * Pure, so the rule can be read and tested without a database, a provider or a
 * request — because it is the rule an account takeover would go through.
 *
 * THREE OUTCOMES, AND THE MIDDLE ONE IS THE POINT
 *
 *   KNOWN      this (provider, subject) has signed in here before. It is that
 *              account, whatever address the provider sends today — people are
 *              renamed and addresses are reassigned, and the subject is the
 *              only stable identifier in the exchange.
 *
 *   ATTACH     a new identity, and an account already holds this address. Only
 *              allowed when the provider says the address is VERIFIED.
 *
 *   REFUSE     a new identity, an account holds the address, and the provider
 *              did not verify it. Anybody who can get any configured provider
 *              to assert an address — and one that lets you type an unverified
 *              one is enough — would otherwise inherit the account that holds
 *              it, with its history and its permissions.
 *
 *   CREATE     nobody holds the address. A new account, unapproved, as always.
 */
final class IdentityLink
{
    /** This identity has signed in before; it names its account directly. */
    public const KNOWN = 'known';

    /** First time for this identity, and it may join an existing account. */
    public const ATTACH = 'attach';

    /** First time, an account holds the address, and nothing verified it. */
    public const REFUSE = 'refuse';

    /** Nobody holds the address. */
    public const CREATE = 'create';

    /**
     * @param bool $identityKnown  is there a row for this (provider, subject)?
     * @param bool $emailTaken     does an account already hold this address?
     * @param bool $emailVerified  did the provider say so, this sign-in?
     */
    public static function decide(bool $identityKnown, bool $emailTaken, bool $emailVerified): string
    {
        /*
         * Checked first, and deliberately before anything about the address.
         * A person whose address was changed at the provider is still the same
         * person, and refusing them because the new address belongs to somebody
         * else here would lock somebody out of their own account for a reason
         * they cannot see or fix.
         */
        if ($identityKnown) {
            return self::KNOWN;
        }

        if (!$emailTaken) {
            return self::CREATE;
        }

        return $emailVerified ? self::ATTACH : self::REFUSE;
    }

    /**
     * Why the refusal, in words an administrator can act on.
     *
     * Said to the person too, because there is a real chance they are exactly
     * who they say they are and the provider simply has not confirmed the
     * address — which is something they can go and fix, unlike most refusals.
     */
    public static function explain(): string
    {
        return 'An account here already uses that address, and the sign-in service did not confirm '
            . 'that the address belongs to you. Confirm it with that service and try again, or sign '
            . 'in the way you did before.';
    }
}
