<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\Recommendations;
use Portal\Content\VideoRepository;

/**
 * "Because you watched X", against real rows.
 *
 * Every fixture here shares a series, because a shared series is the strongest
 * relatedness signal there is — so a video missing from a result is missing
 * because a RULE removed it, not because it was never related enough to rank.
 * Without that, every "is absent" assertion below could pass for the wrong
 * reason.
 */
final class RecommendationsTest extends DatabaseTestCase
{
    private Recommendations $recommendations;
    private int $userId;
    private int $seriesId;

    /** The filters an approved member's homepage would pass. */
    private const MEMBER = ['includeMemberOnly' => true, 'audienceGroupIds' => []];

    /** And a stranger's. */
    private const STRANGER = ['audienceGroupIds' => []];

    protected function setUp(): void
    {
        $this->truncate([
            'watch_progress', 'video_categories', 'scripture_refs',
            'videos', 'series', 'users',
        ]);

        $videos = new VideoRepository($this->db(), new CategoryRepository($this->db()));
        $this->recommendations = new Recommendations($this->db(), $videos);

        $now = date('Y-m-d H:i:s');

        $this->userId = $this->db()->insert('users', [
            'email' => 'member@example.test', 'name' => 'Member', 'authorized' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->seriesId = $this->db()->insert('series', [
            'slug' => 'romans', 'title' => 'Romans', 'is_published' => 1,
            'member_only' => 0, 'hidden' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function video(string $title, array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', $overrides + [
            'provider' => 'bunny', 'provider_id' => bin2hex(random_bytes(6)),
            'slug' => strtolower(str_replace(' ', '-', $title)) . '-' . bin2hex(random_bytes(3)),
            'title' => $title, 'status' => 'ready', 'is_published' => 1,
            'member_only' => 0, 'hidden' => 0, 'series_id' => $this->seriesId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Record a watch, $minutesAgo in the past, optionally finished. */
    private function watched(int $videoId, int $minutesAgo, bool $finished = false): void
    {
        $when = date('Y-m-d H:i:s', time() - ($minutesAgo * 60));

        $this->db()->insert('watch_progress', [
            'user_id' => $this->userId, 'video_id' => $videoId,
            'position_seconds' => $finished ? 1800 : 300, 'duration_seconds' => 1800,
            'completed_at' => $finished ? $when : null,
            'updated_at' => $when,
        ]);
    }

    /** @param list<\Portal\Content\Video> $videos @return list<int> */
    private function ids(array $videos): array
    {
        return array_map(static fn ($v): int => $v->id, $videos);
    }

    // --------------------------------------------------------------- the anchor

    public function testTheRowIsAnchoredOnTheMostRecentWatch(): void
    {
        $older = $this->video('Romans 1');
        $newer = $this->video('Romans 2');
        $this->video('Romans 3');

        $this->watched($older, 60);
        $this->watched($newer, 5);

        $found = $this->recommendations->becauseYouWatched($this->userId, self::MEMBER);

        self::assertNotNull($found);
        self::assertSame($newer, $found['anchor']->id, 'the row was anchored on an older watch');
    }

    /**
     * THE RULE. An anchor the viewer can no longer see is skipped, not named.
     *
     * The most recent watch was unpublished since. Naming it in a heading would
     * put a withdrawn title back on the homepage. The row must fall through to
     * the next watch — and must still exist, or "skipped" would be
     * indistinguishable from "the whole row broke".
     */
    public function testAWithdrawnVideoIsNeverTheAnchor(): void
    {
        $stillThere = $this->video('Romans 1');
        $withdrawn = $this->video('Romans 2');
        $this->video('Romans 3');

        $this->watched($stillThere, 60);
        $this->watched($withdrawn, 5);

        $this->db()->update('videos', ['is_published' => 0], ['id' => $withdrawn]);

        $found = $this->recommendations->becauseYouWatched($this->userId, self::MEMBER);

        self::assertNotNull($found, 'skipping a withdrawn anchor took the whole row with it');
        self::assertNotSame(
            $withdrawn,
            $found['anchor']->id,
            'A WITHDRAWN VIDEO WAS NAMED IN A HOMEPAGE HEADING'
        );
        self::assertSame($stillThere, $found['anchor']->id);
    }

    /** The same for members-only, seen by somebody who is not a member. */
    public function testAMembersOnlyAnchorIsSkippedForSomebodyWhoCannotSeeIt(): void
    {
        $public = $this->video('Romans 1');
        $membersOnly = $this->video('Romans 2', ['member_only' => 1]);
        $this->video('Romans 3');

        $this->watched($public, 60);
        $this->watched($membersOnly, 5);

        $found = $this->recommendations->becauseYouWatched($this->userId, self::STRANGER);

        self::assertNotNull($found);
        self::assertSame($public, $found['anchor']->id, 'A MEMBERS-ONLY TITLE WAS NAMED TO A NON-MEMBER');
    }

    // ----------------------------------------------------------- what it offers

    /**
     * Nothing already finished, and never the anchor itself.
     *
     * Asserted against a row that still has something in it, so the absences
     * are rule-driven and not the row being empty.
     */
    public function testNothingFinishedAndNotTheAnchorItself(): void
    {
        $anchor = $this->video('Romans 1');
        $finished = $this->video('Romans 2');
        $fresh = $this->video('Romans 3');

        $this->watched($finished, 120, true);
        $this->watched($anchor, 5);

        $found = $this->recommendations->becauseYouWatched($this->userId, self::MEMBER);

        self::assertNotNull($found);
        $ids = $this->ids($found['videos']);

        self::assertContains($fresh, $ids, 'the one thing worth recommending was not recommended');
        self::assertNotContains($finished, $ids, 'A SERMON THEY WATCHED TO THE END WAS RECOMMENDED');
        self::assertNotContains($anchor, $ids, 'the row recommended the video it is named after');
    }

    /** And nothing the page already shows, such as continue-watching. */
    public function testNothingAlreadyOnThePage(): void
    {
        $anchor = $this->video('Romans 1');
        $shown = $this->video('Romans 2');
        $fresh = $this->video('Romans 3');

        $this->watched($anchor, 5);

        $found = $this->recommendations->becauseYouWatched($this->userId, self::MEMBER, [$shown]);

        self::assertNotNull($found);
        self::assertContains($fresh, $this->ids($found['videos']));
        self::assertNotContains($shown, $this->ids($found['videos']), 'the same card in two rows');
    }

    /** Recommendations obey the viewer's visibility, not only the anchor. */
    public function testRecommendationsAreFilteredForTheViewer(): void
    {
        $anchor = $this->video('Romans 1');
        $membersOnly = $this->video('Romans 2', ['member_only' => 1]);
        $public = $this->video('Romans 3');

        $this->watched($anchor, 5);

        $asStranger = $this->recommendations->becauseYouWatched($this->userId, self::STRANGER);
        $asMember = $this->recommendations->becauseYouWatched($this->userId, self::MEMBER);

        self::assertNotNull($asStranger);
        self::assertNotContains($membersOnly, $this->ids($asStranger['videos']), 'A MEMBERS-ONLY RECOMMENDATION');
        self::assertContains($public, $this->ids($asStranger['videos']));

        // And a member does get it, or the rule is a deletion.
        self::assertNotNull($asMember);
        self::assertContains($membersOnly, $this->ids($asMember['videos']));
    }

    /**
     * An anchor with nothing left to recommend is passed over, not returned
     * empty — a heading above nothing is worse than no row.
     */
    public function testAnAnchorWithNothingToOfferIsPassedOver(): void
    {
        $now = date('Y-m-d H:i:s');
        $lonely = $this->db()->insert('series', [
            'slug' => 'alone', 'title' => 'Alone', 'is_published' => 1,
            'member_only' => 0, 'hidden' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $withNeighbours = $this->video('Romans 1');
        $this->video('Romans 2');
        $alone = $this->video('The Only One', ['series_id' => $lonely]);

        $this->watched($withNeighbours, 60);
        $this->watched($alone, 5);

        $found = $this->recommendations->becauseYouWatched($this->userId, self::MEMBER);

        self::assertNotNull($found);
        self::assertSame($withNeighbours, $found['anchor']->id, 'an empty row was returned under a heading');
    }

    public function testNoHistoryMeansNoRow(): void
    {
        $this->video('Romans 1');

        self::assertNull($this->recommendations->becauseYouWatched($this->userId, self::MEMBER));
        self::assertNull($this->recommendations->becauseYouWatched(0, self::MEMBER));
    }

    /** related() keeps its ranking order rather than query()'s curated one. */
    public function testRelatedNeverIncludesTheVideoItself(): void
    {
        $anchor = $this->video('Romans 1');
        $this->video('Romans 2');

        $video = (new VideoRepository($this->db(), new CategoryRepository($this->db())))->find($anchor);
        self::assertNotNull($video);

        $related = $this->recommendations->related($video, self::MEMBER);

        self::assertNotSame([], $related, 'nothing related at all, so the next assertion proves nothing');
        self::assertNotContains($anchor, $this->ids($related));
    }
}
