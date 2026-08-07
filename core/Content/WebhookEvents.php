<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Container;
use Portal\Sharing\Share;
use Throwable;

/**
 * The bridge from things that happen to things that get queued.
 *
 * One class rather than an enqueue call at each site, because the failure mode
 * being avoided is specific: a webhook must never be able to break the thing it
 * is reporting on. Every listener here is wrapped, and every one of them fails
 * by logging. Somebody publishing a video should not see an error because an
 * integration nobody remembers setting up has a full disk.
 *
 * Registered even when no endpoint exists. enqueue() opens with a query for
 * active endpoints and returns immediately when there are none, which is one
 * indexed read on the events that actually fire — cheaper than the branching
 * that avoiding it would need, and it means adding an endpoint takes effect
 * without anything being reloaded.
 */
final class WebhookEvents
{
    public static function register(): void
    {
        /*
         * video.published is deliberately absent from this list.
         *
         * There is nothing to hook: a scheduled video becomes visible when a
         * comparison in a query starts returning true, and no code runs at that
         * moment. It is detected in reverse by the cron job — "what is visible
         * now that has never been reported" — with fire-once coming from a
         * PRIMARY KEY rather than from anything running exactly once.
         */

        add_action('video_updated', static function (int $id, string $title): void {
            self::send('video.updated', ['id' => $id, 'title' => $title]);
        });

        add_action('video_deleted', static function (int $id, string $title): void {
            self::send('video.deleted', ['id' => $id, 'title' => $title]);
        });

        add_action('share_created', static function (Share $share): void {
            self::send('share.created', self::describeShare($share));
        });

        add_action('share_revoked', static function (Share $share): void {
            self::send('share.revoked', self::describeShare($share));
        });

        add_action('share_viewed', static function (string $shareId): void {
            // The id only. The recipient's address is on the share and is not
            // sent here: "somebody opened link X" is the event, and whoever
            // receives it already knows who X was issued to if they were told
            // when it was created.
            self::send('share.viewed', ['id' => $shareId]);
        });

        add_action('comment_posted', static function (
            int $id,
            int $videoId,
            string $status,
            string $authorName
        ): void {
            self::send('comment.posted', [
                'id'      => $id,
                'videoId' => $videoId,
                // Included so a listener can tell a published comment from one
                // waiting in the queue, which are different things to act on.
                'status'  => $status,
                'author'  => $authorName,
            ]);
        });

        add_action('user_authorized', static function (int $userId, ?string $by): void {
            self::send('user.authorized', ['userId' => $userId, 'authorizedBy' => $by ?? '']);
        });
    }

    /**
     * What a share looks like on the wire.
     *
     * The recipient's address is included here, and only here, because "a link
     * was issued to this person" is the whole content of the event — an
     * integration that logs shares without knowing to whom has been told
     * nothing. Every other event carries ids.
     *
     * @return array<string, mixed>
     */
    private static function describeShare(Share $share): array
    {
        return [
            'id'        => $share->id,
            'videoId'   => $share->videoId,
            'recipient' => $share->recipientEmail,
            'mode'      => $share->accessMode,
            // Formatted, not handed over as a DateTimeImmutable. json_encode
            // turns one into an object of its private fields, which is neither
            // a date nor stable across PHP versions.
            'expiresAt' => $share->expiresAt->format('c'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function send(string $event, array $data): void
    {
        try {
            Container::instance()
                ->get(WebhookRepository::class)
                ->enqueue($event, $data);
        } catch (Throwable $e) {
            // Including the case where the table does not exist yet, on the
            // single request that applies this migration.
            error_log("Portal: could not queue the '{$event}' webhook: " . $e->getMessage());
        }
    }
}
