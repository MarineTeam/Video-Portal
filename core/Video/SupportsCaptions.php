<?php

declare(strict_types=1);

namespace Portal\Video;

/**
 * A video provider that can carry caption tracks.
 *
 * Separate from VideoProvider, and optional, for one reason: the player is not
 * ours. bunny.net's player is an iframe on bunny.net's domain, so there is no
 * way to attach a <track> element to it from here — no amount of local storage
 * puts a caption on the screen. Captions have to live wherever the player
 * lives, which means a provider either supports them or the feature does not
 * exist for that provider.
 *
 * Making it a required part of VideoProvider would force a self-hosted HLS
 * origin, which serves its own player and needs none of this, to stub three
 * methods that throw. Callers check `instanceof` and hide the panel instead,
 * which is also the honest thing to show: a screen that offers a caption
 * upload that cannot work is worse than no screen.
 *
 * The consequence worth naming: the PROVIDER is the record of what captions
 * exist. Nothing is mirrored locally, because a local list can disagree with
 * the player and the player is what the viewer actually sees — a row saying
 * "English captions" beside a video that shows none is a bug report nobody can
 * act on. The cost is that listing captions is a network call.
 */
interface SupportsCaptions
{
    /**
     * The caption tracks on one video, as the provider has them.
     *
     * @return list<array{language: string, label: string}>
     */
    public function listCaptions(string $providerId): array;

    /**
     * Add or replace a caption track.
     *
     * Replacing rather than erroring on a language that already exists: the
     * common reason to upload twice is a correction, and a caption you cannot
     * fix without deleting first is a caption people leave wrong.
     *
     * @param string $language a validated tag — see CaptionFile::language()
     * @param string $vtt      WebVTT, already prepared
     */
    public function uploadCaption(string $providerId, string $language, string $label, string $vtt): void;

    public function deleteCaption(string $providerId, string $language): void;
}
