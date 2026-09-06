<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Rota\Assignment;
use Portal\Rota\RotaRepository;

/**
 * The rota as the person serving sees it.
 *
 * Everything here is something somebody does about THEMSELVES — answering an
 * ask, saying which days they cannot serve, asking for cover, taking a slot
 * going spare — so none of it needs a capability. What it needs instead is that
 * every write is keyed to the person in its WHERE clause, which is where those
 * rules live, in RotaRepository.
 *
 * Behind auth.authorized rather than auth.user: an account waiting for approval
 * is not on any team and has nothing to answer, and the page would be an empty
 * screen implying they had been forgotten.
 */
final class RotaController extends Controller
{
    public function mine(Request $request): Response
    {
        $user = $this->user();

        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $rota = $this->rota();

        return $this->view(['rota'], [
            'title'       => 'Your rota',
            'asks'        => $rota->forPerson($user->id),
            'blockouts'   => $rota->blockouts($user->id),
            /*
             * Slots going spare. Shown to everybody rather than filtered to
             * their teams, deliberately: a rota with a hole in it on Sunday
             * morning is a problem for the whole church, and the person who can
             * fill it is often not on that team yet. The repository refuses
             * anybody already on the service, which is the only rule that
             * actually matters here.
             */
            'coverWanted' => $rota->coverWanted(),
            'me'          => $user->id,
            'token'       => $this->csrfToken(),
            'flash'       => $this->flash(),
        ]);
    }

    /**
     * One service: the running order, and who is serving.
     *
     * Behind auth.authorized like the rest of this controller. A public "what
     * is on" page is a later section with its own rules about names; until
     * then, a page listing who is serving is for the people serving.
     *
     * A draft is visible to whoever may build the rota and to nobody else —
     * they are the person checking it before it goes out, and the 404 for
     * everybody else is the same answer a service that does not exist gets.
     *
     * @param array<string, string> $params
     */
    public function service(Request $request, array $params): Response
    {
        $rota = $this->rota();
        $service = $rota->service((int) ($params['id'] ?? 0));

        if ($service === null) {
            throw HttpException::notFound('There is no service at that address.');
        }

        if (!$service['is_published'] && !$this->guard()->can(Capability::MANAGE_ROTA)) {
            throw HttpException::notFound('There is no service at that address.');
        }

        /*
         * Only the people who said yes. An invitation nobody has answered is
         * not a fact about Sunday, and printing it would have somebody turn up
         * expecting help that was never agreed to — or, worse, not turn up
         * because they saw a name that was only ever a question.
         */
        $serving = array_values(array_filter(
            $rota->forService((int) $service['id']),
            static fn (Assignment $a): bool => $a->isAccepted()
        ));

        return $this->view(['service'], [
            'title'   => (string) $service['title'],
            'service' => $service,
            'plan'    => $rota->plan((int) $service['id']),
            'serving' => $serving,
            'flash'   => $this->flash(),
        ]);
    }

    /**
     * Yes, no, cover, or a day away.
     *
     * One handler for all of them because they are one form's worth of
     * intentions and every one of them ends up back on the same page. The
     * action is matched exhaustively rather than falling through to a default:
     * an unrecognised action must do nothing, not the first thing on the list.
     */
    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();

        if ($user === null) {
            throw HttpException::forbidden('Sign in first.');
        }

        $rota = $this->rota();
        $action = (string) ($request->input('action') ?? '');
        $id = (int) ($request->input('id') ?? 0);

        return match ($action) {
            'accept' => $this->answered(
                $request,
                $rota->answer($id, $user->id, Assignment::ACCEPTED, (string) ($request->input('reason') ?? '')),
                'Thank you — you are down for it.'
            ),

            'decline' => $this->answered(
                $request,
                $rota->answer($id, $user->id, Assignment::DECLINED, (string) ($request->input('reason') ?? '')),
                'Thank you for letting us know.'
            ),

            'request-cover' => $this->answered(
                $request,
                $rota->requestCover($id, $user->id, (string) ($request->input('reason') ?? '')),
                'Asked. Anybody on the rota can take it now.'
            ),

            'cancel-cover' => $this->answered(
                $request,
                $rota->cancelCoverRequest($id, $user->id),
                'Taken back — it is yours again.'
            ),

            'take-cover' => $this->tookCover($request, $rota, $id, $user->id),

            'add-blockout' => $this->addedBlockout($request, $rota, $user->id),

            'remove-blockout' => $this->answered(
                $request,
                $rota->removeBlockout($id, $user->id),
                'Removed.'
            ),

            default => $this->back($request, 'That is not something this page can do.', 'error'),
        };
    }

    /**
     * The four outcomes of trying to take a slot, in words.
     *
     * "Somebody was quicker" is not an error and is not phrased as one — two
     * people offering to help is a good problem, and the person who lost should
     * not be made to feel they did something wrong.
     */
    private function tookCover(Request $request, RotaRepository $rota, int $id, int $userId): Response
    {
        return match ($rota->takeCover($id, $userId)) {
            RotaRepository::TAKEN => $this->back($request, 'Thank you — it is yours.'),

            RotaRepository::GONE => $this->back(
                $request,
                'Somebody was just ahead of you and has taken it. Nothing has changed for you.',
                'error'
            ),

            RotaRepository::ALREADY_ON => $this->back(
                $request,
                'You are already down for that service, so you cannot cover it as well.',
                'error'
            ),

            default => $this->back($request, 'That slot is not looking for cover.', 'error'),
        };
    }

    private function addedBlockout(Request $request, RotaRepository $rota, int $userId): Response
    {
        try {
            $rota->addBlockout(
                $userId,
                (string) ($request->input('from') ?? ''),
                (string) ($request->input('to') ?? ''),
                (string) ($request->input('reason') ?? '')
            );
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }

        return $this->back(
            $request,
            /*
             * Said plainly, because the rule surprises people: this does not
             * stop anybody asking. Somebody who believes it does will not
             * answer the ask that arrives anyway, and the builder will chase
             * them for a week.
             */
            'Noted. Whoever builds the rota will be warned when they get to those dates — '
            . 'it does not stop them asking, so you may still be asked.'
        );
    }

    private function answered(Request $request, bool $changed, string $message): Response
    {
        return $changed
            ? $this->back($request, $message)
            : $this->back(
                $request,
                'That is not yours to answer, or it has already changed. The page below is current.',
                'error'
            );
    }

    private function rota(): RotaRepository
    {
        return new RotaRepository($this->db());
    }
}
