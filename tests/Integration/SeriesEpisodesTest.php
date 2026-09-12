<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;

/**
 * The episodes of a series, as a particular viewer may see them.
 *
 * VideoRepository::forSeries() filtered publication and hidden, and nothing
 * else — not members-only, not the schedule window, not group audiences. It fed
 * the public series page, both series feeds, the series homepage row and the
 * sequential-unlock order. seriesEpisodes() puts those callers through query(),
 * which owns every visibility rule, while keeping the running order query()
 * does not know about.
 */
final class SeriesEpisodesTest extends DatabaseTestCase
{
    private VideoRepository $videos;
    private int $seriesId;

    protected function setUp(): void
    {
        $this->truncate(['content_audiences', 'permission_groups', 'videos', 'series']);

        $this->videos = new VideoRepository($this->db(), new CategoryRepository($this->db()));

        $now = date('Y-m-d H:i:s');
        $this->seriesId = $this->db()->insert('series', [
            'slug' => 'romans', 'title' => 'Romans', 'is_published' => 1,
            'member_only' => 0, 'hidden' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function episode(int $position, array $extra = []): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', $extra + [
            'provider' => 'bunny', 'provider_id' => bin2hex(random_bytes(6)),
            'slug' => 'ep-' . $position . '-' . bin2hex(random_bytes(3)),
            'title' => 'Episode ' . $position, 'status' => 'ready', 'is_published' => 1,
            'member_only' => 0, 'hidden' => 0, 'series_id' => $this->seriesId,
            'series_position' => $position, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return list<int> */
    private function ids(array $filters): array
    {
        return array_map(
            static fn ($v): int => $v->id,
            $this->videos->seriesEpisodes($this->seriesId, $filters)
        );
    }

    /**
     * THE RULE. A stranger does not get a members-only episode of a public
     * series — while still getting the rest, in order.
     */
    public function testAMembersOnlyEpisodeIsNotListedToAStranger(): void
    {
        $one = $this->episode(1);
        $locked = $this->episode(2, ['member_only' => 1]);
        $three = $this->episode(3);

        self::assertSame(
            [$one, $three],
            $this->ids([]),
            'A MEMBERS-ONLY EPISODE WAS LISTED TO A STRANGER, or the order broke'
        );

        // And a member gets all three, in running order.
        self::assertSame([$one, $locked, $three], $this->ids(['includeMemberOnly' => true]));
    }

    /** An episode scheduled for next week is not listed early. */
    public function testAScheduledEpisodeWaitsForItsDate(): void
    {
        $now = $this->episode(1);
        $this->episode(2, ['published_at' => date('Y-m-d H:i:s', time() + 7 * 86400)]);

        self::assertSame([$now], $this->ids([]), 'AN UNRELEASED EPISODE WAS LISTED EARLY');
    }

    /** An episode restricted to a group is absent for somebody not in it. */
    public function testAGroupRestrictedEpisodeIsAbsentOutsideTheGroup(): void
    {
        $now = date('Y-m-d H:i:s');
        $group = $this->db()->insert('permission_groups', ['slug' => 'elders', 'name' => 'Elders', 'created_at' => $now]);

        $open = $this->episode(1);
        $restricted = $this->episode(2);

        $this->db()->insert('content_audiences', [
            'scope_type' => 'video', 'scope_id' => $restricted,
            'group_id' => $group, 'created_at' => $now,
        ]);

        self::assertSame([$open], $this->ids(['includeMemberOnly' => true, 'audienceGroupIds' => []]));
        self::assertSame(
            [$open, $restricted],
            $this->ids(['includeMemberOnly' => true, 'audienceGroupIds' => [$group]]),
            'the group the episode is for cannot see it'
        );
    }

    /**
     * The RUNNING order, not query()'s curated one.
     *
     * Pinned and featured flags are what query() sorts by; a series is read in
     * the order its episodes were placed. A pinned episode 3 must not jump to
     * the front of the series.
     */
    public function testTheRunningOrderSurvivesQuerysOwnOrdering(): void
    {
        $three = $this->episode(3, ['pinned' => 1, 'featured' => 1]);
        $one = $this->episode(1);
        $two = $this->episode(2);

        self::assertSame([$one, $two, $three], $this->ids([]));
    }

    /**
     * Past a hundred episodes nothing is dropped.
     *
     * query() caps a page at a hundred, so a single call would silently cut a
     * long series at episode 100 — and a teaching series of a hundred and
     * twenty weeks is not unusual. Batched, and asserted on the count and the
     * last id, which is exactly where the truncation would show.
     */
    public function testALongSeriesIsNotCutAtAHundred(): void
    {
        $last = 0;
        for ($i = 1; $i <= 120; $i++) {
            $last = $this->episode($i);
        }

        $ids = $this->ids([]);

        self::assertCount(120, $ids, 'A LONG SERIES WAS CUT OFF at query()\'s page size');
        self::assertSame($last, $ids[119]);
    }

    public function testAnEmptySeriesIsEmpty(): void
    {
        self::assertSame([], $this->ids([]));
    }

    // ----------------------------------------- the playlist shapes of the leak

    /**
     * The three things the PLAYLIST query forgot, through visibleInOrder().
     *
     * A playlist is an arbitrary list of ids in an arranged order, so these are
     * asserted on the method directly, with an ordinary video that must survive
     * beside them and a deliberately reversed order that must be kept.
     */
    public function testAHandArrangedListDropsWhatAStrangerMayNotSeeAndKeepsItsOrder(): void
    {
        $now = date('Y-m-d H:i:s');

        $membersSeries = $this->db()->insert('series', [
            'slug' => 'members', 'title' => 'Members', 'is_published' => 1,
            'member_only' => 1, 'hidden' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $ordinary = $this->episode(10);
        $later = $this->episode(20);
        $hidden = $this->episode(30, ['hidden' => 1]);
        $ended = $this->episode(40, ['unpublish_at' => date('Y-m-d H:i:s', time() - 86400)]);
        $inMembersSeries = $this->episode(50, ['series_id' => $membersSeries]);

        $shown = array_map(
            static fn ($v): int => $v->id,
            $this->videos->visibleInOrder([$later, $hidden, $ended, $inMembersSeries, $ordinary], [])
        );

        self::assertSame(
            [$later, $ordinary],
            $shown,
            'A HIDDEN, ENDED OR MEMBERS-SERIES VIDEO WAS LISTED — or the arranged order was lost'
        );
    }

    public function testNoIdsIsNoVideos(): void
    {
        self::assertSame([], $this->videos->visibleInOrder([], []));
    }
}
