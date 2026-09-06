<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminPrayerView;
use Portal\Auth\Capability;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Prayer\PrayerRepository;
use Portal\Support\Audit;

/**
 * Reading the queue, and deciding what goes on the wall.
 *
 * Everything this screen receives is a PrayerRequest, the same type the public
 * wall gets — so an anonymous request is anonymous HERE too. That is the rule,
 * and the cost of it is stated on the screen rather than worked around: an
 * anonymous request cannot be followed up and its author cannot be blocked.
 */
final class AdminPrayerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::MODERATE_PRAYER);

        $prayer = $this->prayer();

        return $this->render([
            'waiting' => $prayer->queue(),
            'wall'    => $prayer->wall(PrayerRepository::readable(true, true)),
            'removed' => $prayer->removed(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MODERATE_PRAYER);

        $prayer = $this->prayer();
        $id = (int) ($request->input('id') ?? 0);
        $action = (string) ($request->input('action') ?? '');

        if ($id <= 0) {
            return $this->back($request, 'Nothing was chosen.', 'error');
        }

        try {
            return match ($action) {
                'approve'    => $this->approve($request, $prayer, $id),
                'remove'     => $this->simple($request, fn () => $prayer->remove($id), 'Taken off the wall.'),
                'restore'    => $this->simple($request, fn () => $prayer->restore($id), 'Back in the queue.'),
                'visibility' => $this->simple(
                    $request,
                    fn () => $prayer->setVisibility($id, (string) ($request->input('visibility') ?? '')),
                    'Changed who can read it.'
                ),
                'answered'   => $this->simple(
                    $request,
                    fn () => $prayer->markAnswered($id, (string) ($request->input('note') ?? '')),
                    // The surprising half said out loud.
                    'Marked as answered — and it stays on the wall, which is the half worth reading.'
                ),
                'unanswered' => $this->simple($request, fn () => $prayer->markUnanswered($id), 'Updated.'),
                default      => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function approve(Request $request, PrayerRepository $prayer, int $id): Response
    {
        $user = $this->user();
        $who = trim((string) ($user?->name ?? '')) ?: (string) ($user?->email ?? '');

        $prayer->approve($id, $who);

        /*
         * The audit entry records the moderator and the request id, and NOT
         * the request itself. An audit log is read by more people than the
         * wall is, and copying a prayer request into it would put a members-only
         * one in front of anybody with view_audit_log.
         */
        Audit::log($this->db(), $user?->email, 'prayer.approve', 'prayer_request', (string) $id);

        return $this->back($request, 'On the wall.');
    }

    private function simple(Request $request, callable $do, string $message): Response
    {
        $do();

        return $this->back($request, $message);
    }

    private function prayer(): PrayerRepository
    {
        return new PrayerRepository($this->db());
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $view = new AdminPrayerView();

        return Response::html($view->render($data + [
            'screen'   => 'prayer',
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }
}
