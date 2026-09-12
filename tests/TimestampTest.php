<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\Timestamp;

/**
 * A moment in a video, as a person writes it.
 */
final class TimestampTest extends TestCase
{
    public function testTheThreeWaysPeopleWriteAMoment(): void
    {
        self::assertSame(90, Timestamp::parse('90'));
        self::assertSame(90, Timestamp::parse('1:30'));
        self::assertSame(3750, Timestamp::parse('1:02:30'));
        self::assertSame(4500, Timestamp::parse('75:00'), 'minutes past sixty is a fair way to write it');
        self::assertSame(90, Timestamp::parse(' 1:30 '));
        self::assertSame(90, Timestamp::parse(90));
    }

    /** Zero is the start, which is a real answer rather than "nothing". */
    public function testZeroIsTheStart(): void
    {
        self::assertSame(0, Timestamp::parse('0'));
        self::assertSame(0, Timestamp::parse('0:00'));
    }

    /**
     * THE RULE. Ambiguous is refused, not guessed.
     *
     * "1:5" is 1:05 or 1:50, forty-five seconds apart, and a guessed link
     * starts somewhere nobody meant while looking like it worked.
     */
    public function testAnAmbiguousMomentIsRefused(): void
    {
        foreach (['1:5', '1:75', '1:60', '1:02:5', '1:2:30', ':30', '1:', '1::30'] as $ambiguous) {
            self::assertNull(
                Timestamp::parse($ambiguous),
                "{$ambiguous} WAS GUESSED AT — a shared link would start somewhere nobody meant"
            );
        }
    }

    public function testNonsenseIsNotAMoment(): void
    {
        foreach (['', 'soon', '-30', '1.5', '1:30pm', '1h30m', null, [], 1.5, '  '] as $nonsense) {
            self::assertNull(Timestamp::parse($nonsense), json_encode($nonsense));
        }
    }

    /** A day is the ceiling, and a negative integer is not a moment. */
    public function testOutOfRangeIsRefused(): void
    {
        self::assertSame(86400, Timestamp::parse('86400'));
        self::assertNull(Timestamp::parse('86401'));
        self::assertNull(Timestamp::parse('1441:00'));
        self::assertNull(Timestamp::parse(-1));
    }

    public function testItIsWrittenBackTheWayItWasRead(): void
    {
        self::assertSame('0:00', Timestamp::format(0));
        self::assertSame('1:30', Timestamp::format(90));
        self::assertSame('1:02:30', Timestamp::format(3750));
    }

    /**
     * format() and parse() are inverses, so a link built from a displayed time
     * opens at the displayed time. Walked across the boundaries where a
     * one-off error would live — each minute and hour rollover.
     */
    public function testFormatAndParseAgreeEverywhere(): void
    {
        foreach ([0, 1, 59, 60, 61, 599, 600, 3599, 3600, 3601, 36000, 86400] as $seconds) {
            self::assertSame(
                $seconds,
                Timestamp::parse(Timestamp::format($seconds)),
                "a link built from {$seconds}s opens somewhere else"
            );
        }
    }
}
