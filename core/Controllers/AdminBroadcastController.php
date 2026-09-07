<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminBroadcastView;
use Portal\Auth\Capability;
use Portal\Broadcast\BroadcastRepository;
use Portal\Broadcast\Consent;
use Portal\Broadcast\Sender;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Mail\MailProvider;
use Portal\Support\Audit;

/**
 * Writing and sending a broadcast.
 *
 * The order the screen enforces is the order the rules need: write it, see the
 * honest counts, send yourself one, then send it. Nothing here delivers to an
 * audience without the recipient rows existing first.
 */
final class AdminBroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::SEND_BROADCASTS);

        $broadcasts = $this->broadcasts();
        $rows = [];

        foreach ($broadcasts->all() as $broadcast) {
            $broadcast['tally'] = $broadcasts->tally((int) $broadcast['id']);
            $rows[] = $broadcast;
        }

        return $this->render('broadcasts', ['broadcasts' => $rows]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->require(Capability::SEND_BROADCASTS);

        $broadcasts = $this->broadcasts();
        $broadcast = $broadcasts->find((int) ($params['id'] ?? 0));

        if ($broadcast === null) {
            throw HttpException::notFound('There is no broadcast with that id.');
        }

        return $this->render('broadcast', [
            'broadcast' => $broadcast,
            /*
             * Counted on every render rather than cached. A preview that is
             * older than the audience is a number somebody trusts and acts on,
             * and people join and opt out between one page load and the next.
             */
            'preview'   => $broadcasts->preview($broadcast),
            'groups'    => $this->db()->all('SELECT id, name FROM {permission_groups} ORDER BY name'),
            'events'    => $this->db()->all(
                'SELECT id, title FROM {events} WHERE starts_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                  ORDER BY starts_at DESC LIMIT 50'
            ),
            'recipients' => $broadcasts->recipients((int) $broadcast['id']),
            'tally'      => $broadcasts->tally((int) $broadcast['id']),
            'pushReady'  => $broadcasts->pushAvailable(),
            'smsReady'   => $this->sms() !== null && $this->sms()->isConfigured(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::SEND_BROADCASTS);

        $broadcasts = $this->broadcasts();
        $action = (string) ($request->input('action') ?? '');

        try {
            return match ($action) {
                'create' => $this->create($request, $broadcasts),
                'save'   => $this->save($request, $broadcasts),
                'test'   => $this->test($request, $broadcasts),
                'send'   => $this->send($request, $broadcasts),
                'stop'   => $this->stop($request, $broadcasts),
                default  => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function create(Request $request, BroadcastRepository $broadcasts): Response
    {
        $id = $broadcasts->create(
            (string) ($request->input('subject') ?? ''),
            (string) ($this->user()?->email ?? '')
        );

        return $this->redirect('/admin/broadcasts/' . $id);
    }

    private function save(Request $request, BroadcastRepository $broadcasts): Response
    {
        $broadcasts->update((int) ($request->input('id') ?? 0), [
            'subject'       => (string) ($request->input('subject') ?? ''),
            'body'          => (string) ($request->input('body') ?? ''),
            'audience_type' => (string) ($request->input('audience_type') ?? ''),
            'audience_id'   => (int) ($request->input('audience_id') ?? 0),
            '_whole_form'   => $request->input('_whole_form') !== null,
            'by_email'      => $request->input('by_email') !== null,
            'by_sms'        => $request->input('by_sms') !== null,
            'by_push'       => $request->input('by_push') !== null,
        ]);

        return $this->back($request, 'Saved.');
    }

    /**
     * SEND YOURSELF ONE FIRST.
     *
     * Not a preview pane. A rendered preview shows what this application thinks
     * the message looks like; a real message shows what a mail client does with
     * it, which is a different thing and the one that goes to two hundred
     * people. It goes to the signed-in person's own address and touches no
     * recipient row.
     */
    private function test(Request $request, BroadcastRepository $broadcasts): Response
    {
        $broadcast = $broadcasts->find((int) ($request->input('id') ?? 0));
        $user = $this->user();

        if ($broadcast === null || $user === null) {
            return $this->back($request, 'Nothing to send.', 'error');
        }

        $result = $this->container->get(MailProvider::class)->send(
            $user->email,
            '[test] ' . (string) $broadcast['subject'],
            nl2br(e((string) $broadcast['body'])),
            (string) $broadcast['body']
        );

        return $this->back(
            $request,
            $result->sent
                ? 'Sent to ' . $user->email . '. Read it before you send it to anybody else.'
                : 'That did not send: ' . (string) $result->error,
            $result->sent ? 'success' : 'error'
        );
    }

    /**
     * Resolve, then start.
     *
     * The rows are written here, in the request somebody pressed the button in,
     * so the number on the confirmation is the number that will go. The sending
     * itself is left to the scheduled job — a send of four hundred cannot
     * happen inside one request on the hosts this targets.
     */
    private function send(Request $request, BroadcastRepository $broadcasts): Response
    {
        $broadcast = $broadcasts->find((int) ($request->input('id') ?? 0));

        if ($broadcast === null) {
            return $this->back($request, 'Nothing to send.', 'error');
        }

        if (BroadcastRepository::channelsOf($broadcast) === []) {
            return $this->back($request, 'Choose at least one way to send it.', 'error');
        }

        if (trim((string) $broadcast['body']) === '') {
            return $this->back($request, 'There is nothing in the message.', 'error');
        }

        $count = $broadcasts->resolve($broadcast);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'broadcast.send',
            'broadcast',
            (string) $broadcast['id'],
            sprintf('%d recipient(s)', $count)
        );

        /*
         * One run now, so somebody watching sees it start rather than a page
         * that says "sending" and does nothing until a visitor happens to
         * arrive. The rest is the job's.
         */
        $result = $this->sender($broadcasts)->run((array) $broadcasts->find((int) $broadcast['id']));

        return $this->back($request, sprintf(
            'Going out to %d. %d sent so far, %d left — the rest go out over the next few minutes.',
            $count,
            $result['sent'],
            $result['remaining']
        ));
    }

    private function stop(Request $request, BroadcastRepository $broadcasts): Response
    {
        $broadcasts->stop((int) ($request->input('id') ?? 0));

        return $this->back(
            $request,
            // Said plainly: stopping does not unsend, and somebody who thinks
            // it might will press it and then be surprised.
            'Stopped. Whatever has already gone out cannot be taken back.'
        );
    }

    // ---------------------------------------------------------------- wiring

    private function broadcasts(): BroadcastRepository
    {
        return new BroadcastRepository(
            $this->db(),
            (string) ($this->config()->setting('sms_country_code') ?? '')
        );
    }

    private function sms(): ?\Portal\Sms\SmsProvider
    {
        try {
            return $this->container->get(\Portal\Sms\SmsProvider::class);
        } catch (\Throwable) {
            // No SMS provider bound: the ordinary state of a site that never
            // wanted texts. Not an error, and the screen says so.
            return null;
        }
    }

    private function sender(BroadcastRepository $broadcasts): Sender
    {
        return new Sender(
            $this->db(),
            $this->config(),
            $broadcasts,
            $this->container->get(MailProvider::class),
            $this->sms()
        );
    }

    /** @param array<string, mixed> $data */
    private function render(string $screen, array $data): Response
    {
        $view = new AdminBroadcastView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'channels' => Consent::channels(),
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }
}
