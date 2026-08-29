<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Sharing\Share;
use Portal\Sharing\ShareMailer;
use Portal\Sharing\SharePassword;
use Portal\Sharing\ShareRepository;
use Portal\Support\Audit;
use Throwable;

/**
 * Sharing, for people who are not administrators.
 *
 * Separate from AdminShareController on purpose. That one is an operator's
 * tool: it creates links in bulk for any video, lists every link on the site,
 * revokes anybody's, extends expiry, and bundles. This is one person handing
 * out one link to one video they can already watch, and the difference is not
 * cosmetic — sharing the admin controller and branching on a capability is how
 * a member eventually reaches a bulk action nobody meant them to have.
 *
 * Everything here is scoped twice over: the capability is checked against the
 * video, and every later action is checked against the link's own creator.
 */
final class MemberShareController extends Controller
{
    public function create(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $videoId = (int) ($request->input('video_id') ?? 0);

        /*
         * The capability, checked against THIS video.
         *
         * Site-wide holders pass, and so does somebody granted it on the
         * video's category or series — the resolver walks that chain. A holder
         * scoped to one section is refused everywhere else, which is the whole
         * reason this capability is scopable.
         */
        if (!$this->guard()->can(Capability::SHARE_CONTENT, 'video', $videoId)) {
            throw HttpException::forbidden('You do not have permission to share this video.');
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);
        $video = $videos->find($videoId);

        if ($video === null) {
            throw HttpException::notFound('That video does not exist.');
        }

        /*
         * You may only share what you can watch.
         *
         * The capability says "this person may hand out links"; it does not say
         * which videos exist for them. Without this, a grant scoped to a
         * category would let somebody share an unpublished or trashed video in
         * it — content they cannot open themselves.
         */
        if (!$this->canWatch($video->id)) {
            throw HttpException::forbidden('You can only share something you can watch yourself.');
        }

        $email = trim((string) ($request->input('email') ?? ''));
        if ($email === '') {
            return $this->back($request, 'Who is it for? Enter an email address.', 'error');
        }

        $passphrase = (string) ($request->input('passphrase') ?? '');
        if ($passphrase !== '' && !SharePassword::isAcceptable($passphrase)) {
            return $this->back(
                $request,
                sprintf('A passphrase must be at least %d characters.', SharePassword::MINIMUM),
                'error'
            );
        }

        /*
         * Counted from the shares table rather than a rate-limit bucket.
         *
         * A bucket can be cleared, and this is the limit that stops one account
         * turning the site into a mail relay — the recipient gets an email for
         * every link. Counting rows means the limit is a fact about what was
         * actually created.
         */
        $shares = $this->shares();
        if ($shares->createdSince($user->email, 3600) >= Share::MEMBER_HOURLY_LIMIT) {
            return $this->back(
                $request,
                'You have shared a lot in the last hour. Try again later.',
                'error'
            );
        }

        try {
            $share = $shares->create($video->id, $email, [
                // Capped for members below the administrator's ceiling. See
                // Share::MEMBER_MAX_HOURS.
                'hours'      => min(
                    Share::MEMBER_MAX_HOURS,
                    max(1, (int) ($request->input('hours') ?? Share::DEFAULT_HOURS))
                ),
                'accessMode' => $request->input('access_mode') === Share::MODE_GATE
                    ? Share::MODE_GATE
                    : Share::MODE_ACCOUNT,
                'passphrase' => $passphrase,
                'createdBy'  => $user->email,
            ]);
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }

        /*
         * Emailed on a best effort. The link exists either way and is listed on
         * their account page, so a mail failure costs the notification rather
         * than the share — and the failure is recorded on the row, which is
         * what the admin screen reads.
         */
        try {
            $this->container->get(ShareMailer::class)->sendShare($share);
        } catch (Throwable $e) {
            error_log('Could not email a member share: ' . $e->getMessage());
        }

        Audit::log($this->db(), $user->email, 'share.create.member', $share->id);

        return $this->back(
            $request,
            $share->passwordProtected
                ? 'Link sent. Tell them the passphrase yourself — it is not in the email.'
                : 'Link sent.'
        );
    }

    /**
     * Revoke a link you made.
     *
     * Ownership is re-read from the row rather than taken from the form. The
     * id is the only thing the browser supplies, and it identifies somebody
     * else's link just as well as your own.
     */
    public function revoke(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $id = (string) ($request->input('id') ?? '');
        $share = $this->shares()->find($id);

        /*
         * A link that is not yours answers exactly as one that does not exist.
         * Saying "that is not your link" would confirm the id is real, which is
         * the same oracle the refusal pages are built to avoid.
         */
        if ($share === null || $share->createdBy === null
            || \Portal\Support\Str::normalizeEmail($share->createdBy) !== \Portal\Support\Str::normalizeEmail($user->email)
        ) {
            return $this->back($request, 'That link could not be found.', 'error');
        }

        $this->shares()->revoke($share->id);
        Audit::log($this->db(), $user->email, 'share.revoke.member', $share->id);

        return $this->back($request, 'That link no longer works.');
    }

    /**
     * Can this person actually watch the video they are trying to share?
     *
     * Asked through the ordinary listing query so the answer comes from the
     * one place that owns publication, the schedule window, hidden and
     * members-only — rather than from a second copy of those rules here.
     */
    private function canWatch(int $videoId): bool
    {
        $user = $this->user();
        $mayWatch = $user !== null && ($user->isAdmin() || $user->authorized);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $result = $videos->query([
            'ids'               => [$videoId],
            'includeMemberOnly' => $mayWatch,
        ], 1, 1);

        return $result['items'] !== [];
    }

    private function shares(): ShareRepository
    {
        return $this->container->get(ShareRepository::class);
    }
}
