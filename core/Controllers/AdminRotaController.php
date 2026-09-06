<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminRotaView;
use Portal\Auth\Capability;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Rota\AskOutcome;
use Portal\Rota\RotaRepository;
use Portal\Support\Audit;

/**
 * Building the rota.
 *
 * The counterpart to RotaController, which is the same data seen by the person
 * serving. Everything here needs MANAGE_ROTA and everything there needs
 * nothing, which is the whole division: one screen decides who is asked, the
 * other answers.
 *
 * # THE WARNING HAS TO ARRIVE BEFORE THE BUTTON
 *
 * `RotaRepository::wouldAsk()` was written with the comment that a warning
 * shown after the ask has gone out is no use to anybody, and until this screen
 * existed it had no caller — which would have made that comment a description
 * of a thing nobody could do. The people picker asks it for every candidate and
 * puts the answer beside the name, so "away that weekend" is read while
 * choosing rather than reported afterwards.
 */
final class AdminRotaController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::MANAGE_ROTA);

        $rota = $this->rota();

        return $this->render('rota', [
            'services'   => $rota->services(true, 60),
            'teams'      => $rota->teams(),
            /*
             * Who has not answered. The reason this is on the front screen
             * rather than behind a link is that it is the only thing on it that
             * needs doing today — a rota is built once and chased all week.
             */
            'unanswered' => $rota->unanswered(),
            'cover'      => $rota->coverWanted(),
        ]);
    }

    /** @param array<string, string> $params */
    public function service(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_ROTA);

        $rota = $this->rota();
        $service = $rota->service((int) ($params['id'] ?? 0));

        if ($service === null) {
            throw HttpException::notFound('There is no service with that id.');
        }

        $teams = $rota->teams();
        $teamId = (int) ($request->query('team') ?? ($teams[0]['id'] ?? 0));

        return $this->render('rota-service', [
            'service'     => $service,
            'plan'        => $rota->plan((int) $service['id']),
            'assignments' => $rota->forService((int) $service['id']),
            'teams'       => $teams,
            'teamId'      => $teamId,
            'positions'   => $teamId > 0 ? $rota->positions($teamId) : [],
            /*
             * Every candidate with the answer wouldAsk() gives for THIS
             * service, so the blockout warning is on screen while the builder
             * chooses. One query per candidate on a team of a dozen, on a
             * screen somebody opens once a week — the alternative is the
             * warning arriving after the ask, which is no warning at all.
             */
            'candidates'  => $teamId > 0
                ? $this->candidates($rota, (int) $service['id'], $teamId)
                : [],
        ]);
    }

    /** @param array<string, string> $params */
    public function team(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_ROTA);

        $rota = $this->rota();
        $team = $rota->team((int) ($params['id'] ?? 0));

        if ($team === null) {
            throw HttpException::notFound('There is no team with that id.');
        }

        return $this->render('rota-team', [
            'team'      => $team,
            'positions' => $rota->positions((int) $team['id']),
            'members'   => $rota->members((int) $team['id']),
            'people'    => $this->people(),
        ]);
    }

    /**
     * Everything the builder can do, in one handler.
     *
     * Matched exhaustively rather than falling through to a default: an
     * unrecognised action must do nothing rather than the first thing on the
     * list, and on this screen the first thing on the list writes to somebody
     * else's week.
     */
    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_ROTA);

        $rota = $this->rota();
        $action = (string) ($request->input('action') ?? '');

        try {
            return match ($action) {
                'create-team'     => $this->createTeam($request, $rota),
                'add-position'    => $this->addPosition($request, $rota),
                'add-member'      => $this->addMember($request, $rota),
                'remove-member'   => $this->removeMember($request, $rota),
                'create-service'  => $this->createService($request, $rota),
                'publish'         => $this->publish($request, $rota, true),
                'unpublish'       => $this->publish($request, $rota, false),
                'ask'             => $this->ask($request, $rota),
                'withdraw'        => $this->withdraw($request, $rota),
                'add-plan-item'   => $this->addPlanItem($request, $rota),
                'remove-plan-item' => $this->removePlanItem($request, $rota),
                'plan-up'         => $this->movePlanItem($request, $rota, -1),
                'plan-down'       => $this->movePlanItem($request, $rota, 1),
                default           => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            /*
             * A refusal comes back as a message on the screen rather than an
             * error page. "Alice is already on this service" is something the
             * builder acts on; an error page is something they report.
             */
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    // ----------------------------------------------------------- the actions

    private function createTeam(Request $request, RotaRepository $rota): Response
    {
        $id = $rota->createTeam(
            (string) ($request->input('name') ?? ''),
            (string) ($request->input('description') ?? '')
        );

        Audit::log($this->db(), $this->user()?->email, 'rota.team.create', 'rota_team', (string) $id);

        return $this->redirect('/admin/rota/teams/' . $id);
    }

    private function addPosition(Request $request, RotaRepository $rota): Response
    {
        $teamId = (int) ($request->input('team_id') ?? 0);
        $rota->addPosition($teamId, (string) ($request->input('name') ?? ''));

        return $this->back($request, 'Added.');
    }

    private function addMember(Request $request, RotaRepository $rota): Response
    {
        $teamId = (int) ($request->input('team_id') ?? 0);
        $userId = (int) ($request->input('user_id') ?? 0);
        $positionId = (int) ($request->input('position_id') ?? 0);

        if ($teamId <= 0 || $userId <= 0) {
            return $this->back($request, 'Choose somebody to add.', 'error');
        }

        $rota->addMember($teamId, $userId, $positionId > 0 ? $positionId : null);

        return $this->back($request, 'Added to the team.');
    }

    private function removeMember(Request $request, RotaRepository $rota): Response
    {
        $rota->removeMember(
            (int) ($request->input('team_id') ?? 0),
            (int) ($request->input('user_id') ?? 0)
        );

        /*
         * Said explicitly, because it is the surprising half: their existing
         * services stand. A rota that rewrote its own past would be lying about
         * who was there.
         */
        return $this->back(
            $request,
            'Taken off the team. Anything they have already been asked to do still stands — '
            . 'withdraw those separately if they should not.'
        );
    }

    private function createService(Request $request, RotaRepository $rota): Response
    {
        $id = $rota->createService(
            (string) ($request->input('title') ?? ''),
            (string) ($request->input('starts_at') ?? ''),
            (string) ($request->input('notes') ?? '')
        );

        Audit::log($this->db(), $this->user()?->email, 'rota.service.create', 'rota_service', (string) $id);

        return $this->redirect('/admin/rota/services/' . $id);
    }

    private function publish(Request $request, RotaRepository $rota, bool $published): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $rota->publishService($id, $published);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            $published ? 'rota.service.publish' : 'rota.service.unpublish',
            'rota_service',
            (string) $id
        );

        return $this->back(
            $request,
            $published
                // What publishing MEANS, said once rather than assumed: it is
                // the moment the asks become visible to the people asked.
                ? 'Published. Everybody asked can see it now and answer.'
                : 'Unpublished. It is off everybody\'s rota page until you publish it again.'
        );
    }

    private function ask(Request $request, RotaRepository $rota): Response
    {
        $serviceId = (int) ($request->input('service_id') ?? 0);
        $teamId = (int) ($request->input('team_id') ?? 0);
        $positionId = (int) ($request->input('position_id') ?? 0);
        $userId = (int) ($request->input('user_id') ?? 0);

        if ($serviceId <= 0 || $teamId <= 0 || $userId <= 0) {
            return $this->back($request, 'Choose somebody to ask.', 'error');
        }

        $outcome = $rota->ask($serviceId, $teamId, $userId, $positionId > 0 ? $positionId : null);

        /*
         * A warning is reported ALONGSIDE the success, not instead of it. The
         * ask went out; the builder is being told something they may want to
         * act on, and dressing that as a failure would send them looking for a
         * write that happened.
         */
        return $this->back(
            $request,
            $outcome->isWarning()
                ? 'Asked — but note: ' . $outcome->message
                : 'Asked.',
            $outcome->isWarning() ? 'error' : 'success'
        );
    }

    private function withdraw(Request $request, RotaRepository $rota): Response
    {
        $rota->withdraw((int) ($request->input('id') ?? 0));

        return $this->back($request, 'Withdrawn.');
    }

    private function addPlanItem(Request $request, RotaRepository $rota): Response
    {
        $rota->addPlanItem(
            (int) ($request->input('service_id') ?? 0),
            (string) ($request->input('kind') ?? 'item'),
            (string) ($request->input('title') ?? ''),
            (string) ($request->input('reference') ?? ''),
            (string) ($request->input('note') ?? '')
        );

        return $this->back($request, 'Added to the order.');
    }

    private function removePlanItem(Request $request, RotaRepository $rota): Response
    {
        $rota->removePlanItem((int) ($request->input('id') ?? 0));

        return $this->back($request, 'Removed.');
    }

    private function movePlanItem(Request $request, RotaRepository $rota, int $direction): Response
    {
        $moved = $rota->movePlanItem((int) ($request->input('id') ?? 0), $direction);

        /*
         * Silent on success, like the other ordering buttons on this site — the
         * list itself is the feedback. Only the no-op is worth a word, because
         * a button that appears to do nothing otherwise looks broken.
         */
        return $this->back($request, $moved ? '' : 'That one is already at the end.');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The team's members, each with what would happen if they were asked.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(RotaRepository $rota, int $serviceId, int $teamId): array
    {
        $out = [];

        foreach ($rota->members($teamId) as $member) {
            $outcome = $rota->wouldAsk($serviceId, (int) $member['user_id']);

            $out[] = $member + [
                'outcome' => $outcome->state,
                'warning' => $outcome->isWarning() ? $outcome->message : '',
                // A refusal is shown too, greyed rather than hidden: a name
                // that silently disappears from a picker reads as a bug, where
                // "already on this service" answers the question being asked.
                'refused' => $outcome->state === AskOutcome::REFUSED,
            ];
        }

        return $out;
    }

    /**
     * Everybody who could be put on a team.
     *
     * Approved accounts only. An account waiting for approval cannot open the
     * rota page to answer, so offering them here would create asks nobody can
     * respond to.
     *
     * @return list<array<string, mixed>>
     */
    private function people(): array
    {
        return $this->db()->all(
            'SELECT id, COALESCE(NULLIF(name, ""), email) AS person_name, email
               FROM {users}
              WHERE authorized = 1
              ORDER BY person_name
              LIMIT 500'
        );
    }

    private function rota(): RotaRepository
    {
        return new RotaRepository($this->db());
    }

    /** @param array<string, mixed> $data */
    private function render(string $screen, array $data): Response
    {
        $view = new AdminRotaView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }
}
