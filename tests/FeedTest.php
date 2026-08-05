<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\Feed;

/**
 * Feed XML.
 *
 * This is the one output of the application parsed by software nobody here
 * controls, and the failure mode is silent: a podcast client that cannot parse
 * a feed does not tell the site owner, it just stops updating. So every
 * assertion here is followed by an actual parse — asserting that a string
 * contains the right characters proves nothing about whether an XML parser will
 * accept the document around them.
 */
final class FeedTest extends TestCase
{
    // ---------------------------------------------------------------- escaping

    public function testAngleBracketsAndAmpersandsAreEscaped(): void
    {
        self::assertSame('&lt;b&gt;', Feed::escape('<b>'));
        self::assertSame('Rock &amp; Roll', Feed::escape('Rock & Roll'));
        self::assertSame('&quot;quoted&quot;', Feed::escape('"quoted"'));
        self::assertSame('&apos;', Feed::escape("'"));
    }

    /**
     * ENT_XML1, not the HTML default.
     *
     * The HTML entity set includes names an XML parser does not know, and one
     * &nbsp; in a description is enough to make a whole feed unparseable.
     */
    public function testANonBreakingSpaceDoesNotBecomeAnHtmlEntity(): void
    {
        self::assertStringNotContainsString('&nbsp;', Feed::escape("a\u{00A0}b"));
    }

    /**
     * Control characters are illegal in XML 1.0 and there is no escape that
     * makes them legal, so they are removed rather than encoded.
     */
    public function testIllegalControlCharactersAreStripped(): void
    {
        $escaped = Feed::escape("clean\x00\x08\x1Ftext");

        self::assertSame('cleantext', $escaped);
    }

    public function testTabsAndNewlinesSurvive(): void
    {
        self::assertSame("a\tb\nc", Feed::escape("a\tb\nc"));
    }

    // ------------------------------------------------------------------ dates

    public function testRssDatesUseTheFormatRssRequires(): void
    {
        $date = Feed::rssDate('2026-03-04 09:05:00');

        // RFC 822 with a four-digit year, which is what every directory checks.
        self::assertMatchesRegularExpression(
            '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/',
            $date
        );
    }

    /** A missing date must not produce an empty element clients cannot sort. */
    public function testAMissingDateFallsBackToNow(): void
    {
        self::assertNotSame('', Feed::rssDate(null));
        self::assertNotSame('', Feed::rssDate(''));
    }

    public function testAnUnparseableDateDoesNotThrow(): void
    {
        self::assertNotSame('', Feed::rssDate('the day before yesterday'));
    }

    public function testSitemapDatesAreIso8601(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            Feed::sitemapDate('2026-03-04 09:05:00')
        );
    }

    // -------------------------------------------------------------- durations

    public function testDurationsAreHoursMinutesSeconds(): void
    {
        self::assertSame('00:00:00', Feed::duration(0));
        self::assertSame('00:00:45', Feed::duration(45));
        self::assertSame('00:31:05', Feed::duration(1865));
        self::assertSame('01:00:00', Feed::duration(3600));
        self::assertSame('12:34:56', Feed::duration(45296));
    }

    public function testANullOrNegativeDurationIsZero(): void
    {
        self::assertSame('00:00:00', Feed::duration(null));
        self::assertSame('00:00:00', Feed::duration(-90));
    }

    // ------------------------------------------------------------ plain text

    public function testDescriptionsAreFlattenedToPlainText(): void
    {
        self::assertSame(
            'One two three',
            Feed::plainText("One\n  <b>two</b>\n\n  three")
        );
    }

    public function testAVeryLongDescriptionIsTruncated(): void
    {
        $text = Feed::plainText(str_repeat('word ', 2000), 100);

        self::assertLessThanOrEqual(101, mb_strlen($text));
    }

    // ------------------------------------------------------------------- rss

    public function testAFeedParsesAsXml(): void
    {
        $xml = Feed::rss($this->channel(), [Feed::item($this->itemData())]);

        self::assertNotFalse(
            simplexml_load_string($xml),
            'The generated feed is not well-formed XML.'
        );
    }

    /**
     * The single most common reason a podcast submission is rejected.
     */
    public function testTheChannelDeclaresItsOwnAddress(): void
    {
        $xml = Feed::rss($this->channel(), []);
        $parsed = simplexml_load_string($xml);

        self::assertNotFalse($parsed);

        $atom = $parsed->channel->children('http://www.w3.org/2005/Atom');
        self::assertSame('https://example.test/feed', (string) $atom->link->attributes()['href']);
    }

    /**
     * Hostile input, parsed rather than pattern-matched.
     *
     * A title containing a closing tag is exactly what breaks a hand-written
     * generator, and asserting on substrings would not notice.
     */
    public function testATitleContainingMarkupDoesNotBreakTheDocument(): void
    {
        $item = $this->itemData();
        $item['title'] = '</title></item></channel></rss><script>alert(1)</script>';

        $xml = Feed::rss($this->channel(), [Feed::item($item)]);
        $parsed = simplexml_load_string($xml);

        self::assertNotFalse($parsed, 'A crafted title broke the feed.');
        self::assertCount(1, $parsed->channel->item);
        self::assertSame($item['title'], (string) $parsed->channel->item[0]->title);
    }

    public function testAChannelTitleContainingAnAmpersandSurvives(): void
    {
        $channel = $this->channel();
        $channel['title'] = 'Rock & Roll <Church>';

        $parsed = simplexml_load_string(Feed::rss($channel, []));

        self::assertNotFalse($parsed);
        self::assertSame('Rock & Roll <Church>', (string) $parsed->channel->title);
    }

    public function testAnItemCarriesItsLinkGuidAndDate(): void
    {
        $parsed = simplexml_load_string(Feed::rss($this->channel(), [Feed::item($this->itemData())]));

        self::assertNotFalse($parsed);

        $item = $parsed->channel->item[0];
        self::assertSame('https://example.test/watch/a-video', (string) $item->link);
        self::assertSame('https://example.test/watch/id/7', (string) $item->guid);
        self::assertSame('false', (string) $item->guid->attributes()['isPermaLink']);
        self::assertNotSame('', (string) $item->pubDate);
    }

    /** An RSS feed is not a podcast: no enclosure unless one was asked for. */
    public function testAPlainItemHasNoEnclosure(): void
    {
        $parsed = simplexml_load_string(Feed::rss($this->channel(), [Feed::item($this->itemData())]));

        self::assertNotFalse($parsed);
        self::assertCount(0, $parsed->channel->item[0]->enclosure);
    }

    // --------------------------------------------------------------- podcast

    public function testAPodcastItemCarriesAnEnclosureAndADuration(): void
    {
        $item = $this->itemData();
        $item['enclosureUrl'] = 'https://example.test/media/a-video.mp4';
        $item['duration'] = 1865;

        [$namespaces, $channelXml] = Feed::itunesChannel($this->show());

        $xml = Feed::rss($this->channel(), [Feed::item($item, podcast: true)], $namespaces, $channelXml);
        $parsed = simplexml_load_string($xml);

        self::assertNotFalse($parsed, 'The podcast feed is not well-formed XML.');

        $enclosure = $parsed->channel->item[0]->enclosure->attributes();
        self::assertSame('https://example.test/media/a-video.mp4', (string) $enclosure['url']);
        self::assertSame('video/mp4', (string) $enclosure['type']);

        $itunes = $parsed->channel->item[0]->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        self::assertSame('00:31:05', (string) $itunes->duration);
    }

    public function testTheShowCarriesTheOwnerAppleRequires(): void
    {
        [$namespaces, $channelXml] = Feed::itunesChannel($this->show());
        $parsed = simplexml_load_string(Feed::rss($this->channel(), [], $namespaces, $channelXml));

        self::assertNotFalse($parsed);

        $itunes = $parsed->channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        self::assertSame('A Church', (string) $itunes->owner->name);
        self::assertSame('owner@example.test', (string) $itunes->owner->email);
        self::assertSame('false', (string) $itunes->explicit);
    }

    /**
     * The default category contains an ampersand, which is precisely the value
     * that gets double-escaped by a generator that is not careful.
     */
    public function testTheDefaultCategoryIsEscapedExactlyOnce(): void
    {
        [$namespaces, $channelXml] = Feed::itunesChannel($this->show());
        $parsed = simplexml_load_string(Feed::rss($this->channel(), [], $namespaces, $channelXml));

        self::assertNotFalse($parsed);

        $itunes = $parsed->channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        self::assertSame(
            'Religion & Spirituality',
            (string) $itunes->category->attributes()['text']
        );
    }

    // --------------------------------------------------------------- sitemap

    public function testASitemapParsesAndCarriesEveryUrl(): void
    {
        $xml = Feed::sitemap([
            ['loc' => 'https://example.test/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => 'https://example.test/watch/a-video', 'lastmod' => '2026-03-04 09:05:00'],
        ]);

        $parsed = simplexml_load_string($xml);

        self::assertNotFalse($parsed, 'The sitemap is not well-formed XML.');
        self::assertCount(2, $parsed->url);
        self::assertSame('https://example.test/', (string) $parsed->url[0]->loc);
        self::assertSame('daily', (string) $parsed->url[0]->changefreq);
        self::assertNotSame('', (string) $parsed->url[1]->lastmod);
    }

    public function testAnEmptySitemapIsStillValid(): void
    {
        self::assertNotFalse(simplexml_load_string(Feed::sitemap([])));
    }

    public function testASitemapUrlWithAQueryStringIsEscaped(): void
    {
        $parsed = simplexml_load_string(Feed::sitemap([
            ['loc' => 'https://example.test/search?q=a&b=c'],
        ]));

        self::assertNotFalse($parsed);
        self::assertSame('https://example.test/search?q=a&b=c', (string) $parsed->url[0]->loc);
    }

    // -------------------------------------------------------------- fixtures

    /** @return array<string, string> */
    private function channel(): array
    {
        return [
            'title'       => 'A Church',
            'link'        => 'https://example.test/',
            'selfLink'    => 'https://example.test/feed',
            'description' => 'Sermons and talks.',
        ];
    }

    /** @return array<string, mixed> */
    private function itemData(): array
    {
        return [
            'title'       => 'A video',
            'link'        => 'https://example.test/watch/a-video',
            'guid'        => 'https://example.test/watch/id/7',
            'description' => 'What it is about.',
            'pubDate'     => '2026-03-04 09:05:00',
        ];
    }

    /** @return array<string, mixed> */
    private function show(): array
    {
        return [
            'author'     => 'A Church',
            'ownerName'  => 'A Church',
            'ownerEmail' => 'owner@example.test',
            'summary'    => 'Sermons and talks.',
        ];
    }
}
