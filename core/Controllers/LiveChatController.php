<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\LiveStreamRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Live\ChatGate;
use Portal\Live\ChatRepository;
use Portal\Live\ChatWindow;
use Portal\Live\SlowMode;
use Portal\Support\Audit;
use Portal\Support\RateLimit;

/**
 * Posting, polling and moderating a live stream's chat.
 *
 * # IT POLLS, AND THE POLL IS THE EXPENSIVE ENDPOINT
 *
 * A hundred people in a room asking every four seconds is 1,500 requests a
 * minute at a shared host, so poll() does as little as a request can: one
 * indexed range scan, no session write, no template, and a 304-shaped empty
 * answer when there is nothing new. It is also the one endpoint here with no
 * CSRF token, which is the ordinary rule for a GET that reads.
 *
 * # THE DECISION IS NOT IN THIS FILE
 *
 * Whether somebody may post is ChatGate::decide(), which is one function with
 * one answer. Spread across this controller it would be five conditions that
 * the next endpoint reimplements slightly differently — and the version that
 * drifts is always the one that forgets the mute.
 *
 * # A MODERATOR IS NOT AN ADMINISTRATOR
 *
 * moderate_chat, not moderate_comments and not manage_videos. The person
 * watching a room on a Sunday evening is a volunteer, and the alternative is
 * handing them the video library to get them a hide button.
 */
final class LiveChatController extends Controller
{
    /** Posts per person per minute, on top of slow mode. */
    private const POSTS_PER_MINUTE = 20;

    /**
     * Polls per person per minute.
     *
     * Generous, because the legitimate client polls about fifteen times and a
     * page left open in two tabs doubles that honestly. This is here to stop a
     * script, not to shape ordinary use.
     */
    private const POLLS_PER_MINUTE = 120;

    /**
     * What is new in the room.
     *
     * @param array<string, string> $params
     */
    public function poll(Request $request, array $params): Response
    {
        $stream = $this->stream($params);
        $chat = $this->chat();

        $user = $this->user();

        if ($user === null) {
            /*
             * Reading needs an account even though it is only reading.
             *
             * The room is people talking to each other during a service, not
             * published content, and an unauthenticated reader is a transcript
             * anybody can scrape. The stream itself stays watchable — this is
             * the chat, which is a different thing.
             */
            throw HttpException::forbidden('Sign in to read the chat.');
        }

        $limiter = new RateLimit($this->db());

        if (!$limiter->allow('chat-poll:' . $user->id, self::POLLS_PER_MINUTE, 60)) {
            throw HttpException::tooManyRequests('Slow down.');
        }

        $after = (int) ($request->query('after') ?? 0);

        /*
         * after=0 is "I have just arrived", which is the only request that gets
         * history. Every later poll passes the last id it saw, so the tail is
         * fetched once per person per visit rather than every four seconds.
         */
        $messages = $after > 0
            ? $chat->since((int) $stream['id'], $after)
            : $chat->recent((int) $stream['id']);

        $window = ChatWindow::state($stream);

        return Response::json([
            'messages' => array_map(
                static fn (array $row): array => [
                    'id'     => (int) $row['id'],
                    'author' => (string) $row['author'],
                    'body'   => (string) $row['body'],
                    'at'     => (string) $row['created_at'],
                    // So a moderator's client can draw a hide button on other
                    // people's messages and not on their own.
                    'mine'   => (int) $row['user_id'] === $user->id,
                ],
                $messages
            ),
            /*
             * The cursor comes back even when nothing did, and it is the LAST
             * id seen rather than a count. A client that computed it from the
             * array would get 0 on an empty poll and ask for the whole room
             * again on the next one.
             */
            'cursor' => $messages === []
                ? $after
                : (int) $messages[array_key_last($messages)]['id'],

            /*
             * The window state on every poll, so a room closes on its own
             * without somebody reloading. This is the only way a page that has
             * been open since before the stream finds out it may now post —
             * and the only way one open an hour after finds out it may not.
             */
            'window' => $window,
            'closed' => ChatWindow::explain($window),
        ])->private();
    }

    /**
     * Say something.
     *
     * @param array<string, string> $params
     */
    public function post(Request $request, array $params): Response
    {
        $this->verifyCsrf($request);

        $stream = $this->stream($params);
        $user = $this->user();
        $chat = $this->chat();
        $body = (string) ($request->input('body') ?? '');

        $limiter = new RateLimit($this->db());

        if ($user !== null
            && !$limiter->allow('chat-post:' . $user->id, self::POSTS_PER_MINUTE, 60)
        ) {
            /*
             * A second limit above slow mode, and it is not redundant.
             *
             * Slow mode is five seconds, so twelve messages a minute is within
             * it — and twelve messages a minute sustained for an hour is one
             * person filling a room legitimately, one message at a time. This
             * is the ceiling on that; the moderator's mute is the answer to
             * somebody who reaches it.
             */
            return $this->answer(
                false,
                'You have sent a lot of messages. Give it a minute.',
                0,
                [],
                $request
            );
        }

        $verdict = ChatGate::decide($stream, $user, $body, $chat, $this->slowSeconds());

        if ($verdict['allowed'] !== true || $user === null) {
            return $this->answer(false, $verdict['reason'], $verdict['wait'], [], $request);
        }

        $id = $chat->post(
            (int) $stream['id'],
            $user->id,
            // The display name as it is right now, copied into the row. See the
            // migration for why it is not a join.
            $user->name ?? $user->email,
            $body
        );

        return $this->answer(true, 'Sent.', 0, ['id' => $id], $request);
    }

    /**
     * Hide a message, put one back, or stop somebody posting.
     *
     * @param array<string, string> $params
     */
    public function moderate(Request $request, array $params): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MODERATE_CHAT);

        $stream = $this->stream($params);
        $chat = $this->chat();
        $by = (string) ($this->user()?->email ?? '');
        $action = (string) ($request->input('action') ?? '');
        $messageId = (int) ($request->input('message') ?? 0);

        /*
         * A message is looked up and checked to belong to THIS stream before
         * anything happens to it. Ids are sequential and global, so an action
         * taking only an id would let a moderator of one stream — which is
         * every holder of the capability — hide a message in another by
         * counting. It is the same ownership-in-the-WHERE-clause rule the
         * notification record uses, with a stream in place of an address.
         */
        $message = $messageId > 0
            ? $this->db()->first(
                'SELECT id, user_id, author FROM {live_chat_messages} WHERE id = ? AND stream_id = ?',
                [$messageId, (int) $stream['id']]
            )
            : null;

        return match ($action) {
            'hide' => $this->hide($request, $stream, $message, $chat, $by),
            'show' => $this->show($request, $stream, $message, $chat, $by),
            'mute' => $this->mute($request, $stream, $message, $chat, $by),
            'unmute' => $this->unmute($request, $stream, $chat, $by),
            default => $this->answer(false, 'That is not something this screen can do.', 0, [], $request),
        };
    }

    /**
     * @param array<string, mixed>      $stream
     * @param array<string, mixed>|null $message
     */
    private function hide(
        Request $request,
        array $stream,
        ?array $message,
        ChatRepository $chat,
        string $by
    ): Response {
        if ($message === null) {
            return $this->answer(
                false,
                'There is no message with that id in this stream.',
                0,
                [],
                $request
            );
        }

        $did = $chat->hide((int) $message['id'], $by);

        if ($did) {
            Audit::log(
                $this->db(),
                $by,
                'chat.hide',
                'live_stream',
                (string) $stream['id'],
                sprintf('message %d by %s', (int) $message['id'], (string) $message['author'])
            );
        }

        /*
         * Success either way. It was already hidden is the outcome the
         * moderator wanted, and reporting it as a failure in a live room sends
         * somebody looking for a fault while the service is still on.
         */
        return $this->answer(true, $did ? 'Hidden.' : 'Already hidden.', 0, [], $request);
    }

    /**
     * @param array<string, mixed>      $stream
     * @param array<string, mixed>|null $message
     */
    private function show(
        Request $request,
        array $stream,
        ?array $message,
        ChatRepository $chat,
        string $by
    ): Response {
        if ($message === null) {
            return $this->answer(
                false,
                'There is no message with that id in this stream.',
                0,
                [],
                $request
            );
        }

        if ($chat->unhide((int) $message['id'])) {
            Audit::log(
                $this->db(),
                $by,
                'chat.show',
                'live_stream',
                (string) $stream['id'],
                sprintf('message %d', (int) $message['id'])
            );
        }

        return $this->answer(true, 'Back in the room.', 0, [], $request);
    }

    /**
     * @param array<string, mixed>      $stream
     * @param array<string, mixed>|null $message
     */
    private function mute(
        Request $request,
        array $stream,
        ?array $message,
        ChatRepository $chat,
        string $by
    ): Response {
        /*
         * Muted BY MESSAGE, not by typing an account id.
         *
         * The moderator is looking at something somebody said, and the person
         * is identified by the thing that prompted the decision. An id box
         * would mean reading a number off a screen mid-service and muting
         * whoever it belonged to, which is the shape of mistake nobody
         * notices until the wrong person complains.
         */
        if ($message === null) {
            return $this->answer(false, 'Choose a message by the person to mute.', 0, [], $request);
        }

        $userId = (int) $message['user_id'];

        $chat->mute((int) $stream['id'], $userId, $by, (string) ($request->input('reason') ?? ''));

        /*
         * And the message that prompted it goes too, in the same request.
         *
         * Muting somebody stops the NEXT message and does nothing about the one
         * on the screen, so a moderator who only muted would then have to hide
         * it separately — and in a busy room that second step is the one that
         * gets lost.
         */
        $chat->hide((int) $message['id'], $by);

        Audit::log(
            $this->db(),
            $by,
            'chat.mute',
            'live_stream',
            (string) $stream['id'],
            sprintf('user %d (%s)', $userId, (string) $message['author'])
        );

        return $this->answer(
            true,
            sprintf(
                '%s cannot post in this stream now. It does not affect any other stream.',
                (string) $message['author']
            ),
            0,
            [],
            $request
        );
    }

    /** @param array<string, mixed> $stream */
    private function unmute(
        Request $request,
        array $stream,
        ChatRepository $chat,
        string $by
    ): Response {
        $userId = (int) ($request->input('user') ?? 0);

        if ($userId <= 0) {
            return $this->answer(false, 'Nobody was chosen.', 0, [], $request);
        }

        $chat->unmute((int) $stream['id'], $userId);

        Audit::log(
            $this->db(),
            $by,
            'chat.unmute',
            'live_stream',
            (string) $stream['id'],
            sprintf('user %d', $userId)
        );

        return $this->answer(true, 'They can post again.', 0, [], $request);
    }

    // ---------------------------------------------------------------- wiring

    /**
     * The stream this request is about, or a 404.
     *
     * The SAME visibility rule as the page: unpublished is absent, and
     * members-only is a 404 rather than a 403 for somebody who cannot watch,
     * because telling them a members-only stream exists is itself the leak. Any
     * looser rule here would make the chat a way to read the title of a stream
     * the library refuses to name.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function stream(array $params): array
    {
        $slug = (string) ($params['slug'] ?? '');

        $stream = $slug === ''
            ? null
            : $this->container->get(LiveStreamRepository::class)->findBySlug($slug);

        if ($stream === null || (int) $stream['is_published'] !== 1) {
            throw HttpException::notFound('There is no stream at that address.');
        }

        if ((int) $stream['member_only'] === 1 && !$this->canWatch()) {
            throw HttpException::notFound('There is no stream at that address.');
        }

        return $stream;
    }

    private function chat(): ChatRepository
    {
        return new ChatRepository($this->db());
    }

    private function slowSeconds(): int
    {
        return SlowMode::clamp(
            (int) $this->config()->setting('chat_slow_seconds', (string) SlowMode::DEFAULT_SECONDS)
        );
    }

    /**
     * One answer shape for every outcome, refusals included.
     *
     * Status 200 on a refusal rather than 4xx, deliberately. The client has to
     * render the reason next to the box either way, and a fetch that throws on
     * status turns "wait five seconds" into "something went wrong" — which is
     * the message people report as a broken chat.
     *
     * # AND A PLAIN FORM GETS A PAGE, NOT JSON
     *
     * `_plain` is in the markup and is NOT sent by the script, which builds its
     * own body from two fields. So its presence means the browser submitted the
     * form itself — the script is blocked, or failed to load — and the answer
     * has to be a redirect with a flash rather than a screenful of JSON.
     *
     * The same declare-yourself trick as `_whole_form` on the video editor, and
     * it is the difference between "progressive enhancement" being a claim in a
     * comment and being true. Without it the chat is script-only, which for
     * MODERATION in particular is the wrong dependency: hiding something is the
     * action you need most on the evening the page is misbehaving.
     *
     * @param array<string, mixed> $extra
     */
    private function answer(
        bool $ok,
        string $message,
        int $wait = 0,
        array $extra = [],
        ?Request $request = null
    ): Response {
        if ($request !== null && $request->input('_plain') !== null) {
            return $this->back(
                $request,
                $message !== '' ? $message : ($ok ? 'Sent.' : 'That did not send.'),
                $ok ? 'success' : 'error'
            );
        }

        return Response::json([
            'ok'      => $ok,
            'message' => $message,
            'wait'    => $wait,
        ] + $extra)->private();
    }
}
