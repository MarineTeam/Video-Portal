<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Session;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;
use Portal\Support\RateLimit;
use Portal\Tv\Pairing;
use Portal\Tv\PairingCode;
use Portal\Tv\PairingRepository;

/**
 * A television: signing one in, and the page it shows.
 *
 * # WHY A CODE AND NOT A PASSWORD BOX
 *
 * A television remote has four arrows and an OK button. Typing an email address
 * on one takes a minute and a half; typing a password with mixed case and a
 * symbol is not realistically possible. So a password box on a television means
 * people choose something short and memorable for exactly the device that is
 * hardest to type on — which makes it the worst place in the product to ask for
 * one.
 *
 * RFC 8628 instead: the television shows a short code, the person signs in on
 * the phone already in their hand, and the television polls until somebody says
 * yes. The television never handles a credential belonging to a person.
 *
 * # AND IT ENDS IN AN ORDINARY SESSION
 *
 * A claimed pairing calls Session::login(), the same call the sign-in form
 * makes. Not a second kind of token with its own middleware: this codebase's
 * standing rule is that both authorization checks run on every request, and a
 * parallel auth path is how one of them stops being asked. What the television
 * gets is a session like anybody else's, so every guard downstream already
 * knows about it.
 */
final class TvController extends Controller
{
    /** Pairings one address may start in an hour. */
    private const STARTS_PER_HOUR = 20;

    /**
     * Codes one person may type in ten minutes.
     *
     * The per-person half of the throttle. The per-pairing half is
     * `attempts`, which stops somebody typing codes at ONE pairing; this stops
     * them typing codes to find ANY pending pairing, which on a Sunday morning
     * might be several.
     */
    private const TRIES_PER_TEN_MINUTES = 10;

    /**
     * A television asks to be paired. No session, by definition.
     *
     * @noinspection PhpUnused — routed
     */
    public function start(Request $request): Response
    {
        /*
         * Throttled by address, which is the only thing there is to throttle
         * by: the caller has no session and no account. A pairing costs a row
         * and a code from a space of a million, so this is about stopping
         * somebody filling the table, not about guessing.
         */
        $limiter = new RateLimit($this->db());

        if (!$limiter->allow('tv-start:' . $request->ip(), self::STARTS_PER_HOUR, 3600)) {
            throw HttpException::tooManyRequests('Too many pairings from here. Try again shortly.');
        }

        $made = $this->pairings()->start((string) ($request->input('label') ?? ''));

        return Response::json([
            /*
             * RFC 8628's field names, because the client is a program. A
             * television written against the specification should not have to
             * learn this site's spelling of `user_code`.
             */
            'device_code'      => $made['device_code'],
            'user_code'        => PairingCode::forDisplay($made['user_code']),
            'verification_uri' => rtrim((string) $this->config()->get('base_url', ''), '/') . '/tv',
            'expires_in'       => $made['expires_in'],
            'interval'         => $made['interval'],
        ])->private();
    }

    /**
     * The television asking whether anybody has said yes yet.
     *
     * On success this SIGNS THE CALLER IN — the response carries a session
     * cookie, because claim() is single-use and the caller holding the device
     * code is the device. Anything else would mean handing back a second
     * credential for the television to store.
     */
    public function poll(Request $request): Response
    {
        $pairings = $this->pairings();
        $row = $pairings->byDeviceCode((string) ($request->input('device_code') ?? ''));

        $state = Pairing::state($row);

        if ($state !== Pairing::APPROVED) {
            /*
             * 200 with an error field, which is what RFC 8628 specifies for
             * `authorization_pending` — a television polling every five seconds
             * and getting 4xx would, in most HTTP clients, start backing off or
             * treating it as fatal.
             */
            return Response::json([
                'error'    => Pairing::deviceAnswer($state),
                'interval' => Pairing::POLL_SECONDS,
            ])->private();
        }

        /** @var array<string, mixed> $row — APPROVED implies a row */
        $userId = $pairings->claim((int) $row['id']);

        if ($userId === null) {
            /*
             * Approved a moment ago and not claimable now: another poll won the
             * race, or it expired between the two statements. Reported as
             * access_denied rather than retried — the pairing is spent either
             * way, and a television that kept asking would poll a dead row for
             * ten minutes.
             */
            return Response::json([
                'error'    => Pairing::deviceAnswer(Pairing::CLAIMED),
                'interval' => Pairing::POLL_SECONDS,
            ])->private();
        }

        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $session->login($userId);

        Audit::log(
            $this->db(),
            null,
            'tv.paired',
            'tv_pairing',
            (string) $row['id'],
            (string) ($row['device_label'] ?? 'a television')
        );

        return Response::json([
            'ok'   => true,
            'next' => '/tv/screen',
        ])->private();
    }

    /**
     * One address, answering differently to each device.
     *
     * Signed out it is the television — a code on the screen and a script that
     * waits. Signed in it is the phone — a box to type the code into.
     *
     * The branch is on whether this browser has a session of its own, not on a
     * user agent. That is the thing that actually distinguishes the two devices
     * here, and it is the thing that decides which of the two pages is any use:
     * a television with a session does not need pairing, and a phone without
     * one cannot approve anything.
     */
    public function entry(Request $request): Response
    {
        return $this->user() === null
            ? $this->waitingScreen()
            : $this->approveScreen();
    }

    /**
     * What the television shows: a code, and a script that waits for yes.
     *
     * The pairing is started HERE, server-side, rather than by the script.
     * A television whose JavaScript is slow, blocked or simply old still shows
     * a code somebody can type — and the code is the feature. What the script
     * adds is the waiting and the redirect, which is the part a person can do
     * for themselves by reloading.
     */
    private function waitingScreen(): Response
    {
        $made = $this->pairings()->start('A television');

        return $this->view(['tv-waiting'], [
            'title'   => 'Sign this television in',
            'heading' => 'Sign this television in',
            /*
             * The DEVICE code goes into the page, which is the one place it may
             * go: this response is the television's own, it is no-store, and
             * the script needs it to poll. It is never logged, never in a URL,
             * and the row holds only its hash.
             */
            'deviceCode' => $made['device_code'],
            'userCode'   => PairingCode::forDisplay($made['user_code']),
            'interval'   => $made['interval'],
            'expiresIn'  => $made['expires_in'],
            'siteAddress' => preg_replace(
                '#^https?://#',
                '',
                rtrim((string) $this->config()->get('base_url', ''), '/')
            ),
        ])->private();
    }

    /** The page where somebody types the code they can see across the room. */
    private function approveScreen(): Response
    {
        return $this->view(['tv-approve'], [
            'title'   => 'Sign in a television',
            'heading' => 'Sign in a television',
            'token'   => $this->csrfToken(),
            'flash'   => $this->flash(),
            'tvs'     => $this->pairings()->forUser($this->user()?->id ?? 0),
        ]);
    }

    /** Somebody typing the code, and saying yes. */
    public function approve(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();

        if ($user === null) {
            throw HttpException::forbidden('Sign in first.');
        }

        $limiter = new RateLimit($this->db());

        if (!$limiter->allow('tv-approve:' . $user->id, self::TRIES_PER_TEN_MINUTES, 600)) {
            return $this->back($request, 'Too many tries. Wait a few minutes.', 'error');
        }

        $pairings = $this->pairings();
        $typed = (string) ($request->input('code') ?? '');
        $row = $pairings->byUserCode($typed);
        $state = Pairing::state($row);

        if (!Pairing::isApprovable($state)) {
            /*
             * A wrong code is counted against the PAIRING it named, when it
             * named one. A code matching nothing costs nothing here — it is the
             * per-person limit above that answers that, because there is no row
             * to count against.
             */
            if ($row !== null && $state === Pairing::BLOCKED) {
                // Already at the ceiling; nothing to add.
                $state = Pairing::BLOCKED;
            }

            return $this->back($request, Pairing::explain($state), 'error');
        }

        /** @var array<string, mixed> $row — approvable implies a row */
        if (!$pairings->approve((int) $row['id'], $user->id)) {
            /*
             * Lost a race, or it expired in the moment between the page render
             * and the press. The conditional UPDATE is what decided, and its
             * answer is authoritative — re-reading the state to explain would
             * be a third opinion about the same row.
             */
            return $this->back($request, 'That code is no longer waiting. Ask the television '
                . 'for a new one.', 'error');
        }

        Audit::log(
            $this->db(),
            $user->email,
            'tv.approve',
            'tv_pairing',
            (string) $row['id'],
            (string) ($row['device_label'] ?? 'a television')
        );

        return $this->back($request, 'Done. The television should come on in a few seconds.');
    }

    /**
     * The ten-foot page: the library, laid out for a remote control.
     *
     * Behind auth.authorized like the rest of the library, which the pairing
     * above is what gets a television through.
     */
    public function screen(Request $request): Response
    {
        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        /*
         * One page of the newest, and no pager.
         *
         * Paging with a remote means moving focus to a control at the end of a
         * grid, which nobody does. What a television needs is rows short enough
         * to walk along — so this is the newest twenty, and finding something
         * specific is what the phone in their hand is for.
         */
        $recent = $videos->query(['includeMemberOnly' => $this->canWatch()], 1, 20);

        /*
         * The cards come from VideoPresenter, not from anything written here.
         *
         * That class is where the members-only THUMBNAIL rule lives and is
         * tested — a locked card carries no URL, and the provider is never even
         * asked for one. Phase 3 recorded what the alternative costs: the check
         * that a signed-out visitor sees no CDN address passed with the guard
         * deleted, because the theme happened not to print one, and a second
         * surface reading `thumbnail` without knowing about `membersOnly` would
         * have leaked the artwork with everything green.
         *
         * A television is exactly that second surface.
         */
        $presenter = new \Portal\Content\VideoPresenter($videos, $this->tvVideoProvider());

        return $this->view(['tv-screen'], [
            'title'   => 'Television',
            'heading' => (string) $this->config()->setting('site_name', 'Video Portal'),
            'videos'  => $presenter->cards(
                $recent['items'],
                $this->canWatch(),
                $this->config()->settingBool('members_thumbnail_default', false)
            ),
        ]);
    }

    /**
     * The video provider, or null if none is configured.
     *
     * Wrapped, so a site part-way through its installer renders a list of
     * titles rather than a 500 — the same swallow LibraryController does, and
     * the same reason.
     */
    private function tvVideoProvider(): ?\Portal\Video\VideoProvider
    {
        try {
            return $this->container->get(\Portal\Video\VideoProvider::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The catalogue, for somebody building a channel.
     *
     * # NO SESSION, SO NOBODY TO CHECK LATER
     *
     * This is fetched by a device — a Roku channel's feed puller, a set-top box
     * — with no cookie and no key. Which means the visibility decision has to
     * be made HERE and completely, because there is no second chance: whatever
     * is in this file is public from the moment it is served, and a feed is
     * cached and re-served by people who are not this site.
     *
     * So it asks the ordinary listing query with members-only OFF. Not a query
     * of its own: the rule about what a stranger may see lives in one place,
     * and a feed with its own copy is the one that drifts. That is also how the
     * members-only-SERIES leak was found — the requirement to filter on the
     * video and its series turned out to be unmet by the listing itself, and
     * fixing it there fixed it for every public surface at once.
     */
    public function catalogue(Request $request): Response
    {
        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        // Explicitly false rather than omitted. The default is already "no",
        // but a reader of this method should not have to know that.
        $page = $videos->query(['includeMemberOnly' => false], 1, 200);

        $items = [];

        foreach ($page['items'] as $video) {
            $items[] = [
                'id'          => $video->slug,
                'title'       => $video->title,
                'description' => (string) ($video->description ?? ''),
                'published'   => (string) ($video->publishedAt ?? ''),
                'duration'    => $video->duration,
                /*
                 * A URL on THIS site, never the CDN.
                 *
                 * The standing rule, and a feed is where it matters most: a CDN
                 * address is signed and short-lived, a catalogue is read hours
                 * later, and a URL already handed out cannot be recalled.
                 * /media/{slug}.mp4 re-checks visibility and can start
                 * refusing — which is the whole reason the podcast feed points
                 * there too.
                 */
                'page'  => $this->config()->url('/watch/' . $video->slug),
                'media' => $this->config()->url('/media/' . $video->slug . '.mp4'),
            ];
        }

        return Response::json([
            'name'      => (string) $this->config()->setting('site_name', 'Video Portal'),
            'generated' => gmdate('c'),
            'note'      => 'Everything here is public. Members-only content — including '
                . 'anything in a members-only series — is absent rather than marked, because a '
                . 'device fetching this has no session and there is nobody to check later.',
            'videos'    => $items,
        ])
            ->header('X-Robots-Tag', 'noindex')
            /*
             * Cacheable, unlike almost everything else here. It is public by
             * construction and a channel builder may fetch it often; five
             * minutes is short enough that unpublishing something takes effect
             * while somebody is still watching the screen they did it on.
             */
            ->header('Cache-Control', 'public, max-age=300');
    }

    private function pairings(): PairingRepository
    {
        return new PairingRepository($this->db());
    }
}
