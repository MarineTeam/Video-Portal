<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Playback\PlaybackPolicy;

require_once dirname(__DIR__) . '/plugins/playback/src/PlaybackPolicy.php';

/**
 * Where "skip ahead" goes, and how long "up next" waits.
 *
 * The plumbing lives in a script and can only be proved in a browser; every
 * decision worth arguing about was pulled out to here, where it can be.
 */
final class PlaybackPolicyTest extends TestCase
{
    /** @return list<array{start: int, title: string}> */
    private function service(): array
    {
        return [
            ['start' => 0, 'title' => 'Welcome'],
            ['start' => 240, 'title' => 'Notices'],
            ['start' => 900, 'title' => 'Sermon: Romans 8'],
            ['start' => 2700, 'title' => 'Closing'],
        ];
    }

    public function testItFindsTheChapterByName(): void
    {
        $target = PlaybackPolicy::skipTarget($this->service(), 'Sermon');

        self::assertNotNull($target);
        self::assertSame(900, $target['start']);
        self::assertSame('Sermon: Romans 8', $target['title']);
    }

    /**
     * Contains, not equals, and case-insensitive.
     *
     * Demanding an exact match makes the feature depend on typing discipline
     * nobody was told about — "Sermon: Romans 8" is what people actually write.
     */
    public function testMatchingIgnoresCaseAndSurroundingWords(): void
    {
        self::assertNotNull(PlaybackPolicy::skipTarget($this->service(), 'sermon'));
        self::assertNotNull(PlaybackPolicy::skipTarget($this->service(), 'SERMON'));
    }

    /** First name in the list wins, so the order in the setting is a preference. */
    public function testTheConfiguredOrderIsThePreference(): void
    {
        $chapters = [
            ['start' => 0, 'title' => 'Welcome'],
            ['start' => 100, 'title' => 'Teaching'],
            ['start' => 200, 'title' => 'Sermon'],
        ];

        self::assertSame(200, PlaybackPolicy::skipTarget($chapters, 'Sermon, Teaching')['start']);
        self::assertSame(100, PlaybackPolicy::skipTarget($chapters, 'Teaching, Sermon')['start']);
    }

    /**
     * One chapter is a label on the whole video, not an intro and a sermon —
     * so there is nothing to skip, and a button offering to would jump somebody
     * to the start of what they are already watching.
     */
    public function testASingleChapterIsNotSomethingToSkip(): void
    {
        self::assertNull(PlaybackPolicy::skipTarget([['start' => 0, 'title' => 'Sermon']], 'Sermon'));
        self::assertNull(PlaybackPolicy::skipTarget([], 'Sermon'));
    }

    /**
     * A chapter at zero is never a target, whatever it is called.
     *
     * Some people title the whole recording "Sermon", and a button that seeks
     * to 0:00 appears to do nothing — which reads as broken rather than as
     * inapplicable.
     */
    public function testAChapterAtTheStartIsNeverTheTarget(): void
    {
        $chapters = [
            ['start' => 0, 'title' => 'Sermon'],
            ['start' => 600, 'title' => 'Questions'],
        ];

        self::assertNull(PlaybackPolicy::skipTarget($chapters, 'Sermon'));
    }

    public function testNoMatchMeansNoButton(): void
    {
        self::assertNull(PlaybackPolicy::skipTarget($this->service(), 'Interview, Panel'));
        self::assertNull(PlaybackPolicy::skipTarget($this->service(), '   '));
    }

    /**
     * The label names the destination.
     *
     * On a service recording the thing being skipped is the welcome and the
     * songs; calling that "the intro" is inaccurate and slightly rude about the
     * part somebody led.
     */
    public function testTheLabelNamesWhereItGoes(): void
    {
        self::assertSame('Skip to Sermon: Romans 8', PlaybackPolicy::skipLabel('Sermon: Romans 8'));
        self::assertSame('Skip ahead', PlaybackPolicy::skipLabel('  '));
    }

    public function testTitlesAreSplitAndCleaned(): void
    {
        self::assertSame(['Sermon', 'Message'], PlaybackPolicy::titles(' Sermon , , Message '));
        self::assertSame([], PlaybackPolicy::titles('  ,  '));
    }

    /**
     * Zero survives, because zero means "show the card and never play by
     * itself" — the setting for a site that finds autoplay rude.
     */
    public function testZeroCountdownIsKept(): void
    {
        self::assertSame(0, PlaybackPolicy::countdown(0));
        self::assertSame(0, PlaybackPolicy::countdown('0'));
        self::assertSame(0, PlaybackPolicy::countdown(-5));
    }

    /**
     * Anything else is clamped rather than refused: this comes from a number
     * field, and 900 should become "a long time" rather than an error page.
     */
    public function testOtherCountdownsAreClampedToSomethingLiveable(): void
    {
        self::assertSame(10, PlaybackPolicy::countdown(10));
        self::assertSame(3, PlaybackPolicy::countdown(1));
        self::assertSame(60, PlaybackPolicy::countdown(900));
    }
}
