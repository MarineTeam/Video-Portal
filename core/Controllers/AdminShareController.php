<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminShareView;
use Portal\Auth\Capability;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Sharing\BundleRepository;
use Portal\Sharing\PrivateList;
use Portal\Sharing\Share;
use Portal\Sharing\ShareMailer;
use Portal\Sharing\ShareRepository;
use Portal\Sharing\ViewerGroups;
use Portal\Support\Audit;
use Portal\Support\RateLimit;
use Throwable;

/**
 * Creating and managing share links.
 *
 * Every bulk action reports per-item results rather than a single verdict.
 * "Revoked 47 of 50, these 3 failed and why" is actionable; "partially failed"
 * is not, and an all-or-nothing transaction would make one bad id undo
 * forty-nine correct ones.
 */
final class AdminShareController extends Controller
{
    /** Share creation is cheap for us and consequential for recipients. */
    private const CREATES_PER_HOUR = 60;

    public function index(Request $request): Response
    {
        $this->require(Capability::MANAGE_SHARES);

        $page = max(1, (int) ($request->query('page') ?? 1));

        $result = $this->shares()->query([
            'status' => $request->query('status') ?? 'all',
            'search' => $request->query('q') ?? '',
        ], $page, 50);

        return $this->render('shares', [
            'shares'     => $result['items'],
            'total'      => $result['total'],
            'page'       => $page,
            'status'     => $request->query('status') ?? 'all',
            'search'     => $request->query('q') ?? '',
            'bundles'    => $this->bundles()->listForAdmin(),
            'purgeable'  => $this->shares()->purgeableCount(),
            'mailReady'  => $this->mailer()->isConfigured(),
            'videos'     => $this->videoChoices(),
            'groups'     => $this->groups()->all(),
            'tags'       => $this->groups()->allTags(),
        ]);
    }

    /**
     * Create links.
     *
     * One form covers every case: one video or many, typed addresses or a
     * group or a tag or all three. Separate "share" and "bulk share" screens
     * would be two implementations of the same decision.
     */
    public function create(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SHARES);

        $user = $this->user();

        $limiter = new RateLimit($this->db());
        if (!$limiter->allow('share-create:' . ($user?->id ?? 0), self::CREATES_PER_HOUR, 3600)) {
            return $this->back($request, 'Too many share links created recently. Try again shortly.', 'error');
        }

        $videoIds = array_map('intval', $request->inputArray('videos'));
        if ($videoIds === []) {
            return $this->back($request, 'Choose at least one video.', 'error');
        }

        $recipients = $this->groups()->resolveRecipients(
            [$request->input('emails') ?? ''],
            array_map('intval', $request->inputArray('groups')),
            $request->inputArray('tags')
        );

        if ($recipients['valid'] === []) {
            return $this->back($request, 'No valid email addresses were given.', 'error');
        }

        try {
            $result = $this->shares()->createBulk($videoIds, $recipients['valid'], [
                'hours'      => (int) ($request->input('hours') ?? Share::DEFAULT_HOURS),
                'accessMode' => $request->input('access_mode') ?? Share::MODE_ACCOUNT,
                'watermark'  => $request->input('watermark') ?? 'default',
                'createdBy'  => $user?->email,
            ]);
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }

        // Bundle first, then notify. Someone who now has several links should
        // get one consolidated email rather than one per video, and that is
        // only decidable once the bundle exists.
        $notified = $this->settleAndNotify(
            $recipients['valid'],
            $request->inputBool('notify', true),
            $request->input('access_mode') ?? Share::MODE_ACCOUNT
        );

        Audit::log(
            $this->db(),
            $user?->email,
            'share.create',
            null,
            null,
            sprintf('%d link(s) for %d recipient(s)', count($result['created']), count($recipients['valid']))
        );

        return $this->back($request, $this->describeCreation($result, $recipients, $notified));
    }

    /**
     * Revoke, restore, extend, delete, or resend, one or many.
     */
    public function act(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SHARES);

        $action = $request->input('action') ?? '';
        $ids = $request->inputArray('ids');

        // A single-row button posts one id; the bulk checkbox posts many.
        $single = $request->input('id');
        if ($single !== null && $single !== '') {
            $ids[] = $single;
        }

        if ($ids === []) {
            return $this->back($request, 'Nothing was selected.', 'error');
        }

        if ($action === 'resend') {
            return $this->resend($request, $ids);
        }

        if (!in_array($action, ['revoke', 'restore', 'extend', 'delete'], true)) {
            return $this->back($request, 'Unknown action.', 'error');
        }

        $result = $this->shares()->bulk($action, $ids, (int) ($request->input('hours') ?? Share::DEFAULT_HOURS));

        // Extending or restoring changes what a bundle should cover.
        if (in_array($action, ['extend', 'restore'], true)) {
            $this->refreshBundlesFor($result['ok']);
        }

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'share.' . $action,
            null,
            null,
            sprintf('%d succeeded, %d failed', count($result['ok']), count($result['failed']))
        );

        return $this->back($request, $this->describeBulk($action, $result), $result['failed'] === [] ? 'success' : 'error');
    }

    /**
     * Resend notifications.
     *
     * Consolidated per recipient, not per link. Selecting eight links
     * belonging to one person should produce one email, not eight.
     *
     * @param list<string> $ids
     */
    private function resend(Request $request, array $ids): Response
    {
        if (!$this->mailer()->isConfigured()) {
            return $this->back($request, 'No email service is configured.', 'error');
        }

        $shares = $this->shares()->findMany($ids);

        $recipients = [];
        foreach ($shares as $share) {
            if ($share->isLive()) {
                $recipients[$share->recipientEmail] = true;
            }
        }

        if ($recipients === []) {
            return $this->back($request, 'None of those links are still live.', 'error');
        }

        $sent = 0;
        $failed = [];

        foreach (array_keys($recipients) as $email) {
            $result = $this->mailer()->notify($email);

            if ($result->sent) {
                $sent++;
            } else {
                $failed[$email] = $result->error ?? 'Unknown error.';
            }
        }

        Audit::log($this->db(), $this->user()?->email, 'share.resend', null, null, sprintf('%d sent', $sent));

        if ($failed === []) {
            return $this->back($request, sprintf('Emailed %d recipient(s).', $sent));
        }

        return $this->back(
            $request,
            sprintf('Emailed %d, failed for %s.', $sent, implode(', ', array_keys($failed))),
            'error'
        );
    }

    /** Remove links that are long past useful. */
    public function cleanup(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SHARES);

        $shares = $this->shares()->purgeExpired();
        $bundles = $this->bundles()->purgeEmpty();

        Audit::log($this->db(), $this->user()?->email, 'share.cleanup', null, null, "{$shares} shares, {$bundles} bundles");

        return $this->back($request, sprintf(
            'Removed %d old link(s) and %d empty bundle page(s).',
            $shares,
            $bundles
        ));
    }

    // -------------------------------------------------------- private lists

    /** @param array<string, string> $params */
    public function privateList(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_SHARES);

        $videoId = (int) ($params['video'] ?? 0);
        $video = $this->videos()->find($videoId);

        if ($video === null) {
            throw HttpException::notFound('That video does not exist.');
        }

        return $this->render('private-list', [
            'video'     => $video,
            'members'   => $this->lists()->members($videoId),
            'groups'    => $this->groups()->all(),
            'tags'      => $this->groups()->allTags(),
            'mailReady' => $this->mailer()->isConfigured(),
        ]);
    }

    public function updatePrivateList(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SHARES);

        $videoId = (int) ($request->input('video') ?? 0);
        $action = $request->input('action') ?? 'add';

        if ($action === 'remove') {
            $email = $request->input('email') ?? '';
            $this->lists()->remove($videoId, $email);

            Audit::log($this->db(), $this->user()?->email, 'share.list_remove', 'video', (string) $videoId, $email);

            return $this->back($request, $email . ' can no longer watch this.');
        }

        $recipients = $this->groups()->resolveRecipients(
            [$request->input('emails') ?? ''],
            array_map('intval', $request->inputArray('groups')),
            $request->inputArray('tags')
        );

        if ($recipients['valid'] === []) {
            return $this->back($request, 'No valid email addresses were given.', 'error');
        }

        $result = $this->lists()->add($videoId, $recipients['valid'], [
            'hours'      => (int) ($request->input('hours') ?? Share::MAX_HOURS),
            'accessMode' => $request->input('access_mode') ?? Share::MODE_ACCOUNT,
            'createdBy'  => $this->user()?->email,
        ]);

        // Only the newly added are notified. Someone already on the list did
        // not just gain access and should not get a second invitation.
        $notified = 0;
        if ($request->inputBool('notify', true) && $result['added'] !== []) {
            $emails = array_map(static fn (Share $s): string => $s->recipientEmail, $result['added']);
            $notified = $this->settleAndNotify($emails, true, $request->input('access_mode') ?? Share::MODE_ACCOUNT);
        }

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'share.list_add',
            'video',
            (string) $videoId,
            sprintf('%d added, %d already there', count($result['added']), count($result['skipped']))
        );

        $message = sprintf('Added %d.', count($result['added']));

        if ($result['skipped'] !== []) {
            $message .= sprintf(' %d were already on the list.', count($result['skipped']));
        }
        if ($notified > 0) {
            $message .= sprintf(' Emailed %d.', $notified);
        }

        return $this->back($request, $message);
    }

    // -------------------------------------------------------- viewer groups

    public function groupsPage(Request $request): Response
    {
        $this->require(Capability::MANAGE_VIEWERS);

        $groups = [];
        foreach ($this->groups()->all() as $group) {
            $groups[] = $group + ['emails' => $this->groups()->emails($group['id'])];
        }

        return $this->render('viewer-groups', ['groups' => $groups]);
    }

    public function updateGroups(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_VIEWERS);

        $action = $request->input('action') ?? '';

        switch ($action) {
            case 'create':
                $name = $request->input('name') ?? '';
                if (trim($name) === '') {
                    return $this->back($request, 'A group needs a name.', 'error');
                }
                $this->groups()->create($name);
                return $this->back($request, 'Group created.');

            case 'delete':
                $this->groups()->delete((int) ($request->input('group') ?? 0));
                // Worth saying plainly: people reasonably expect deletion to
                // revoke, and it deliberately does not.
                return $this->back($request, 'Group deleted. Links already sent still work.');

            case 'add':
                $groupId = (int) ($request->input('group') ?? 0);
                $parsed = $this->groups()->addMembers(
                    $groupId,
                    \Portal\Support\Str::parseEmailList($request->input('emails') ?? '')['valid']
                );
                return $this->back($request, sprintf('Added %d address(es).', count($parsed['added'])));

            case 'remove':
                $this->groups()->removeMember(
                    (int) ($request->input('group') ?? 0),
                    $request->input('email') ?? ''
                );
                return $this->back($request, 'Removed.');

            default:
                return $this->back($request, 'Unknown action.', 'error');
        }
    }

    // -------------------------------------------------------------- helpers

    /**
     * Settle bundles, then send one notification per recipient.
     *
     * @param list<string> $emails
     */
    private function settleAndNotify(array $emails, bool $notify, string $accessMode): int
    {
        $sent = 0;

        foreach (array_unique($emails) as $email) {
            try {
                $this->bundles()->ensureFor($email, $accessMode);

                if ($notify && $this->mailer()->isConfigured()) {
                    if ($this->mailer()->notify($email)->sent) {
                        $sent++;
                    }
                }
            } catch (Throwable $e) {
                // A notification failure must never undo the share it was
                // announcing. The error is recorded on the share itself.
                error_log('Portal: could not notify ' . $email . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /** @param list<string> $shareIds */
    private function refreshBundlesFor(array $shareIds): void
    {
        $seen = [];

        foreach ($this->shares()->findMany($shareIds) as $share) {
            if ($share->bundleId !== null && !isset($seen[$share->bundleId])) {
                $seen[$share->bundleId] = true;
                $this->bundles()->refresh($share->bundleId);
            }
        }
    }

    /**
     * @param array{created: list<Share>, failed: array<string, string>} $result
     * @param array{valid: list<string>, invalid: list<string>}          $recipients
     */
    private function describeCreation(array $result, array $recipients, int $notified): string
    {
        $message = sprintf(
            'Created %d link(s) for %d recipient(s).',
            count($result['created']),
            count($recipients['valid'])
        );

        if ($notified > 0) {
            $message .= sprintf(' Emailed %d.', $notified);
        } elseif (!$this->mailer()->isConfigured()) {
            $message .= ' No email service is configured, so nothing was sent — copy the links below.';
        }

        if ($recipients['invalid'] !== []) {
            $message .= ' Ignored: ' . implode(', ', array_slice($recipients['invalid'], 0, 5)) . '.';
        }

        if ($result['failed'] !== []) {
            $message .= sprintf(' %d failed.', count($result['failed']));
        }

        return $message;
    }

    /** @param array{ok: list<string>, failed: array<string, string>} $result */
    private function describeBulk(string $action, array $result): string
    {
        $verb = match ($action) {
            'revoke'  => 'Revoked',
            'restore' => 'Restored',
            'extend'  => 'Extended',
            'delete'  => 'Deleted',
            default   => 'Updated',
        };

        $message = sprintf('%s %d link(s).', $verb, count($result['ok']));

        if ($result['failed'] !== []) {
            // Name the reasons, not just the count. "3 failed" leaves an admin
            // with nothing to do next.
            $reasons = array_unique(array_values($result['failed']));
            $message .= sprintf(' %d failed: %s', count($result['failed']), implode(' ', array_slice($reasons, 0, 3)));
        }

        return $message;
    }

    /** @return list<array{id: int, title: string}> */
    private function videoChoices(): array
    {
        $result = $this->videos()->query([
            'includeUnpublished' => true,
            'includeHidden'      => true,
            'includeMemberOnly'  => true,
        ], 1, 200);

        $choices = [];
        foreach ($result['items'] as $video) {
            $choices[] = ['id' => $video->id, 'title' => $video->title];
        }

        return $choices;
    }

    /** @param array<string, mixed> $data */
    private function render(string $screen, array $data): Response
    {
        $view = new AdminShareView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'baseUrl'  => $this->config()->baseUrl(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }

    // -------------------------------------------------------------- services

    private function shares(): ShareRepository
    {
        return $this->container->get(ShareRepository::class);
    }

    private function bundles(): BundleRepository
    {
        return $this->container->get(BundleRepository::class);
    }

    private function lists(): PrivateList
    {
        return $this->container->get(PrivateList::class);
    }

    private function groups(): ViewerGroups
    {
        return $this->container->get(ViewerGroups::class);
    }

    private function mailer(): ShareMailer
    {
        return $this->container->get(ShareMailer::class);
    }

    private function videos(): VideoRepository
    {
        return $this->container->get(VideoRepository::class);
    }
}
