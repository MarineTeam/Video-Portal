<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Support\Http;
use Throwable;

/**
 * Sending the queue.
 *
 * Runs from the cron tick, never from a page render. Everything about it is
 * shaped by being on a shared host: a small batch, a short timeout, and no
 * assumption that it will be allowed to finish — a process killed halfway
 * through leaves a pending row that the next tick picks up, which is why
 * nothing here holds state between deliveries.
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookRepository $webhooks,
        private readonly bool $allowPrivateAddresses = false,
    ) {
    }

    /**
     * Deliver everything that is due.
     *
     * @return array{sent: int, failed: int, disabled: int}
     */
    public function run(int $batch = 10): array
    {
        $sent = 0;
        $failed = 0;
        $disabled = 0;

        foreach ($this->webhooks->due($batch) as $delivery) {
            $result = $this->deliver($delivery);

            if ($result === 'sent') {
                $sent++;
                continue;
            }

            $failed++;

            if ($result === 'disabled') {
                $disabled++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'disabled' => $disabled];
    }

    /**
     * One delivery.
     *
     * @param  array<string, mixed> $delivery
     * @return string 'sent', 'failed', or 'disabled'
     */
    public function deliver(array $delivery): string
    {
        $id = (int) $delivery['id'];
        $webhookId = (int) $delivery['webhook_id'];
        $attempts = (int) $delivery['attempts'];
        $url = (string) $delivery['url'];
        $body = (string) $delivery['payload'];

        /*
         * The address is re-checked immediately before the request, not only
         * when the endpoint was saved.
         *
         * A hostname that resolved to a public address when an admin typed it
         * can resolve to 127.0.0.1 by the time we call it, and arranging that
         * needs control of a DNS record rather than an account on this site.
         * Checking once, at save time, is checking the wrong moment.
         */
        if (!$this->allowPrivateAddresses) {
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
            $reason = WebhookPolicy::reasonHostIsUnreachable($host);

            if ($reason !== null) {
                // Not retryable: waiting will not make an internal address a
                // public one, and retrying is itself the probe we are refusing.
                return $this->webhooks->markFailed(
                    $id,
                    $webhookId,
                    $attempts + 1,
                    0,
                    $reason,
                    retryable: false
                ) ? 'disabled' : 'failed';
            }
        }

        $timestamp = time();

        try {
            $response = Http::request(
                'POST',
                $url,
                $body,
                [
                    'Content-Type'        => 'application/json',
                    'User-Agent'          => 'VideoPortal-Webhook/1.0',
                    'X-Portal-Event'      => (string) $delivery['event'],
                    'X-Portal-Delivery'   => (string) $id,
                    'X-Portal-Signature'  => WebhookPolicy::signature(
                        (string) $delivery['secret'],
                        $body,
                        $timestamp
                    ),
                ],
                [
                    'timeout' => WebhookPolicy::TIMEOUT_SECONDS,
                    /*
                     * Redirects stay off. Following one would send a signed
                     * payload to an address that never passed the checks above
                     * — reopening exactly the hole they exist to close, this
                     * time at the receiver's discretion rather than an admin's.
                     */
                    'follow'  => false,
                ]
            );
        } catch (Throwable $e) {
            return $this->webhooks->markFailed(
                $id,
                $webhookId,
                $attempts + 1,
                0,
                $e->getMessage(),
                retryable: true
            ) ? 'disabled' : 'failed';
        }

        if (WebhookPolicy::isSuccess($response->status)) {
            $this->webhooks->markDelivered($id, $webhookId, $response->status);

            return 'sent';
        }

        return $this->webhooks->markFailed(
            $id,
            $webhookId,
            $attempts + 1,
            $response->status,
            $response->status === 0
                ? ($response->transportError ?? 'Could not reach the endpoint.')
                : 'The endpoint answered ' . $response->status . '.',
            WebhookPolicy::isRetryable($response->status)
        ) ? 'disabled' : 'failed';
    }
}
