<?php

declare(strict_types=1);

namespace Portal\Plugins\Popular;

use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Content\ViewRepository;

/**
 * The videos behind the row, in the order they were watched.
 */
final class PopularRow
{
    public function __construct(
        private readonly ViewRepository $views,
        private readonly VideoRepository $videos,
    ) {
    }

    /**
     * @param array<string, mixed> $filters the visibility filters for whoever is asking
     * @return list<Video> most watched first, already cut to $count
     */
    public function forViewer(int $days, int $count, array $filters): array
    {
        $top = $this->views->topVideos($days, PopularPolicy::candidateLimit($count));
        if ($top === []) {
            return [];
        }

        $ranked = array_map(static fn (array $row): int => (int) $row['video_id'], $top);

        /*
         * Through the ordinary listing query, and not by joining {video_views}
         * to {videos} here.
         *
         * topVideos() excludes nothing but deleted rows — it is an analytics
         * query and it is right that it counts everything. Publication dates,
         * the schedule window, hidden, and members-only all live in
         * buildWhere(), and a second implementation of them beside it would be
         * a second place for them to be wrong. Only one of the two would get
         * fixed, and the failure is an unpublished video on a public homepage.
         *
         * Same rule the tag pages and the up-next card follow: a new way to
         * reach content must not be a second way to see what the listing hides.
         */
        $filters['ids'] = $ranked;

        $result = $this->videos->query($filters, 1, count($ranked));

        /** @var list<Video> $items */
        $items = $result['items'];

        $byId = [];
        foreach ($items as $video) {
            $byId[$video->id] = $video;
        }

        $keep = PopularPolicy::keepInRankOrder($ranked, array_keys($byId), $count);

        return array_values(array_map(static fn (int $id): Video => $byId[$id], $keep));
    }
}
