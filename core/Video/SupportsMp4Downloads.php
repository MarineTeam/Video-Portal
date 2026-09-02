<?php

declare(strict_types=1);

namespace Portal\Video;

/**
 * A provider that can say WHY there is no downloadable MP4.
 *
 * Separate and optional, for the same reason `SupportsCaptions` is: the base
 * interface's `downloadUrl(): ?string` is all a self-hosted HLS origin can
 * honestly answer, and making it implement three methods that throw would be
 * worse than it declining to implement this one.
 *
 * It also replaces an `instanceof BunnyStreamProvider` check in the resolver,
 * which is a shape this codebase has already been bitten by: written in a
 * controller without the import, it resolves to a class in the controller's own
 * namespace, is silently false, and takes the fallback path forever while
 * `php -l` and the class loader both pass.
 */
interface SupportsMp4Downloads
{
    /**
     * Ask the provider what exists, and answer with a URL or a reason.
     *
     * $onAnswer, where given, receives the provider's reply on the paths where
     * there was one — so a caller can cache it without this method knowing
     * about storage, and without a second copy of the rule that separates "no
     * file" from "could not ask".
     *
     * @param null|callable(VideoMeta): void $onAnswer
     */
    public function mp4Source(
        string $providerId,
        int $ttlSeconds = 3600,
        ?callable $onAnswer = null
    ): Mp4Source;

    /**
     * The same answer, from a reply somebody already has.
     *
     * No outbound call. Must reach the identical decision as `mp4Source()`
     * given the same facts: a cached path that picks a different rendition
     * produces a URL that works only while the cache is warm.
     *
     * @param list<int> $resolutions
     */
    public function mp4SourceFrom(
        string $providerId,
        bool $hasFallback,
        array $resolutions,
        int $ttlSeconds = 3600
    ): Mp4Source;
}
