<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Video\EmbedResolver;
use Portal\Video\ExternalVideo;

/**
 * Reading a pasted address.
 *
 * The refusals matter more than the successes here. This string ends up as the
 * `src` of an iframe, so anything that gets through and should not is a page
 * embedding somebody else's content — and the failure is invisible, because an
 * embed of the wrong video looks exactly like an embed of the right one to
 * everybody except the person who knows which sermon it should have been.
 */
final class ExternalVideoTest extends TestCase
{
    // ------------------------------------------------------- it reads them

    /**
     * Every shape either service actually hands somebody.
     *
     * People paste what they were given, and refusing a form because it came
     * from the Share button rather than the address bar is a refusal they
     * cannot act on.
     */
    public function testItReadsEveryShapeYouTubeHandsOut(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abcdef',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/live/dQw4w9WgXcQ',
            'https://www.youtube.com/v/dQw4w9WgXcQ',
        ] as $url) {
            $parsed = ExternalVideo::parse($url);

            self::assertNotNull($parsed, "refused {$url}");
            self::assertSame(ExternalVideo::YOUTUBE, $parsed->source, $url);
            self::assertSame('dQw4w9WgXcQ', $parsed->id, $url);
        }
    }

    public function testItReadsEveryShapeVimeoHandsOut(): void
    {
        foreach ([
            'https://vimeo.com/123456789',
            'https://player.vimeo.com/video/123456789',
            // The unlisted-link form, where the trailing hash is not the video.
            'https://vimeo.com/123456789/a1b2c3d4e5',
            'https://vimeo.com/channels/staffpicks/123456789',
        ] as $url) {
            $parsed = ExternalVideo::parse($url);

            self::assertNotNull($parsed, "refused {$url}");
            self::assertSame(ExternalVideo::VIMEO, $parsed->source, $url);
            self::assertSame('123456789', $parsed->id, $url);
        }
    }

    /** A scheme-less paste is what comes out of a chat message. */
    public function testAnAddressWithNoSchemeIsStillRead(): void
    {
        self::assertSame('dQw4w9WgXcQ', ExternalVideo::parse('youtu.be/dQw4w9WgXcQ')?->id);
        self::assertSame('123456789', ExternalVideo::parse('vimeo.com/123456789')?->id);
    }

    // ---------------------------------------------------------- it refuses

    /**
     * THE RULE: only these two hosts.
     *
     * The parsed id goes into an iframe src. A host check that let anything
     * through would make this a way to embed an arbitrary page in the site's
     * own player frame, which is where a watermark overlay and a members-only
     * badge are drawn.
     */
    public function testAnAddressAtAnotherHostIsRefused(): void
    {
        foreach ([
            'https://example.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
            'https://notyoutube.com/watch?v=dQw4w9WgXcQ',
            'https://evil.test/embed/dQw4w9WgXcQ',
        ] as $url) {
            self::assertNull(ExternalVideo::parse($url), "accepted {$url}");
        }
    }

    /**
     * A bare id is refused, and that is deliberate rather than an oversight.
     *
     * Eleven characters could be anything, and guessing which service a naked
     * string belongs to is how a typo becomes a video pointing at content
     * nobody chose.
     */
    public function testABareIdIsRefused(): void
    {
        self::assertNull(ExternalVideo::parse('dQw4w9WgXcQ'));
        self::assertNull(ExternalVideo::parse('123456789'));
    }

    /** A YouTube id is exactly eleven base64url characters or it is not one. */
    public function testAMalformedYouTubeIdIsRefused(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=short',
            'https://www.youtube.com/watch?v=waaaaaaytoolongforanid',
            'https://www.youtube.com/watch?v=has spaces',
            'https://www.youtube.com/watch?v=has/slash',
            'https://www.youtube.com/watch?v=',
            'https://www.youtube.com/watch',
        ] as $url) {
            self::assertNull(ExternalVideo::parse($url), "accepted {$url}");
        }
    }

    public function testAMalformedVimeoIdIsRefused(): void
    {
        foreach ([
            'https://vimeo.com/',
            'https://vimeo.com/notanumber',
            'https://vimeo.com/12',
            'https://vimeo.com/channels/staffpicks',
        ] as $url) {
            self::assertNull(ExternalVideo::parse($url), "accepted {$url}");
        }
    }

    public function testRubbishIsRefusedRatherThanCrashing(): void
    {
        foreach (['', '   ', 'not a url at all', 'javascript:alert(1)', str_repeat('a', 3000)] as $url) {
            self::assertNull(ExternalVideo::parse($url));
        }
    }

    // ----------------------------------------------------------- addresses

    /**
     * The privacy-preserving players, and that is not decoration.
     *
     * This product is often a church's only web presence and has no cookie
     * banner because it has never needed one. youtube-nocookie sets no
     * advertising cookie until somebody presses play; Vimeo's dnt=1 is the
     * same promise.
     */
    public function testTheEmbedUsesThePrivacyPreservingPlayers(): void
    {
        $youtube = ExternalVideo::parse('https://youtu.be/dQw4w9WgXcQ')?->embedUrl() ?? '';
        self::assertStringStartsWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $youtube);
        self::assertStringNotContainsString('//www.youtube.com/embed', $youtube);

        $vimeo = ExternalVideo::parse('https://vimeo.com/123456789')?->embedUrl() ?? '';
        self::assertStringStartsWith('https://player.vimeo.com/video/123456789', $vimeo);
        self::assertStringContainsString('dnt=1', $vimeo);
    }

    public function testTheOEmbedAddressPointsAtThePublicEndpoint(): void
    {
        $youtube = ExternalVideo::parse('https://youtu.be/dQw4w9WgXcQ')?->oEmbedUrl() ?? '';
        self::assertStringStartsWith('https://www.youtube.com/oembed?', $youtube);

        $vimeo = ExternalVideo::parse('https://vimeo.com/123456789')?->oEmbedUrl() ?? '';
        self::assertStringStartsWith('https://vimeo.com/api/oembed.json?', $vimeo);
    }

    // ------------------------------------------------------- the resolver

    /**
     * A stored row rebuilds the same address the paste produced.
     *
     * The pasted URL is not kept — the source and id ARE the parse result — so
     * this is what proves a row can be turned back into a player without it.
     */
    public function testAStoredSourceAndIdRebuildTheEmbed(): void
    {
        self::assertSame(
            ExternalVideo::parse('https://youtu.be/dQw4w9WgXcQ')?->embedUrl(),
            EmbedResolver::externalEmbed(ExternalVideo::YOUTUBE, 'dQw4w9WgXcQ')
        );

        self::assertSame(
            ExternalVideo::parse('https://vimeo.com/123456789')?->embedUrl(),
            EmbedResolver::externalEmbed(ExternalVideo::VIMEO, '123456789')
        );
    }

    /**
     * An id that no longer parses gives nothing rather than half an address.
     *
     * A row edited by hand, or written by a build that knew a source this one
     * does not. Empty is what every template already treats as "no player",
     * because that is what a premiere looks like — where a malformed embed URL
     * would be an iframe pointing somewhere unintended.
     */
    public function testAnUnknownSourceOrBrokenIdGivesNoAddress(): void
    {
        self::assertSame('', EmbedResolver::externalEmbed('dailymotion', 'x8abcde'));
        self::assertSame('', EmbedResolver::externalEmbed(ExternalVideo::YOUTUBE, 'not-an-id'));
        self::assertSame('', EmbedResolver::externalEmbed(ExternalVideo::VIMEO, 'nope'));
        self::assertSame('', EmbedResolver::externalEmbed(ExternalVideo::YOUTUBE, ''));
    }

    public function testIsExternalKnowsTheTwoAndNothingElse(): void
    {
        self::assertTrue(ExternalVideo::isExternal(ExternalVideo::YOUTUBE));
        self::assertTrue(ExternalVideo::isExternal(ExternalVideo::VIMEO));
        self::assertFalse(ExternalVideo::isExternal('bunny'));
        self::assertFalse(ExternalVideo::isExternal(''));
    }
}
