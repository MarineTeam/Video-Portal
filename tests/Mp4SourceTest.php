<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Video\Mp4Source;

/**
 * A downloadable MP4, or the specific reason there isn't one.
 *
 * The reason IS the feature. `downloadUrl()` answers `?string`, and four
 * different situations produce that null — a library setting that was never
 * switched on, a video that predates it being switched on, no rendition inside
 * the height cap, and no pull zone. They need four different fixes, and this is
 * the URL a podcast client fetches and an offline download would save, so it is
 * the one most often reported as broken by somebody who cannot read a log.
 */
final class Mp4SourceTest extends TestCase
{
    public function testAFoundSourceCarriesItsUrlAndHeight(): void
    {
        $source = Mp4Source::found('https://cdn.example.com/x/play_480p.mp4?token=a&expires=1', 480);

        self::assertTrue($source->ok());
        self::assertSame(480, $source->height);
        self::assertNull($source->reason);
    }

    public function testAMissingSourceIsNotOkAndHasNoUrl(): void
    {
        $source = Mp4Source::missing(Mp4Source::NO_FALLBACK);

        self::assertFalse($source->ok());
        self::assertNull($source->url);
        self::assertSame(Mp4Source::NO_FALLBACK, $source->reason);
    }

    /**
     * Each reason has to say something an administrator can act on, and they
     * have to differ — a shared "no MP4 available" would be true of all four
     * and useful for none.
     */
    public function testEachReasonExplainsItselfDifferently(): void
    {
        $explanations = [];

        $reasons = [
            Mp4Source::NO_FALLBACK,
            Mp4Source::NO_RENDITION,
            Mp4Source::NOT_CONFIGURED,
            Mp4Source::NOT_AT_PROVIDER,
        ];

        foreach ($reasons as $reason) {
            $text = Mp4Source::missing($reason)->explain();

            self::assertNotSame('', $text);
            $explanations[] = $text;
        }

        self::assertSame(
            count($explanations),
            count(array_unique($explanations)),
            'two reasons produce the same sentence, so they cannot be told apart'
        );
    }

    /**
     * The MP4 Fallback explanation must mention that turning the setting on
     * does not fix existing videos. That is the part people lose an afternoon
     * to: the dashboard says it is on, and the old video still has no file.
     */
    public function testTheFallbackReasonWarnsItIsNotRetroactive(): void
    {
        $text = Mp4Source::missing(Mp4Source::NO_FALLBACK)->explain();

        self::assertStringContainsString('MP4 Fallback', $text);
        self::assertStringContainsString('retroactive', $text);
    }

    /** The height-cap reason names the setting to change. */
    public function testTheRenditionReasonNamesTheCap(): void
    {
        self::assertStringContainsString(
            'download height',
            Mp4Source::missing(Mp4Source::NO_RENDITION)->explain()
        );
    }

    /** An unrecognised reason still says something rather than nothing. */
    public function testAnUnknownReasonStillExplains(): void
    {
        self::assertNotSame('', Mp4Source::missing('something-else')->explain());
    }
}
