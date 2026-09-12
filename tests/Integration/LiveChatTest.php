<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\User;
use Portal\Live\ChatGate;
use Portal\Live\ChatRepository;
use Portal\Live\ChatWindow;
use Portal\Live\SlowMode;

/**
 * Live chat, against real rows.
 *
 * Three of the four rules in this feature can only be seen from here:
 *
 *   SLOW MODE IS PER PERSON. Invisible from inside SlowMode, which is handed
 *   one person's timestamp and knows nothing about anybody else. A per-CHAT
 *   implementation would pass every test in SlowModeTest — it is the same
 *   arithmetic on a different row — and is only visible when two people post.
 *
 *   HIDING KEEPS THE MESSAGE. Needs a row to still be there afterwards.
 *
 *   A MUTE IS PER STREAM. Needs two streams.
 */
final class LiveChatTest extends DatabaseTestCase
{
    private ChatRepository $chat;
    private int $streamId;
    private int $otherStreamId;
    private int $aliceId;
    private int $bobId;

    protected function setUp(): void
    {
        $this->truncate(['live_chat_messages', 'live_chat_mutes', 'live_streams', 'users']);

        $this->chat = new ChatRepository($this->db());

        $now = date('Y-m-d H:i:s');

        $this->streamId = $this->db()->insert('live_streams', [
            'slug' => 'sunday', 'title' => 'Sunday morning',
            'embed_url' => 'https://example.test/embed',
            'starts_at' => $now, 'is_published' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->otherStreamId = $this->db()->insert('live_streams', [
            'slug' => 'evening', 'title' => 'Sunday evening',
            'embed_url' => 'https://example.test/embed2',
            'starts_at' => $now, 'is_published' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->aliceId = $this->person('alice@example.test', 'Alice');
        $this->bobId = $this->person('bob@example.test', 'Bob');
    }

    private function person(string $email, string $name): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('users', [
            'email' => $email, 'name' => $name, 'authorized' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function user(int $id, string $email, string $name): User
    {
        return new User($id, $email, $name, authorized: true);
    }

    /** @return array<string, mixed> */
    private function stream(int $id = 0): array
    {
        return (array) $this->db()->first(
            'SELECT * FROM {live_streams} WHERE id = ?',
            [$id > 0 ? $id : $this->streamId]
        );
    }

    // --------------------------------------------------- per person, not chat

    /**
     * THE RULE. One person's message does not slow anybody else down.
     *
     * The mutation this kills is `lastPostedAt` dropping `AND user_id = ?` —
     * which is the whole of a per-chat slow mode, is a one-word change, and
     * passes every assertion in SlowModeTest.
     *
     * What it would do to a real room: the fastest typist in a hundred people
     * holds the floor, and it gets worse the more people arrive, so the busier
     * the service the more often an ordinary person is refused for a reason
     * they cannot see.
     */
    public function testOnePersonPostingDoesNotSlowAnybodyElseDown(): void
    {
        $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Morning all');

        $verdict = ChatGate::decide(
            $this->stream(),
            $this->user($this->bobId, 'bob@example.test', 'Bob'),
            'Morning',
            $this->chat,
            SlowMode::DEFAULT_SECONDS
        );

        self::assertTrue(
            $verdict['allowed'],
            'SLOW MODE IS PER CHAT — one person typing refused everybody else'
        );
    }

    /** And it does slow the person who just posted. */
    public function testItDoesSlowThePersonWhoJustPosted(): void
    {
        $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Morning all');

        $verdict = ChatGate::decide(
            $this->stream(),
            $this->user($this->aliceId, 'alice@example.test', 'Alice'),
            'And another thing',
            $this->chat,
            SlowMode::DEFAULT_SECONDS
        );

        self::assertFalse($verdict['allowed'], 'slow mode did nothing at all');
        self::assertGreaterThan(0, $verdict['wait']);
    }

    /**
     * A hidden message still counts towards the wait.
     *
     * Otherwise every takedown hands its author a free post, which points the
     * incentive exactly the wrong way.
     */
    public function testAHiddenMessageStillCountsTowardsTheWait(): void
    {
        $id = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Something unkind');
        $this->chat->hide($id, 'moderator@example.test');

        self::assertNotNull(
            $this->chat->lastPostedAt($this->streamId, $this->aliceId),
            'A TAKEDOWN RESET THE CLOCK — the author gets a free message every time'
        );
    }

    // ------------------------------------------------ hiding keeps the message

    /** THE RULE. The row is still there, with who decided and when. */
    public function testHidingKeepsTheMessageAndRecordsWhoDecided(): void
    {
        $id = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Something unkind');

        self::assertTrue($this->chat->hide($id, 'moderator@example.test'));

        $row = $this->db()->first('SELECT * FROM {live_chat_messages} WHERE id = ?', [$id]);

        self::assertNotNull($row, 'THE MESSAGE WAS DELETED — the evidence went with it');
        self::assertSame('Something unkind', (string) $row['body']);
        self::assertNotNull($row['hidden_at']);
        self::assertSame('moderator@example.test', (string) $row['hidden_by']);
    }

    /** And it stops being served. */
    public function testAHiddenMessageIsNotServed(): void
    {
        $kept = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Morning');
        $gone = $this->chat->post($this->streamId, $this->bobId, 'Bob', 'Something unkind');

        $this->chat->hide($gone, 'moderator@example.test');

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->chat->since($this->streamId, 0)
        );

        // Both directions. Asserting only the absence would pass if the query
        // returned nothing at all.
        self::assertContains($kept, $ids);
        self::assertNotContains($gone, $ids, 'A HIDDEN MESSAGE WAS STILL BEING SERVED');
    }

    /**
     * THE REASON hiding keeps it: the same text cannot be sent again.
     *
     * The obvious move for somebody whose message vanished is to send it again,
     * and without the row there is nothing to compare against — so it would
     * work, and keep working, and the moderator would be the only person in the
     * room doing any work.
     */
    public function testTheSameTextCannotBeSentAgainPastAModerator(): void
    {
        $id = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Something unkind');
        $this->chat->hide($id, 'moderator@example.test');

        $verdict = ChatGate::decide(
            $this->stream(),
            $this->user($this->aliceId, 'alice@example.test', 'Alice'),
            // Whitespace and case differ, which is what somebody retyping
            // produces and what a naive comparison would miss.
            '  SOMETHING   unkind  ',
            $this->chat,
            0
        );

        self::assertFalse(
            $verdict['allowed'],
            'A HIDDEN MESSAGE COULD BE SENT STRAIGHT BACK — the takedown meant nothing'
        );
    }

    /**
     * But somebody ELSE saying the same short thing is not a resend.
     *
     * Two people independently typing "amen" is a coincidence, and refusing the
     * second is silencing somebody for what a stranger typed. This is the check
     * that stops the resend guard becoming a word filter.
     */
    public function testSomebodyElseSayingTheSameThingIsNotARefusal(): void
    {
        $id = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Amen');
        $this->chat->hide($id, 'moderator@example.test');

        $verdict = ChatGate::decide(
            $this->stream(),
            $this->user($this->bobId, 'bob@example.test', 'Bob'),
            'Amen',
            $this->chat,
            0
        );

        self::assertTrue(
            $verdict['allowed'],
            'ONE TAKEDOWN BANNED A WORD FOR EVERYBODY — the guard is about a person, not a phrase'
        );
    }

    /** Putting it back is offered, because a moderator hides the wrong one. */
    public function testAMessageCanBePutBack(): void
    {
        $id = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Morning');
        $this->chat->hide($id, 'moderator@example.test');

        self::assertTrue($this->chat->unhide($id));

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->chat->since($this->streamId, 0)
        );

        self::assertContains($id, $ids);

        // The name goes with it: a moderator's name on a visible message reads
        // as an accusation.
        self::assertNull(
            $this->db()->value('SELECT hidden_by FROM {live_chat_messages} WHERE id = ?', [$id])
        );
    }

    /**
     * Two moderators pressing the button at once: the first decision stands.
     *
     * The ordinary case in a busy room, and a plain UPDATE would let the second
     * one overwrite the first's name on the decision.
     */
    public function testASecondHideDoesNotRewriteTheFirstDecision(): void
    {
        $id = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Something unkind');

        self::assertTrue($this->chat->hide($id, 'first@example.test'));
        self::assertFalse(
            $this->chat->hide($id, 'second@example.test'),
            'the second hide reported itself as the one that did it'
        );

        self::assertSame(
            'first@example.test',
            (string) $this->db()->value(
                'SELECT hidden_by FROM {live_chat_messages} WHERE id = ?',
                [$id]
            ),
            'THE SECOND MODERATOR OVERWROTE THE FIRST ONE\'S DECISION'
        );
    }

    // ------------------------------------------------------- mute, per stream

    /** THE RULE. A mute stops them here and nowhere else. */
    public function testAMuteStopsThemInThisStreamOnly(): void
    {
        $this->chat->mute($this->streamId, $this->aliceId, 'moderator@example.test', 'shouting');

        $alice = $this->user($this->aliceId, 'alice@example.test', 'Alice');

        self::assertFalse(
            ChatGate::decide($this->stream(), $alice, 'Hello again', $this->chat, 0)['allowed'],
            'THE MUTE DID NOTHING'
        );

        self::assertTrue(
            ChatGate::decide(
                $this->stream($this->otherStreamId),
                $alice,
                'Hello again',
                $this->chat,
                0
            )['allowed'],
            'A MUTE ON ONE STREAM LOCKED SOMEBODY OUT OF EVERY STREAM — that is a ban, '
            . 'and it is a different decision made by somebody else'
        );
    }

    /** And it does not touch anybody else in the same stream. */
    public function testAMuteIsAboutOnePerson(): void
    {
        $this->chat->mute($this->streamId, $this->aliceId, 'moderator@example.test');

        self::assertTrue(
            ChatGate::decide(
                $this->stream(),
                $this->user($this->bobId, 'bob@example.test', 'Bob'),
                'Morning',
                $this->chat,
                0
            )['allowed'],
            'muting one person closed the room'
        );
    }

    /** Muting twice is not an error and does not leave a second row behind. */
    public function testMutingTwiceLeavesOneRow(): void
    {
        $this->chat->mute($this->streamId, $this->aliceId, 'first@example.test', 'shouting');
        $this->chat->mute($this->streamId, $this->aliceId, 'second@example.test', 'again');

        self::assertSame(
            1,
            (int) $this->db()->value(
                'SELECT COUNT(*) FROM {live_chat_mutes} WHERE stream_id = ? AND user_id = ?',
                [$this->streamId, $this->aliceId]
            ),
            'a second row a later unmute would leave behind'
        );

        // And the first decision is the one kept, for the same reason hide
        // keeps the first moderator's name.
        self::assertSame(
            'first@example.test',
            (string) $this->db()->value(
                'SELECT muted_by FROM {live_chat_mutes} WHERE stream_id = ? AND user_id = ?',
                [$this->streamId, $this->aliceId]
            )
        );
    }

    public function testUnmutingLetsThemPostAgain(): void
    {
        $this->chat->mute($this->streamId, $this->aliceId, 'moderator@example.test');
        $this->chat->unmute($this->streamId, $this->aliceId);

        self::assertFalse($this->chat->isMuted($this->streamId, $this->aliceId));

        self::assertTrue(
            ChatGate::decide(
                $this->stream(),
                $this->user($this->aliceId, 'alice@example.test', 'Alice'),
                'Sorry',
                $this->chat,
                0
            )['allowed']
        );
    }

    /**
     * Unmuting one stream does not unmute another.
     *
     * The mirror of the mute rule, and the direction a DELETE missing its
     * stream_id would break — which would look like the feature working.
     */
    public function testUnmutingOneStreamLeavesTheOtherMute(): void
    {
        $this->chat->mute($this->streamId, $this->aliceId, 'moderator@example.test');
        $this->chat->mute($this->otherStreamId, $this->aliceId, 'moderator@example.test');

        $this->chat->unmute($this->streamId, $this->aliceId);

        self::assertTrue(
            $this->chat->isMuted($this->otherStreamId, $this->aliceId),
            'UNMUTING ONE STREAM CLEARED EVERY MUTE THIS PERSON HAD'
        );
    }

    // ------------------------------------------------------- the gate's order

    /**
     * Outside the window nobody posts, muted or not.
     *
     * And the reason the window is checked BEFORE the mute: a muted person
     * arriving at a closed room should be told the room is shut, not handed a
     * moderator's decision to argue with at the wrong moment.
     */
    public function testNobodyPostsOutsideTheWindow(): void
    {
        $tomorrow = date('Y-m-d H:i:s', time() + 86400);

        $this->db()->update(
            'live_streams',
            ['starts_at' => $tomorrow, 'ends_at' => null],
            ['id' => $this->streamId]
        );

        $verdict = ChatGate::decide(
            $this->stream(),
            $this->user($this->aliceId, 'alice@example.test', 'Alice'),
            'Early',
            $this->chat,
            0
        );

        self::assertFalse($verdict['allowed'], 'A CLOSED ROOM TOOK A MESSAGE');
        self::assertStringContainsString(
            (string) ChatWindow::OPENS_MINUTES_BEFORE,
            $verdict['reason'],
            'the refusal did not tell them when to come back'
        );
    }

    /** Signed out, nothing is posted and the refusal says what to do. */
    public function testSignedOutIsRefusedWithSomethingActionable(): void
    {
        $verdict = ChatGate::decide($this->stream(), null, 'Hello', $this->chat, 0);

        self::assertFalse($verdict['allowed']);
        self::assertStringContainsString('Sign in', $verdict['reason']);
    }

    /**
     * An empty message is not a message, and "empty" includes the invisible.
     *
     * The last three are the ones that matter. A non-breaking space is not
     * matched by `\s` in PHP (which does not enable PCRE_UCP), and a zero-width
     * space is a FORMAT character rather than whitespace at all — so each posts
     * as a blank line under a naive check, and repeated that is the room
     * scrolled clean.
     */
    public function testThereIsNothingToSend(): void
    {
        foreach (['', '   ', "\n\n", "\u{200B}", "\u{00A0}", "\u{200B}\u{200B}\u{200B}"] as $nothing) {
            $verdict = ChatGate::decide(
                $this->stream(),
                $this->user($this->aliceId, 'alice@example.test', 'Alice'),
                $nothing,
                $this->chat,
                0
            );

            self::assertFalse($verdict['allowed'], json_encode($nothing));
        }

        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {live_chat_messages}'),
            'an empty message was stored'
        );
    }

    /**
     * But an emoji is not empty, and is not taken apart.
     *
     * The reason the format characters are stripped for the EMPTINESS TEST
     * ONLY rather than by the cleaner. A family emoji is several code points
     * joined by U+200D; a cleaner that removed those would turn one emoji into
     * four separate people, and somebody sending it is not attacking the room.
     */
    public function testAnEmojiIsNotAnEmptyMessageAndSurvivesIntact(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

        self::assertFalse(ChatRepository::isEmpty($family));

        $id = $this->chat->post($this->streamId, $this->aliceId, 'Alice', $family);

        self::assertSame(
            $family,
            (string) $this->db()->value(
                'SELECT body FROM {live_chat_messages} WHERE id = ?',
                [$id]
            ),
            'THE CLEANER TOOK AN EMOJI APART — one family became four people'
        );
    }

    // ------------------------------------------------------------- the cursor

    /**
     * The cursor is an id, and it cannot tie.
     *
     * Two messages in the same second is the ordinary case in a live room, and
     * a timestamp cursor would either repeat one or lose one — the bug the
     * calendar device sync had to be wound two seconds back to survive. Written
     * as a test because "use an id" is the kind of decision a later refactor
     * undoes for something that looks tidier.
     */
    public function testTwoMessagesInTheSameSecondAreBothDeliveredExactlyOnce(): void
    {
        $stamp = date('Y-m-d H:i:s');

        $ids = [];
        foreach (['first', 'second', 'third'] as $index => $word) {
            $ids[] = $this->db()->insert('live_chat_messages', [
                'stream_id' => $this->streamId,
                'user_id'   => $index % 2 === 0 ? $this->aliceId : $this->bobId,
                'author'    => 'Someone',
                'body'      => $word,
                'created_at' => $stamp,
            ]);
        }

        // A client that has seen the first asks for what follows and gets both
        // of the others, once each.
        $after = $this->chat->since($this->streamId, $ids[0]);

        self::assertSame(
            [$ids[1], $ids[2]],
            array_map(static fn (array $row): int => (int) $row['id'], $after),
            'A CURSOR REPEATED OR LOST A MESSAGE sent in the same second as another'
        );
    }

    /** Somebody arriving gets the tail, oldest first. */
    public function testSomebodyArrivingGetsTheTailInReadingOrder(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'message ' . $n);
        }

        $recent = $this->chat->recent($this->streamId, 3);

        self::assertSame(
            array_slice($ids, -3),
            array_map(static fn (array $row): int => (int) $row['id'], $recent),
            'the newcomer got the OLDEST messages, or got them backwards'
        );
    }

    // ------------------------------------------------------------ the cleanup

    /**
     * A hundred newlines is shouting, and it is collapsed.
     *
     * The cheapest way to scroll everybody else's messages off the screen in a
     * room with no formatting, and maxlength does not stop it.
     */
    public function testWhitespaceIsCollapsedSoNobodyCanScrollTheRoom(): void
    {
        $id = $this->chat->post(
            $this->streamId,
            $this->aliceId,
            'Alice',
            "hello" . str_repeat("\n", 200) . "there"
        );

        self::assertSame(
            'hello there',
            (string) $this->db()->value(
                'SELECT body FROM {live_chat_messages} WHERE id = ?',
                [$id]
            )
        );
    }

    /**
     * The name is copied, not joined.
     *
     * So the transcript says who said it under the name everybody saw, and
     * still says it after a rename.
     */
    public function testTheNameIsTheOneThatWasShownAtTheTime(): void
    {
        $id = $this->chat->post($this->streamId, $this->aliceId, 'Alice', 'Morning');

        $this->db()->update('users', ['name' => 'Alexandra'], ['id' => $this->aliceId]);

        self::assertSame(
            'Alice',
            (string) $this->db()->value(
                'SELECT author FROM {live_chat_messages} WHERE id = ?',
                [$id]
            ),
            'A RENAME REWROTE THE TRANSCRIPT'
        );
    }
}
