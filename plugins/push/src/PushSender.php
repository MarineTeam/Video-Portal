<?php

declare(strict_types=1);

namespace Portal\Plugins\Push;

use Portal\Support\Http;
use Throwable;

/**
 * Sending one notification to one browser.
 *
 * Runs from the cron tick, never from a page render — a notification goes to as
 * many push services as there are subscribers, and doing that while somebody
 * waits would put every one of those round trips in front of a page.
 */
final class PushSender
{
    /** A push service that has not answered in this long is not going to. */
    private const TIMEOUT_SECONDS = 10;

    /**
     * How long the push service should hold a message for a browser that is
     * offline. A day: long enough for somebody who closed their laptop on
     * Friday, short enough that Monday's notification is not about Thursday.
     */
    private const TTL_SECONDS = 86400;

    public function __construct(
        private readonly PushRepository $subscriptions,
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $subject,
    ) {
    }

    /**
     * Send one payload to everybody.
     *
     * @param  array<string, mixed> $payload
     * @return array{sent: int, failed: int, dropped: int}
     */
    public function broadcast(array $payload, int $limit = 200): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            return ['sent' => 0, 'failed' => 0, 'dropped' => 0];
        }

        $sent = 0;
        $failed = 0;
        $dropped = 0;

        foreach ($this->subscriptions->all($limit) as $subscription) {
            $result = $this->send($subscription, $body);

            match ($result) {
                'sent'    => $sent++,
                'dropped' => $dropped++,
                default   => $failed++,
            };
        }

        return ['sent' => $sent, 'failed' => $failed, 'dropped' => $dropped];
    }

    /**
     * One subscription.
     *
     * @param  array<string, mixed> $subscription
     * @return string 'sent', 'failed', or 'dropped'
     */
    public function send(array $subscription, string $body): string
    {
        $id = (int) $subscription['id'];
        $endpoint = (string) $subscription['endpoint'];

        try {
            $encrypted = PushCrypto::encrypt(
                $body,
                (string) $subscription['p256dh'],
                (string) $subscription['auth_secret']
            );

            $response = Http::request(
                'POST',
                $endpoint,
                $encrypted,
                [
                    'Authorization'    => PushCrypto::vapidHeader(
                        $endpoint,
                        $this->subject,
                        $this->privateKey,
                        $this->publicKey
                    ),
                    'Content-Type'     => 'application/octet-stream',
                    'Content-Encoding' => 'aes128gcm',
                    'TTL'              => (string) self::TTL_SECONDS,
                    /*
                     * "normal" rather than "high". Urgency governs whether a
                     * push service will wake a sleeping phone, and a new sermon
                     * appearing does not warrant that — a site that used high
                     * for everything would be the site people turn off.
                     */
                    'Urgency'          => 'normal',
                ],
                ['timeout' => self::TIMEOUT_SECONDS, 'follow' => false]
            );
        } catch (Throwable $e) {
            error_log('Push: could not send to a subscription: ' . $e->getMessage());

            return $this->subscriptions->recordFailure($id) ? 'dropped' : 'failed';
        }

        /*
         * 404 and 410 are definitive: the browser is uninstalled, or the
         * permission was withdrawn, and that endpoint will never work again.
         * Deleted outright rather than counted — retrying it is traffic spent
         * on somebody who is gone, and the row would otherwise sit there
         * failing forever.
         */
        if ($response->status === 404 || $response->status === 410) {
            $this->subscriptions->drop($id);

            return 'dropped';
        }

        if ($response->status >= 200 && $response->status < 300) {
            $this->subscriptions->recordSuccess($id);

            return 'sent';
        }

        error_log(sprintf(
            'Push: %s answered %d for a subscription.',
            (string) (parse_url($endpoint, PHP_URL_HOST) ?: 'the push service'),
            $response->status
        ));

        return $this->subscriptions->recordFailure($id) ? 'dropped' : 'failed';
    }
}
