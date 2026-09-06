<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminGroupView;
use Portal\Auth\Capability;
use Portal\Groups\GroupRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;

/**
 * Keeping the small-group directory.
 *
 * This is the person who makes groups and appoints leaders — NOT the leaders
 * themselves, who answer their own requests on their own group's page. Giving
 * leaders a capability would make every leader of every group a moderator of
 * all of them, which is the one thing "a leader is not staff" rules out.
 */
final class AdminGroupController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::MANAGE_GROUPS);

        $groups = $this->groups();

        return $this->render('groups', [
            'groups'     => $groups->directory(null, true),
            /*
             * Flagged on the front screen, because a group with no leader still
             * appears in the directory and still takes requests — and those
             * requests go to nobody. Somebody who has to go looking will not.
             */
            'leaderless' => $groups->leaderless(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_GROUPS);

        $groups = $this->groups();
        $row = $groups->find((int) ($params['id'] ?? 0));

        if ($row === null) {
            throw HttpException::notFound('There is no group with that id.');
        }

        return $this->render('group', [
            'group'    => $groups->card($row),
            'row'      => $row,
            'people'   => $groups->people((int) $row['id']),
            'accounts' => $this->accounts(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_GROUPS);

        $groups = $this->groups();
        $action = (string) ($request->input('action') ?? '');

        try {
            return match ($action) {
                'create'    => $this->create($request, $groups),
                'save'      => $this->save($request, $groups),
                'leader'    => $this->leader($request, $groups, true),
                'no-leader' => $this->leader($request, $groups, false),
                'remove'    => $this->remove($request, $groups),
                default     => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function create(Request $request, GroupRepository $groups): Response
    {
        $id = $groups->create((string) ($request->input('name') ?? ''));

        Audit::log($this->db(), $this->user()?->email, 'group.create', 'small_group', (string) $id);

        return $this->redirect('/admin/groups/' . $id);
    }

    private function save(Request $request, GroupRepository $groups): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $attributes = [];

        foreach (['name', 'description', 'area', 'address', 'meets', 'capacity'] as $field) {
            if ($request->input($field) !== null) {
                $attributes[$field] = (string) $request->input($field);
            }
        }

        if ($request->input('_whole_form') !== null) {
            $attributes['is_published'] = $request->input('is_published') !== null;
        }

        $groups->update($id, $attributes);

        // Raising the capacity frees places, so the list moves at once rather
        // than waiting for somebody to notice.
        $moved = $groups->promote($id);

        return $this->back($request, $moved === []
            ? 'Saved.'
            : sprintf('Saved, and %d person on the list has been asked.', count($moved)));
    }

    private function leader(Request $request, GroupRepository $groups, bool $leads): Response
    {
        $groupId = (int) ($request->input('id') ?? 0);
        $userId = (int) ($request->input('person') ?? 0);

        if ($userId <= 0) {
            return $this->back($request, 'Choose somebody.', 'error');
        }

        $groups->setLeader($groupId, $userId, $leads);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            $leads ? 'group.leader.add' : 'group.leader.remove',
            'small_group',
            (string) $groupId,
            'user ' . $userId
        );

        return $this->back($request, $leads
            // Said, because it is the surprising half and it is the moment
            // somebody gains an address.
            ? 'They lead this group now — which also puts them in it, and gives them the address.'
            : 'No longer leading it. They are still in the group.');
    }

    private function remove(Request $request, GroupRepository $groups): Response
    {
        $groups->leave(
            (int) ($request->input('id') ?? 0),
            (int) ($request->input('person') ?? 0)
        );

        $groups->promote((int) ($request->input('id') ?? 0));

        return $this->back($request, 'Taken out of the group.');
    }

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        return $this->db()->all(
            'SELECT id, COALESCE(NULLIF(name, ""), email) AS person_name
               FROM {users} WHERE authorized = 1 ORDER BY person_name LIMIT 500'
        );
    }

    private function groups(): GroupRepository
    {
        return new GroupRepository($this->db());
    }

    /** @param array<string, mixed> $data */
    private function render(string $screen, array $data): Response
    {
        $view = new AdminGroupView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }
}
