<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Content\CategoryRepository;
use Portal\Content\SeriesRepository;
use Portal\Content\SpeakerRepository;
use Portal\Content\SubscriptionRepository;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;
use Portal\Support\RateLimit;
use Portal\Support\Str;
use Throwable;

/**
 * Subscribing to new content, and getting out again.
 *
 * The unsubscribe half is the part worth care. It has to work for somebody with
 * no account, from a link in an email, possibly years later, on a phone, in one
 * tap — because the alternative to a working unsubscribe link is the spam
 * button, and that costs the whole site's deliverability rather than one
 * subscription.
 */
final class SubscriptionController extends Controller
{
    /**
     * Subscribe.
     *
     * Deliberately open to people with no account: a podcast listener or a
     * parent who wants to know when the service is posted has no reason to
     * create one, and requiring it would mean the feature is only used by
     * people who already visit.
     */
    public function subscribe(Request $request): Response
    {
        /*
         * No CSRF token here, and that is a decision.
         *
         * A token protects an action that borrows the victim's AUTHORITY —
         * their session, their role, their account. Subscribing borrows none:
         * an attacker can post any address from their own machine without
         * involving a victim at all, so a token stops nothing they could not
         * already do. What actually bounds the abuse is the rate limit below
         * and the unsubscribe link in the first email.
         *
         * Requiring one would cost something real. The form appears on every
         * public listing, so generating a token would start a session and set a
         * cookie for every anonymous visitor to the site — a row per stranger
         * and a cookie nobody needed, to protect a POST that has no authority
         * to steal.
         *
         * The same reasoning exempts the notice-dismiss route. Both are
         * anonymous endpoints whose authority is not the session.
         */
        if (!$this->config()->settingBool('subscriptions_enabled', true)) {
            return $this->back($request, 'Subscriptions are switched off on this site.', 'error');
        }

        $user = $this->user();
        $email = Str::normalizeEmail((string) ($request->input('email') ?? $user?->email ?? ''));

        if (!Str::isEmail($email)) {
            return $this->back($request, 'That does not look like an email address.', 'error');
        }

        $scope = SubscriptionRepository::sanitizeScope($request->input('scope'))
            ?? SubscriptionRepository::SITE;

        $scopeId = (int) ($request->input('scope_id') ?? 0);
        $scopeId = $scopeId > 0 ? $scopeId : null;

        // A scope that names something must name something that exists,
        // otherwise a tampered form creates a subscription to nothing that
        // shows on the admin screen as a subscriber who never hears anything.
        if ($scope !== SubscriptionRepository::SITE && !$this->targetExists($scope, $scopeId)) {
            return $this->back($request, 'There is nothing to subscribe to there.', 'error');
        }

        /*
         * Rate-limited by address AND by IP.
         *
         * By address, because this endpoint will happily email a confirmation
         * to anybody named in the form, and without a limit it is a way to use
         * this site to send mail to a stranger. By IP, because one script
         * should not be able to fill the table.
         */
        $limiter = new RateLimit($this->db());

        if (!$limiter->allow('subscribe:' . $email, 5, 3600)
            || !$limiter->allow('subscribe-ip:' . $request->ip(), 20, 3600)) {
            /*
             * The same wording as success. Telling somebody they have been
             * rate-limited on an address confirms that address was used here,
             * which is the enumeration the sharing gate is careful about too.
             */
            return $this->back($request, $this->confirmation());
        }

        try {
            $repo = $this->subscriptions();
            $repo->subscribe($email, $scope, $scopeId, $user?->id);
        } catch (Throwable $e) {
            error_log('Could not subscribe: ' . $e->getMessage());

            return $this->back($request, 'That did not work. Try again in a moment.', 'error');
        }

        Audit::log($this->db(), $email, 'subscription.create', $scope, (string) ($scopeId ?? ''));

        return $this->back($request, $this->confirmation());
    }

    /**
     * The unsubscribe page.
     *
     * A GET that shows a button rather than one that acts. Mail clients and
     * security scanners follow links in email without being asked, and an
     * unsubscribe that happens on GET is one that fires when a scanner looks at
     * the message — quietly removing somebody who never clicked anything.
     *
     * @param array<string, string> $params
     */
    public function confirmUnsubscribe(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $subscription = $this->subscriptions()->findByToken($token);

        return $this->view(['unsubscribe', 'index'], [
            'title'       => 'Unsubscribe',
            'heading'     => 'Unsubscribe',
            'token'       => $token,
            // Null when the token is unknown — already used, or never real. The
            // template says the same thing either way.
            'found'       => $subscription !== null,
            'description' => $subscription === null ? null : $this->describe($subscription),
            'videos'      => [],
            'children'    => [],
            'pagination'  => ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null],
            'flash'       => $this->flash(),
        ]);
    }

    /**
     * Actually unsubscribe.
     *
     * Answers identically whether or not the token matched. A token is a
     * credential; "no such subscription" tells whoever is probing that a
     * different guess might work.
     */
    public function unsubscribe(Request $request): Response
    {
        /*
         * No CSRF token, for a sharper version of the reason above: the
         * subscription token IS the authority here. Somebody who can forge this
         * request already holds the token, and a session-bound token cannot
         * protect a credential the attacker has. Meanwhile the person using it
         * has no session at all — they arrived from an email.
         */
        $token = (string) ($request->input('token') ?? '');
        $repo = $this->subscriptions();

        $subscription = $repo->findByToken($token);

        if ($subscription !== null && $request->input('all') !== null) {
            // "Stop everything" — the option somebody wants when they have
            // subscribed to four things and remember none of them.
            $repo->unsubscribeAll((string) $subscription['email']);
        } else {
            $repo->unsubscribe($token);
        }

        if ($subscription !== null) {
            Audit::log($this->db(), (string) $subscription['email'], 'subscription.delete');
        }

        return $this->view(['unsubscribe', 'index'], [
            'title'       => 'Unsubscribed',
            'heading'     => 'Unsubscribed',
            'token'       => '',
            'found'       => false,
            'description' => null,
            'done'        => true,
            'videos'      => [],
            'children'    => [],
            'pagination'  => ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null],
            'flash'       => null,
        ]);
    }

    // --------------------------------------------------------------- helpers

    private function subscriptions(): SubscriptionRepository
    {
        return $this->container->get(SubscriptionRepository::class);
    }

    /**
     * The same words whether the subscription was new or already there.
     *
     * Saying "you were already subscribed" to somebody who typed a stranger's
     * address confirms that the stranger is subscribed.
     */
    private function confirmation(): string
    {
        return 'Thanks — you will hear about new videos.';
    }

    private function targetExists(string $scope, ?int $id): bool
    {
        if ($id === null) {
            return false;
        }

        return match ($scope) {
            SubscriptionRepository::CATEGORY =>
                $this->container->get(CategoryRepository::class)->find($id) !== null,
            SubscriptionRepository::SERIES =>
                $this->container->get(SeriesRepository::class)->find($id) !== null,
            SubscriptionRepository::SPEAKER =>
                $this->container->get(SpeakerRepository::class)->find($id) !== null,
            default => false,
        };
    }

    /** @param array<string, mixed> $subscription */
    private function describe(array $subscription): string
    {
        $scope = (string) $subscription['scope_type'];
        $id = $subscription['scope_id'] === null ? null : (int) $subscription['scope_id'];

        if ($scope === SubscriptionRepository::SITE || $id === null) {
            return 'everything new on this site';
        }

        $name = match ($scope) {
            SubscriptionRepository::CATEGORY =>
                $this->container->get(CategoryRepository::class)->find($id)?->name,
            SubscriptionRepository::SERIES =>
                $this->container->get(SeriesRepository::class)->find($id)?->title,
            SubscriptionRepository::SPEAKER =>
                $this->container->get(SpeakerRepository::class)->find($id)?->name,
            default => null,
        };

        return $name === null ? 'something that has since been removed' : $scope . ' “' . $name . '”';
    }
}
