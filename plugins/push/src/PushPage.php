<?php

declare(strict_types=1);

namespace Portal\Plugins\Push;

use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginContext;
use Portal\Plugins\PluginPage;
use Throwable;

/**
 * The settings screen: keys, a subscriber count, and a test send.
 */
final class PushPage extends PluginPage
{
    public function __construct(private readonly PluginContext $plugin)
    {
        parent::__construct();
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        // Route middleware decides who gets through the admin front door; the
        // capability for changing plugin behaviour is checked here.
        $this->require(\Portal\Auth\Capability::MANAGE_PLUGINS);

        if ($request->method === 'POST') {
            return $this->handle($request);
        }

        return $this->page('Push notifications', $this->body(), 'push');
    }

    private function handle(Request $request): Response
    {
        $this->plugin->verifyCsrf($request);

        $action = (string) ($request->input('action') ?? '');

        if ($action === 'generate') {
            try {
                $keys = PushCrypto::generateVapidKeys();
            } catch (Throwable $e) {
                return $this->back($request, $e->getMessage(), 'error');
            }

            $this->plugin->setSetting('public_key', $keys['publicKey']);
            $this->plugin->setSetting('private_key', $keys['privateKey']);

            return $this->back(
                $request,
                'Keys generated. Anybody already subscribed will have to subscribe again.'
            );
        }

        if ($action === 'subject') {
            $this->plugin->setSetting('subject', trim((string) ($request->input('subject') ?? '')));

            return $this->back($request, 'Contact address saved.');
        }

        if ($action === 'test') {
            return $this->sendTest($request);
        }

        /*
         * Forget one of your own subscriptions.
         *
         * The state this exists for: a browser subscribes, the service worker
         * it was bound to is later replaced, and the push service goes on
         * accepting messages for an endpoint that has no registration left to
         * deliver to. That answers 201 and delivers nothing, which is
         * indistinguishable from every other silent failure — and until this
         * button there was no way to clear the dead row and start again.
         *
         * Scoped to the signed-in user's own rows. The id comes from a form and
         * names somebody else's subscription just as well as your own.
         */
        if ($action === 'forget') {
            $user = $this->plugin->user();
            $id = (int) ($request->input('id') ?? 0);

            if ($user === null) {
                return $this->back($request, 'Sign in first.', 'error');
            }

            $repository = new PushRepository($this->plugin->db());
            $mine = array_filter(
                $repository->forUser($user->id),
                static fn (array $row): bool => (int) $row['id'] === $id
            );

            if ($mine === []) {
                return $this->back($request, 'That subscription is not one of yours.', 'error');
            }

            $repository->drop($id);

            return $this->back($request, 'Forgotten. Subscribe again from the bell in the header.');
        }

        return $this->back($request, 'Nothing to do.', 'error');
    }

    /**
     * Send one notification to whoever is looking at this screen.
     *
     * Only to their own subscriptions, not to everybody. A "test" button that
     * notified the whole site would be pressed exactly once by each new
     * administrator, and there is no way to take it back.
     */
    private function sendTest(Request $request): Response
    {
        $user = $this->plugin->user();

        if ($user === null) {
            return $this->back($request, 'Sign in first.', 'error');
        }

        $repository = new PushRepository($this->plugin->db());
        $mine = $repository->forUser($user->id);

        if ($mine === []) {
            return $this->back(
                $request,
                'This browser is not subscribed yet. Allow notifications on the site first.',
                'error'
            );
        }

        $sender = new PushSender(
            $repository,
            (string) $this->plugin->setting('public_key', ''),
            (string) $this->plugin->setting('private_key', ''),
            $this->subject()
        );

        $body = (string) json_encode([
            'title' => (string) ($this->plugin->config()->setting('site_name', 'Video Portal') ?? ''),
            'body'  => 'Notifications are working.',
            'url'   => '/',
        ]);

        $sent = 0;
        $outcomes = [];

        foreach ($mine as $subscription) {
            if ($sender->send($subscription, $body) === 'sent') {
                $sent++;
            }
            if ($sender->lastOutcome !== null) {
                $outcomes[$sender->lastOutcome] = true;
            }
        }

        /*
         * The push service's own words, shown rather than logged.
         *
         * "It should appear in a moment" was the whole message before, which is
         * useless in the case that actually happens: the service returns 201,
         * nothing appears, and there is no way to tell whether the send failed
         * or the browser discarded the payload. A 201 means the bytes were
         * ACCEPTED, not that they were readable — a payload encrypted wrongly
         * is dropped by the browser before the push event fires, silently. So
         * the status is reported either way and the two states are named.
         */
        $detail = implode(' ', array_keys($outcomes));

        if ($sent > 0) {
            return $this->back(
                $request,
                $detail . ' If nothing appears within a minute, the browser received it and could not '
                . 'read it — which points at the payload encryption or the keys, not at the send.'
            );
        }

        return $this->back(
            $request,
            $detail === '' ? 'The push service would not take it.' : $detail,
            'error'
        );
    }

    private function subject(): string
    {
        $subject = trim((string) $this->plugin->setting('subject', ''));

        // Push services require a contact of some kind and reject a token
        // without one. The site's own URL is a defensible default and is
        // always available.
        return $subject !== '' ? $subject : $this->config()->url('/');
    }

    private function body(): string
    {
        $token = $this->csrfField();

        $publicKey = (string) $this->plugin->setting('public_key', '');
        $subject = e((string) $this->plugin->setting('subject', ''));
        $repository = new PushRepository($this->plugin->db());
        $subscribers = $repository->count();
        $mine = $this->plugin->user() === null ? [] : $repository->forUser($this->plugin->user()->id);

        /*
         * This browser's own subscriptions, listed rather than counted.
         *
         * A count cannot show the state that actually goes wrong: TWO rows,
         * one of them bound to a service worker that no longer exists. The
         * push service accepts messages for a dead endpoint and delivers
         * nothing, so a test reports success and nothing arrives — and with
         * only a number on the screen there is no way to see the stale row,
         * let alone remove it.
         */
        $mineRows = '';
        foreach ($mine as $row) {
            $host = (string) (parse_url((string) $row['endpoint'], PHP_URL_HOST) ?: 'unknown');
            $created = \Portal\Support\Str::since((string) ($row['created_at'] ?? ''));
            $lastSent = ($row['last_sent_at'] ?? null) === null
                ? 'never sent to'
                : 'last sent ' . \Portal\Support\Str::since((string) $row['last_sent_at']);
            $failures = (int) ($row['failure_count'] ?? 0);

            $mineRows .= sprintf(
                '<tr><td>%s</td><td class="muted small">added %s · %s%s</td>
                 <td class="right"><form method="post" class="inline">%s
                 <input type="hidden" name="id" value="%d">
                 <button class="btn tiny danger" name="action" value="forget">Forget</button>
                 </form></td></tr>',
                e($host),
                e($created),
                e($lastSent),
                $failures > 0 ? ' · ' . $failures . ' failure(s)' : '',
                $token,
                (int) $row['id']
            );
        }

        $mineTable = $mineRows === ''
            ? '<p class="muted">This browser is not subscribed. Use the bell in the site header.</p>'
            : '<table><tbody>' . $mineRows . '</tbody></table>'
              . '<p class="muted small">More than one row here is usually the problem when a test '
              . 'says it was accepted and nothing arrives: an old subscription can outlive the '
              . 'service worker it was bound to, and the push service goes on accepting messages '
              . 'for it. Forget them all and subscribe once more.</p>';

        /*
         * Does the worker this site would serve actually carry the push
         * handlers?
         *
         * Asked of the filter directly, server-side, because the alternative is
         * asking somebody to open /sw.js in a browser tab and read JavaScript —
         * which is what it took to find this, and which cannot distinguish "the
         * server is not producing it" from "something between the server and
         * the browser is serving an old copy".
         *
         * A push arrives at a worker with no push listener and does nothing at
         * all: no notification, no error, no record. It is the most silent
         * failure in this plugin and it had no indicator anywhere.
         */
        $workerJs = (string) apply_filters('service_worker', '');
        $handlerPresent = str_contains($workerJs, "addEventListener('push'");

        $workerState = $handlerPresent
            ? '<p class="pill ok">The service worker this site serves includes the push handlers.</p>'
              . '<p class="muted small">If <code>/sw.js</code> in a browser does NOT contain '
              . '<code>addEventListener(\'push\'</code>, the copy reaching the browser is stale — '
              . 'a CDN or proxy is caching it. The worker is served with no-cache for exactly that '
              . 'reason; purge the cache for <code>/sw.js</code> and reload twice.</p>'
            : '<p class="pill bad">The service worker this site serves does NOT include the push '
              . 'handlers.</p><p class="muted small">A push delivered to a worker with no push '
              . 'listener does nothing at all — no notification, no error. Nothing can arrive '
              . 'until this says otherwise.</p>';

        $keyState = $publicKey === ''
            ? '<p class="pill bad">No keys yet. Nothing can be sent until they are generated.</p>'
            : '<p class="pill ok">Keys are set.</p><p class="muted small">Public key: <code>'
              . e($publicKey) . '</code></p>';

        $regenerateWarning = $publicKey === ''
            ? 'Generate keys'
            : 'Replace the keys';

        return <<<HTML
        <h1>Push notifications</h1>

        <p class="muted">A notification in the browser when something new is published. It goes to
           people who allowed notifications on this site, whether or not they have an account.</p>

        <p class="muted small"><strong>Only over https, and only for public videos.</strong> Browsers
           refuse to register a service worker on an insecure origin, so this does nothing on a site
           served over http. Members-only videos are never pushed: a push service is somebody else's
           server, and the title of a members-only video is not theirs to hold.</p>

        <h2>The service worker</h2>
        {$workerState}

        <h2>Keys</h2>
        {$keyState}
        <p class="muted small">A key pair identifies this site to every push service. Replacing them
           invalidates every existing subscription — browsers subscribe TO a key, so everybody would
           have to allow notifications again.</p>

        <form method="post">
          {$token}
          <button class="btn" name="action" value="generate"
                  onclick="return confirm('Replace the keys? Everybody subscribed now will stop receiving notifications until they subscribe again.')">{$regenerateWarning}</button>
        </form>

        <h2>Contact address</h2>
        <form method="post">
          {$token}
          <label>Address <input type="text" name="subject" value="{$subject}"
                                placeholder="mailto:you@example.com"></label>
          <p class="muted small">Push services require a way to reach whoever is sending, and reject
             a request without one. A <code>mailto:</code> or an https URL. Left blank, this site's
             own address is used.</p>
          <button class="btn" name="action" value="subject">Save</button>
        </form>

        <h2>Yours</h2>
        {$mineTable}

        <h2>Subscribers</h2>
        <p class="muted">{$subscribers} browser(s) subscribed.</p>
        <p class="muted small">Not people — browsers. One person with a laptop and a phone counts
           twice, and a subscription disappears on its own when a push service says the browser has
           gone.</p>

        <form method="post">
          {$token}
          <button class="btn secondary" name="action" value="test">Send a test to this browser</button>
        </form>
        <p class="muted small">Only to browsers signed in as you. A test that notified everybody would
           be pressed once by every new administrator, and there is no way to take one back.</p>
        HTML;
    }
}
