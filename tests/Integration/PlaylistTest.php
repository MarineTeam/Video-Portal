<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\Playlist;
use Portal\Content\PlaylistRepository;
use Portal\Content\SavedVideoRepository;
use Portal\Content\Video;

/**
 * Playlists and saved videos against a real database.
 *
 * The two live in one file because the interesting claims are about how they
 * DIFFER. Both are "a group of videos"; one is content everybody sees and one
 * is a private bookmark, and every leak in this area comes from treating them
 * as the same thing.
 */
final class PlaylistTest extends DatabaseTestCase
{
    private PlaylistRepository $playlists;
    private SavedVideoRepository $saved;

    protected function setUp(): void
    {
        $this->truncate(['saved_videos', 'playlist_items', 'playlists', 'slug_aliases', 'videos', 'users']);

        $this->playlists = new PlaylistRepository($this->db());
        $this->saved = new SavedVideoRepository($this->db());
    }

    // ------------------------------------------------------------- playlists

    public function testCreatingDerivesAnAddress(): void
    {
        $playlist = $this->playlists->create(['title' => 'Advent 2026']);

        self::assertSame('advent-2026', $playlist->slug);
        self::assertTrue($playlist->isPublished);
    }

    public function testTwoPlaylistsCannotShareAnAddress(): void
    {
        $first = $this->playlists->create(['title' => 'Advent']);
        $second = $this->playlists->create(['title' => 'Advent']);

        self::assertNotSame($first->slug, $second->slug);
        self::assertSame('advent-2', $second->slug);
    }

    /** A printed link outlives the title somebody typed on the first attempt. */
    public function testRenamingKeepsTheOldAddressWorking(): void
    {
        $playlist = $this->playlists->create(['title' => 'Advent']);
        $this->playlists->update($playlist->id, ['slug' => 'christmas']);

        $found = $this->playlists->findByAlias('advent');

        self::assertNotNull($found);
        self::assertSame($playlist->id, $found->id);
        self::assertSame('christmas', $found->slug);
    }

    /**
     * The difference from a series, stated as a test.
     *
     * A video belongs to at most one series because "episode 3" cannot mean two
     * things. A playlist is somebody's selection, so the same video can be on
     * any number of them and adding it to one must not remove it from another.
     */
    public function testAVideoCanBeOnSeveralPlaylistsAtOnce(): void
    {
        $video = $this->video('Shared');

        $first = $this->playlists->create(['title' => 'First'])->id;
        $second = $this->playlists->create(['title' => 'Second'])->id;

        $this->playlists->setVideos($first, [$video]);
        $this->playlists->setVideos($second, [$video]);

        self::assertSame(['Shared'], $this->titles($first));
        self::assertSame(['Shared'], $this->titles($second));
    }

    public function testTheOrderIsTheOrderThatWasSet(): void
    {
        $a = $this->video('A');
        $b = $this->video('B');
        $c = $this->video('C');

        $playlist = $this->playlists->create(['title' => 'Ordered'])->id;
        $this->playlists->setVideos($playlist, [$c, $a, $b]);

        self::assertSame(['C', 'A', 'B'], $this->titles($playlist));
    }

    public function testSettingTheVideosAgainReplacesTheWholeList(): void
    {
        $a = $this->video('A');
        $b = $this->video('B');

        $playlist = $this->playlists->create(['title' => 'Replaced'])->id;
        $this->playlists->setVideos($playlist, [$a, $b]);
        $this->playlists->setVideos($playlist, [$b]);

        self::assertSame(['B'], $this->titles($playlist));
    }

    /** The same video listed twice is one entry, not a duplicate card. */
    public function testAddingTheSameVideoTwiceKeepsOneEntry(): void
    {
        $video = $this->video('Once');
        $playlist = $this->playlists->create(['title' => 'Deduped'])->id;

        $this->playlists->setVideos($playlist, [$video, $video]);

        self::assertSame(['Once'], $this->titles($playlist));
    }

    public function testMovingAVideoSwapsItWithItsNeighbour(): void
    {
        $a = $this->video('A');
        $b = $this->video('B');
        $c = $this->video('C');

        $playlist = $this->playlists->create(['title' => 'Movable'])->id;
        $this->playlists->setVideos($playlist, [$a, $b, $c]);

        $this->playlists->move($playlist, $c, -1);
        self::assertSame(['A', 'C', 'B'], $this->titles($playlist));

        $this->playlists->move($playlist, $a, 1);
        self::assertSame(['C', 'A', 'B'], $this->titles($playlist));
    }

    public function testMovingPastTheEndDoesNothing(): void
    {
        $a = $this->video('A');
        $b = $this->video('B');

        $playlist = $this->playlists->create(['title' => 'Bounded'])->id;
        $this->playlists->setVideos($playlist, [$a, $b]);

        $this->playlists->move($playlist, $a, -1);
        $this->playlists->move($playlist, $b, 1);

        self::assertSame(['A', 'B'], $this->titles($playlist));
    }

    /**
     * Reordering one playlist must not disturb another.
     *
     * The series version of this shipped with the playlist id missing from the
     * neighbour lookup, and two series reordered each other. Raw positions are
     * snapshotted rather than reading the listings back, because a cross-list
     * swap can leave both listings reading correctly while the stored
     * positions are wrong.
     */
    public function testReorderingOnePlaylistLeavesTheOtherUntouched(): void
    {
        /*
         * Three things about this setup are deliberate, and the test proves
         * nothing without all of them.
         *
         * DISJOINT videos. Sharing them lets a cross-playlist neighbour be
         * corrected by accident: the UPDATEs are scoped to the playlist, so
         * picking the wrong row still names a video that happens to be in the
         * right one, and the result is identical. With disjoint videos the
         * second UPDATE matches nothing and the damage shows.
         *
         * The OTHER playlist is created FIRST, so its rows sort earlier under
         * the primary key. Positions are dense per playlist, so the wrong query
         * ties with the right one and the tie is broken by storage order —
         * created second, the mutant would pick correctly by luck and the test
         * would pass against broken code.
         *
         * RAW POSITIONS, not the rendered listing. A cross-list swap can leave
         * both listings reading correctly while the stored positions are wrong,
         * which is exactly how the series version of this passed twice.
         */
        $c = $this->video('C');
        $d = $this->video('D');
        $other = $this->playlists->create(['title' => 'Other'])->id;
        $this->playlists->setVideos($other, [$c, $d]);

        $a = $this->video('A');
        $b = $this->video('B');
        $mine = $this->playlists->create(['title' => 'Mine'])->id;
        $this->playlists->setVideos($mine, [$a, $b]);

        $otherBefore = $this->rawPositions($other);

        $this->playlists->move($mine, $b, -1);

        self::assertSame(
            [['video_id' => $a, 'position' => 1], ['video_id' => $b, 'position' => 0]],
            $this->rawPositions($mine),
            'The swap did not happen, or left both videos at the same position.'
        );
        self::assertSame(['B', 'A'], $this->titles($mine));
        self::assertSame($otherBefore, $this->rawPositions($other), 'The other playlist was reordered too.');
    }

    /** Deleting the list must never delete the content on it. */
    public function testDeletingAPlaylistKeepsItsVideos(): void
    {
        $video = $this->video('Survivor');
        $playlist = $this->playlists->create(['title' => 'Doomed'])->id;
        $this->playlists->setVideos($playlist, [$video]);

        $this->playlists->delete($playlist);

        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {videos} WHERE id = ?', [$video]));
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {playlist_items}'));
    }

    public function testDeletingAVideoTakesItOffEveryPlaylist(): void
    {
        $video = $this->video('Gone');
        $playlist = $this->playlists->create(['title' => 'A list'])->id;
        $this->playlists->setVideos($playlist, [$video]);

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$video]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {playlist_items}'));
    }

    // ------------------------------------------------- playlist visibility

    /**
     * A playlist is hand-made, so it is exactly where an unpublished video gets
     * quietly included and then rendered to everybody.
     */
    public function testAnUnpublishedVideoIsNotShownToVisitors(): void
    {
        $open = $this->video('Public');
        $draft = $this->video('Draft');
        $this->db()->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$draft]);

        $playlist = $this->playlists->create(['title' => 'Mixed'])->id;
        $this->playlists->setVideos($playlist, [$open, $draft]);

        self::assertSame(['Public'], $this->titles($playlist));
        self::assertSame(['Public', 'Draft'], $this->titles($playlist, includeUnpublished: true));
    }

    public function testAMemberOnlyVideoIsNotShownToStrangers(): void
    {
        $open = $this->video('Public');
        $members = $this->video('Members');
        $this->db()->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$members]);

        $playlist = $this->playlists->create(['title' => 'Mixed'])->id;
        $this->playlists->setVideos($playlist, [$open, $members]);

        self::assertSame(['Public'], $this->titles($playlist));
        self::assertSame(['Public', 'Members'], $this->titles($playlist, includeMemberOnly: true));
    }

    /**
     * The count on the listing is what a visitor would see.
     *
     * "12 videos" beside a page showing 9 is the sort of small lie that makes
     * people distrust the rest of the page.
     */
    public function testTheCountExcludesWhatAVisitorCannotSee(): void
    {
        $open = $this->video('Public');
        $draft = $this->video('Draft');
        $this->db()->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$draft]);

        $id = $this->playlists->create(['title' => 'Counted'])->id;
        $this->playlists->setVideos($id, [$open, $draft]);

        self::assertSame(1, $this->playlists->find($id)?->videoCount);
    }

    /**
     * The edit screen must show an editor everything they queued, including
     * what a visitor cannot see — otherwise the next Save silently drops it.
     */
    public function testTheEditListKeepsVideosAVisitorCannotSee(): void
    {
        $open = $this->video('Public');
        $draft = $this->video('Draft');
        $this->db()->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$draft]);

        $id = $this->playlists->create(['title' => 'Edited'])->id;
        $this->playlists->setVideos($id, [$open, $draft]);

        self::assertSame([$open, $draft], $this->playlists->orderedVideoIds($id));
    }

    public function testAnUnpublishedPlaylistIsNotListedForVisitors(): void
    {
        $id = $this->playlists->create(['title' => 'Draft list'])->id;
        $this->db()->execute('UPDATE {playlists} SET is_published = 0 WHERE id = ?', [$id]);

        self::assertSame([], $this->playlists->all());
        self::assertCount(1, $this->playlists->all(true));
    }

    // ----------------------------------------------------------- saved videos

    public function testSavingAndUnsavingRoundTrips(): void
    {
        $user = $this->user();
        $video = $this->video('Kept');

        self::assertTrue($this->saved->toggle($user, $video, SavedVideoRepository::FAVORITE));
        self::assertSame([SavedVideoRepository::FAVORITE], $this->saved->listsFor($user, $video));

        self::assertFalse($this->saved->toggle($user, $video, SavedVideoRepository::FAVORITE));
        self::assertSame([], $this->saved->listsFor($user, $video));
    }

    /** A double-click is the normal way somebody saves twice. */
    public function testSavingTwiceStoresOneRow(): void
    {
        $user = $this->user();
        $video = $this->video('Kept');

        $this->saved->save($user, $video, SavedVideoRepository::FAVORITE);
        $this->saved->save($user, $video, SavedVideoRepository::FAVORITE);

        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {saved_videos}'));
    }

    public function testTheTwoListsAreIndependent(): void
    {
        $user = $this->user();
        $video = $this->video('Both');

        $this->saved->save($user, $video, SavedVideoRepository::FAVORITE);
        $this->saved->save($user, $video, SavedVideoRepository::WATCH_LATER);

        self::assertCount(2, $this->saved->listsFor($user, $video));

        $this->saved->forget($user, $video, SavedVideoRepository::FAVORITE);

        self::assertSame([SavedVideoRepository::WATCH_LATER], $this->saved->listsFor($user, $video));
    }

    /** One person's bookmarks are nobody else's business. */
    public function testOnePersonsSavedListIsNotAnothers(): void
    {
        $mine = $this->user('mine@example.com');
        $theirs = $this->user('theirs@example.com');
        $video = $this->video('Kept');

        $this->saved->save($mine, $video, SavedVideoRepository::FAVORITE);

        self::assertCount(1, $this->saved->videos($mine, SavedVideoRepository::FAVORITE));
        self::assertSame([], $this->saved->videos($theirs, SavedVideoRepository::FAVORITE));
    }

    /**
     * Somebody can save a video and later lose access to it. A saved list is
     * exactly the back door that would keep showing it.
     */
    public function testASavedVideoThatBecomesMemberOnlyIsHiddenAgain(): void
    {
        $user = $this->user();
        $video = $this->video('Was public');

        $this->saved->save($user, $video, SavedVideoRepository::FAVORITE);
        self::assertCount(1, $this->saved->videos($user, SavedVideoRepository::FAVORITE));

        $this->db()->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$video]);

        self::assertSame([], $this->saved->videos($user, SavedVideoRepository::FAVORITE));
        self::assertCount(1, $this->saved->videos($user, SavedVideoRepository::FAVORITE, includeMemberOnly: true));
    }

    public function testASavedVideoThatIsUnpublishedDisappears(): void
    {
        $user = $this->user();
        $video = $this->video('Was live');

        $this->saved->save($user, $video, SavedVideoRepository::FAVORITE);
        $this->db()->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$video]);

        self::assertSame([], $this->saved->videos($user, SavedVideoRepository::FAVORITE));
    }

    /**
     * Refused rather than defaulted. Quietly treating an unknown list as
     * "favourite" would put a video somewhere nobody asked for.
     */
    public function testAnUnknownListIsRefused(): void
    {
        self::assertNull(SavedVideoRepository::sanitizeList('bookmarks'));
        self::assertNull(SavedVideoRepository::sanitizeList(''));
        self::assertNull(SavedVideoRepository::sanitizeList(null));
        self::assertNull(SavedVideoRepository::sanitizeList(7));

        self::assertSame('favorite', SavedVideoRepository::sanitizeList('favorite'));
        self::assertSame('watch_later', SavedVideoRepository::sanitizeList(' watch_later '));
    }

    public function testSavingToAnUnknownListWritesNothing(): void
    {
        $user = $this->user();
        $video = $this->video('Kept');

        self::assertFalse($this->saved->save($user, $video, 'bookmarks'));
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {saved_videos}'));
    }

    public function testDeletingAUserTakesTheirSavedListWithThem(): void
    {
        $user = $this->user();
        $video = $this->video('Kept');
        $this->saved->save($user, $video, SavedVideoRepository::FAVORITE);

        $this->db()->execute('DELETE FROM {users} WHERE id = ?', [$user]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {saved_videos}'));
    }

    public function testCountsReportBothListsEvenWhenEmpty(): void
    {
        $user = $this->user();
        $video = $this->video('Kept');
        $this->saved->save($user, $video, SavedVideoRepository::FAVORITE);

        self::assertSame(
            ['favorite' => 1, 'watch_later' => 0],
            $this->saved->counts($user)
        );
    }

    // --------------------------------------------------------------- fixtures

    /** @return list<string> */
    private function titles(int $playlistId, bool $includeUnpublished = false, bool $includeMemberOnly = false): array
    {
        return array_map(
            static fn (Video $v): string => $v->title,
            $this->playlists->videos($playlistId, $includeUnpublished, $includeMemberOnly)
        );
    }

    /** @return list<array<string, mixed>> raw (video_id, position) rows */
    private function rawPositions(int $playlistId): array
    {
        return $this->db()->all(
            'SELECT video_id, position FROM {playlist_items} WHERE playlist_id = ? ORDER BY video_id',
            [$playlistId]
        );
    }

    private function video(string $title): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    private function user(string $email = 'viewer@example.com'): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('users', [
            'email'      => $email,
            'name'       => 'A Viewer',
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
