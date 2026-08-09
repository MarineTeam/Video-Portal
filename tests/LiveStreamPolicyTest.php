<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\LiveStreamPolicy as Policy;

/**
 * When a stream is live, and what may be embedded.
 *
 * Two unrelated decisions, both of the kind somebody gets wrong quietly: a
 * badge that says LIVE when nothing is on, and a URL that executes rather than
 * merely failing.
 */
final class LiveStreamPolicyTest extends TestCase
{
    private const NOW = 1700000000; // a fixed moment, so nothing here is timing-dependent

    private function at(int $offsetSeconds): string
    {
        return date('Y-m-d H:i:s', self::NOW + $offsetSeconds);
    }

    // ------------------------------------------------------------------ state

    public function testAStreamBeforeItsStartIsScheduled(): void
    {
        self::assertSame(
            Policy::SCHEDULED,
            Policy::state($this->at(3600), $this->at(7200), null, self::NOW)
        );
    }

    public function testAStreamInsideItsWindowIsLive(): void
    {
        self::assertSame(
            Policy::LIVE,
            Policy::state($this->at(-600), $this->at(3600), null, self::NOW)
        );
    }

    public function testAStreamPastItsEndHasEnded(): void
    {
        self::assertSame(
            Policy::ENDED,
            Policy::state($this->at(-7200), $this->at(-3600), null, self::NOW)
        );
    }

    /**
     * Nothing has to run for a stream to go live — the comparison is the whole
     * mechanism, for the reason scheduled publishing gives: a job-driven "go
     * live" goes live late, or on a quiet morning not at all.
     */
    public function testItGoesLiveTheMomentItsStartPasses(): void
    {
        self::assertSame(Policy::SCHEDULED, Policy::state($this->at(1), null, null, self::NOW));
        self::assertSame(Policy::LIVE, Policy::state($this->at(0), null, null, self::NOW));
    }

    // ------------------------------------------------------------ ending early

    /**
     * Somebody pressed a button that says "this is over". No schedule outranks
     * that — a service that finished early must not keep claiming to be live
     * until its planned end.
     */
    public function testEndingItByHandBeatsTheSchedule(): void
    {
        self::assertSame(
            Policy::ENDED,
            Policy::state($this->at(-600), $this->at(3600), $this->at(-60), self::NOW)
        );
    }

    /** And an end time in the future has not happened yet. */
    public function testAnEndStampInTheFutureDoesNotEndItYet(): void
    {
        self::assertSame(
            Policy::LIVE,
            Policy::state($this->at(-600), $this->at(3600), $this->at(600), self::NOW)
        );
    }

    // ------------------------------------------------------------ the safety net

    /**
     * The most important rule here. Somebody starts a stream on Sunday and
     * never comes back to end it; without this the site says LIVE NOW for a
     * month, and after the second week nobody believes the badge on the week it
     * is true.
     */
    public function testAStreamWithNoEndStopsSayingLiveEventually(): void
    {
        $justInside = (Policy::MAX_UNENDED_HOURS * 3600) - 60;
        $justOutside = (Policy::MAX_UNENDED_HOURS * 3600) + 60;

        self::assertSame(Policy::LIVE, Policy::state($this->at(-$justInside), null, null, self::NOW));
        self::assertSame(Policy::ENDED, Policy::state($this->at(-$justOutside), null, null, self::NOW));
    }

    /** An explicit end time is honoured even past the safety net's window. */
    public function testAnExplicitEndBeatsTheSafetyNetInBothDirections(): void
    {
        $long = (Policy::MAX_UNENDED_HOURS * 3600) + 7200;

        // Still running, and said so — a genuinely long broadcast.
        self::assertSame(
            Policy::LIVE,
            Policy::state($this->at(-$long), $this->at(3600), null, self::NOW)
        );

        // And a short one that is over stays over.
        self::assertSame(
            Policy::ENDED,
            Policy::state($this->at(-3600), $this->at(-1800), null, self::NOW)
        );
    }

    /**
     * Made and never scheduled. Read as not yet live rather than live forever:
     * the failure of the first is an announcement nobody sees, and of the
     * second a permanent badge on a site with no stream.
     */
    public function testAStreamWithNoStartIsNotLive(): void
    {
        self::assertSame(Policy::SCHEDULED, Policy::state(null, null, null, self::NOW));
        self::assertSame(Policy::SCHEDULED, Policy::state('', null, null, self::NOW));
    }

    public function testAnUnparseableDateIsNotLive(): void
    {
        self::assertSame(Policy::SCHEDULED, Policy::state('not a date', null, null, self::NOW));
    }

    // -------------------------------------------------------------- embed URLs

    public function testAnOrdinaryEmbedIsAccepted(): void
    {
        self::assertNull(Policy::rejectionReason('https://www.youtube.com/embed/abc123'));
        self::assertNull(Policy::rejectionReason('https://player.vimeo.com/video/123456'));
    }

    /**
     * The security decision. This value goes into an iframe's src, where a
     * scheme other than http(s) is not merely wrong but EXECUTABLE — and
     * escaping the attribute does not help, because the string is legal HTML.
     */
    public function testASchemeThatWouldExecuteIsRefused(): void
    {
        self::assertNotNull(Policy::rejectionReason('javascript:alert(1)'));
        self::assertNotNull(Policy::rejectionReason('data:text/html,<script>alert(1)</script>'));
        self::assertNotNull(Policy::rejectionReason('vbscript:msgbox(1)'));
        self::assertNotNull(Policy::rejectionReason('file:///etc/passwd'));
    }

    /**
     * http is refused too, and not for tidiness: a browser will not load an
     * insecure frame inside a secure page, so an http embed is a blank
     * rectangle with the explanation only in the console.
     */
    public function testPlainHttpIsRefusedWithTheRealReason(): void
    {
        $reason = Policy::rejectionReason('http://example.com/embed/1');

        self::assertNotNull($reason);
        self::assertStringContainsString('https', $reason);
    }

    /**
     * The scheme is checked before anything else, and this pins the ORDER.
     *
     * An earlier version required a host first, which happened to catch
     * javascript: and data: because neither has one — so the scheme rule was
     * not doing the work its comment claimed, and removing it killed only one
     * test. These addresses all have a host, so only the scheme rule can
     * refuse them.
     */
    public function testADangerousSchemeIsRefusedEvenWithAPlausibleHost(): void
    {
        self::assertNotNull(Policy::rejectionReason('ftp://example.com/stream'));
        self::assertNotNull(Policy::rejectionReason('ws://example.com/stream'));
        self::assertNotNull(Policy::rejectionReason('javascript://example.com/%0aalert(1)'));
    }

    public function testCredentialsInTheAddressAreRefused(): void
    {
        self::assertNotNull(Policy::rejectionReason('https://user:pass@example.com/embed'));
    }

    public function testSomethingThatIsNotAnAddressIsRefused(): void
    {
        self::assertNotNull(Policy::rejectionReason(''));
        self::assertNotNull(Policy::rejectionReason('   '));
        self::assertNotNull(Policy::rejectionReason('example.com/embed'));
        self::assertNotNull(Policy::rejectionReason('https://' . str_repeat('a', 600)));
    }

    // --------------------------------------------------------------- warnings

    /**
     * The commonest mistake by a distance: pasting the page you watch on. It is
     * flagged rather than rewritten — a silent rewrite would make the stored
     * value stop matching what somebody typed, and the next edit a surprise.
     */
    public function testAWatchPageIsFlaggedRatherThanRewritten(): void
    {
        self::assertNotNull(Policy::embedWarning('https://www.youtube.com/watch?v=abc123'));
        self::assertNotNull(Policy::embedWarning('https://youtu.be/abc123'));
        self::assertNotNull(Policy::embedWarning('https://vimeo.com/123456'));
    }

    public function testARealEmbedIsNotFlagged(): void
    {
        self::assertNull(Policy::embedWarning('https://www.youtube.com/embed/abc123'));
        self::assertNull(Policy::embedWarning('https://player.vimeo.com/video/123456'));
        self::assertNull(Policy::embedWarning('https://stream.example.com/live'));
    }
}
