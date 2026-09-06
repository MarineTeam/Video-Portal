<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Prayer\PrayerRepository;

/**
 * The prayer wall, as anybody reads it.
 *
 * Open to people with no account, because the public requests are public and a
 * sign-in wall over them would mean the only people who could read them are the
 * ones who already know.
 *
 * Nothing here can put a request on the wall. That is the moderation rule and
 * it is enforced by the repository having no way to do it, not by this class
 * remembering.
 */
final class PrayerController extends Controller
{
    /** Remembers which requests this device has already prayed for. */
    private const COOKIE = 'portal_prayed';

    /** How many ids the cookie holds before the oldest fall off. */
    private const COOKIE_KEEP = 200;

    public function index(Request $request): Response
    {
        $prayer = $this->prayer();

        return $this->view(['prayer'], [
            'title'    => 'Prayer',
            'requests' => $prayer->wall($this->readable()),
            'prayed'   => $this->alreadyPrayed($request),
            'mine'     => $this->user() === null ? [] : $prayer->mine($this->user()->id),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
        ]);
    }

    /**
     * Ask for prayer.
     *
     * No account needed. The people most likely to use this are the ones least
     * likely to have made one.
     */
    public function add(Request $request): Response
    {
        $this->verifyCsrf($request);

        $anonymous = $request->input('anonymous') !== null;

        $this->prayer()->add(
            (string) ($request->input('body') ?? ''),
            (string) ($request->input('name') ?? ''),
            $anonymous,
            (string) ($request->input('visibility') ?? PrayerRepository::MEMBERS),
            // Not stored at all for an anonymous request — see the repository.
            $this->user()?->id
        );

        return $this->back(
            $request,
            /*
             * Said plainly, because the wait is the surprising part. Somebody
             * who posts and sees nothing appear concludes it did not work and
             * posts again, and then the queue has two of them.
             */
            'Thank you. Somebody will read it before it goes on the wall, so it will not appear '
            . 'straight away.'
        );
    }

    /**
     * "I prayed for this."
     *
     * A count. The device remembers it has already pressed, in a cookie —
     * there is no table of who prayed for what, because the only way to be
     * certain a list cannot leak is not to keep one.
     */
    public function pray(Request $request): Response
    {
        $this->verifyCsrf($request);

        $id = (int) ($request->input('id') ?? 0);
        $already = $this->alreadyPrayed($request);

        if ($id <= 0 || in_array($id, $already, true)) {
            return $this->back($request);
        }

        if (!$this->prayer()->pray($id)) {
            /*
             * Nothing was counted, and the response says nothing about why —
             * a request that is not on the wall must not be distinguishable
             * from one that is, or this becomes a way to ask whether a pending
             * request exists.
             */
            return $this->back($request);
        }

        $already[] = $id;

        return $this->back($request, 'Thank you.')->cookie(
            self::COOKIE,
            implode(',', array_slice($already, -self::COOKIE_KEEP)),
            ['expires' => time() + 365 * 86400, 'samesite' => 'Lax']
        );
    }

    /** Take down something you put up yourself. */
    public function withdraw(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();

        if ($user === null) {
            throw HttpException::notFound('There is nothing here.');
        }

        // Ownership goes into the WHERE clause, not into a check here: ids are
        // sequential, so an action taking only an id lets anybody remove a
        // stranger's request by counting.
        $this->prayer()->withdraw((int) ($request->input('id') ?? 0), $user->id);

        return $this->back($request, 'Taken down.');
    }

    // ---------------------------------------------------------- internals

    /** @return list<int> */
    private function alreadyPrayed(Request $request): array
    {
        $raw = (string) ($request->cookie(self::COOKIE) ?? '');

        return array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn (int $id): bool => $id > 0
        ));
    }

    /** @return list<string> */
    private function readable(): array
    {
        $user = $this->user();
        $isMember = $user !== null && ($user->isAdmin() || $user->authorized);

        /*
         * A "leader" here is somebody who moderates the wall. There is no
         * separate leadership concept in core yet, and inventing one that
         * nothing else uses would be worse than saying what this means.
         */
        return PrayerRepository::readable(
            $isMember,
            $this->guard()->can(\Portal\Auth\Capability::MODERATE_PRAYER)
        );
    }

    private function prayer(): PrayerRepository
    {
        return new PrayerRepository($this->db());
    }
}
