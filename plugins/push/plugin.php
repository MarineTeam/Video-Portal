<?php
/**
 * Plugin Name: Push notifications
 * Slug: push
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Tells a browser when something new is published, even when the site is not open.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Auth\Guard;
use Portal\Container;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\Push\PushCrypto;
use Portal\Plugins\Push\PushPage;
use Portal\Plugins\Push\PushRepository;
use Portal\Plugins\Push\PushSender;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/PushCrypto.php';
require_once __DIR__ . '/src/PushRepository.php';
require_once __DIR__ . '/src/PushSender.php';
require_once __DIR__ . '/src/PushPage.php';

$repository = new PushRepository($plugin->db());

$configured = static fn (): bool =>
    trim((string) $plugin->setting('public_key', '')) !== ''
    && trim((string) $plugin->setting('private_key', '')) !== '';

/*
 * The service worker, served from the site root.
 *
 * It has to be at the root — a service worker can only control pages at or
 * below its own path, so one served from /plugin-asset/push/sw.js could only
 * ever receive notifications for pages under that directory, which is none of
 * them. That is why this claims a top-level URL, and it is the one thing about
 * this plugin that could collide with a theme.
 *
 * Served by PHP rather than written into public/, because writing a file into
 * the document root at activation is a thing that fails silently on a host with
 * tight permissions and leaves a stale file behind on uninstall.
 */
/*
 * The push handlers, CONTRIBUTED to the site's one service worker rather than
 * served as a worker of this plugin's own.
 *
 * This used to be a route at /push-sw.js that the subscribe button registered
 * at scope `/`. That worked only because nothing else registered a worker: a
 * scope has exactly one active worker, so the moment core gained one, whichever
 * registered last would have silently replaced the other — and both
 * registrations report success either way. Push would simply have stopped
 * arriving, with nothing anywhere saying so.
 *
 * The handlers themselves are unchanged; only where they live has moved.
 */
$plugin->addFilter('service_worker', static function (string $js): string {
    $push = <<<'JS'
    /*
     * The service worker.
     *
     * It runs with no page open, which is the whole point and also the whole
     * constraint: there is no DOM here, no site JavaScript, and no way to ask
     * the user anything.
     */
    self.addEventListener('push', function (event) {
      if (!event.data) { return; }

      var payload;
      try {
        payload = event.data.json();
      } catch (e) {
        // A push with an unreadable body still has to show something. A
        // browser that receives a push and shows nothing may revoke the site's
        // permission to send any more.
        payload = { title: 'New video', body: '', url: '/' };
      }

      event.waitUntil(self.registration.showNotification(payload.title || 'New video', {
        body: payload.body || '',
        icon: payload.icon || undefined,
        // Collapses repeats: a second notification with the same tag replaces
        // the first rather than stacking, so a retry cannot produce two.
        tag: payload.tag || 'portal-video',
        data: { url: payload.url || '/' }
      }));
    });

    self.addEventListener('notificationclick', function (event) {
      event.notification.close();

      var url = (event.notification.data && event.notification.data.url) || '/';

      event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        // Focus a tab that is already open rather than opening another. Someone
        // who has the site open in a tab does not want a second one.
        for (var i = 0; i < list.length; i++) {
          if (list[i].url.indexOf(url) !== -1 && 'focus' in list[i]) {
            return list[i].focus();
          }
        }
        return clients.openWindow ? clients.openWindow(url) : undefined;
      }));
    });
    JS;

    return $js . "\n" . $push;
});

/*
 * Taking a subscription.
 *
 * No CSRF token, deliberately, and for the reason the subscribe and unsubscribe
 * endpoints give: a token protects an action that borrows the victim's
 * AUTHORITY, and this borrows none. The worst a forged request can do is
 * register a push endpoint the attacker already holds — which notifies the
 * attacker's own browser about this site's public videos. Requiring one would
 * mean starting a session for every anonymous visitor to every page, which is
 * the cost that got this wrong once already.
 */
$plugin->addRoute('POST', '/push/subscribe', static function (Request $request) use ($repository): Response {
    $payload = $request->json();

    $endpoint = trim((string) ($payload['endpoint'] ?? ''));
    $p256dh = trim((string) ($payload['keys']['p256dh'] ?? ''));
    $auth = trim((string) ($payload['keys']['auth'] ?? ''));

    $userId = null;
    try {
        $userId = Container::instance()->get(Guard::class)->user()?->id;
    } catch (Throwable) {
        // Anonymous is a normal answer; the site is public.
    }

    $stored = $repository->store($endpoint, $p256dh, $auth, $userId);

    // A refusal is reported honestly rather than pretended away: the browser
    // can retry, and a subscription silently dropped is one nobody ever finds.
    return Response::json(['subscribed' => $stored], $stored ? 200 : 400)->private();
});

$plugin->addRoute('POST', '/push/unsubscribe', static function (Request $request) use ($repository): Response {
    $endpoint = trim((string) ($request->json()['endpoint'] ?? ''));

    if ($endpoint !== '') {
        $repository->forget($endpoint);
    }

    // Always 200. Unsubscribing something that was never subscribed is the
    // state the caller wanted, and an error there would leave a browser
    // retrying forever.
    return Response::json(['unsubscribed' => true])->private();
});

/*
 * The button, and the code that registers the worker.
 *
 * Rendered into the footer of every page rather than into one place, because
 * asking for notification permission is only ever appropriate in response to a
 * click — every browser now ignores a permission prompt that was not — and the
 * click has to be somewhere people are.
 */
$plugin->addAction('header_actions', static function () use ($plugin, $configured): void {
    if (!$configured()) {
        // Nothing to subscribe TO. Rendering the button anyway would produce a
        // control that fails for everybody with an error only the console sees.
        return;
    }

    $publicKey = json_encode((string) $plugin->setting('public_key', ''));

    /*
     * In the header, not floating in the footer.
     *
     * A control people have to scroll to the bottom of the page to find is one
     * most people never find. A bell in the nav is where every other site puts
     * this.
     *
     * Rendered VISIBLE and hidden again if it turns out to be unnecessary,
     * which is the opposite of what this did before. It used to start hidden
     * and be revealed only once navigator.serviceWorker.ready had resolved —
     * and `ready` waits for a worker to install, activate and claim the page,
     * which on a first visit is seconds and on a failed registration is never.
     * So the button appeared late, or not at all, and looked intermittent.
     * Nothing about deciding whether to OFFER the subscription needs the
     * worker; only subscribing does.
     */
    echo <<<HTML
    <button type="button" id="push-subscribe-button" class="push-subscribe" hidden
            title="Notify me about new videos" aria-label="Notify me about new videos">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="M12 2a6 6 0 0 0-6 6v3.6l-1.7 3.2A1 1 0 0 0 5.2 16h13.6a1 1 0 0 0 .9-1.2L18 11.6V8a6 6 0 0 0-6-6Zm0 20a3 3 0 0 0 2.8-2H9.2A3 3 0 0 0 12 22Z"/>
      </svg>
      <span>Notify me</span>
    </button>
    <script>
    (function () {
      var KEY = {$publicKey};
      var button = document.getElementById('push-subscribe-button');

      // Everything here is optional. A browser without service workers, or one
      // where the user has already refused, simply never sees the button —
      // rather than seeing one that does nothing.
      if (!button || !('serviceWorker' in navigator) || !('PushManager' in window)) { return; }
      if (Notification.permission === 'denied') { return; }

      /*
       * Shown immediately. Whether this browser COULD subscribe is answerable
       * from the three checks above, and none of them needs the worker.
       */
      button.hidden = false;

      /*
       * And hidden again if it turns out they are already subscribed. That
       * answer does need the worker, so it arrives late — which is fine in
       * this direction: the worst case is the button being offered for a
       * second to somebody who does not need it, rather than never offered to
       * somebody who does.
       */
      navigator.serviceWorker.ready.then(function (registration) {
        return registration.pushManager.getSubscription().then(function (existing) {
          if (existing) { button.hidden = true; }
        });
      }).catch(function () {});

      function urlBase64ToUint8Array(base64) {
        var padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(padded);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
      }

      /*
       * The site's worker, not one of this plugin's own.
       *
       * `ready` waits for whatever core registered in the footer to be active,
       * rather than registering a second script at the same scope — which would
       * replace core's worker and break every other thing that uses it, while
       * reporting success.
       */
      /*
       * The worker is waited for HERE, on the click, rather than before the
       * button is shown. By this point somebody has asked for notifications,
       * so a moment's wait is expected — and a failure now can be reported,
       * where a failure during page load could only be swallowed.
       */
      button.addEventListener('click', function () {
        button.disabled = true;

        navigator.serviceWorker.ready.then(function (registration) {
          return registration.pushManager.subscribe({
            // Required by every browser: a push that shows no notification is
            // not allowed, and declaring it up front is how they enforce it.
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(KEY)
          });
        }).then(function (subscription) {
          return fetch('/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(subscription)
          });
        }).then(function (response) {
          if (!response || !response.ok) { throw new Error('not stored'); }
          button.hidden = true;
        }).catch(function () {
          /*
           * Say so, rather than quietly re-enabling. Somebody who has just
           * granted permission and seen nothing happen has no way to tell
           * whether it worked, and the commonest outcomes here — the site is
           * on http, or the browser refused — are ones they can act on.
           */
          button.disabled = false;
          button.querySelector('span').textContent = 'Could not subscribe';
        });
      });
    })();
    </script>
    HTML;
});

$plugin->addAdminPage(
    'Push',
    'push',
    Capability::MANAGE_PLUGINS,
    static fn ($request, $params) => (new PushPage($plugin))->show($request, $params),
    position: 65
);

/*
 * Push anything newly visible.
 *
 * The same question the announcement job and the webhook job ask, and for the
 * same reason: there is no publish event to hook, because a scheduled video
 * becomes visible when a comparison starts returning true and no code runs at
 * that moment.
 *
 * Members-only videos are excluded in the query, not here. A push service is
 * somebody else's server and the payload passes through it; the title of a
 * members-only video is not theirs to hold, and a filter applied at the last
 * moment is one a later refactor moves.
 */
$plugin->addCronJob('send', 300, static function () use ($plugin, $repository, $configured): string {
    if (!$configured()) {
        return 'No VAPID keys, so nothing can be sent.';
    }

    if ($repository->count() === 0) {
        return 'Nobody is subscribed.';
    }

    $sender = new PushSender(
        $repository,
        (string) $plugin->setting('public_key', ''),
        (string) $plugin->setting('private_key', ''),
        trim((string) $plugin->setting('subject', '')) !== ''
            ? (string) $plugin->setting('subject', '')
            : $plugin->config()->url('/')
    );

    $pushed = 0;
    $totals = ['sent' => 0, 'failed' => 0, 'dropped' => 0];

    foreach ($repository->unpushedVideos() as $video) {
        // The claim comes BEFORE the send. Losing a notification is
        // recoverable by a person; sending the same one twice to everybody is
        // not.
        if (!$repository->claimVideo((int) $video['id'])) {
            continue;
        }

        $result = $sender->broadcast([
            'title' => (string) ($plugin->config()->setting('site_name', 'New video') ?? 'New video'),
            'body'  => (string) $video['title'],
            'url'   => '/watch/' . (string) $video['slug'],
            'tag'   => 'video-' . (int) $video['id'],
        ]);

        foreach ($totals as $key => $value) {
            $totals[$key] = $value + $result[$key];
        }

        /*
         * The receipt, written for everyone the notification was addressed to
         * — NOT only for the endpoints that succeeded.
         *
         * This is the opposite rule to the email channel, deliberately. A mail
         * provider refusing a message means nothing was sent and nothing will
         * arrive, so recording it would invent something. A push that fails at
         * the endpoint was still genuinely dispatched to that person's
         * subscription, and whether their device ever showed it is unknowable
         * from here — a dismissed notification, a closed browser and a stale
         * subscription are indistinguishable.
         *
         * That case is the entire reason this list exists, so it is the one
         * case it must not drop.
         */
        try {
            $log = new \Portal\Content\NotificationLog(Container::instance()->get(\Portal\Db::class));

            foreach ($repository->subscribedEmails() as $email) {
                $log->record(
                    $email,
                    \Portal\Content\NotificationLog::PUSH,
                    (string) $video['title'],
                    '/watch/' . (string) $video['slug'],
                    (int) $video['id']
                );
            }
        } catch (Throwable $e) {
            // A receipt must never turn a delivered notification into a failed
            // job. The push has already gone out by this point.
            error_log('Push: could not record the notification. ' . $e->getMessage());
        }

        $pushed++;
    }

    if ($pushed === 0) {
        return 'Nothing new to announce.';
    }

    return sprintf(
        '%d video(s): %d sent, %d failed, %d subscription(s) dropped.',
        $pushed,
        $totals['sent'],
        $totals['failed'],
        $totals['dropped']
    );
});
