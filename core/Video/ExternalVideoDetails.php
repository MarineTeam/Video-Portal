<?php

declare(strict_types=1);

namespace Portal\Video;

use Portal\Support\Http;
use Throwable;

/**
 * What YouTube or Vimeo says about a video, over oEmbed.
 *
 * # Why oEmbed and not the proper APIs
 *
 * The YouTube Data API gives duration, description, publication date and
 * chapters. It also needs an API key, which means another credential on the
 * Services screen, a Google Cloud project, and a quota that runs out — for a
 * site whose entire installation story is "upload the files and open /install".
 * Vimeo's API needs OAuth.
 *
 * oEmbed needs nothing. It is a public endpoint on both services, it returns
 * the title, the author and a thumbnail, and it works on the first try on a
 * shared host with no configuration at all.
 *
 * WHAT THAT COSTS, and it is stated on the import screen rather than left to be
 * discovered: YouTube's oEmbed does not return a DURATION. So an imported
 * YouTube video has no runtime until somebody types one, which means no
 * "45 min" on its card and no percentage on the history screen. Vimeo's does
 * return one. The two behave differently and the screen says which.
 *
 * # Failure is not fatal
 *
 * Every field here is a convenience. An import whose lookup failed is still a
 * perfectly good video row — the id is what makes it play, and the id came
 * from the address that was pasted. So a network error produces a details
 * object with nothing in it rather than an exception, and the caller falls back
 * to asking the person to type a title.
 */
final class ExternalVideoDetails
{
    private function __construct(
        public readonly string $title = '',
        public readonly string $author = '',
        public readonly string $thumbnailUrl = '',
        public readonly int $duration = 0,
        /** Whether the service answered at all. */
        public readonly bool $answered = false,
    ) {
    }

    public static function unknown(): self
    {
        return new self();
    }

    /**
     * Ask the service.
     *
     * Short timeouts. This runs while somebody is waiting on a form submission,
     * and a service that is slow to answer must not turn an import into a
     * request the host kills — the import works without any of this.
     */
    public static function fetch(ExternalVideo $video, int $timeoutSeconds = 6): self
    {
        try {
            // Only `timeout` — Http::request supports that and `follow`, and
            // passing an option it does not read would be a setting that looks
            // like a limit and is not one.
            $response = Http::get($video->oEmbedUrl(), [], ['timeout' => max(1, $timeoutSeconds)]);
        } catch (Throwable) {
            return self::unknown();
        }

        if ($response->status !== 200) {
            /*
             * 401 and 403 are the interesting ones and they are not errors
             * here: they are what YouTube answers for a private video and
             * Vimeo for one whose owner has disabled embedding. The import
             * still proceeds — the person pasting the link may well be the
             * owner, and the alternative is refusing a video that will play
             * perfectly well once they change a setting on the other site.
             */
            return self::unknown();
        }

        $payload = json_decode($response->body, true);

        if (!is_array($payload)) {
            return self::unknown();
        }

        return new self(
            title: self::text($payload['title'] ?? null),
            author: self::text($payload['author_name'] ?? null),
            thumbnailUrl: self::url($payload['thumbnail_url'] ?? null),
            // Vimeo returns seconds; YouTube returns no duration at all.
            duration: max(0, (int) ($payload['duration'] ?? 0)),
            answered: true,
        );
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        /*
         * Somebody else's title, going into this site's database and then onto
         * its pages. Escaping happens at output like everything else, but the
         * length cap is here: `videos.title` is VARCHAR(190) and a title
         * longer than that would be truncated by MySQL — silently in the
         * loose mode some shared hosts still run, which is how a row ends up
         * holding half a sentence.
         */
        return mb_substr(trim($value), 0, 190);
    }

    private static function url(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $value = trim($value);

        /*
         * https only, and only from the two services this understands.
         *
         * The response comes from YouTube or Vimeo, so this is not really
         * defence against them — it is defence against this method being
         * pointed at something else later. A thumbnail URL is written into an
         * <img src> on every listing that shows the video, so the one thing it
         * must never become is an arbitrary address from an arbitrary payload.
         */
        if (!preg_match('~^https://~i', $value)) {
            return '';
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        foreach (['ytimg.com', 'ggpht.com', 'vimeocdn.com'] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return mb_substr($value, 0, 500);
            }
        }

        return '';
    }
}
