<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * What a link to this page looks like when somebody pastes it somewhere.
 *
 * Until now every link this site produces previewed with one site-wide title
 * and no image, whatever it pointed at. For a product whose main distribution
 * mechanism is people sending each other links, that is the first thing anyone
 * sees of it.
 *
 * SEPARATE FROM INDEXING, DELIBERATELY. `allow_indexing` decides whether search
 * engines may crawl; this decides what a preview looks like when a person
 * chooses to share something. A private site still wants a legible card in a
 * group chat, and a card is only ever built for a page the fetcher could
 * already reach — an unfurler is anonymous, so a guarded page gives it a
 * sign-in redirect and there is nothing to preview.
 *
 * THE RULE THAT MATTERS: artwork withheld from a viewer is withheld from a
 * preview. `og:image` is fetched by a stranger's server with no session, so a
 * members-only thumbnail put here would be handed to exactly the people the
 * setting exists to keep it from — and it would be cached by them afterwards.
 * `forVideo()` will not take an image for a locked card, and the URL is not
 * minted rather than minted and dropped.
 *
 * Share pages are not covered here at all, and that is not an omission. They
 * render their own document in `Sharing\ShareView` and must keep doing so: a
 * per-recipient link unfurling in a group chat with the video's title defeats
 * the point of sending it to one person.
 */
final class PageMeta
{
    private function __construct(
        public readonly string $title,
        public readonly string $description = '',
        public readonly ?string $imageUrl = null,
        public readonly ?string $canonical = null,
        public readonly string $type = 'website',
        /** @var list<array{name: string, url: string}> */
        public readonly array $breadcrumbs = [],
        /** @var array<string, mixed>|null */
        public readonly ?array $structured = null,
    ) {
    }

    /** @param list<array{name: string, url: string}> $breadcrumbs */
    public static function page(
        string $title,
        string $description = '',
        ?string $canonical = null,
        array $breadcrumbs = []
    ): self {
        return new self(
            title: $title,
            description: self::summarise($description),
            canonical: $canonical,
            breadcrumbs: $breadcrumbs,
        );
    }

    /**
     * A video's card.
     *
     * $imageUrl is nullable and the caller passes null for a members-only
     * video. It is not this class's job to decide that — the resolver that owns
     * thumbnail visibility already did — but it IS this class's job never to
     * invent one, so there is no fallback to a site logo here that would
     * quietly restore the leak.
     *
     * @param list<array{name: string, url: string}> $breadcrumbs
     */
    public static function forVideo(
        string $title,
        string $description,
        ?string $imageUrl,
        string $canonical,
        ?string $publishedAt = null,
        ?int $durationSeconds = null,
        array $breadcrumbs = []
    ): self {
        $description = self::summarise($description);

        /*
         * schema.org VideoObject, which is what turns a search result into a
         * card with a thumbnail and a duration. Only the fields that are
         * actually known are emitted: a structured-data block asserting an
         * empty description or a zero duration is worse than a shorter one,
         * because a consumer believes it.
         */
        $structured = array_filter([
            '@context'     => 'https://schema.org',
            '@type'        => 'VideoObject',
            'name'         => $title,
            'description'  => $description !== '' ? $description : null,
            'thumbnailUrl' => $imageUrl,
            'uploadDate'   => $publishedAt,
            'duration'     => $durationSeconds !== null && $durationSeconds > 0
                ? self::iso8601Duration($durationSeconds)
                : null,
            'url'          => $canonical,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        return new self(
            title: $title,
            description: $description,
            imageUrl: $imageUrl,
            canonical: $canonical,
            type: 'video.other',
            breadcrumbs: $breadcrumbs,
            structured: $structured,
        );
    }

    /**
     * The card type a preview should use.
     *
     * `summary_large_image` with no image renders as an empty grey box in some
     * clients, which looks worse than the small card it replaced.
     */
    public function twitterCard(): string
    {
        return $this->imageUrl !== null ? 'summary_large_image' : 'summary';
    }

    /**
     * The breadcrumb trail as schema.org, or null when there is not one.
     *
     * A single-item trail is not a trail. Emitting one gives a search engine a
     * BreadcrumbList that says only "here", which is noise rather than
     * structure.
     *
     * @return array<string, mixed>|null
     */
    public function breadcrumbList(): ?array
    {
        if (count($this->breadcrumbs) < 2) {
            return null;
        }

        $items = [];
        $position = 1;

        foreach ($this->breadcrumbs as $crumb) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $crumb['name'],
                'item'     => $crumb['url'],
            ];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * Plain text, short enough for a preview.
     *
     * Tags stripped before truncating, not after: cutting inside a tag leaves a
     * fragment that a consumer either renders as text or, worse, tries to
     * parse. Entities are decoded first so `&amp;` counts as one character
     * rather than five, and re-encoding happens at output.
     */
    public static function summarise(string $text, int $limit = 200): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        // Cut at a word boundary where there is one within reach, so the
        // preview does not end mid-word — but never throw away most of the
        // text to find one.
        if ($lastSpace !== false && $lastSpace > (int) ($limit * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:") . '…';
    }

    /** Seconds as ISO 8601, which is the only form schema.org accepts. */
    public static function iso8601Duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        return 'PT' . ($hours > 0 ? $hours . 'H' : '')
            . ($minutes > 0 ? $minutes . 'M' : '')
            . ($remaining > 0 || ($hours === 0 && $minutes === 0) ? $remaining . 'S' : '');
    }
}
