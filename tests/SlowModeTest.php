<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Live\SlowMode;

/**
 * Slow mode, which is PER PERSON.
 *
 * The per-person rule cannot be seen from inside this class — it takes one
 * person's last message and knows nothing about anybody else, which is the
 * whole point but also means the property is a fact about the CALLER. So it is
 * tested here as far as it can be (one person's own timing) and again in
 * LiveChatTest against two real rows, which is the only place a per-chat
 * implementation would be visible.
 */
final class SlowModeTest extends TestCase
{
    private const NOW = 1789041600;

    private function at(int $offset): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW + $offset);
    }

    /** Somebody who has never said anything waits for nothing. */
    public function testAFirstMessageNeverWaits(): void
    {
        self::assertSame(0, SlowMode::waitFor(null, 5, self::NOW));
        self::assertTrue(SlowMode::allows(null, 5, self::NOW));
    }

    /**
     * THE RULE, at the boundary from both sides.
     *
     * A check at "one second ago, refused" and "an hour ago, allowed" passes
     * for every wrong interval between them.
     */
    public function testItWaitsExactlyTheConfiguredInterval(): void
    {
        self::assertSame(
            1,
            SlowMode::waitFor($this->at(-4), 5, self::NOW),
            'four seconds after a five-second limit should have one to go'
        );

        self::assertSame(
            0,
            SlowMode::waitFor($this->at(-5), 5, self::NOW),
            'THE LIMIT WAS LONGER THAN IT SAYS — five seconds had passed'
        );

        self::assertSame(
            0,
            SlowMode::waitFor($this->at(-6), 5, self::NOW)
        );
    }

    /** Zero is a real setting and means off, not "a moment". */
    public function testZeroSecondsTurnsItOff(): void
    {
        self::assertSame(0, SlowMode::waitFor($this->at(-1), 0, self::NOW));
        self::assertTrue(SlowMode::allows($this->at(0), 0, self::NOW));
    }

    /**
     * A cap, because a ten-minute slow mode is a closed room with a Send
     * button — which is worse than no chat, since it looks like it works.
     */
    public function testTheIntervalIsCapped(): void
    {
        self::assertSame(SlowMode::MAX_SECONDS, SlowMode::clamp(100000));

        self::assertSame(
            SlowMode::MAX_SECONDS,
            SlowMode::waitFor($this->at(0), 100000, self::NOW),
            'A SITE COULD SET AN INTERVAL NOBODY WAITS OUT'
        );
    }

    /** Nonsense becomes off rather than an error nobody can act on. */
    public function testNegativeSecondsBecomesOff(): void
    {
        self::assertSame(0, SlowMode::clamp(-5));
        self::assertSame(0, SlowMode::waitFor($this->at(0), -5, self::NOW));
    }

    /**
     * An unreadable timestamp FAILS OPEN, deliberately.
     *
     * Against this codebase's rule for access checks, and for the reason
     * sequential unlock gives: slow mode is pacing layered on a decision
     * already made. Everything that matters has said yes by the time this runs,
     * so refusing somebody over a malformed DATETIME would be silencing them
     * over a data glitch — and the cost of allowing it is one message arriving
     * a few seconds early.
     */
    public function testAnUnreadableTimestampLetsTheMessageThrough(): void
    {
        self::assertSame(0, SlowMode::waitFor('not a timestamp', 5, self::NOW));
        self::assertSame(0, SlowMode::waitFor('', 5, self::NOW));
    }

    /**
     * A clock that went backwards does not create a long wait.
     *
     * Not hypothetical on shared hosting: this project has already had to move
     * the OIDC clock skew allowance to 120 seconds because the host's clock ran
     * behind. A stored timestamp in the future would otherwise mute somebody
     * until it caught up.
     */
    public function testAFutureTimestampWaitsAtMostTheInterval(): void
    {
        self::assertLessThanOrEqual(
            5,
            SlowMode::waitFor($this->at(600), 5, self::NOW),
            'A CLOCK SKEW COULD MUTE SOMEBODY FOR TEN MINUTES'
        );
    }

    /** The wording is singular at one second, because "1 seconds" is sloppy. */
    public function testTheWordingCountsProperly(): void
    {
        self::assertStringContainsString('One more second', SlowMode::explain(1));
        self::assertStringContainsString('3 more seconds', SlowMode::explain(3));
        self::assertSame('', SlowMode::explain(0));
    }
}
