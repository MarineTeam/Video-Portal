<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\WatchProgressRepository;

/**
 * What one person has watched, and the rule about who may change it.
 *
 * Against a real database because every assertion here is about what one SQL
 * statement does to a row that already exists — COALESCE, GREATEST, and an
 * UPDATE that must match one user. A double would be asserting that the string
 * was built, which is not the claim.
 */
final class WatchProgressTest extends DatabaseTestCase
{
    private WatchProgressRepository $progress;
    private int $userId;
    private int $otherUserId;
    private int $videoId;

    protected function setUp(): void
    {
        $this->truncate(['watch_progress', 'videos', 'users']);

        $this->progress = new WatchProgressRepository($this->db());
        $this->userId = $this->user('watcher@example.test');
        $this->otherUserId = $this->user('somebody-else@example.test');
        $this->videoId = $this->video();
    }

    private function user(string $email): int
    {
        return (int) $this->db()->insert('users', [
            'email'      => $email,
            'authorized' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function video(int $duration = 1000): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('videos', [
            'provider_id' => 'bunny-' . $suffix,
            'slug'        => 'video-' . $suffix,
            'title'       => 'A sermon',
            'duration'    => $duration,
            'status'      => 'ready',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function row(?int $userId = null): ?array
    {
        return $this->db()->first(
            'SELECT * FROM {watch_progress} WHERE user_id = ? AND video_id = ?',
            [$userId ?? $this->userId, $this->videoId]
        );
    }

    // ------------------------------------------------------- THE RULE

    /**
     * THE RULE: the heartbeat may finish a video and may never unfinish one.
     *
     * The player reports the present. "Watched" is a fact about the past, and
     * somebody who scrubs back to the start of a sermon they have already heard
     * has not un-heard it. Without this a series with sequential unlock
     * re-locks itself the moment anybody rewatches an earlier part.
     */
    public function testAHeartbeatCanFinishAVideo(): void
    {
        self::assertTrue($this->progress->record($this->userId, $this->videoId, 990, 1000));
        self::assertNotNull($this->row()['completed_at']);
    }

    public function testALaterHeartbeatNearTheStartCannotUnfinishIt(): void
    {
        $this->progress->record($this->userId, $this->videoId, 990, 1000);
        $finishedAt = $this->row()['completed_at'];

        // They opened it again a week later and watched twenty seconds.
        self::assertFalse($this->progress->record($this->userId, $this->videoId, 20, 1000));

        self::assertNotNull($this->row()['completed_at'], 'a rewatch un-finished the video');
        self::assertSame($finishedAt, $this->row()['completed_at'], 'the completion time was rewritten');

        // The position DID move, which is the half that should: it is where
        // they are now, and only the completion is a fact about the past.
        self::assertSame(20, (int) $this->row()['position_seconds']);
    }

    /**
     * The exception, and it is not a hole in the rule.
     *
     * The rule constrains the AUTOMATIC path — the player does not get to
     * revise history. A person saying "I have not watched this" is the only
     * authority on the question, and refusing them would make the mark a trap.
     */
    public function testAPersonMayTakeTheMarkOffAgain(): void
    {
        $this->progress->record($this->userId, $this->videoId, 990, 1000);
        self::assertTrue($this->progress->isCompleted($this->userId, $this->videoId));

        $this->progress->markUnwatched($this->userId, $this->videoId);

        self::assertFalse($this->progress->isCompleted($this->userId, $this->videoId));
        self::assertNull($this->row()['completed_at']);
    }

    // ------------------------------------------------------ marking

    /** The case the button exists for: nothing was ever played here. */
    public function testMarkingAVideoNobodyPlayedCreatesTheRow(): void
    {
        self::assertNull($this->row());

        $this->progress->markWatched($this->userId, $this->videoId, 1000);

        self::assertTrue($this->progress->isCompleted($this->userId, $this->videoId));
        self::assertSame(1000, (int) $this->row()['position_seconds']);
        self::assertSame(1000, (int) $this->row()['duration_seconds']);
    }

    /**
     * The position moves with the flag, in both directions.
     *
     * They are one fact. A video finished at ten seconds in would offer to
     * resume from ten seconds, and an un-watched video left at the end would
     * offer to resume from the closing credits — so a screen reading position
     * would contradict the one reading completion either way round.
     */
    public function testMarkingMovesThePositionToTheEndAndUnmarkingSendsItBack(): void
    {
        $this->progress->record($this->userId, $this->videoId, 120, 1000);
        self::assertSame(120, (int) $this->row()['position_seconds']);

        $this->progress->markWatched($this->userId, $this->videoId, 1000);
        self::assertSame(1000, (int) $this->row()['position_seconds']);

        $this->progress->markUnwatched($this->userId, $this->videoId);
        self::assertSame(0, (int) $this->row()['position_seconds']);
    }

    /**
     * Unmarking keeps the row, where forgetting deletes it.
     *
     * Two different requests. Somebody undoing a mis-click has not asked to be
     * forgotten, and losing the entry would take their history with it.
     */
    public function testUnmarkingKeepsTheHistoryEntry(): void
    {
        $this->progress->markWatched($this->userId, $this->videoId, 1000);
        $this->progress->markUnwatched($this->userId, $this->videoId);

        self::assertNotNull($this->row(), 'taking the mark off deleted the history entry');
    }

    /** Marking twice does not rewrite when they first finished it. */
    public function testMarkingAnAlreadyWatchedVideoLeavesTheOriginalTime(): void
    {
        $this->progress->record($this->userId, $this->videoId, 990, 1000);
        $first = $this->row()['completed_at'];

        $this->progress->markWatched($this->userId, $this->videoId, 1000);

        self::assertSame($first, $this->row()['completed_at']);
    }

    /** And after unmarking, the player can finish it again. */
    public function testAfterUnmarkingTheHeartbeatCanFinishItOnceMore(): void
    {
        $this->progress->markWatched($this->userId, $this->videoId, 1000);
        $this->progress->markUnwatched($this->userId, $this->videoId);

        self::assertTrue($this->progress->record($this->userId, $this->videoId, 990, 1000));
        self::assertTrue($this->progress->isCompleted($this->userId, $this->videoId));
    }

    // ---------------------------------------------------- whose row it is

    /**
     * Every write names the person as well as the video.
     *
     * Ids are sequential, so a write taking only a video id would let anybody
     * mark — and, through the unmark, erase — a stranger's progress by
     * counting. Same rule as the notification record.
     */
    public function testMarkingTouchesOnlyTheSignedInPersonsRow(): void
    {
        $this->progress->record($this->otherUserId, $this->videoId, 990, 1000);
        $this->progress->markWatched($this->userId, $this->videoId, 1000);
        $this->progress->markUnwatched($this->userId, $this->videoId);

        self::assertTrue(
            $this->progress->isCompleted($this->otherUserId, $this->videoId),
            'unmarking reached somebody else\'s row'
        );
        self::assertSame(990, (int) $this->row($this->otherUserId)['position_seconds']);
    }

    // -------------------------------------------------------- the reading

    public function testIsCompletedAnswersBothWays(): void
    {
        self::assertFalse($this->progress->isCompleted($this->userId, $this->videoId));

        $this->progress->markWatched($this->userId, $this->videoId, 1000);

        self::assertTrue($this->progress->isCompleted($this->userId, $this->videoId));
    }

    /** A part-watched video is not a watched one. */
    public function testPartWayThroughIsNotCompleted(): void
    {
        self::assertFalse($this->progress->record($this->userId, $this->videoId, 500, 1000));
        self::assertFalse($this->progress->isCompleted($this->userId, $this->videoId));
        self::assertNull($this->row()['completed_at']);
    }

    /**
     * A video of unknown length is never finished by the heartbeat.
     *
     * Without the duration check, `position >= 0 * 0.95` is true for every
     * position, so the first heartbeat on a video whose runtime the provider
     * has not reported yet would mark it watched.
     */
    public function testAVideoWithNoKnownDurationIsNeverFinishedAutomatically(): void
    {
        self::assertFalse($this->progress->record($this->userId, $this->videoId, 30, 0));
        self::assertNull($this->row()['completed_at']);
    }
}
