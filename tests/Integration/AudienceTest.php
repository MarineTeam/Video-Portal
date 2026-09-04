<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;

/**
 * Content restricted to named groups.
 *
 * The rule is expressed twice and unavoidably: in SQL for a listing, and in PHP
 * for the single video the watch page resolves by slug. Scheduling has had that
 * arrangement since Phase 4 for the same reason, and the tests that matter most
 * here are the ones asserting the two agree — because a disagreement is either
 * a video that is listed and 404s, or one that is hidden from the listing and
 * plays.
 */
final class AudienceTest extends DatabaseTestCase
{
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['content_audiences', 'group_members', 'permission_groups', 'videos', 'series']);

        $this->videos = new VideoRepository($this->db(), new CategoryRepository($this->db()));
    }

    // ------------------------------------------------------------- the rule

    public function testAnUnrestrictedVideoIsVisibleToEverybody(): void
    {
        $id = $this->video();

        self::assertTrue($this->listed($id, []));
        self::assertTrue($this->single($id, []));
    }

    public function testARestrictedVideoIsHiddenFromSomebodyNotInTheGroup(): void
    {
        $group = $this->group('Elders');
        $id = $this->video();
        $this->videos->setAudienceGroups('video', $id, [$group]);

        self::assertFalse($this->listed($id, []), 'it was listed to a stranger');
        self::assertFalse($this->single($id, []), 'it played for a stranger');
    }

    public function testAndVisibleToSomebodyInIt(): void
    {
        $group = $this->group('Elders');
        $id = $this->video();
        $this->videos->setAudienceGroups('video', $id, [$group]);

        self::assertTrue($this->listed($id, [$group]));
        self::assertTrue($this->single($id, [$group]));
    }

    /** Any one of the named groups is enough, not all of them. */
    public function testBeingInOneOfSeveralNamedGroupsIsEnough(): void
    {
        $a = $this->group('Elders');
        $b = $this->group('Youth');
        $id = $this->video();
        $this->videos->setAudienceGroups('video', $id, [$a, $b]);

        self::assertTrue($this->listed($id, [$b]));
        self::assertTrue($this->single($id, [$b]));
    }

    /** Membership of some OTHER group is not membership of a named one. */
    public function testAnUnrelatedGroupDoesNotOpenIt(): void
    {
        $named = $this->group('Elders');
        $other = $this->group('Musicians');
        $id = $this->video();
        $this->videos->setAudienceGroups('video', $id, [$named]);

        self::assertFalse($this->listed($id, [$other]));
        self::assertFalse($this->single($id, [$other]));
    }

    // ---------------------------------------------------------- inheritance

    public function testASeriesRestrictionCoversItsEpisodes(): void
    {
        $group = $this->group('Youth');
        $seriesId = $this->series();
        $id = $this->video($seriesId);
        $this->videos->setAudienceGroups('series', $seriesId, [$group]);

        self::assertFalse($this->listed($id, []));
        self::assertFalse($this->single($id, []));
        self::assertTrue($this->listed($id, [$group]));
        self::assertTrue($this->single($id, [$group]));
    }

    /**
     * The video's own restriction wins, which is the nearest-scope rule every
     * other setting here uses — and it can OPEN as well as narrow.
     */
    public function testAVideosOwnGroupsOverrideItsSeries(): void
    {
        $seriesGroup = $this->group('Youth');
        $videoGroup = $this->group('Elders');

        $seriesId = $this->series();
        $id = $this->video($seriesId);
        $this->videos->setAudienceGroups('series', $seriesId, [$seriesGroup]);
        $this->videos->setAudienceGroups('video', $id, [$videoGroup]);

        // In the series group only: the video's own list decides, and they are
        // not on it.
        self::assertFalse($this->listed($id, [$seriesGroup]));
        self::assertFalse($this->single($id, [$seriesGroup]));

        self::assertTrue($this->listed($id, [$videoGroup]));
        self::assertTrue($this->single($id, [$videoGroup]));
    }

    /**
     * A restriction on a series this video is NOT in must not reach it.
     *
     * The shape that has been vacuous three times in this suite's history: a
     * query missing its scope_id would pick up whichever row came back first.
     */
    public function testAnotherSeriesRestrictionDoesNotLeakAcross(): void
    {
        $group = $this->group('Youth');
        $mine = $this->series('Mine');
        $theirs = $this->series('Theirs');
        $this->videos->setAudienceGroups('series', $theirs, [$group]);

        $id = $this->video($mine);

        self::assertTrue($this->listed($id, []), 'a restriction on another series hid this one');
        self::assertTrue($this->single($id, []));
    }

    // ------------------------------------------------------------- clearing

    public function testClearingTheGroupsUnrestrictsIt(): void
    {
        $group = $this->group('Elders');
        $id = $this->video();
        $this->videos->setAudienceGroups('video', $id, [$group]);
        self::assertFalse($this->listed($id, []));

        $this->videos->setAudienceGroups('video', $id, []);

        self::assertTrue($this->listed($id, []));
        self::assertTrue($this->single($id, []));
    }

    /** Naming the same group twice is one restriction, not two. */
    public function testDuplicateGroupsCollapse(): void
    {
        $group = $this->group('Elders');
        $id = $this->video();
        $this->videos->setAudienceGroups('video', $id, [$group, $group]);

        self::assertSame([$group], $this->videos->audienceGroups('video', $id));
    }

    // ------------------------------------------------------------ the halves

    /**
     * THE TEST THIS FILE EXISTS FOR: the listing and the single-video check
     * must give the same answer for every combination.
     *
     * They are separate implementations — SQL and PHP — and the failure when
     * they drift is silent in both directions.
     */
    public function testTheListingAndTheWatchPageAlwaysAgree(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $seriesId = $this->series();

        $cases = [
            'unrestricted'          => [[], [], []],
            'video restricted, out' => [[$a], [], []],
            'video restricted, in'  => [[$a], [], [$a]],
            'series restricted, out' => [[], [$a], []],
            'series restricted, in'  => [[], [$a], [$a]],
            'video overrides series' => [[$b], [$a], [$a]],
            'video overrides, in'    => [[$b], [$a], [$b]],
            'member of both'         => [[$a], [$b], [$a, $b]],
        ];

        foreach ($cases as $name => [$videoGroups, $seriesGroups, $viewerGroups]) {
            $id = $this->video($seriesId);
            $this->videos->setAudienceGroups('video', $id, $videoGroups);
            $this->videos->setAudienceGroups('series', $seriesId, $seriesGroups);

            self::assertSame(
                $this->listed($id, $viewerGroups),
                $this->single($id, $viewerGroups),
                "the two halves disagree for: {$name}"
            );
        }
    }

    // --------------------------------------------------------------- fixture

    /** Does the LISTING query return it? */
    private function listed(int $videoId, array $viewerGroups): bool
    {
        $result = $this->videos->query([
            'ids'               => [$videoId],
            'includeMemberOnly' => true,
            'audienceGroupIds'  => $viewerGroups,
        ], 1, 10);

        return $result['items'] !== [];
    }

    /** Does the SINGLE-VIDEO check allow it? */
    private function single(int $videoId, array $viewerGroups): bool
    {
        $video = $this->videos->find($videoId);
        self::assertNotNull($video);

        return $this->videos->audienceAllows($video, $viewerGroups);
    }

    private function group(string $name): int
    {
        $suffix = bin2hex(random_bytes(3));

        return (int) $this->db()->insert('permission_groups', [
            'slug'       => strtolower($name) . '-' . $suffix,
            'name'       => $name . '-' . $suffix,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function series(string $title = 'A course'): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('series', [
            'slug'       => 'series-' . bin2hex(random_bytes(4)),
            'title'      => $title,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function video(?int $seriesId = null): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => 'A sermon',
            'status'       => 'ready',
            'is_published' => 1,
            'series_id'    => $seriesId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
