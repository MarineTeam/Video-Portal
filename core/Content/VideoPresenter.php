<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Video\VideoProvider;
use Throwable;

/**
 * Turns video models into the flat cards a template renders.
 *
 * Extracted from the controller for one specific reason. The rule that a
 * members-only card carries no thumbnail URL was previously enforced inside a
 * private method, and the only test of it read the rendered HTML — which passed
 * even with the rule deleted, because the bundled theme happens not to print a
 * URL for a locked card. The check was testing the theme.
 *
 * The guarantee worth having is at this level: a locked card never carries a
 * URL, so no theme — including one somebody else wrote, which has never heard
 * of `membersOnly` — can reveal artwork by reading the obvious field.
 */
final class VideoPresenter
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly ?VideoProvider $provider,
    ) {
    }

    /**
     * @param list<Video> $videos
     * @param bool $canWatch  can this visitor actually play these videos
     * @param bool $siteDefault  the site-wide members-only thumbnail setting
     * @return list<array<string, mixed>>
     */
    public function cards(array $videos, bool $canWatch, bool $siteDefault): array
    {
        $modes = $this->videos->thumbnailModes($videos, $siteDefault);

        $cards = [];

        foreach ($videos as $video) {
            $mode = $modes[$video->id] ?? ThumbnailPolicy::INHERIT;
            $locked = !ThumbnailPolicy::showsRealArt($mode, $canWatch);

            $cards[] = [
                'id'             => $video->id,
                'title'          => $video->title,
                'url'            => $video->url(),
                'thumbnail'      => $locked ? null : $this->thumbnail($video),
                'membersOnly'    => $locked,
                'duration'       => $video->duration,
                'status'         => $video->status,
                'encodeProgress' => $video->encodeProgress,
                /*
                 * A premiere is listed before it plays, so a card has to say
                 * so. Carried as a date rather than a boolean because a badge
                 * reading "Premiering" without saying when is an invitation to
                 * click something that will not play.
                 */
                'premiereAt'     => $video->isPremiering() ? $video->publishedAt : null,
            ];
        }

        return $cards;
    }

    /**
     * Mint a signed thumbnail URL, or null.
     *
     * Only ever reached for an unlocked card. Note the ordering in cards():
     * this is not called at all when locked, rather than called and discarded.
     * A URL that is created and thrown away still existed, and the next
     * refactor puts it back into the array.
     */
    private function thumbnail(Video $video): ?string
    {
        /*
         * An imported video's artwork is already an address, so it is returned
         * as it is rather than signed. Asked BEFORE the provider, because an
         * imported video must not need one: a site with no video service
         * configured can still import a YouTube link, and reaching for a
         * provider here would give it a card with no picture.
         *
         * This is still only reached for an unlocked card — the members-only
         * rule is decided in cards() above and applies to imported videos like
         * any other. What it cannot do is make the picture secret: it is public
         * at YouTube either way, and withholding it here only means this site
         * does not hand it out.
         */
        if ($video->externalThumbnailUrl !== null && $video->externalThumbnailUrl !== '') {
            return $video->externalThumbnailUrl;
        }

        if ($this->provider === null) {
            return null;
        }

        try {
            return $this->provider->thumbnailUrl($video->providerId, $video->thumbnailFile);
        } catch (Throwable) {
            // A thumbnail is decoration; never fail a page over one.
            return null;
        }
    }
}
