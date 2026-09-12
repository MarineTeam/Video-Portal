<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Live\ChatWindow;

/**
 * When a stream's chat is open.
 *
 * Every time here is INJECTED. A test that used the real clock would pass or
 * fail depending on what time the suite ran, which for a class whose whole
 * subject is the clock is the one thing that must not happen.
 */
final class ChatWindowTest extends TestCase
{
    /** 2026-09-20 10:00:00 UTC, a Sunday morning. */
    private const START = 1789041600;

    /** @return array<string, mixed> */
    private function stream(?int $startsAt, ?int $endsAt = null, ?int $endedAt = null): array
    {
        return [
            'id'        => 1,
            'starts_at' => $startsAt === null ? null : gmdate('Y-m-d H:i:s', $startsAt),
            'ends_at'   => $endsAt === null ? null : gmdate('Y-m-d H:i:s', $endsAt),
            'ended_at'  => $endedAt === null ? null : gmdate('Y-m-d H:i:s', $endedAt),
        ];
    }

    // ----------------------------------------------------- thirty before

    /**
     * THE RULE. Open half an hour before, not at the start.
     *
     * Asserted at the boundary from both sides, because a rule about a
     * threshold is only tested by the two minutes either side of it — a check
     * at "an hour before" and "ten minutes after" passes for any offset
     * between them, which is every wrong answer.
     */
    public function testItOpensThirtyMinutesBeforeAndNotAMinuteEarlier(): void
    {
        $stream = $this->stream(self::START, self::START + 3600);

        self::assertSame(
            ChatWindow::EARLY,
            ChatWindow::state($stream, self::START - (31 * 60)),
            'the room was open 31 minutes before the stream'
        );

        self::assertSame(
            ChatWindow::OPEN,
            ChatWindow::state($stream, self::START - (29 * 60)),
            'THE ROOM WAS SHUT 29 MINUTES BEFORE — people arrive early'
        );
    }

    public function testItIsOpenWhileTheStreamIsOn(): void
    {
        $stream = $this->stream(self::START, self::START + 3600);

        self::assertTrue(ChatWindow::isOpen($stream, self::START + 60));
        self::assertTrue(ChatWindow::isOpen($stream, self::START + 3000));
    }

    // -------------------------------------------------------- sixty after

    /**
     * THE OTHER RULE. Open an hour after it ends, then shut.
     *
     * Both sides of the boundary again, and measured from the END rather than
     * the start — an implementation that closed sixty minutes after the START
     * would pass a test that only looked an hour and a half in.
     */
    public function testItStaysOpenAnHourAfterTheEndAndThenCloses(): void
    {
        $end = self::START + 3600;
        $stream = $this->stream(self::START, $end);

        self::assertSame(
            ChatWindow::OPEN,
            ChatWindow::state($stream, $end + (59 * 60)),
            'the room shut while people were still talking'
        );

        self::assertSame(
            ChatWindow::LATE,
            ChatWindow::state($stream, $end + (61 * 60)),
            'THE ROOM WAS STILL OPEN AN HOUR AND A MINUTE LATER — nobody is moderating it'
        );
    }

    /**
     * Ending it by hand beats the schedule, and the hour runs from THAT.
     *
     * A service that finished twenty minutes early must not leave the room open
     * for the eighty minutes its plan implied — that is forty minutes of
     * unwatched room, which is exactly where the thing a moderator would have
     * removed sits.
     */
    public function testEndingItByHandStartsTheHourImmediately(): void
    {
        $endedEarly = self::START + 1200;

        // Planned to run an hour; stopped after twenty minutes.
        $stream = $this->stream(self::START, self::START + 3600, $endedEarly);

        self::assertSame(
            ChatWindow::OPEN,
            ChatWindow::state($stream, $endedEarly + (30 * 60))
        );

        self::assertSame(
            ChatWindow::LATE,
            ChatWindow::state($stream, $endedEarly + (61 * 60)),
            'THE HOUR WAS MEASURED FROM THE PLAN, not from when it actually stopped'
        );
    }

    // ----------------------------------------------- no end, and no start

    /**
     * A stream with no end time closes on the safety net, not never.
     *
     * The same twelve hours that stop a LIVE badge staying up for three weeks.
     * A room that never closes is the one state nobody is moderating, and it is
     * the DEFAULT for a stream somebody scheduled without an end — which is
     * most of them.
     */
    public function testAStreamWithNoEndTimeStillClosesEventually(): void
    {
        $stream = $this->stream(self::START);

        self::assertTrue(
            ChatWindow::isOpen($stream, self::START + (11 * 3600)),
            'a stream with no end time closed while it could still be running'
        );

        self::assertSame(
            ChatWindow::LATE,
            ChatWindow::state($stream, self::START + (14 * 3600)),
            'A ROOM WITH NO END TIME STAYED OPEN FOR EVER'
        );
    }

    /**
     * A stream with no start time has no window at all.
     *
     * Distinct from EARLY, because the two send somebody to different places:
     * "opens half an hour before" is useful and true for one, and wrong and
     * confusing for the other.
     */
    public function testAStreamWithNoScheduleHasNoWindow(): void
    {
        $stream = $this->stream(null);

        self::assertSame(ChatWindow::UNSCHEDULED, ChatWindow::state($stream, self::START));
        self::assertFalse(ChatWindow::isOpen($stream, self::START));
        self::assertNull(ChatWindow::opensAt($stream));
        self::assertNull(ChatWindow::closesAt($stream));
    }

    /** An unparseable timestamp is the same as none, never "now". */
    public function testRubbishInTheScheduleIsNotAnOpenRoom(): void
    {
        foreach (['', '   ', 'soon', 'not a date'] as $rubbish) {
            self::assertSame(
                ChatWindow::UNSCHEDULED,
                ChatWindow::state(['id' => 1, 'starts_at' => $rubbish], self::START),
                $rubbish
            );
        }
    }

    // --------------------------------------------------------- the wording

    /**
     * Each state says something different, and none of them is empty.
     *
     * "The chat is closed" is true of three situations and useful in none:
     * come back later, you have missed it, and there is nothing to wait for.
     */
    public function testEveryClosedStateExplainsItselfDifferently(): void
    {
        $said = [];

        foreach ([ChatWindow::EARLY, ChatWindow::LATE, ChatWindow::UNSCHEDULED] as $state) {
            $words = ChatWindow::explain($state);

            self::assertNotSame('', $words, $state . ' says nothing');
            $said[] = $words;
        }

        self::assertSame(
            $said,
            array_unique($said),
            'TWO STATES GIVE THE SAME EXPLANATION — one of them is sending somebody to the wrong place'
        );

        // And an open room says nothing at all, because there is nothing to say.
        self::assertSame('', ChatWindow::explain(ChatWindow::OPEN));
    }
}
