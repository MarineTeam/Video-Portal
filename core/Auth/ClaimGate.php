<?php

declare(strict_types=1);

namespace Portal\Auth;

/**
 * Membership of something, asserted by the identity provider.
 *
 * The third gate, and the only one whose answer this site does not own. The
 * allowlist is a list somebody here maintains; the approval flag is a decision
 * somebody here makes; this is a claim in a verified ID token — an Auth0
 * organization (`org_id`), a Google Workspace domain (`hd`), an Azure tenant
 * (`tid`) — and all this site can do is say which values it accepts.
 *
 * HOW IT COMBINES WITH THE ALLOWLIST
 *
 *   BOTH          both must pass — a member of the organization AND somebody
 *                 we listed. The default, and what anything unrecognised
 *                 resolves to.
 *   ORGANIZATION  membership only; the list is ignored.
 *   ALLOWLIST     the list only; membership is ignored.
 *   EITHER        one is enough. What a site wants when some people sign in
 *                 through the organization and others have personal accounts —
 *                 the list is then how an individual gets in without being
 *                 added to the organization.
 *
 * There is deliberately no value that switches both off. That is not caution
 * for its own sake: a mode meaning "let everybody in" is one typo away from
 * being selected, and the way to have no gate is to configure no gate. A test
 * asserts that every reachable value — legal or mistyped — consults at least
 * one check.
 *
 * WHICH CHECKS A MODE COUNTS IS NOT WHETHER THEY ARE CONFIGURED. That second
 * fact belongs to the caller, and keeping them apart is what stops "require
 * both" refusing every visitor to a site that has no organization set up —
 * which, on a product installed by strangers on hosting with no shell, is a
 * site nobody can recover.
 *
 * A typo must not loosen a boundary: the failure should be somebody being
 * refused and asking why, not somebody getting in and nobody asking anything.
 */
final class ClaimGate
{
    /**
     * The four modes, named.
     *
     *   BOTH          organisation AND allowlist
     *   ORGANIZATION  membership only; the list is ignored
     *   ALLOWLIST     the list only; membership is ignored
     *   EITHER        one of them is enough
     *
     * There is deliberately no fifth value meaning "neither", and a test
     * asserts that every mode counts at least one check. A mode that switched
     * both off would be one typo away from being selected, and the way to have
     * no gate is to configure no gate.
     */
    public const BOTH = 'BOTH';
    public const ORGANIZATION = 'ORGANIZATION';
    public const ALLOWLIST = 'ALLOWLIST';
    public const EITHER = 'EITHER';

    /** @var list<string> */
    private const MODES = [self::BOTH, self::ORGANIZATION, self::ALLOWLIST, self::EITHER];

    /** The provider asserted the claim, but not a value this site accepts. */
    public const NOT_A_MEMBER = 'not_a_member';

    /** The provider did not assert the claim at all. */
    public const NO_CLAIM = 'no_claim';

    /** In `either` mode: neither gate let them through. */
    public const NEITHER = 'neither';

    /**
     * Accepted values, from the comma-separated list an administrator typed.
     *
     * Case and surrounding space are trimmed but case is NOT folded: these are
     * opaque identifiers from somebody else's system — `org_A1b2C3` — and two
     * that differ only in case are two different organizations. Lowercasing
     * them would silently accept one the administrator did not name.
     *
     * @return list<string>
     */
    public static function parseValues(string $raw): array
    {
        $out = [];

        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $value) {
            $value = trim($value);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Does the claim this account carries satisfy the gate?
     *
     * @param list<string> $accepted
     */
    public static function decide(bool $enabled, ?string $claimValue, array $accepted): ?string
    {
        // No accepted values means nothing to check against. Treated as OFF
        // rather than as "accept nothing", because the alternative is that
        // clearing the field refuses the entire site.
        if (!$enabled || $accepted === []) {
            return null;
        }

        if ($claimValue === null || $claimValue === '') {
            return self::NO_CLAIM;
        }

        return in_array($claimValue, $accepted, true) ? null : self::NOT_A_MEMBER;
    }

    /**
     * Put the two gates together.
     *
     * In `either` mode a refusal means BOTH said no, and it is reported as its
     * own reason rather than as one of theirs — because there are then two ways
     * to fix it and naming only one sends somebody down half the path.
     */
    public static function combine(string $mode, ?string $allowlist, ?string $claim): ?string
    {
        if (self::normalizeMode($mode) === self::EITHER) {
            if ($allowlist === null || $claim === null) {
                return null;
            }

            return self::NEITHER;
        }

        return $allowlist ?? $claim;
    }

    /**
     * Anything unrecognised is the strictest mode. A typo must not open a gate.
     *
     * Compared case-insensitively after trimming, because these are values
     * somebody types: "both" and " BOTH " are plainly the same intention, and
     * treating them as a typo would resolve them to BOTH anyway — but by
     * accident rather than on purpose, which is the kind of luck that stops
     * holding the day somebody adds a looser default.
     */
    public static function normalizeMode(string $mode): string
    {
        $mode = strtoupper(trim($mode));

        return in_array($mode, self::MODES, true) ? $mode : self::BOTH;
    }

    /**
     * Does this mode count the organisation check?
     *
     * Separate from whether the check is CONFIGURED, which is a different fact
     * and is the caller's to establish. A check that counts but has nothing to
     * check against cannot refuse anybody, and conflating the two is how
     * selecting BOTH on a site with no organisation configured would refuse
     * every visitor.
     */
    public static function countsOrganisation(string $mode): bool
    {
        return in_array(
            self::normalizeMode($mode),
            [self::BOTH, self::ORGANIZATION, self::EITHER],
            true
        );
    }

    /** Does this mode count the address list? */
    public static function countsAllowlist(string $mode): bool
    {
        return in_array(
            self::normalizeMode($mode),
            [self::BOTH, self::ALLOWLIST, self::EITHER],
            true
        );
    }

    /**
     * The modes an administrator picks from, with what each one does.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            self::BOTH         => 'Require both — membership and the address list',
            self::ORGANIZATION => 'Membership only — ignore the address list',
            self::ALLOWLIST    => 'The address list only — ignore membership',
            self::EITHER       => 'Either one is enough',
        ];
    }

    /**
     * Should the extra authorize parameter be sent?
     *
     * Auth0 takes an `organization` parameter and renders that organization's
     * login directly. Sending it is right when there is exactly one accepted
     * value and membership is required. It is WRONG in two cases, both learned
     * the hard way in the application this is ported from:
     *
     *   - Several accepted values: there is no single one to send, and sending
     *     any of them picks for the person. Withholding it makes Auth0 show its
     *     own organization picker, which is the correct behaviour.
     *
     *   - `either` mode: sending it makes Auth0 refuse a non-member before this
     *     site ever gets to check its own allowlist, so the personal-account
     *     route it exists to provide is unreachable.
     *
     * @param list<string> $accepted
     */
    public static function authorizeValue(string $mode, array $accepted): ?string
    {
        $mode = self::normalizeMode($mode);

        /*
         * Withheld under EITHER, and under ALLOWLIST for a sharper version of
         * the same reason. Under EITHER the provider would refuse a non-member
         * before this site could offer them the personal-account route; under
         * ALLOWLIST membership is not being checked at all, so asking the
         * provider to enforce it refuses people this site would admit.
         */
        if ($mode === self::EITHER || $mode === self::ALLOWLIST) {
            return null;
        }

        return count($accepted) === 1 ? $accepted[0] : null;
    }

    /** Something an administrator can act on. */
    public static function explain(string $reason): string
    {
        return match ($reason) {
            self::NOT_A_MEMBER => 'This account is not in an organization this site accepts.',
            self::NO_CLAIM => 'The sign-in provider did not say which organization this account is in. '
                . 'That is usually a missing scope or a setting at the provider rather than the person.',
            self::NEITHER => 'This account is neither in an accepted organization nor on the list of '
                . 'addresses that may sign in. Either would be enough.',
            default => 'This account cannot sign in here.',
        };
    }
}
