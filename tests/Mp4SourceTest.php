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

    // ----------------------------------------------------- what the CDN said

    /**
     * The fifth situation, which the four reasons above cannot cover.
     *
     * Everything looks right here, the URL is signed and handed over, and the
     * CDN refuses it — to the BROWSER, so this site never sees it. 403 and 404
     * mean opposite things and are indistinguishable from the outside: one is
     * the wrong pull-zone key, the other is a file that was never encoded.
     */
    public function testARejectedSignatureNamesTheKeyAndSaysItIsADifferentOne(): void
    {
        $text = Mp4Source::diagnose(403, null);

        self::assertStringContainsString('403', $text);
        self::assertStringContainsString('pull zone', $text);
        self::assertStringContainsString(
            'DIFFERENT',
            $text,
            'pasting the playback key into both fields is the usual cause, so the message has to say so'
        );
    }

    public function testAMissingFileIsNotReportedAsACredentialsProblem(): void
    {
        $text = Mp4Source::diagnose(404, null);

        self::assertStringContainsString('404', $text);
        self::assertStringNotContainsString(
            'key',
            $text,
            'a 404 means the signature was accepted — sending somebody to re-enter a key is the wrong afternoon'
        );
    }

    public function testSuccessSaysSoPlainly(): void
    {
        self::assertStringContainsString('work', Mp4Source::diagnose(206, null));
        self::assertStringContainsString('work', Mp4Source::diagnose(200, null));
    }

    /** Unreachable is not a verdict about the file, and must not read as one. */
    public function testATransportFailureIsNotReportedAsARefusal(): void
    {
        $text = Mp4Source::diagnose(0, 'could not resolve host');

        self::assertStringContainsString('could not resolve host', $text);
        self::assertStringContainsString('rather than a setting', $text);
    }

    /** Each status leads somewhere different. */
    public function testTheDiagnosesAreAllDistinct(): void
    {
        $texts = [
            Mp4Source::diagnose(206, null),
            Mp4Source::diagnose(401, null),
            Mp4Source::diagnose(403, null),
            Mp4Source::diagnose(404, null),
            Mp4Source::diagnose(500, null),
        ];

        self::assertSame(count($texts), count(array_unique($texts)));
    }
}
