<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Feeds\Ics;

/**
 * The four RFC 5545 details a calendar mangles silently.
 *
 * None of these produces an error anywhere. A calendar application reading a
 * malformed feed shows a truncated title, a URL that will not open, or an
 * appointment on the wrong day, and the person holding the phone has no way to
 * know. That is the whole reason these are worth testing precisely.
 */
final class IcsTest extends TestCase
{
    /** Every line of a folded value, with the continuation space removed. */
    private function unfold(string $folded): string
    {
        return str_replace("\r\n ", '', $folded);
    }

    // ------------------------------------------------- folding by octet

    /**
     * THE RULE. 75 OCTETS, not characters — a description full of em dashes
     * counts three octets each, and folding by strlen on characters leaves
     * lines over the limit.
     */
    public function testLinesFoldAtSeventyFiveOctets(): void
    {
        $line = Ics::fold('DESCRIPTION:' . str_repeat('a', 200));

        foreach (explode("\r\n", $line) as $part) {
            self::assertLessThanOrEqual(75, strlen($part), 'a folded line is over 75 octets');
        }
    }

    /**
     * And OCTETS rather than characters, which only a multi-byte line can show.
     *
     * The test above passes just as happily against code counting characters,
     * because in 200 letters the two numbers are the same — it was the only
     * check on this rule and it constrained nothing. An em dash is three
     * octets, so a line counted by characters folds at 75 characters and
     * arrives 225 octets long: over the limit, and mangled by the reader with
     * nothing anywhere reporting it.
     */
    public function testOctetsRatherThanCharactersDecideWhereALineFolds(): void
    {
        $folded = Ics::fold('DESCRIPTION:' . str_repeat('—', 80));

        foreach (explode("\r\n", $folded) as $part) {
            self::assertLessThanOrEqual(
                75,
                strlen($part),
                'FOLDED BY CHARACTER RATHER THAN BY OCTET — every line is over the limit'
            );
        }

        // And it really did have to fold, or the assertion above is vacuous.
        self::assertGreaterThan(1, count(explode("\r\n", $folded)));
    }

    /**
     * And a continuation's leading space counts toward ITS 75.
     *
     * Getting that wrong leaves every line after the first one octet too long
     * — which is the version that looks right until somebody measures.
     */
    public function testAContinuationLineCountsItsOwnLeadingSpace(): void
    {
        $parts = explode("\r\n", Ics::fold(str_repeat('x', 300)));

        array_shift($parts);

        foreach ($parts as $part) {
            self::assertSame(' ', substr($part, 0, 1), 'a continuation does not begin with a space');
            self::assertLessThanOrEqual(75, strlen($part));
        }
    }

    /**
     * NEVER INSIDE A UTF-8 SEQUENCE. Split one and the halves arrive as
     * replacement characters — a name rendered as mojibake on a phone, with
     * nothing anywhere reporting a problem.
     */
    public function testFoldingNeverSplitsAMultiByteCharacter(): void
    {
        // Three octets each, so the fold point lands mid-character unless the
        // code is counting properly.
        $value = str_repeat('—', 60);
        $folded = Ics::fold('DESCRIPTION:' . $value);

        foreach (explode("\r\n", $folded) as $part) {
            self::assertSame(
                $part,
                mb_convert_encoding($part, 'UTF-8', 'UTF-8'),
                'a UTF-8 sequence was cut in half'
            );
        }

        self::assertSame('DESCRIPTION:' . $value, $this->unfold($folded), 'unfolding lost something');
    }

    /** An accented name survives a fold intact. */
    public function testAnAccentedNameSurvivesFolding(): void
    {
        $value = 'SUMMARY:' . str_repeat('José Ángel ', 12);

        self::assertSame($value, $this->unfold(Ics::fold($value)));
    }

    public function testAShortLineIsNotFoldedAtAll(): void
    {
        self::assertSame('SUMMARY:Morning service', Ics::fold('SUMMARY:Morning service'));
    }

    // ------------------------------------------------------ the escaping

    /**
     * THE RULE. A colon is literal inside a value. Escaping it is the tempting
     * mistake, and it breaks every URL in the feed — which is most of what a
     * feed is for.
     */
    public function testAColonIsNotEscaped(): void
    {
        self::assertSame(
            'Come at 10:30',
            Ics::text('Come at 10:30'),
            'ESCAPING A COLON BREAKS EVERY URL IN THE FEED'
        );

        self::assertStringContainsString(
            'https://example.test/x',
            Ics::event([
                'uid'     => 'a',
                'summary' => 'Quiz',
                'stamp'   => 0,
                'start'   => 0,
                'url'     => 'https://example.test/x',
            ])
        );
    }

    /** What IS escaped: backslash, semicolon, comma, and a newline. */
    public function testTheThingsThatAreEscaped(): void
    {
        self::assertSame('a\\\\b', Ics::text('a\\b'));
        self::assertSame('a\\;b', Ics::text('a;b'));
        self::assertSame('a\\,b', Ics::text('a,b'));
        self::assertSame('a\\nb', Ics::text("a\nb"));
        self::assertSame('a\\nb', Ics::text("a\r\nb"), 'a CRLF became two escapes');
    }

    /** The backslash goes first, or the escapes added after it get escaped. */
    public function testABackslashIsEscapedBeforeWhatFollowsIt(): void
    {
        self::assertSame('\\\\\\;', Ics::text('\\;'));
    }

    // -------------------------------------------------- the all-day end

    /**
     * THE RULE. DTEND is EXCLUSIVE, so a one-day event ends on the following
     * day. Ending it on its own date gives it zero length, and it vanishes
     * from half the calendars that read it.
     */
    public function testAnAllDayEventEndsTheFollowingDay(): void
    {
        self::assertSame('20260907', Ics::allDayEnd('2026-09-06'));

        $event = Ics::event([
            'uid'         => 'a',
            'summary'     => 'Church weekend',
            'stamp'       => 0,
            'allDayStart' => '2026-09-06',
        ]);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260906', $event);
        self::assertStringContainsString(
            'DTEND;VALUE=DATE:20260907',
            $event,
            'A ONE-DAY EVENT WOULD VANISH FROM HALF THE CALENDARS THAT READ IT'
        );
    }

    /** It crosses a month end correctly rather than by adding one to the day. */
    public function testTheFollowingDayCrossesAMonthEnd(): void
    {
        self::assertSame('20261001', Ics::allDayEnd('2026-09-30'));
        self::assertSame('20270101', Ics::allDayEnd('2026-12-31'));
    }

    // ------------------------------------------------------- cancelled

    /**
     * THE RULE. A date somebody declined is CANCELLED, not omitted — omitting
     * it leaves it on the phone of the one person who already synced, who is
     * the person who said no and then turns up.
     */
    public function testADeclinedDateCanBeMarkedCancelledRatherThanOmitted(): void
    {
        $event = Ics::event([
            'uid'     => 'rota-7',
            'summary' => 'Reading',
            'stamp'   => 0,
            'start'   => 0,
            'status'  => Ics::CANCELLED,
        ]);

        self::assertStringContainsString('STATUS:CANCELLED', $event);
        self::assertStringContainsString('UID:rota-7', $event, 'the UID must match what was synced');
    }

    // ---------------------------------------------------------- shape

    public function testACalendarIsWrappedAndUsesCrlf(): void
    {
        $calendar = Ics::calendar('What is on', [Ics::event([
            'uid'     => 'a',
            'summary' => 'Quiz',
            'stamp'   => 0,
            'start'   => 0,
        ])]);

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $calendar);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $calendar);
        self::assertStringNotContainsString("\n\n", $calendar);

        // Every line break is a CRLF, which several readers enforce.
        self::assertSame(
            substr_count($calendar, "\n"),
            substr_count($calendar, "\r\n"),
            'a bare LF got in'
        );
    }

    public function testTimesAreUtc(): void
    {
        self::assertSame('19700101T000000Z', Ics::stamp(0));
        self::assertStringEndsWith('Z', Ics::stamp(time()));
    }

    /**
     * Invalid UTF-8 becomes a visible question mark rather than being split
     * into bytes. Mojibake on somebody's phone is the worse failure, because
     * nothing reports it.
     */
    public function testInvalidUtf8DoesNotProduceMojibake(): void
    {
        $folded = Ics::fold('SUMMARY:' . str_repeat("\xC3\x28", 60));

        foreach (explode("\r\n", $folded) as $part) {
            self::assertSame($part, mb_convert_encoding($part, 'UTF-8', 'UTF-8'));
        }
    }
}
