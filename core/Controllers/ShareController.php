<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Sharing\AccessResolver;
use Portal\Sharing\AccessResult;
use Portal\Sharing\Bundle;
use Portal\Sharing\Gate;
use Portal\Sharing\Share;
use Portal\Sharing\ShareMailer;
use Portal\Sharing\SharePassword;
use Portal\Sharing\ShareRepository;
use Portal\Sharing\ShareView;
use Portal\Support\RateLimit;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * What a recipient sees.
 *
 * Deliberately not themed. These pages are shown to people who may never have
 * visited the site, on a link someone forwarded them; they must render if the
 * active theme is broken, and they must look the same regardless of which
 * theme an admin happens to have installed.
 */
final class ShareController extends Controller
{
    /** Playback URLs last three hours, as elsewhere. */
    private const EMBED_TTL = 10800;

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $result = $this->resolver()->resolveShare($params['id'] ?? '', $request);

        return match ($result->state) {
            AccessResult::GRANTED  => $this->playShare($result, $request),
            AccessResult::SIGN_IN  => $this->toSignIn($request),
            AccessResult::GATE     => $this->gateForm($request, 'share', $params['id'] ?? ''),
            AccessResult::LOCKED   => $this->unlockForm($params['id'] ?? ''),
            AccessResult::MISMATCH => $this->mismatchPage(),
            default                => $this->gonePage(),
        };
    }

    /**
     * The passphrase, submitted.
     *
     * Every refusal here returns gonePage() — the same 404 as a revoked,
     * expired, unknown or malformed id. A wrong passphrase, a throttled link
     * and a link that never existed are one response, which is what stops this
     * form becoming a way to find out which ids are real.
     *
     * The cost is stated on the form itself rather than hidden: somebody who
     * mistypes is told nothing, so the page warns them in advance.
     */
    public function unlock(Request $request, array $params): Response
    {
        /*
         * Deliberately no CSRF check, matching requestLink() above and for the
         * same reasons.
         *
         * This page is reached from an emailed link, sometimes inside a webmail
         * preview, where a session may not survive. And the action borrows no
         * authority: an attacker who could forge this request would have to
         * already know the passphrase, and all they achieve is unlocking a link
         * they already hold — in somebody else's browser.
         *
         * Requiring a token would also mean rendering one, which starts a
         * session and sets a cookie for every anonymous recipient who opens a
         * protected link. This codebase has paid that cost once before, on the
         * subscribe form, and took it back out.
         *
         * The per-link throttle below is what limits abuse here.
         */
        $id = (string) ($params['id'] ?? '');
        $share = $this->shares()->find($id);

        /*
         * A link with no passphrase must not be openable through this route.
         * Without this check, POSTing here would set an unlock cookie for any
         * live link — harmless today, because nothing consults the cookie
         * unless password_hash is set, but it would become a real hole the
         * moment anything else did.
         */
        if ($share === null || !$share->isLive() || !$share->passwordProtected) {
            return $this->gonePage();
        }

        /*
         * Throttled per link, before the hash is ever computed. A six-character
         * passphrase is guessable at network speed otherwise, and password
         * verification is deliberately expensive — so an unthrottled endpoint
         * is also a way to spend the server's CPU.
         */
        $limiter = new RateLimit($this->db());
        if (!$limiter->allow(
            SharePassword::bucket($id),
            SharePassword::MAX_ATTEMPTS,
            SharePassword::LOCKOUT_SECONDS
        )) {
            return $this->gonePage();
        }

        $hash = (string) ($this->db()->value(
            'SELECT password_hash FROM {shares} WHERE id = ?',
            [$id]
        ) ?? '');

        if (!SharePassword::matches($hash === '' ? null : $hash, (string) ($request->input('passphrase') ?? ''))) {
            return $this->gonePage();
        }

        /*
         * Right. Remember it for this browser, scoped to this link's own path
         * so unlocking one share never unlocks another, and send them back to
         * the link to carry on with whatever it asks for next — signing in, or
         * the email gate, or the video itself.
         */
        return Response::redirect($this->config()->url('/s/' . $id))->cookie(
            $this->gate()->unlockCookieName($id),
            $this->gate()->unlockToken($id),
            [
                'expires'  => time() + $this->gate()->cookieLifetime(),
                'path'     => $this->gate()->cookiePath('share', $id),
                'secure'   => $request->isSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    private function unlockForm(string $id): Response
    {
        /*
         * No CSRF token, so opening a protected link does not start a session
         * for an anonymous recipient. See unlock() for why the endpoint does
         * not need one.
         */
        return Response::html(ShareView::unlockForm(
            $this->siteName(),
            '/s/' . $id . '/unlock'
        ))->private();
    }

    /** @param array<string, string> $params */
    public function showBundle(Request $request, array $params): Response
    {
        $result = $this->resolver()->resolveBundle($params['id'] ?? '', $request);

        return match ($result->state) {
            AccessResult::GRANTED  => $this->listBundle($result, $request),
            AccessResult::SIGN_IN  => $this->toSignIn($request),
            AccessResult::GATE     => $this->gateForm($request, 'bundle', $params['id'] ?? ''),
            AccessResult::MISMATCH => $this->mismatchPage(),
            default                => $this->gonePage(),
        };
    }

    /**
     * Ask for a sign-in link.
     *
     * Always answers the same way, whatever happened. The Gate itself is
     * silent by design; this must not undo that by reporting an outcome.
     *
     * @param array<string, string> $params
     */
    public function requestLink(Request $request, array $params): Response
    {
        // Inferred from the path rather than a route parameter: /s/ and /b/
        // are what distinguish them, and a missing parameter would silently
        // treat every bundle request as a share request.
        $targetType = str_starts_with($request->path, '/b/') ? 'bundle' : 'share';
        $targetId = $params['id'] ?? '';

        // Deliberately no CSRF check. This page is reached from an emailed
        // link, sometimes inside a webmail preview, where a session may not
        // survive — and the action grants nothing: at worst it emails the
        // rightful recipient a link they could have requested themselves. The
        // Gate's own per-target throttle is what limits abuse.

        try {
            $mailer = $this->mailer();

            $this->gate()->request(
                $targetType,
                $targetId,
                $request->input('email') ?? '',
                static function (string $email, string $url, string $title) use ($mailer): void {
                    $mailer->sendGateLink($email, $url, $title);
                }
            );
        } catch (Throwable $e) {
            // Logged, never surfaced. A visitor learning that something went
            // wrong learns something about the link.
            error_log('Portal: gate link request failed: ' . $e->getMessage());
        }

        return Response::html(ShareView::linkSent($this->siteName()))->private();
    }

    /**
     * Record playback against a share.
     *
     * Authenticated by the share id itself, which is unguessable — the same
     * secret that grants access. Requiring a session here would exclude gate
     * recipients, who deliberately have none.
     */
    public function track(Request $request): Response
    {
        $id = (string) ($request->data('shareId') ?? '');
        $event = (string) ($request->data('event') ?? '');

        if (!Share::isValidId($id) || !in_array($event, ['play', 'progress', 'ended'], true)) {
            return $this->json(['ok' => false], 400);
        }

        $share = $this->shares()->find($id);

        // Tracking a dead link would let someone keep a share's counters
        // moving after it was revoked.
        if ($share === null || !$share->isLive()) {
            return $this->json(['ok' => false], 404);
        }

        $this->shares()->recordPlayback($id, $event, (int) ($request->data('percent') ?? 0));

        return $this->json(['ok' => true]);
    }

    // ------------------------------------------------------------- rendering

    private function playShare(AccessResult $result, Request $request): Response
    {
        $share = $result->share;

        if ($share === null) {
            return $this->gonePage();
        }

        $video = $this->videos()->find($share->videoId);

        if ($video === null) {
            // The video was deleted after the share was made. The link is not
            // wrong, the thing behind it is gone.
            return $this->gonePage();
        }

        try {
            $embedUrl = $this->container->get(VideoProvider::class)
                ->embedUrl($video->providerId, self::EMBED_TTL);
        } catch (Throwable $e) {
            error_log('Portal: could not mint an embed URL for a share: ' . $e->getMessage());
            return Response::html(ShareView::unavailable($this->siteName()), 502)->private();
        }

        $this->shares()->recordView($share->id);

        $html = ShareView::player(
            siteName: $this->siteName(),
            title: $share->videoTitle,
            embedUrl: $embedUrl,
            shareId: $share->id,
            viewerEmail: (string) $result->viewerEmail,
            // The watermark plugin, if active, draws here.
            overlay: $this->overlay($share, (string) $result->viewerEmail),
            bundleUrl: $this->bundleUrlFor($share)
        );

        return $this->withGrant(Response::html($html)->private(), $result, 'share', $share->id, $request);
    }

    private function listBundle(AccessResult $result, Request $request): Response
    {
        $bundle = $result->bundle;

        if ($bundle === null) {
            return $this->gonePage();
        }

        $items = [];

        foreach ($this->bundles()->liveItems($bundle->id) as $share) {
            $items[] = [
                'title'   => $share->videoTitle,
                'url'     => $this->config()->url($share->url()),
                'expires' => \Portal\Support\Str::relativeTo($share->expiresAt),
            ];
        }

        $html = ShareView::bundle($this->siteName(), $items);

        return $this->withGrant(Response::html($html)->private(), $result, 'bundle', $bundle->id, $request);
    }

    /**
     * Attach the gate cookie when a magic link was just redeemed.
     *
     * Scoped to this one target's path, so the browser sends it only where it
     * is needed rather than broadcasting every share the person can reach.
     */
    private function withGrant(
        Response $response,
        AccessResult $result,
        string $targetType,
        string $targetId,
        Request $request
    ): Response {
        if ($result->grant === null) {
            return $response;
        }

        return $response->cookie(
            $this->gate()->cookieName($targetType, $targetId),
            $result->grant,
            [
                'expires'  => time() + $this->gate()->cookieLifetime(),
                'path'     => $this->gate()->cookiePath($targetType, $targetId),
                'secure'   => $request->isSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    private function toSignIn(Request $request): Response
    {
        // Return path preserved, so signing in lands on the video rather than
        // dumping someone on the homepage with no idea what they clicked.
        return Response::redirect(
            $this->config()->url('/auth/login?returnTo=' . rawurlencode($request->path))
        )->private();
    }

    private function gateForm(Request $request, string $targetType, string $targetId): Response
    {
        return Response::html(ShareView::gateForm(
            $this->siteName(),
            '/' . ($targetType === 'bundle' ? 'b' : 's') . '/' . $targetId . '/request',
            $this->csrfToken()
        ))->private();
    }

    /** Says nothing about who the link was for. */
    private function mismatchPage(): Response
    {
        return Response::html(ShareView::mismatch($this->siteName()), 403)->private();
    }

    /** Revoked, expired, unknown, and malformed all arrive here. */
    private function gonePage(): Response
    {
        return Response::html(ShareView::gone($this->siteName()), 404)->private();
    }

    private function overlay(Share $share, string $viewerEmail): string
    {
        ob_start();
        do_action('share_overlay', $share, $viewerEmail);
        return (string) ob_get_clean();
    }

    private function bundleUrlFor(Share $share): ?string
    {
        if ($share->bundleId === null) {
            return null;
        }

        $bundle = $this->bundles()->find($share->bundleId);

        if ($bundle === null || count($this->bundles()->liveItems($bundle->id)) < 2) {
            return null;
        }

        return $this->config()->url($bundle->url());
    }

    // -------------------------------------------------------------- services

    private function resolver(): AccessResolver
    {
        return $this->container->get(AccessResolver::class);
    }

    private function shares(): ShareRepository
    {
        return $this->container->get(ShareRepository::class);
    }

    private function bundles(): \Portal\Sharing\BundleRepository
    {
        return $this->container->get(\Portal\Sharing\BundleRepository::class);
    }

    private function videos(): \Portal\Content\VideoRepository
    {
        return $this->container->get(\Portal\Content\VideoRepository::class);
    }

    private function gate(): Gate
    {
        return $this->container->get(Gate::class);
    }

    private function mailer(): ShareMailer
    {
        return $this->container->get(ShareMailer::class);
    }

    private function siteName(): string
    {
        return $this->config()->setting('site_name', 'Video Portal') ?? 'Video Portal';
    }
}
