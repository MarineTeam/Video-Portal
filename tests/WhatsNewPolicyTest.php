<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\WhatsNew\WhatsNewPolicy;

require_once dirname(__DIR__) . '/plugins/whats-new/src/WhatsNewPolicy.php';

/**
 * When a visit ends, and how far back "new" is allowed to reach.
 *
 * The tracker reads and writes; every judgement it defers to is here, where it
 * can be tested without a database and without waiting for a clock.
 */
final class WhatsNewPolicyTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function ago(int $seconds): string
    {
        return date('Y-m-d H:i:s', self::NOW - $seconds);
    }

    public function testAGapMeansTheyWentAwayAndCameBack(): void
    {
        self::assertTrue(WhatsNewPolicy::isReturning($this->ago(WhatsNewPolicy::SESSION_GAP + 1), self::NOW));
        self::assertTrue(WhatsNewPolicy::isReturning($this->ago(86400), self::NOW));
    }

    /**
     * The important half. Rolling the marker mid-visit clears the badges off
     * the page somebody is still looking at, which reads as the feature
     * flickering rather than as a session ending.
     */
    public function testStillBrowsingIsNotANewVisit(): void
    {
        self::assertFalse(WhatsNewPolicy::isReturning($this->ago(0), self::NOW));
        self::assertFalse(WhatsNewPolicy::isReturning($this->ago(WhatsNewPolicy::SESSION_GAP - 1), self::NOW));
    }

    /** Nothing to roll: the tracker creates the row instead. */
    public function testNoStampIsNotAReturn(): void
    {
        self::assertFalse(WhatsNewPolicy::isReturning(null, self::NOW));
        self::assertFalse(WhatsNewPolicy::isReturning('', self::NOW));
    }

    /**
     * An unreadable stamp rolls, so the row repairs itself on the next visit.
     * Answering "not returning" would leave it stuck forever with no badges and
     * nothing to indicate why.
     */
    public function testAnUnreadableStampRollsRatherThanSticking(): void
    {
        self::assertTrue(WhatsNewPolicy::isReturning('not a date', self::NOW));
    }

    public function testTheStillHereStampIsWrittenAtMostOnceAMinute(): void
    {
        self::assertFalse(WhatsNewPolicy::shouldTouch($this->ago(0), self::NOW));
        self::assertFalse(WhatsNewPolicy::shouldTouch($this->ago(WhatsNewPolicy::TOUCH_INTERVAL - 1), self::NOW));
        self::assertTrue(WhatsNewPolicy::shouldTouch($this->ago(WhatsNewPolicy::TOUCH_INTERVAL), self::NOW));
    }

    public function testTheCutoffIsTheEndOfThePreviousVisit(): void
    {
        $marker = $this->ago(3 * 86400);

        self::assertSame($marker, WhatsNewPolicy::cutoff($marker, self::NOW, 30));
    }

    /**
     * The setting that makes the feature worth having.
     *
     * A marker eighteen months old is perfectly valid and honouring it badges
     * the entire library. The horizon is what stops "new since your last visit"
     * from meaning "everything".
     */
    public function testAnOldMarkerIsPulledForwardToTheHorizon(): void
    {
        $cutoff = WhatsNewPolicy::cutoff($this->ago(400 * 86400), self::NOW, 30);

        self::assertSame(date('Y-m-d H:i:s', self::NOW - (30 * 86400)), $cutoff);
    }

    /** And the horizon never pushes a recent marker BACKWARDS. */
    public function testARecentMarkerIsNotWidenedToTheHorizon(): void
    {
        $marker = $this->ago(2 * 86400);

        self::assertSame($marker, WhatsNewPolicy::cutoff($marker, self::NOW, 30));
    }

    /**
     * A first visit badges nothing. Everything is new then, and a page where
     * every card carries the same badge carries no information.
     */
    public function testNoMarkerBadgesNothing(): void
    {
        self::assertNull(WhatsNewPolicy::cutoff(null, self::NOW, 30));
        self::assertNull(WhatsNewPolicy::cutoff('', self::NOW, 30));
        self::assertNull(WhatsNewPolicy::cutoff('not a date', self::NOW, 30));
    }

    /**
     * A clock corrected backwards — routine on shared hosting — leaves a marker
     * in the future. That must badge nothing rather than everything: quiet is
     * the right failure for a decoration.
     */
    public function testAMarkerInTheFutureBadgesNothing(): void
    {
        $future = date('Y-m-d H:i:s', self::NOW + 86400);

        $cutoff = WhatsNewPolicy::cutoff($future, self::NOW, 30);

        self::assertNotNull($cutoff);
        self::assertGreaterThan(self::NOW, strtotime($cutoff));
    }

    public function testTheHorizonIsClampedRatherThanRefused(): void
    {
        self::assertSame(30, WhatsNewPolicy::horizon(30));
        self::assertSame(1, WhatsNewPolicy::horizon(-5));
        self::assertSame(WhatsNewPolicy::MAX_HORIZON_DAYS, WhatsNewPolicy::horizon(5000));
    }

    /**
     * Zero is NOT kept, unlike the up-next countdown where zero is a setting
     * somebody wants. A zero-day horizon badges nothing, which is what turning
     * the plugin off is for and would otherwise read as it being broken.
     */
    public function testZeroDaysIsNotAWayToTurnItOff(): void
    {
        self::assertSame(1, WhatsNewPolicy::horizon(0));
    }

    public function testNonNumericHorizonsFallBack(): void
    {
        self::assertSame(WhatsNewPolicy::DEFAULT_HORIZON_DAYS, WhatsNewPolicy::horizon('a month'));
        self::assertSame(WhatsNewPolicy::DEFAULT_HORIZON_DAYS, WhatsNewPolicy::horizon(null));
        self::assertSame(WhatsNewPolicy::DEFAULT_HORIZON_DAYS, WhatsNewPolicy::horizon(['30']));
    }

    public function testTheLabelIsTrimmedCappedAndNeverBlank(): void
    {
        self::assertSame('Fresh', WhatsNewPolicy::label('  Fresh  '));
        self::assertSame(WhatsNewPolicy::DEFAULT_LABEL, WhatsNewPolicy::label('   '));
        self::assertSame(WhatsNewPolicy::DEFAULT_LABEL, WhatsNewPolicy::label(null));
        self::assertSame(
            WhatsNewPolicy::LABEL_MAX,
            mb_strlen(WhatsNewPolicy::label(str_repeat('x', 200)))
        );
    }

    /**
     * mb_substr rather than substr: a badge in Japanese would otherwise be cut
     * mid-character and render as a replacement glyph.
     */
    public function testTheLabelIsCappedInCharactersNotBytes(): void
    {
        self::assertSame('新着', WhatsNewPolicy::label('新着'));
    }
}
