<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Throwable;

/**
 * What one person has watched, and how far.
 *
 * The writes lived inline in WatchController until now. They are here because
 * the rule below is a rule about the data rather than about the request that
 * happened to carry it, and a rule written inside a controller is one the next
 * controller does not know about — which is exactly what a manual "mark as
 * watched" is: a second way in.
 *
 * # THE RULE: the heartbeat may finish a video and may never unfinish one
 *
 * The player posts a position every ten seconds. Past 95% that counts as
 * finished, and `record()` writes the completion — but it can only ever ADD
 * one. A finished video that somebody scrubs back to the beginning of, or
 * re-opens a week later, must not stop being finished, because "watched" is a
 * fact about the past and the player is reporting the present.
 *
 * Without that, a series with sequential unlock re-locks itself the moment
 * somebody rewatches an earlier part, and a history screen loses entries by
 * being used.
 *
 * markUnwatched() is the deliberate exception and is not a hole in the rule.
 * The rule constrains the AUTOMATIC path — a heartbeat is the player talking,
 * and the player does not get to revise history. A person saying "I have not
 * watched this" is the only authority on the question, and refusing them would
 * make the mark a trap rather than a control.
 */
final class WatchProgressRepository
{
    /** Past this fraction of the runtime, the player counts it as finished. */
    public const COMPLETE_AT = 0.95;

    /**
     * Below this many seconds nothing is stored at all.
     *
     * It is somebody clicking away, and recording it fills the
     * continue-watching row with videos nobody started.
     */
    public const MIN_SECONDS = 10;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The heartbeat. Returns whether this position counts as finished.
     *
     * The COALESCE is the rule. Written as
     * `COALESCE(existing, new)`, so an existing completion always wins and a
     * NULL from this heartbeat cannot overwrite one.
     */
    public function record(int $userId, int $videoId, int $position, int $duration): bool
    {
        $completed = $duration > 0 && $position >= $duration * self::COMPLETE_AT;

        $this->db->execute(
            'INSERT INTO {watch_progress}
                (user_id, video_id, position_seconds, duration_seconds, completed_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                position_seconds = VALUES(position_seconds),
                duration_seconds = VALUES(duration_seconds),
                completed_at = COALESCE({watch_progress}.completed_at, VALUES(completed_at)),
                updated_at = NOW()',
            [$userId, $videoId, $position, $duration, $completed ? date('Y-m-d H:i:s') : null]
        );

        return $completed;
    }

    /**
     * Mark it watched, whatever the player saw.
     *
     * For the sermon listened to in the car, the one watched on somebody
     * else's television, and the one whose last two minutes are credits. A
     * library that can only be marked by playing it is one that quietly
     * disagrees with the person using it, and there is no way to correct it.
     *
     * The position is moved to the end as well as the flag being set. They are
     * one fact — a video finished at ten seconds in would offer itself to
     * resume from ten seconds, so a screen reading position rather than
     * completion would contradict the one reading completion.
     */
    public function markWatched(int $userId, int $videoId, int $duration): void
    {
        $duration = max(0, $duration);

        $this->db->execute(
            'INSERT INTO {watch_progress}
                (user_id, video_id, position_seconds, duration_seconds, completed_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                position_seconds = GREATEST({watch_progress}.position_seconds, VALUES(position_seconds)),
                duration_seconds = GREATEST({watch_progress}.duration_seconds, VALUES(duration_seconds)),
                completed_at = COALESCE({watch_progress}.completed_at, VALUES(completed_at)),
                updated_at = NOW()',
            [$userId, $videoId, $duration, $duration]
        );
    }

    /**
     * Take the mark off again.
     *
     * The row is kept and only the completion is cleared, rather than deleted:
     * deleting it is what "forget this" means on the history screen, and it is
     * a different request. Somebody undoing a mis-click has not asked to be
     * forgotten.
     *
     * The position goes back to zero with it. Left at the end, the video would
     * be un-watched and still offer to resume from the closing seconds, which
     * is the same contradiction markWatched() avoids from the other side.
     */
    public function markUnwatched(int $userId, int $videoId): void
    {
        $this->db->execute(
            'UPDATE {watch_progress}
                SET completed_at = NULL, position_seconds = 0, updated_at = NOW()
              WHERE user_id = ? AND video_id = ?',
            [$userId, $videoId]
        );
    }

    public function isCompleted(int $userId, int $videoId): bool
    {
        if ($userId <= 0 || $videoId <= 0) {
            return false;
        }

        try {
            return $this->db->value(
                'SELECT completed_at FROM {watch_progress}
                  WHERE user_id = ? AND video_id = ? AND completed_at IS NOT NULL',
                [$userId, $videoId]
            ) !== null;
        } catch (Throwable) {
            /*
             * Fails to NOT WATCHED, which is the harmless answer: the button
             * offers to mark something already marked, and pressing it changes
             * nothing. The opposite would offer to unmark a video somebody has
             * never seen.
             */
            return false;
        }
    }
}
