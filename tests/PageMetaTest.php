<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\PageMeta;

/**
 * What a link to a page looks like when somebody pastes it somewhere.
 *
 * The rule worth pinning hardest is the one about images: `og:image` is fetched
 * by a stranger's server with no session and cached there, so a thumbnail
 * withheld from a signed-out visitor must never appear in a card. There is
 * deliberately no fallback to a site logo, because a fallback is how the leak
 * comes back the next time somebody tidies this up.
 */
final class PageMetaTest extends TestCase
{
    public function testAVideoCardCarriesWhatItWasGiven(): void
    {
        $meta = PageMeta::forVideo(
            'Sunday Morning',
            'A sermon on Romans 8.',
            'https://cdn.test/thumb.jpg?token=x',
            'https://example.test/watch/sunday'
        );

        self::assertSame('Sunday Morning', $meta->title);
        self::assertSame('A sermon on Romans 8.', $meta->description);
        self::assertSame('video.other', $meta->type);
        self::assertSame('https://cdn.test/thumb.jpg?token=x', $meta->imageUrl);
    }

    /**
     * No image means NO IMAGE. Not a logo, not a placeholder.
     *
     * The caller passes null for a members-only video, and anything invented
     * here would hand the withheld frame to whoever unfurled the link.
     */
    public function testAVideoWithNoImageGetsNoImage(): void
    {
        $meta = PageMeta::forVideo('Locked', 'Members only.', null, 'https://example.test/watch/locked');

        self::assertNull($meta->imageUrl);
        self::assertArrayNotHasKey('thumbnailUrl', (array) $meta->structured);
    }

    /**
     * And the card type follows, because `summary_large_image` with no image
     * renders as an empty grey box in some clients — worse than the small card
     * it replaced.
     */
    public function testTheCardTypeFollowsWhetherThereIsAnImage(): void
    {
        self::assertSame(
            'summary',
            PageMeta::forVideo('a', '', null, 'https://example.test/x')->twitterCard()
        );
        self::assertSame(
            'summary_large_image',
            PageMeta::forVideo('a', '', 'https://cdn.test/i.jpg', 'https://example.test/x')->twitterCard()
        );
    }

    /**
     * Structured data asserts only what is known.
     *
     * A block claiming an empty description or a zero duration is worse than a
     * shorter one, because a consumer believes it.
     */
    public function testStructuredDataOmitsWhatIsNotKnown(): void
    {
        $meta = PageMeta::forVideo('Just a title', '', null, 'https://example.test/watch/x');
        $structured = (array) $meta->structured;

        self::assertSame('VideoObject', $structured['@type']);
        self::assertSame('Just a title', $structured['name']);
        self::assertArrayNotHasKey('description', $structured);
        self::assertArrayNotHasKey('duration', $structured);
        self::assertArrayNotHasKey('uploadDate', $structured);
    }

    public function testStructuredDataCarriesADurationWhenThereIsOne(): void
    {
        $meta = PageMeta::forVideo('x', '', null, 'https://example.test/x', '2026-09-03 10:00:00', 3725);

        self::assertSame('PT1H2M5S', ((array) $meta->structured)['duration']);
    }

    // ------------------------------------------------------------ durations

    public function testDurationsAreIso8601(): void
    {
        self::assertSame('PT45S', PageMeta::iso8601Duration(45));
        self::assertSame('PT2M', PageMeta::iso8601Duration(120));
        self::assertSame('PT1H', PageMeta::iso8601Duration(3600));
        self::assertSame('PT1H30M', PageMeta::iso8601Duration(5400));
    }

    /** Zero is still a duration, and PT alone is not valid. */
    public function testAZeroDurationIsStillWellFormed(): void
    {
        self::assertSame('PT0S', PageMeta::iso8601Duration(0));
        self::assertSame('PT0S', PageMeta::iso8601Duration(-10));
    }

    // ---------------------------------------------------------- breadcrumbs

    /**
     * A single-item trail is not a trail. Emitting one gives a search engine a
     * list that says only "here", which is noise rather than structure.
     */
    public function testASingleCrumbProducesNoBreadcrumbList(): void
    {
        $meta = PageMeta::page('Home', '', 'https://example.test/', [
            ['name' => 'Library', 'url' => 'https://example.test/'],
        ]);

        self::assertNull($meta->breadcrumbList());
    }

    public function testATrailIsNumberedFromOne(): void
    {
        $meta = PageMeta::page('Romans', '', 'https://example.test/category/romans', [
            ['name' => 'Library', 'url' => 'https://example.test/'],
            ['name' => 'Romans', 'url' => 'https://example.test/category/romans'],
        ]);

        $list = $meta->breadcrumbList();

        self::assertNotNull($list);
        self::assertSame('BreadcrumbList', $list['@type']);
        self::assertSame(1, $list['itemListElement'][0]['position']);
        self::assertSame(2, $list['itemListElement'][1]['position']);
        self::assertSame('Romans', $list['itemListElement'][1]['name']);
    }

    // ----------------------------------------------------------- summarising

    public function testMarkupIsStrippedBeforeTruncating(): void
    {
        // Cutting inside a tag leaves a fragment a consumer either renders as
        // text or tries to parse, so the tags go first.
        self::assertSame(
            'Hello there',
            PageMeta::summarise('<p>Hello <strong>there</strong></p>')
        );
    }

    public function testEntitiesAreDecodedSoTheyCountAsOneCharacter(): void
    {
        self::assertSame('Fish & chips', PageMeta::summarise('Fish &amp; chips'));
    }

    public function testWhitespaceIsCollapsed(): void
    {
        self::assertSame('One two three', PageMeta::summarise("One\n  two\t three"));
    }

    public function testLongTextIsCutAtAWordBoundary(): void
    {
        $text = str_repeat('word ', 100);
        $summary = PageMeta::summarise($text, 50);

        self::assertLessThanOrEqual(51, mb_strlen($summary));
        self::assertStringEndsWith('…', $summary);
        self::assertStringNotContainsString('wor…', $summary, 'it cut mid-word');
    }

    /**
     * A long run with no spaces still has to be cut somewhere. Hunting for a
     * word boundary that does not exist must not throw most of it away.
     */
    public function testTextWithNoSpacesIsStillTruncated(): void
    {
        $summary = PageMeta::summarise(str_repeat('a', 300), 50);

        self::assertLessThanOrEqual(51, mb_strlen($summary));
        self::assertGreaterThan(40, mb_strlen($summary));
    }

    public function testShortTextIsLeftAlone(): void
    {
        self::assertSame('Short.', PageMeta::summarise('Short.'));
        self::assertSame('', PageMeta::summarise('   '));
    }
}
