<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\Str;

/**
 * How long ago something happened.
 *
 * Scoped to since(), which is what the People screen's "Last seen" column
 * renders. The rest of Str has no tests, which is a real gap and a separate
 * piece of work — not one to pretend away by naming this file broadly.
 *
 * Every case pins $now, because a test whose answer depends on the wall clock
 * is a test that fails at midnight for reasons nobody can reproduce.
 */
final class StrTest extends TestCase
{
    private const NOW = 1755000000; // a fixed point; nothing here depends on which

    private function ago(int $seconds): string
    {
        return date('Y-m-d H:i:s', self::NOW - $seconds);
    }

    /**
     * "Never" is a real answer and has to be distinguishable from "a long time
     * ago". An account nobody has ever signed into is the case an admin is
     * looking for when they scan this column.
     */
    public function testNothingRecordedIsNever(): void
    {
        self::assertSame('Never', Str::since(null, self::NOW));
        self::assertSame('Never', Str::since('', self::NOW));
        self::assertSame('Never', Str::since('   ', self::NOW));
    }

    /**
     * MySQL's zero date and anything unparseable mean the same thing to a
     * reader. Neither may render as 1 Jan 1970, which looks like data rather
     * than like an absence.
     */
    public function testUnreadableStampsAreAlsoNever(): void
    {
        // Three different failures behind one answer, and they take two
        // different branches: the zero date parses to a large NEGATIVE number
        // rather than to false, and the epoch parses to exactly 0. Only the
        // last of these is what strtotime() actually rejects.
        self::assertSame('Never', Str::since('0000-00-00 00:00:00', self::NOW));
        self::assertSame('Never', Str::since('1970-01-01 00:00:00', self::NOW));
        self::assertSame('Never', Str::since('not a date at all', self::NOW));
    }

    public function testRecentIsJustNow(): void
    {
        self::assertSame('Just now', Str::since($this->ago(0), self::NOW));
        self::assertSame('Just now', Str::since($this->ago(59), self::NOW));
    }

    /**
     * A stamp in the future reads as "Just now", never as a negative count.
     *
     * Shared hosts have their clocks corrected, and this project has already
     * lost time to skew between two machines once. "-3 minutes ago" on the
     * People screen would send somebody hunting for a bug that is not there.
     */
    public function testAFutureStampDoesNotCountBackwards(): void
    {
        self::assertSame('Just now', Str::since($this->ago(-600), self::NOW));
    }

    public function testMinutesHoursAndDays(): void
    {
        self::assertSame('1 minute ago', Str::since($this->ago(60), self::NOW));
        self::assertSame('5 minutes ago', Str::since($this->ago(300), self::NOW));
        self::assertSame('1 hour ago', Str::since($this->ago(3600), self::NOW));
        self::assertSame('3 hours ago', Str::since($this->ago(10800), self::NOW));
        self::assertSame('1 day ago', Str::since($this->ago(86400), self::NOW));
        self::assertSame('9 days ago', Str::since($this->ago(9 * 86400), self::NOW));
    }

    /** Singular and plural are separate answers at every boundary. */
    public function testTheUnitBoundaries(): void
    {
        self::assertSame('59 minutes ago', Str::since($this->ago(3599), self::NOW));
        self::assertSame('23 hours ago', Str::since($this->ago(86399), self::NOW));
    }

    /**
     * Past two months it becomes a date.
     *
     * "412 days ago" is arithmetic the reader has to do before it means
     * anything. The question actually being asked of a dormant account — has
     * this person been gone since the spring? — a date answers directly.
     */
    public function testOldStampsBecomeADate(): void
    {
        self::assertSame('59 days ago', Str::since($this->ago(59 * 86400), self::NOW));

        $old = $this->ago(400 * 86400);
        self::assertSame(date('j M Y', strtotime($old)), Str::since($old, self::NOW));

        // And the switch really happened rather than the count coincidentally
        // matching: no "ago" survives past the boundary.
        self::assertStringNotContainsString('ago', Str::since($this->ago(60 * 86400), self::NOW));
    }
}
