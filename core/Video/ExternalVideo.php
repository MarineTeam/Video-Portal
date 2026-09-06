<?php

declare(strict_types=1);

namespace Portal\Video;

/**
 * A video that lives on YouTube or Vimeo rather than at the video provider.
 *
 * Pure: a string in, a source and an id out, or null. No database, no network.
 *
 * # Why these are rows and not a provider
 *
 * `VideoProvider` is a service the whole site is configured to use — one at a
 * time, swappable, owning upload and signing and encoding status. An imported
 * YouTube link is none of that: it is one video, hosted by somebody else, that
 * this site wants in its own categories and series and playlists. Implementing
 * `VideoProvider` for it would mean a site could be "on YouTube", which is not
 * a thing anybody wants — it cannot upload, cannot sign, cannot report
 * encoding, and cannot be asked what renditions exist.
 *
 * So the source is a property of the ROW. `{videos}.provider` has been in the
 * schema since Phase 1 with a default of 'bunny' and has never held anything
 * else; this is what it was for.
 *
 * # THE THING THAT MUST BE SAID OUT LOUD
 *
 * A video hosted on YouTube is subject to YouTube's permissions, not this
 * site's. Marking an imported video members-only hides it HERE. It does not
 * make it private there: anybody with the original link can still watch it,
 * and an unlisted video is unlisted rather than protected.
 *
 * That is not a defect to be fixed — it is what importing a link means — but a
 * site owner who has not thought it through will file a members-only sermon
 * under a public YouTube id and believe it is restricted. The import screen
 * says so, in those words, next to the field.
 */
final class ExternalVideo
{
    public const YOUTUBE = 'youtube';
    public const VIMEO = 'vimeo';

    /** @var list<string> */
    public const SOURCES = [self::YOUTUBE, self::VIMEO];

    private function __construct(
        public readonly string $source,
        public readonly string $id,
    ) {
    }

    /**
     * Read a pasted address.
     *
     * Every shape either service actually hands somebody: the address bar, the
     * Share button, an embed snippet, a short link, a Shorts link. People paste
     * what they were given, and refusing a form because it came from the wrong
     * button is a refusal they cannot act on.
     *
     * Returns null for anything else, INCLUDING a bare id. "dQw4w9WgXcQ" is
     * eleven characters that could be anything, and guessing which service a
     * naked string belongs to is how a typo becomes a video pointing at
     * somebody else's content.
     */
    public static function parse(string $raw): ?self
    {
        $raw = trim($raw);

        if ($raw === '' || strlen($raw) > 2048) {
            return null;
        }

        // A scheme-less paste is common — people copy "youtu.be/x" out of a
        // message. Added rather than refused, because parse_url needs one.
        if (!preg_match('~^https?://~i', $raw)) {
            $raw = 'https://' . ltrim($raw, '/');
        }

        $parts = parse_url($raw);

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('~^www\.~', '', $host) ?? $host;
        $path = trim((string) ($parts['path'] ?? ''), '/');

        parse_str((string) ($parts['query'] ?? ''), $query);

        return match (true) {
            $host === 'youtu.be' => self::youtube(self::firstSegment($path)),

            $host === 'youtube.com' || $host === 'youtube-nocookie.com' || $host === 'm.youtube.com'
                => self::fromYouTubePath($path, $query),

            $host === 'vimeo.com' || $host === 'player.vimeo.com'
                => self::vimeo($path),

            default => null,
        };
    }

    /** The address to put in an iframe. */
    public function embedUrl(): string
    {
        return match ($this->source) {
            /*
             * youtube-nocookie.com, not youtube.com. It is the same player and
             * it does not set advertising cookies until somebody presses play
             * — which matters on a site that is often a church's only web
             * presence and has no cookie banner because it has never needed
             * one. `rel=0` keeps the end-of-video suggestions inside the same
             * channel rather than offering the whole of YouTube.
             */
            self::YOUTUBE => 'https://www.youtube-nocookie.com/embed/'
                . rawurlencode($this->id) . '?rel=0&modestbranding=1',

            // dnt=1 is Vimeo's do-not-track flag: no session cookie, no
            // analytics beacon. Same reasoning.
            self::VIMEO => 'https://player.vimeo.com/video/'
                . rawurlencode($this->id) . '?dnt=1',

            default => '',
        };
    }

    /** Where somebody would go to watch it at the source. */
    public function canonicalUrl(): string
    {
        return match ($this->source) {
            self::YOUTUBE => 'https://www.youtube.com/watch?v=' . rawurlencode($this->id),
            self::VIMEO => 'https://vimeo.com/' . rawurlencode($this->id),
            default => '',
        };
    }

    /** The endpoint that will describe it, without a key or an account. */
    public function oEmbedUrl(): string
    {
        return match ($this->source) {
            self::YOUTUBE => 'https://www.youtube.com/oembed?format=json&url='
                . rawurlencode($this->canonicalUrl()),
            self::VIMEO => 'https://vimeo.com/api/oembed.json?url='
                . rawurlencode($this->canonicalUrl()),
            default => '',
        };
    }

    public static function isExternal(string $provider): bool
    {
        return in_array($provider, self::SOURCES, true);
    }

    /** @param array<string, mixed> $query */
    private static function fromYouTubePath(string $path, array $query): ?self
    {
        // /watch?v=ID — the address bar.
        if ($path === 'watch') {
            return self::youtube(is_string($query['v'] ?? null) ? $query['v'] : '');
        }

        // /embed/ID, /shorts/ID, /live/ID, /v/ID — everything else it hands out.
        foreach (['embed', 'shorts', 'live', 'v'] as $prefix) {
            if (str_starts_with($path, $prefix . '/')) {
                return self::youtube(self::firstSegment(substr($path, strlen($prefix) + 1)));
            }
        }

        return null;
    }

    private static function youtube(string $id): ?self
    {
        /*
         * YouTube ids are eleven characters of base64url. Validated rather
         * than trusted, because this string goes into an iframe src: a
         * pasted address with something else where the id should be must not
         * become a page that embeds it.
         */
        return preg_match('~^[A-Za-z0-9_-]{11}$~', $id) === 1
            ? new self(self::YOUTUBE, $id)
            : null;
    }

    private static function vimeo(string $path): ?self
    {
        /*
         * Vimeo ids are digits, and the path can carry more than one segment:
         * /video/123 from the player, /123/abcdef for an unlisted link, and
         * /channels/name/123.
         *
         * The LAST all-digit segment is taken, because that is the video in
         * every one of those shapes — the leading segments are the channel or
         * the word "video", and the trailing hash on an unlisted link is not
         * numeric.
         */
        $id = '';

        foreach (explode('/', $path) as $segment) {
            if (preg_match('~^\d{6,12}$~', $segment) === 1) {
                $id = $segment;
            }
        }

        return $id === '' ? null : new self(self::VIMEO, $id);
    }

    private static function firstSegment(string $path): string
    {
        $path = explode('/', trim($path, '/'))[0] ?? '';

        // youtu.be links carry ?t= and sometimes ?si=, and a fragment.
        return explode('?', explode('#', $path)[0])[0];
    }
}
