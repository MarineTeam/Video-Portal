<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Groups\GroupAddress;
use Portal\Groups\GroupRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;

/**
 * The small-group directory, and joining one.
 *
 * # THE ADDRESS
 *
 * It reaches a template through exactly one variable, computed here by
 * GroupAddress. GroupCard — everything else this hands to a view — has no
 * address property at all, so a template that forgets has nothing to print.
 *
 * # THE LEADER'S SCREEN IS HERE, NOT IN THE ADMIN
 *
 * A leader is not staff. Answering requests for the group you lead is something
 * you do about your own group, so it lives on the group's own page behind a
 * check against THAT group rather than behind a capability that would make
 * every leader a moderator of every group.
 */
final class GroupController extends Controller
{
    public function index(Request $request): Response
    {
        $groups = $this->groups();
        $user = $this->user();

        return $this->view(['groups'], [
            'title'  => 'Small groups',
            'groups' => $groups->directory($user?->id),
            'mine'   => $user === null ? [] : $groups->mine($user->id),
            'token'  => $this->csrfToken(),
            'flash'  => $this->flash(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $groups = $this->groups();
        $row = $this->visibleGroup((string) ($params['slug'] ?? ''));
        $user = $this->user();
        $card = $groups->card($row, $user?->id);

        $iLead = $card->iLeadThis;

        return $this->view(['group-' . $card->slug, 'group'], [
            'title' => $card->name,
            'group' => $card,
            /*
             * THE ONE PLACE AN ADDRESS COMES FROM. Null for everybody who is
             * not actually in the group — having asked does not count, and
             * neither does waiting.
             */
            'address' => GroupAddress::for($row, $card->myState),
            /*
             * The leader's own list of people to answer. Only for somebody who
             * leads THIS group; the check is the card's, which came from a row
             * keyed to this group and this person.
             */
            'people' => $iLead ? $groups->people($card->id) : [],
            'iLead'  => $iLead,
            'token'  => $this->csrfToken(),
            'flash'  => $this->flash(),
        ]);
    }

    /** Ask to join. */
    public function ask(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->requireAccount();
        $groups = $this->groups();
        $row = $this->visibleGroup((string) ($request->input('group') ?? ''));

        $state = $groups->ask((int) $row['id'], $user->id, (string) ($request->input('note') ?? ''));

        return $this->back($request, match ($state) {
            GroupRepository::WAITING => 'That group is full, so you are on the list. '
                . 'You will be asked when a place comes free.',
            GroupRepository::MEMBER, GroupRepository::LEADING => 'You are already in that group.',
            default => 'Asked. Whoever leads the group will get back to you — and they will send '
                . 'you the details then.',
        });
    }

    /** Withdraw an ask, or leave. */
    public function leave(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->requireAccount();
        $groups = $this->groups();
        $row = $this->visibleGroup((string) ($request->input('group') ?? ''));

        $groups->leave((int) $row['id'], $user->id);

        // Somebody leaving frees a place, so the list moves immediately rather
        // than waiting for a leader to notice.
        $groups->promote((int) $row['id']);

        return $this->back($request, 'Done. If anybody was waiting, they have been asked.');
    }

    /**
     * The leader answers.
     *
     * The capability check is "do you lead THIS group", read from a row keyed
     * to both ids — not a site-wide permission, and not a check on the group
     * alone.
     */
    public function answer(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->requireAccount();
        $groups = $this->groups();
        $row = $this->visibleGroup((string) ($request->input('group') ?? ''));
        $groupId = (int) $row['id'];

        if ($groups->stateOf($groupId, $user->id) !== GroupRepository::LEADING) {
            throw HttpException::notFound('There is nothing here.');
        }

        $person = (int) ($request->input('person') ?? 0);
        $reply = (string) ($request->input('reply') ?? '');

        if ((string) ($request->input('answer') ?? '') === 'yes') {
            $groups->accept($groupId, $person, $reply);

            return $this->back($request, 'They are in, and they can see where you meet now.');
        }

        $groups->decline($groupId, $person, $reply);

        // A "no" gives the place back, so whoever is next is asked at once.
        $moved = $groups->promote($groupId);

        return $this->back($request, $moved === []
            ? 'Answered.'
            : 'Answered, and the next person on the list has been asked.');
    }

    // ---------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function visibleGroup(string $slug): array
    {
        $row = $this->groups()->findBySlug($slug);

        if ($row === null || !$row['is_published']) {
            throw HttpException::notFound('There is no group here.');
        }

        return $row;
    }

    private function requireAccount(): \Portal\Auth\User
    {
        $user = $this->user();

        if ($user === null) {
            /*
             * Joining needs an account, unlike a form or a prayer request —
             * because a group has to be able to answer somebody, and because
             * membership is what the address travels with. Said as a 403 with
             * a reason rather than a silent redirect.
             */
            throw HttpException::forbidden('You need an account here to join a group.');
        }

        return $user;
    }

    private function groups(): GroupRepository
    {
        return new GroupRepository($this->db());
    }
}
