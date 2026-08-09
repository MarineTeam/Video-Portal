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
$plugin->addRoute('GET', '/push-sw.js', static function (): Response {
    $script = <<<'JS'
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

    return Response::text($script)
        ->header('Content-Type', 'application/javascript; charset=utf-8')
        // A service worker is cached aggressively by browsers; a short max-age
        // is how a fix to it ever reaches anybody.
        ->header('Cache-Control', 'public, max-age=300')
        /*
         * Without this a worker at /push-sw.js could only control /push-sw.js.
         * The header is the only way to widen it, and getting it wrong produces
         * a worker that registers successfully and receives nothing.
         */
        ->header('Service-Worker-Allowed', '/');
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
$plugin->addAction('footer', static function () use ($plugin, $configured): void {
    if (!$configured()) {
        // Nothing to subscribe TO. Rendering the button anyway would produce a
        // control that fails for everybody with an error only the console sees.
        return;
    }

    $publicKey = json_encode((string) $plugin->setting('public_key', ''));

    echo <<<HTML
    <div class="push-subscribe" hidden>
      <button type="button" id="push-subscribe-button" class="btn secondary">
        Notify me about new videos
      </button>
    </div>
    <script>
    (function () {
      var KEY = {$publicKey};
      var box = document.querySelector('.push-subscribe');
      var button = document.getElementById('push-subscribe-button');

      // Everything here is optional. A browser without service workers, or one
      // where the user has already refused, simply never sees the button —
      // rather than seeing one that does nothing.
      if (!box || !button || !('serviceWorker' in navigator) || !('PushManager' in window)) { return; }
      if (Notification.permission === 'denied') { return; }

      function urlBase64ToUint8Array(base64) {
        var padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(padded);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
      }

      navigator.serviceWorker.register('/push-sw.js', { scope: '/' }).then(function (registration) {
        return registration.pushManager.getSubscription().then(function (existing) {
          if (existing) { return; }
          box.hidden = false;

          button.addEventListener('click', function () {
            button.disabled = true;

            registration.pushManager.subscribe({
              // Required by every browser: a push that shows no notification is
              // not allowed, and declaring it up front is how they enforce it.
              userVisibleOnly: true,
              applicationServerKey: urlBase64ToUint8Array(KEY)
            }).then(function (subscription) {
              return fetch('/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subscription)
              });
            }).then(function () {
              box.hidden = true;
            }).catch(function () {
              button.disabled = false;
            });
          });
        });
      }).catch(function () {
        // A registration failure is not worth telling a visitor about: it means
        // the site is on http, or the browser refused, and neither is something
        // they can act on.
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
