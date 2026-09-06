<?php

declare(strict_types=1);

namespace Portal\Broadcast;

use Portal\Config;
use Portal\Content\NotificationLog;
use Portal\Db;
use Portal\Mail\MailProvider;
use Portal\Sms\SmsProvider;
use Throwable;

/**
 * Working through the recipient rows.
 *
 * # BOUNDED BY COUNT AND BY THE CLOCK, BOTH
 *
 * The count alone is not enough. Fifty emails through a fast provider is a
 * second; fifty texts through a slow gateway on a bad afternoon is well past
 * what a shared host will allow a request to take — and pseudo-cron runs INSIDE
 * somebody's page view, so the cost of getting this wrong is a visitor staring
 * at a blank page while the church's mail goes out.
 *
 * So a run stops at whichever comes first, and the next run picks up exactly
 * where this one stopped, because the state lives in rows rather than in the
 * process.
 *
 * # ONE BAD ADDRESS DOES NOT STOP THE REST
 *
 * Every send is wrapped. A row that fails is marked failed with the provider's
 * own words and the loop carries on. Without that, one dead mailbox ends the
 * broadcast at whoever happens to be alphabetically unlucky, and the people
 * after them are never told anything — silently, because the run looks like it
 * finished.
 */
final class Sender
{
    /** How many messages one run will attempt. */
    public const PER_RUN = 40;

    /**
     * And how long it may spend, whatever the count says.
     *
     * Twenty seconds against a shared host's thirty. The margin is for the
     * request that is already in flight when the clock runs out — the check is
     * between messages, so a run can always overshoot by one send.
     */
    public const SECONDS_PER_RUN = 20;

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly BroadcastRepository $broadcasts,
        private readonly MailProvider $mail,
        private readonly ?SmsProvider $sms = null,
        private readonly ?PushChannel $push = null,
    ) {
    }

    /**
     * Send what is due, for one broadcast.
     *
     * @param array<string, mixed> $broadcast
     * @return array{sent: int, failed: int, remaining: int, ranOut: bool}
     */
    public function run(array $broadcast, ?int $startedAt = null): array
    {
        $id = (int) $broadcast['id'];
        $startedAt ??= time();

        $sent = 0;
        $failed = 0;
        $ranOut = false;

        foreach ($this->broadcasts->nextBatch($id, self::PER_RUN) as $recipient) {
            if (time() - $startedAt >= self::SECONDS_PER_RUN) {
                $ranOut = true;
                break;
            }

            /*
             * Claimed BEFORE the attempt. A process killed in between loses
             * one message; claiming afterwards would send a second copy to
             * everybody every time a run was interrupted, and interruption is
             * the normal case here.
             */
            if (!$this->broadcasts->claim((int) $recipient['id'])) {
                continue;
            }

            $result = $this->deliver($broadcast, $recipient);

            if ($result === null) {
                $this->broadcasts->markSent((int) $recipient['id']);
                $this->record($broadcast, $recipient);
                $sent++;

                continue;
            }

            $this->broadcasts->markFailed((int) $recipient['id'], $result);
            $failed++;
        }

        $remaining = $this->broadcasts->pendingCount($id);

        if ($remaining === 0) {
            $this->broadcasts->finish($id);
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining, 'ranOut' => $ranOut];
    }

    /**
     * One message. Returns null on success, or why it failed.
     *
     * Everything is caught. A provider that throws — and one eventually will,
     * whatever its interface promises — must fail one row rather than the run.
     *
     * @param array<string, mixed> $broadcast
     * @param array<string, mixed> $recipient
     */
    private function deliver(array $broadcast, array $recipient): ?string
    {
        try {
            return match ((string) $recipient['channel']) {
                Consent::EMAIL => $this->byEmail($broadcast, $recipient),
                Consent::SMS   => $this->bySms($broadcast, $recipient),
                Consent::PUSH  => $this->byPush($broadcast, $recipient),
                default        => 'That is not a channel this can send on.',
            };
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $broadcast
     * @param array<string, mixed> $recipient
     */
    private function byEmail(array $broadcast, array $recipient): ?string
    {
        $result = $this->mail->send(
            (string) $recipient['address'],
            (string) $broadcast['subject'],
            $this->html($broadcast),
            (string) $broadcast['body']
        );

        return $result->sent ? null : ($result->error ?? 'The email provider refused it.');
    }

    /**
     * @param array<string, mixed> $broadcast
     * @param array<string, mixed> $recipient
     */
    private function bySms(array $broadcast, array $recipient): ?string
    {
        if ($this->sms === null || !$this->sms->isConfigured()) {
            // Named rather than "failed": an admin who ticked the text box on a
            // site with no gateway needs to be told that, not shown two hundred
            // mysterious failures.
            return 'No text provider is set up on this site.';
        }

        $result = $this->sms->send((string) $recipient['address'], $this->text($broadcast));

        return $result->sent ? null : ($result->error ?? 'The text provider refused it.');
    }

    /**
     * @param array<string, mixed> $broadcast
     * @param array<string, mixed> $recipient
     */
    private function byPush(array $broadcast, array $recipient): ?string
    {
        if ($this->push === null) {
            return 'Push notifications are not switched on for this site.';
        }

        return $this->push->send(
            (string) $recipient['address'],
            (string) $broadcast['subject'],
            $this->text($broadcast)
        );
    }

    /**
     * A record of what somebody was told.
     *
     * Best effort, and deliberately so: this is a receipt for a message that
     * has already gone, and letting it fail would turn a delivered broadcast
     * into a failed row.
     *
     * @param array<string, mixed> $broadcast
     * @param array<string, mixed> $recipient
     */
    private function record(array $broadcast, array $recipient): void
    {
        if ((string) $recipient['channel'] === Consent::SMS) {
            /*
             * A text is not recorded in the in-app inbox. The inbox is keyed by
             * EMAIL ADDRESS, and an SMS row carries a phone number — writing
             * one would either invent an address or file the record under a
             * string that is not one. The recipient row is the record for that
             * channel.
             */
            return;
        }

        (new NotificationLog($this->db))->record(
            (string) ($recipient['channel'] === Consent::EMAIL
                ? $recipient['address']
                : ($this->emailFor((int) ($recipient['user_id'] ?? 0)) ?? '')),
            $recipient['channel'] === Consent::EMAIL ? NotificationLog::EMAIL : NotificationLog::PUSH,
            (string) $broadcast['subject']
        );
    }

    private function emailFor(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $email = $this->db->value('SELECT email FROM {users} WHERE id = ?', [$userId]);

        return $email === null ? null : (string) $email;
    }

    /** @param array<string, mixed> $broadcast */
    private function html(array $broadcast): string
    {
        $base = rtrim((string) $this->config->get('base_url', ''), '/');

        return sprintf(
            '<h2>%s</h2><div>%s</div>'
            . '<p style="color:#666;font-size:13px">You are getting this because you are on this '
            . 'church\'s list. <a href="%s/account/messages">Change what you hear about</a>.</p>',
            e((string) $broadcast['subject']),
            nl2br(e((string) $broadcast['body'])),
            e($base)
        );
    }

    /**
     * The plain version, which is what a text and a push notification get.
     *
     * Trimmed hard for SMS: a gateway charges per 160 characters and silently
     * splits past that, so a long broadcast becomes three texts and three
     * charges without anybody having chosen it.
     *
     * @param array<string, mixed> $broadcast
     */
    private function text(array $broadcast): string
    {
        $body = trim(strip_tags((string) $broadcast['body']));

        return mb_strlen($body) > 300 ? mb_substr($body, 0, 297) . '...' : $body;
    }
}
