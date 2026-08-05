<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Content\CategoryRepository;
use Portal\Content\PlaylistRepository;
use Portal\Content\SeriesRepository;
use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Feed;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * RSS, podcast feeds, and the sitemap.
 *
 * # Public content only, without exception
 *
 * Every other listing in this application decides visibility from who is
 * asking. A feed has no reader — it is fetched by a podcast client with no
 * session, cached by an aggregator, and re-served to strangers. So these
 * methods do not consult the guard at all: they ask for public content, and an
 * administrator fetching /feed sees exactly what an anonymous visitor sees.
 * Reusing visibilityFilters() here would mean an admin's browser could warm a
 * shared cache with members-only titles.
 *
 * # Why enclosures point back at this site
 *
 * A podcast enclosure has to be a file, and the provider's file URLs are signed
 * and short-lived. A feed is read once and acted on hours later, so a signed
 * URL written into it is a broken episode by the time anybody downloads it.
 *
 * The enclosure therefore points at /media/{slug}.mp4 here, which re-checks
 * that the video is still public and then redirects to a URL signed on the
 * spot. The expiry problem disappears, and — the part worth more — unpublishing
 * a video actually withdraws it. A signed URL already handed out cannot be
 * recalled; a redirect can start refusing.
 */
final class FeedController extends Controller
{
    /** How many items a feed carries. Enough for a client's first sync. */
    private const ITEMS = 50;

    /** Long enough that the signed URL survives a slow download, short enough to matter. */
    private const DOWNLOAD_TTL = 3600;

    // ------------------------------------------------------------------- RSS

    /** @param array<string, string> $params */
    public function rss(Request $request, array $params = []): Response
    {
        return $this->feed($request, $params, podcast: false);
    }

    /** @param array<string, string> $params */
    public function podcast(Request $request, array $params = []): Response
    {
        return $this->feed($request, $params, podcast: true);
    }

    /**
     * Both feeds, which differ only in whether they carry enclosures and the
     * iTunes block. One method, because two would drift and the visibility
     * rules are the part that must not.
     */
    /** @param array<string, string> $params */
    private function feed(Request $request, array $params, bool $podcast): Response
    {
        [$scopeTitle, $scopeDescription, $videos] = $this->scope($params);

        $siteName = (string) $this->config()->setting('site_name', 'Video Portal');
        $title = $scopeTitle === '' ? $siteName : $siteName . ' — ' . $scopeTitle;

        $items = [];
        foreach ($videos as $video) {
            $items[] = Feed::item($this->itemData($video, $podcast), $podcast);
        }

        $namespaces = '';
        $channelXml = '';

        if ($podcast) {
            [$namespaces, $channelXml] = Feed::itunesChannel([
                'author'     => (string) $this->config()->setting('podcast_author', $siteName),
                'ownerName'  => (string) $this->config()->setting('podcast_owner_name', $siteName),
                'ownerEmail' => (string) $this->config()->setting('podcast_owner_email', ''),
                'summary'    => $scopeDescription !== ''
                    ? $scopeDescription
                    : (string) $this->config()->setting('site_description', $siteName),
                'imageUrl'   => $this->config()->setting('podcast_image_url') ?: null,
                'explicit'   => $this->config()->settingBool('podcast_explicit', false),
                'category'   => (string) $this->config()->setting('podcast_category', 'Religion & Spirituality'),
            ]);
        }

        $xml = Feed::rss(
            [
                'title'       => $title,
                'link'        => $this->config()->url('/'),
                'selfLink'    => $this->config()->url($request->path),
                'description' => $scopeDescription !== ''
                    ? $scopeDescription
                    : (string) $this->config()->setting('site_description', $siteName),
            ],
            $items,
            $namespaces,
            $channelXml
        );

        return $this->xml($xml, 'application/rss+xml');
    }

    /**
     * Which videos this feed covers.
     *
     * @param  array<string, string> $params
     * @return array{0: string, 1: string, 2: list<Video>}
     */
    private function scope(array $params): array
    {
        $type = (string) ($params['type'] ?? '');
        $slug = (string) ($params['slug'] ?? '');

        // The public filter set, written once. Nothing here reads the guard.
        $filters = [];

        switch ($type) {
            case 'category':
                $category = $this->container->get(CategoryRepository::class)->findBySlug($slug);
                if ($category === null || !$category->isPublic()) {
                    throw HttpException::notFound('There is no feed at that address.');
                }
                $filters['categoryId'] = $category->id;

                return [
                    $category->name,
                    (string) ($category->description ?? ''),
                    $this->videos()->query($filters, 1, self::ITEMS)['items'],
                ];

            case 'series':
                $series = $this->container->get(SeriesRepository::class)->findBySlug($slug);
                if ($series === null || !$series->isPublic()) {
                    throw HttpException::notFound('There is no feed at that address.');
                }

                return [
                    $series->title,
                    (string) ($series->description ?? ''),
                    $this->videos()->forSeries($series->id),
                ];

            case 'playlist':
                /** @var PlaylistRepository $playlists */
                $playlists = $this->container->get(PlaylistRepository::class);
                $playlist = $playlists->findBySlug($slug);
                if ($playlist === null || !$playlist->isPublic()) {
                    throw HttpException::notFound('There is no feed at that address.');
                }

                return [
                    $playlist->title,
                    (string) ($playlist->description ?? ''),
                    $playlists->videos($playlist->id),
                ];

            default:
                return ['', '', $this->videos()->query($filters, 1, self::ITEMS)['items']];
        }
    }

    /** @return array<string, mixed> */
    private function itemData(Video $video, bool $podcast): array
    {
        $data = [
            'title'       => $video->title,
            'link'        => $this->config()->url($video->url()),
            /*
             * Built from the id, not the slug. Podcast clients key their
             * "already downloaded" state on the guid, so a rename must not
             * make every past episode reappear as new.
             */
            'guid'        => $this->config()->url('/watch/id/' . $video->id),
            'description' => (string) ($video->description ?? ''),
            // Whichever date is the most honest answer to "when was this
            // published", falling back through what the provider knows.
            'pubDate'     => $video->publishedAt ?? $video->recordedAt ?? $video->providerCreatedAt,
            'duration'    => $video->duration,
        ];

        if ($podcast) {
            $data['enclosureUrl'] = $this->config()->url('/media/' . $video->slug . '.mp4');
            $data['enclosureType'] = 'video/mp4';
            /*
             * length is required by the spec and we do not know it without
             * asking the provider per video — one HTTP round trip each, on a
             * page fetched by robots. Zero is what every client tolerates and
             * what a directory reads as "unknown"; a wrong number is worse,
             * because some clients truncate the download at it.
             */
            $data['enclosureLength'] = 0;
        }

        return $data;
    }

    // ----------------------------------------------------------------- media

    /**
     * Redirect to a freshly signed media URL.
     *
     * The indirection the whole podcast feature rests on. Three things happen
     * here that could not happen in a static enclosure URL: the video's
     * visibility is re-checked at download time, the signature is minted now
     * rather than whenever the feed was built, and a video that has been
     * unpublished stops being downloadable.
     *
     * @param array<string, string> $params
     */
    public function media(Request $request, array $params): Response
    {
        $video = $this->videos()->findBySlug((string) ($params['slug'] ?? ''));

        /*
         * A 404 rather than a 403, matching /watch. Telling an anonymous
         * client that a video exists but is private is itself a leak, and a
         * podcast client does nothing useful with either.
         */
        if ($video === null || !$video->isVisible() || $video->memberOnly) {
            throw HttpException::notFound('There is no media at that address.');
        }

        try {
            /** @var VideoProvider $provider */
            $provider = $this->container->get(VideoProvider::class);
            $url = $provider->downloadUrl($video->providerId, self::DOWNLOAD_TTL);
        } catch (Throwable $e) {
            throw HttpException::upstream('The video service is not responding: ' . $e->getMessage());
        }

        if ($url === null) {
            throw HttpException::notFound('Downloads are not configured for this site.');
        }

        /*
         * 302, not 301. A permanent redirect would be cached by the client and
         * by every proxy between here and it, which is precisely the expiry
         * problem this route exists to avoid.
         */
        return Response::redirect($url, 302)
            ->header('Cache-Control', 'private, max-age=0, no-store');
    }

    // --------------------------------------------------------------- sitemap

    /**
     * Everything a search engine should know about.
     *
     * Public content only, for the same reason as the feeds and with a sharper
     * edge: a sitemap is an invitation to crawl, so a members-only URL listed
     * here is one that gets fetched, indexed, and shown in results as a title
     * with no page behind it.
     */
    public function sitemap(Request $request): Response
    {
        /*
         * Off unless the owner turned indexing on.
         *
         * The theme has always sent noindex, and shipping a sitemap route
         * without this check would quietly contradict it — one file inviting
         * crawlers, every page telling them to go away. A 404 is the honest
         * answer for a site that has not asked to be found.
         */
        if (!$this->config()->settingBool('allow_indexing', false)) {
            throw HttpException::notFound('This site is not listed in search engines.');
        }

        $urls = [[
            'loc'        => $this->config()->url('/'),
            'changefreq' => 'daily',
            'priority'   => '1.0',
        ]];

        foreach ($this->videos()->query([], 1, 500)['items'] as $video) {
            $urls[] = [
                'loc'     => $this->config()->url($video->url()),
                'lastmod' => $video->publishedAt ?? $video->recordedAt,
            ];
        }

        foreach ($this->container->get(CategoryRepository::class)->all() as $category) {
            if ($category->isPublic()) {
                $urls[] = ['loc' => $this->config()->url($category->url()), 'changefreq' => 'weekly'];
            }
        }

        foreach ($this->container->get(SeriesRepository::class)->all() as $series) {
            if ($series->isPublic()) {
                $urls[] = ['loc' => $this->config()->url($series->url()), 'changefreq' => 'weekly'];
            }
        }

        foreach ($this->container->get(PlaylistRepository::class)->all() as $playlist) {
            if ($playlist->isPublic()) {
                $urls[] = ['loc' => $this->config()->url($playlist->url()), 'changefreq' => 'weekly'];
            }
        }

        return $this->xml(Feed::sitemap($urls), 'application/xml');
    }

    /**
     * robots.txt, naming the sitemap.
     *
     * Served by PHP rather than shipped as a file because the sitemap URL
     * depends on BASE_URL, which is only known after installation. The admin
     * area is disallowed — not as a security measure, since it is behind auth
     * anyway, but because crawling it produces nothing but redirect chains.
     */
    public function robots(Request $request): Response
    {
        if (!$this->config()->settingBool('allow_indexing', false)) {
            /*
             * A blanket refusal, matching the noindex the pages send. Stated in
             * robots.txt as well as in the meta tag because the two are read at
             * different moments: robots.txt is fetched before anything else, so
             * a well-behaved crawler stops here and never sees a page.
             */
            return Response::text("User-agent: *\nDisallow: /\n");
        }

        $sitemap = $this->config()->url('/sitemap.xml');

        /*
         * Share and bundle addresses are excluded even when indexing is on.
         * They are unguessable by design, but a crawler that finds one — from a
         * referrer header, a pasted link in a public forum — would put a
         * private recipient's page in a search index.
         */
        return Response::text(<<<TXT
        User-agent: *
        Disallow: /admin
        Disallow: /auth
        Disallow: /s/
        Disallow: /b/
        Disallow: /saved

        Sitemap: {$sitemap}
        TXT);
    }

    // --------------------------------------------------------------- helpers

    private function videos(): VideoRepository
    {
        return $this->container->get(VideoRepository::class);
    }

    /**
     * XML, cacheable by anyone.
     *
     * The opposite of every themed page, which is marked private because it
     * reflects who is signed in. A feed reflects nobody, so a shared cache in
     * front of it is correct and welcome — and the fact that this had to be
     * stated is why the visibility rules above ignore the guard.
     */
    private function xml(string $body, string $contentType): Response
    {
        return Response::html($body)
            ->header('Content-Type', $contentType . '; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=900');
    }
}
