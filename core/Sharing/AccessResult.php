<?php

declare(strict_types=1);

namespace Portal\Sharing;

/**
 * The outcome of asking whether someone may open a share or bundle.
 *
 * Resolved once, at the top of the controller, so every path downstream is
 * mode-agnostic. Without this the account and gate flows would each grow their
 * own copy of the expiry, revocation, and recipient checks, and the two would
 * eventually disagree about something that matters.
 *
 * ON THE OUTCOMES THAT LOOK ALIKE:
 *
 * GONE covers revoked, expired, never existed, and malformed. All four render
 * the same words. Telling a recipient their link was revoked rather than
 * expired leaks a decision that is none of their business, and distinguishing
 * "never existed" from "expired" turns the page into a probe for whether a
 * given id was ever real.
 *
 * MISMATCH never names the intended recipient. Someone holding a forwarded
 * link must not learn who it was for.
 */
final class AccessResult
{
    public const GRANTED   = 'granted';
    public const SIGN_IN   = 'sign_in';
    public const GATE      = 'gate';
    public const MISMATCH  = 'mismatch';
    public const GONE      = 'gone';

    /**
     * A passphrase is set and this browser has not given it yet.
     *
     * A separate state rather than a flavour of GATE, because the two ask for
     * different things and can both apply to the same link: a gate-mode share
     * with a passphrase wants the passphrase first and the address second.
     *
     * This state is only ever reached for a link that is otherwise LIVE, so it
     * does reveal that the id is real -- exactly as GATE and SIGN_IN already
     * do, and for the same unavoidable reason: a working link has to do
     * something. What must stay indistinguishable is the FAILURES, and a wrong
     * passphrase returns GONE along with every other refusal.
     */
    public const LOCKED    = 'locked';

    private function __construct(
        public readonly string $state,
        public readonly ?Share $share = null,
        public readonly ?Bundle $bundle = null,
        public readonly ?string $viewerEmail = null,
        /** Set only when a fresh gate cookie should be attached to the response. */
        public readonly ?string $grant = null,
    ) {
    }

    public static function granted(
        ?Share $share,
        ?Bundle $bundle,
        string $viewerEmail,
        ?string $grant = null
    ): self {
        return new self(self::GRANTED, $share, $bundle, $viewerEmail, $grant);
    }

    /** Account mode, nobody signed in: send them to the identity provider. */
    public static function signIn(): self
    {
        return new self(self::SIGN_IN);
    }

    /** Gate mode, no valid grant: show the "what is your email address" form. */
    public static function gate(): self
    {
        return new self(self::GATE);
    }

    /** Signed in, but as somebody else. */
    public static function mismatch(): self
    {
        return new self(self::MISMATCH);
    }

    /** Revoked, expired, never existed, or malformed — indistinguishable. */
    public static function gone(): self
    {
        return new self(self::GONE);
    }

    /** Locked behind a passphrase this browser has not supplied. */
    public static function locked(?Share $share): self
    {
        return new self(self::LOCKED, $share);
    }

    /*
     * There was an isGranted() here, and it never had a caller.
     *
     * Both consumers dispatch with `match ($result->state)` over all five
     * states, which is the shape this class wants: adding a sixth state makes
     * every match arm visible at once, where a boolean would quietly fold the
     * new case into whatever the `default` arm does. On an access decision,
     * that default is the difference between a refusal and a page.
     *
     * Removed rather than left as a convenience, because the convenient version
     * is the one that stops you noticing the case you have not handled.
     */
}
