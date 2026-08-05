<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Building RSS, podcast, and sitemap XML.
 *
 * Pure: strings in, a string out. No database, no request, no provider. Feed
 * XML is the one output of this application that is parsed by software nobody
 * here controls, and the failure mode is silent — a podcast client that cannot
 * parse a feed does not report an error to the site owner, it simply stops
 * updating. That makes the escaping and date formatting worth testing
 * exhaustively rather than eyeballing in a browser.
 *
 * Hand-written rather than built with DOMDocument. The ext-dom requirement
 * would be a new one on a shared host, the output is a fixed shape, and every
 * value goes through one escaper that is easy to audit.
 */
final class Feed
{
    /**
     * Escape a value for XML text or an attribute.
     *
     * htmlspecialchars with ENT_XML1 rather than the HTML default: the HTML
     * entity set includes names an XML parser does not know, and one &nbsp; in
     * a description is enough to make a whole feed unparseable.
     *
     * Characters XML 1.0 forbids outright — most control codes — are stripped
     * rather than escaped, because there is no escape that makes them legal.
     */
    public static function escape(string $value): string
    {
        $value = (string) preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * A date in the format RSS requires.
     *
     * RFC 822 with a four-digit year, which is what RSS 2.0 asks for and what
     * every podcast directory validates against. An unparseable date is not a
     * cosmetic problem: clients sort by it, and a feed full of dates they
     * cannot read sorts arbitrarily.
     */
    public static function rssDate(?string $timestamp): string
    {
        try {
            $date = $timestamp === null || $timestamp === ''
                ? new \DateTimeImmutable()
                : new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            $date = new \DateTimeImmutable();
        }

        return $date->format(\DateTimeInterface::RSS);
    }

    /** A date in the format a sitemap requires (W3C / ISO 8601). */
    public static function sitemapDate(?string $timestamp): string
    {
        try {
            $date = $timestamp === null || $timestamp === ''
                ? new \DateTimeImmutable()
                : new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            $date = new \DateTimeImmutable();
        }

        return $date->format('Y-m-d\TH:i:sP');
    }

    /**
     * Seconds as HH:MM:SS, which is what iTunes wants for a duration.
     *
     * A plain number of seconds is also legal, but several clients get it
     * wrong, and the explicit form is never ambiguous.
     */
    public static function duration(?int $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }

    /**
     * A description reduced to plain text.
     *
     * Descriptions are written by editors and may contain newlines, but a feed
     * item is not the place to try to preserve formatting: some clients render
     * HTML, some show the tags, and none of them agree. One paragraph of plain
     * text reads correctly everywhere.
     */
    public static function plainText(?string $value, int $maxLength = 4000): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return Str::truncate($text, $maxLength);
    }

    /**
     * An RSS 2.0 channel.
     *
     * @param array{title: string, link: string, selfLink: string, description: string,
     *              language?: string, imageUrl?: ?string} $channel
     * @param list<string> $items already-rendered <item> elements
     * @param string $extraNamespaces attributes for the <rss> element
     * @param string $extraChannelXml channel-level elements, already rendered
     */
    public static function rss(
        array $channel,
        array $items,
        string $extraNamespaces = '',
        string $extraChannelXml = ''
    ): string {
        $title = self::escape($channel['title']);
        $link = self::escape($channel['link']);
        $self = self::escape($channel['selfLink']);
        $description = self::escape($channel['description']);
        $language = self::escape($channel['language'] ?? 'en');
        $now = self::rssDate(null);

        $image = '';
        if (!empty($channel['imageUrl'])) {
            $url = self::escape((string) $channel['imageUrl']);
            $image = <<<XML
                <image>
                  <url>{$url}</url>
                  <title>{$title}</title>
                  <link>{$link}</link>
                </image>
            XML;
        }

        $body = implode("\n", $items);

        /*
         * atom:link rel="self" is not optional in practice. Podcast directories
         * use it to detect a feed that has moved, and its absence is the most
         * common reason a submission is rejected.
         */
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"{$extraNamespaces}>
          <channel>
            <title>{$title}</title>
            <link>{$link}</link>
            <description>{$description}</description>
            <language>{$language}</language>
            <lastBuildDate>{$now}</lastBuildDate>
            <atom:link href="{$self}" rel="self" type="application/rss+xml"/>
        {$image}{$extraChannelXml}
        {$body}
          </channel>
        </rss>
        XML;
    }

    /**
     * One <item>.
     *
     * @param array{title: string, link: string, guid: string, description: string,
     *              pubDate: ?string, enclosureUrl?: ?string, enclosureType?: string,
     *              enclosureLength?: int, duration?: ?int, imageUrl?: ?string,
     *              author?: ?string} $item
     */
    public static function item(array $item, bool $podcast = false): string
    {
        $title = self::escape($item['title']);
        $link = self::escape($item['link']);
        $description = self::escape(self::plainText($item['description'] ?? ''));
        $date = self::rssDate($item['pubDate'] ?? null);

        /*
         * isPermaLink="false" because the guid is the watch URL and a client
         * must not treat it as a second address to fetch. Podcast clients key
         * their "already downloaded" state on this value, so it has to be
         * stable across renames — which is why it is built from the id rather
         * than from the slug.
         */
        $guid = self::escape($item['guid']);

        $enclosure = '';
        if (!empty($item['enclosureUrl'])) {
            $enclosure = sprintf(
                '    <enclosure url="%s" type="%s" length="%d"/>' . "\n",
                self::escape((string) $item['enclosureUrl']),
                self::escape((string) ($item['enclosureType'] ?? 'video/mp4')),
                max(0, (int) ($item['enclosureLength'] ?? 0))
            );
        }

        $extra = '';
        if ($podcast) {
            $extra .= sprintf(
                '    <itunes:duration>%s</itunes:duration>' . "\n",
                self::duration($item['duration'] ?? 0)
            );
            $extra .= sprintf(
                '    <itunes:summary>%s</itunes:summary>' . "\n",
                $description
            );
            if (!empty($item['author'])) {
                $extra .= sprintf(
                    '    <itunes:author>%s</itunes:author>' . "\n",
                    self::escape((string) $item['author'])
                );
            }
            if (!empty($item['imageUrl'])) {
                $extra .= sprintf(
                    '    <itunes:image href="%s"/>' . "\n",
                    self::escape((string) $item['imageUrl'])
                );
            }
        }

        return <<<XML
            <item>
                <title>{$title}</title>
                <link>{$link}</link>
                <guid isPermaLink="false">{$guid}</guid>
                <description>{$description}</description>
                <pubDate>{$date}</pubDate>
            {$enclosure}{$extra}    </item>
        XML;
    }

    /**
     * The iTunes namespace attribute and channel block.
     *
     * @param array{author: string, ownerName: string, ownerEmail: string,
     *              summary: string, imageUrl?: ?string, explicit?: bool,
     *              category?: string} $show
     * @return array{0: string, 1: string} namespaces, channel XML
     */
    public static function itunesChannel(array $show): array
    {
        $namespaces = ' xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"';

        $author = self::escape($show['author']);
        $ownerName = self::escape($show['ownerName']);
        $ownerEmail = self::escape($show['ownerEmail']);
        $summary = self::escape(self::plainText($show['summary'] ?? ''));
        $explicit = !empty($show['explicit']) ? 'true' : 'false';
        // Escaped once, here. Writing the entity into the default would produce
        // "&amp;amp;" after escaping, which renders as literal "&amp;" in every
        // directory listing.
        $category = self::escape($show['category'] ?? 'Religion & Spirituality');

        $image = '';
        if (!empty($show['imageUrl'])) {
            $image = sprintf(
                '    <itunes:image href="%s"/>' . "\n",
                self::escape((string) $show['imageUrl'])
            );
        }

        $xml = <<<XML

            <itunes:author>{$author}</itunes:author>
            <itunes:summary>{$summary}</itunes:summary>
            <itunes:explicit>{$explicit}</itunes:explicit>
            <itunes:category text="{$category}"/>
            <itunes:owner>
              <itunes:name>{$ownerName}</itunes:name>
              <itunes:email>{$ownerEmail}</itunes:email>
            </itunes:owner>
        {$image}
        XML;

        return [$namespaces, $xml];
    }

    /**
     * A sitemap.
     *
     * @param list<array{loc: string, lastmod?: ?string, changefreq?: string, priority?: string}> $urls
     */
    public static function sitemap(array $urls): string
    {
        $entries = '';

        foreach ($urls as $url) {
            $loc = self::escape($url['loc']);
            $lastmod = isset($url['lastmod']) && $url['lastmod'] !== null
                ? '    <lastmod>' . self::sitemapDate($url['lastmod']) . "</lastmod>\n"
                : '';
            $changefreq = isset($url['changefreq'])
                ? '    <changefreq>' . self::escape($url['changefreq']) . "</changefreq>\n"
                : '';
            $priority = isset($url['priority'])
                ? '    <priority>' . self::escape($url['priority']) . "</priority>\n"
                : '';

            $entries .= "  <url>\n    <loc>{$loc}</loc>\n{$lastmod}{$changefreq}{$priority}  </url>\n";
        }

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
        {$entries}</urlset>
        XML;
    }
}
