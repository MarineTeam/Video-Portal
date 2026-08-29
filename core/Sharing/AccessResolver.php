<?php

declare(strict_types=1);

namespace Portal\Sharing;

use Portal\Auth\Guard;
use Portal\Http\Request;
use Portal\Support\Str;

/**
 * Decides whether a visitor may open a share or a bundle.
 *
 * The single place both access modes are reconciled. Callers get one
 * AccessResult and never branch on the mode themselves — the moment two
 * controllers each implement "is this link still good", they start to disagree.
 *
 * Order matters and is deliberate: liveness is checked BEFORE identity. A
 * revoked link answers "gone" to everyone, including its rightful recipient,
 * rather than first establishing who they are. Checking identity first would
 * mean a recipient could distinguish "revoked" from "not for you", and an
 * outsider could learn a link had once been real.
 */
final class AccessResolver
{
    public function __construct(
        private readonly ShareRepository $shares,
        private readonly BundleRepository $bundles,
        private readonly Gate $gate,
        private readonly Guard $guard,
    ) {
    }

    public function resolveShare(string $id, Request $request): AccessResult
    {
        $share = $this->shares->find($id);

        // Missing, malformed, revoked, and expired all land here together.
        if ($share === null || !$share->isLive()) {
            return AccessResult::gone();
        }

        /*
         * The passphrase comes BEFORE identity, for the same reason liveness
         * does.
         *
         * Asking who somebody is first would mean the account-mode mismatch
         * page — which is a different response — could be reached without ever
         * knowing the passphrase, and that page confirms the link is real and
         * addressed to somebody else. Something you KNOW is the outer lock;
         * everything else happens behind it.
         */
        if ($share->passwordProtected && !$this->unlocked($share->id, $request)) {
            return AccessResult::locked($share);
        }

        return $share->requiresAccount()
            ? $this->viaAccount($share, null, $share->recipientEmail)
            : $this->viaGate('share', $share->id, $share, null, $share->recipientEmail, $request);
    }

    /**
     * Has this browser already given the passphrase for this link?
     *
     * Reads the signed cookie the unlock form sets. Any failure — no cookie, a
     * forged one, a secret that is unset — answers no, which asks for the
     * passphrase again. Failing to "locked" rather than to "granted" is the
     * only safe direction: the cost of being wrong here is one extra prompt,
     * and the cost of being wrong the other way is the lock never applying.
     */
    private function unlocked(string $shareId, Request $request): bool
    {
        try {
            $presented = $request->cookie($this->gate->unlockCookieName($shareId));

            return is_string($presented) && $this->gate->unlockMatches($shareId, $presented);
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolveBundle(string $id, Request $request): AccessResult
    {
        $bundle = $this->bundles->find($id);

        if ($bundle === null || $bundle->hasExpired()) {
            return AccessResult::gone();
        }

        // A bundle whose every item has died is gone, not empty. A page saying
        // "nothing here" is a worse answer than a link that plainly does not
        // resolve, and it confirms the bundle existed.
        if ($this->bundles->liveItems($bundle->id) === []) {
            return AccessResult::gone();
        }

        return $bundle->requiresAccount()
            ? $this->viaAccount(null, $bundle, $bundle->recipientEmail)
            : $this->viaGate('bundle', $bundle->id, null, $bundle, $bundle->recipientEmail, $request);
    }

    /**
     * Account mode: the session's email must be the recipient's.
     */
    private function viaAccount(?Share $share, ?Bundle $bundle, string $recipient): AccessResult
    {
        $user = $this->guard->user();

        if ($user === null) {
            return AccessResult::signIn();
        }

        if (Str::normalizeEmail($user->email) !== $recipient) {
            return AccessResult::mismatch();
        }

        // Deliberately NOT requiring an authorized account. A share is a
        // specific, deliberate grant to a named person; making them wait for
        // separate approval as well would defeat the point of sending it.
        return AccessResult::granted($share, $bundle, $user->email);
    }

    /**
     * Gate mode: a valid grant cookie, or a magic-link key in the URL.
     */
    private function viaGate(
        string $targetType,
        string $targetId,
        ?Share $share,
        ?Bundle $bundle,
        string $recipient,
        Request $request
    ): AccessResult {
        // A key in the URL means they have just clicked the emailed link.
        // Redeemed first, so arriving with a fresh key always works even if an
        // older cookie is still lying around.
        $key = $request->query('key');

        if ($key !== null && $key !== '') {
            $grant = $this->gate->redeem($targetType, $targetId, $key);

            if ($grant !== null) {
                return AccessResult::granted($share, $bundle, $recipient, $grant);
            }

            // A spent or forged key is not an error worth explaining — it is
            // just another way of not being signed in.
            return AccessResult::gate();
        }

        $cookie = $request->cookie($this->gate->cookieName($targetType, $targetId));

        if ($cookie === null || $cookie === '') {
            return AccessResult::gate();
        }

        $email = $this->gate->verify($targetType, $targetId, $cookie);

        if ($email === null) {
            return AccessResult::gate();
        }

        // The grant is bound to a target and signed, but the recipient may
        // have changed since it was issued — a share re-created for someone
        // else reuses neither id nor grant, but this costs nothing.
        if (Str::normalizeEmail($email) !== $recipient) {
            return AccessResult::gate();
        }

        return AccessResult::granted($share, $bundle, $email);
    }
}
