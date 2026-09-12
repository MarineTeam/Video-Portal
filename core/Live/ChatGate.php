<?php

declare(strict_types=1);

namespace Portal\Live;

use Portal\Auth\User;

/**
 * The one function that decides whether somebody may say something.
 *
 * Five rules, and they are checked IN THIS ORDER for reasons that are about
 * what the person is told rather than about correctness — every order refuses
 * the same messages, but only one of them sends people to the right place:
 *
 *   1. Is there a room at all (is the stream one they can even see).
 *   2. Is it open. Before the mute, so a muted person outside the window is
 *      told the room is shut rather than being handed a moderator's decision
 *      they would then argue with at the wrong time.
 *   3. Are they muted.
 *   4. Is there anything to send.
 *   5. Slow mode, LAST of the refusals, because it is the only one that will
 *      stop being true if they wait — telling somebody to wait five seconds
 *      when they are muted is a lie that wastes their evening.
 *
 * In the controller this would be five `if` blocks that the moderation
 * endpoint, the poll endpoint and the next feature would each reimplement
 * slightly differently. Here it is one answer with one shape.
 */
final class ChatGate
{
    /**
     * @param array<string, mixed> $stream a {live_streams} row
     * @return array{allowed: bool, reason: string, wait: int}
     */
    public static function decide(
        array $stream,
        ?User $user,
        string $body,
        ChatRepository $chat,
        int $slowSeconds = SlowMode::DEFAULT_SECONDS,
        ?int $now = null
    ): array {
        if ($user === null) {
            return self::no('Sign in to join the chat.');
        }

        $streamId = (int) ($stream['id'] ?? 0);

        if ($streamId <= 0) {
            return self::no('There is no chat here.');
        }

        $window = ChatWindow::state($stream, $now);

        if ($window !== ChatWindow::OPEN) {
            return self::no(ChatWindow::explain($window));
        }

        if ($chat->isMuted($streamId, $user->id)) {
            /*
             * What a mute says, and what it does not.
             *
             * It does not name the moderator, and it does not quote the reason
             * they typed — the reason is for the other moderators, who need to
             * know why somebody is on the list, and handing it back verbatim
             * turns a note into an argument in the middle of a service.
             *
             * It DOES say plainly that it is only this stream, because the
             * alternative reading — that they have been banned from the site —
             * is much worse than the truth and is what somebody will assume.
             */
            return self::no('A moderator has stopped you posting in this stream.');
        }

        /*
         * isEmpty(), not clean() === ''. A body of nothing but zero-width
         * spaces survives the cleaner — it is a format character rather than
         * whitespace — and posts as a blank line, which repeated is the
         * scrolling attack the cleaner exists to stop.
         */
        if (ChatRepository::isEmpty($body)) {
            return self::no('There is nothing to send.');
        }

        $wait = SlowMode::waitFor(
            $chat->lastPostedAt($streamId, $user->id),
            $slowSeconds,
            $now
        );

        if ($wait > 0) {
            return ['allowed' => false, 'reason' => SlowMode::explain($wait), 'wait' => $wait];
        }

        /*
         * And last, the resend of something already hidden.
         *
         * After slow mode rather than before, because this is the only check
         * that costs a query somebody can trigger repeatedly, and slow mode is
         * what stops them triggering it repeatedly.
         *
         * The refusal deliberately does NOT say "a moderator hid this". It
         * would be an invitation to work out what the filter compares and edit
         * around it, and the person sending it already knows what happened to
         * their message.
         */
        if ($chat->wasHidden($streamId, $user->id, $body)) {
            return self::no('That message was not posted.');
        }

        return ['allowed' => true, 'reason' => '', 'wait' => 0];
    }

    /** @return array{allowed: bool, reason: string, wait: int} */
    private static function no(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason, 'wait' => 0];
    }
}
