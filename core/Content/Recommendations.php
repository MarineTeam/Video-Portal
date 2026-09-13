<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * "More like this", and "Because you watched".
 *
 * # ONE RANKING, TWO PLACES
 *
 * The watch page's related row and the homepage's "Because you watched" row are
 * the same question asked about different videos — so they share this class
 * rather than the homepage growing a copy of the watch page's logic. Two
 * rankings drift, and the visible symptom is a homepage recommending something
 * the video page beside it would never list.
 *
 * # VISIBILITY IS NOT DECIDED HERE
 *
 * Every list this returns has been through VideoRepository::query() with the
 * CALLER'S filters — the caller knows who is asking; this class does not. That
 * is the rule relatednessSignals() already states, carried one step further:
 * candidates come from the signals, and only query() says what a given viewer
 * may see.
 */
final class Recommendations
{
    /** How many recent watches to look back through for an anchor. */
    private const ANCHOR_CANDIDATES = 5;

    public function __construct(
        private readonly Db $db,
        private readonly VideoRepository $videos,
    ) {
    }

    /**
     * Videos related to this one, best first, that the viewer may see.
     *
     * @param array<string, mixed> $filters the caller's visibility filters
     * @param list<int>            $exclude ids that must not appear
     * @return list<Video>
     */
    public function related(Video $video, array $filters, array $exclude = [], int $limit = Relatedness::LIMIT): array
    {
        $signals = $this->videos->relatednessSignals($video);

        // Never the video itself, and never anything the caller has already put
        // on the page — removed BEFORE ranking, so an excluded id cannot take a
        // place in the top N and leave the row one short.
        unset($signals[$video->id]);
        foreach ($exclude as $id) {
            unset($signals[(int) $id]);
        }

        if ($signals === []) {
            return [];
        }

        $ranked = Relatedness::rank($signals, [], $limit);

        if ($ranked === []) {
            return [];
        }

        $result = $this->videos->query(['ids' => $ranked] + $filters, 1, $limit);

        /*
         * query() returns its own curated order — pinned first, then whatever an
         * editor arranged. Right for a listing, wrong here, where the ranking IS
         * the answer. Restored, with anything the query refused simply absent.
         */
        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item->id] = $item;
        }

        $ordered = [];
        foreach ($ranked as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * "Because you watched X": an anchor, and what is related to it.
     *
     * # THE ANCHOR MUST STILL BE SOMETHING THEY CAN SEE
     *
     * The most recent watch is not always usable. A video an administrator has
     * since unpublished, hidden, scheduled out or restricted is still in the
     * person's history — and naming it in a homepage heading would put a title
     * back on the site that the site has deliberately withdrawn. So each recent
     * watch is passed through the same query as any listing, and the first one
     * that survives is the anchor. One that does not is skipped, not named.
     *
     * # AND NOTHING THEY HAVE FINISHED
     *
     * Recommending a sermon somebody watched to the end is the commonest way a
     * recommendation row looks broken. The anchor itself, anything completed,
     * and whatever the caller already shows (continue-watching) are all left out
     * before ranking.
     *
     * @param array<string, mixed> $filters the caller's visibility filters
     * @param list<int>            $alreadyShown ids already on the page
     * @return array{anchor: Video, videos: list<Video>}|null
     */
    public function becauseYouWatched(
        int $userId,
        array $filters,
        array $alreadyShown = [],
        int $limit = Relatedness::LIMIT
    ): ?array {
        if ($userId <= 0) {
            return null;
        }

        $recent = $this->db->all(
            'SELECT video_id, completed_at
               FROM {watch_progress}
              WHERE user_id = ?
              ORDER BY updated_at DESC, video_id DESC
              LIMIT 50',
            [$userId]
        );

        if ($recent === []) {
            return null;
        }

        $finished = [];
        $candidates = [];

        foreach ($recent as $row) {
            if ($row['completed_at'] !== null) {
                $finished[] = (int) $row['video_id'];
            }

            if (count($candidates) < self::ANCHOR_CANDIDATES) {
                $candidates[] = (int) $row['video_id'];
            }
        }

        $visible = [];
        foreach ($this->videos->query(['ids' => $candidates] + $filters, 1, self::ANCHOR_CANDIDATES)['items'] as $video) {
            $visible[$video->id] = $video;
        }

        // In watch order, not the order query() chose.
        foreach ($candidates as $id) {
            if (!isset($visible[$id])) {
                continue;
            }

            $videos = $this->related(
                $visible[$id],
                $filters,
                array_values(array_unique(array_merge($finished, $alreadyShown))),
                $limit
            );

            /*
             * An anchor with nothing related to it is passed over rather than
             * returned empty. A heading reading "Because you watched X" above
             * nothing is worse than the row not being there, and the next most
             * recent watch may well have neighbours.
             */
            if ($videos !== []) {
                return ['anchor' => $visible[$id], 'videos' => $videos];
            }
        }

        return null;
    }
}
