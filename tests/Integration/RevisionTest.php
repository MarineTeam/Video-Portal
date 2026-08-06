<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\RevisionRepository;
use Portal\Content\VideoRepository;

/**
 * Revision history.
 *
 * The snapshot is taken BEFORE a write, so the newest revision is the state you
 * can go back TO rather than the one you are already in. Every test here is
 * written in that direction, because getting it backwards produces a feature
 * that looks right on screen and restores the wrong thing.
 */
final class RevisionTest extends DatabaseTestCase
{
    private RevisionRepository $revisions;
    private VideoRepository $videos;
    private CategoryRepository $categories;

    protected function setUp(): void
    {
        $this->truncate(['revisions', 'video_categories', 'categories', 'videos', 'series', 'playlists']);

        $this->revisions = new RevisionRepository($this->db());
        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
    }

    // -------------------------------------------------------------- recording

    public function testRecordingCapturesTheStateBeforeAnEdit(): void
    {
        $id = $this->video('Original title');

        $this->revisions->record(RevisionRepository::VIDEO, $id, 'editor@example.com');
        $this->videos->update($id, ['title' => 'Changed title']);

        $history = $this->revisions->forSubject(RevisionRepository::VIDEO, $id);

        self::assertCount(1, $history);
        self::assertSame('Original title', $history[0]['data']['title']);
        self::assertSame('editor@example.com', $history[0]['changedBy']);
    }

    public function testHistoryIsNewestFirst(): void
    {
        $id = $this->video('One');

        $this->revisions->record(RevisionRepository::VIDEO, $id);
        $this->videos->update($id, ['title' => 'Two']);
        $this->revisions->record(RevisionRepository::VIDEO, $id);
        $this->videos->update($id, ['title' => 'Three']);

        $history = $this->revisions->forSubject(RevisionRepository::VIDEO, $id);

        self::assertSame(['Two', 'One'], array_column(array_column($history, 'data'), 'title'));
    }

    /**
     * Opening a form and pressing Save without changing anything is common.
     * Recording it pushes a real earlier version off the end of the list.
     */
    public function testAnIdenticalSnapshotIsNotRecordedTwice(): void
    {
        $id = $this->video('Unchanged');

        self::assertNotNull($this->revisions->record(RevisionRepository::VIDEO, $id));
        self::assertNull($this->revisions->record(RevisionRepository::VIDEO, $id));

        self::assertCount(1, $this->revisions->forSubject(RevisionRepository::VIDEO, $id));
    }

    public function testRecordingSomethingThatDoesNotExistStoresNothing(): void
    {
        self::assertNull($this->revisions->record(RevisionRepository::VIDEO, 999999));
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {revisions}'));
    }

    public function testAnUnknownSubjectTypeStoresNothing(): void
    {
        $id = $this->video('Anything');

        self::assertNull($this->revisions->record('spaceship', $id));
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {revisions}'));
    }

    /**
     * Only the fields somebody edits. Restoring a stale updated_at or a
     * provider_id that has since been re-synced would be actively wrong.
     */
    public function testOnlyEditableFieldsAreCaptured(): void
    {
        $id = $this->video('Anything');
        $this->revisions->record(RevisionRepository::VIDEO, $id);

        $data = $this->revisions->forSubject(RevisionRepository::VIDEO, $id)[0]['data'];

        self::assertArrayHasKey('title', $data);
        self::assertArrayNotHasKey('id', $data);
        self::assertArrayNotHasKey('provider_id', $data);
        self::assertArrayNotHasKey('updated_at', $data);
    }

    // ---------------------------------------------------------------- pruning

    /**
     * The bound has to hold on a site whose cron never runs, which is a site
     * this product has to work on — so pruning happens on write.
     */
    public function testOnlyTheMostRecentVersionsAreKept(): void
    {
        $id = $this->video('Version 0');

        for ($i = 1; $i <= RevisionRepository::KEEP + 5; $i++) {
            $this->revisions->record(RevisionRepository::VIDEO, $id);
            $this->videos->update($id, ['title' => 'Version ' . $i]);
        }

        $history = $this->revisions->forSubject(RevisionRepository::VIDEO, $id);

        self::assertCount(RevisionRepository::KEEP, $history);
        // The newest survives; the oldest is gone.
        self::assertSame('Version ' . (RevisionRepository::KEEP + 4), $history[0]['data']['title']);
        self::assertNotContains('Version 0', array_column(array_column($history, 'data'), 'title'));
    }

    public function testPruningIsPerSubject(): void
    {
        $first = $this->video('A');
        $second = $this->video('B');

        for ($i = 1; $i <= RevisionRepository::KEEP + 3; $i++) {
            $this->revisions->record(RevisionRepository::VIDEO, $first);
            $this->videos->update($first, ['title' => 'A' . $i]);
        }

        $this->revisions->record(RevisionRepository::VIDEO, $second);

        self::assertCount(RevisionRepository::KEEP, $this->revisions->forSubject(RevisionRepository::VIDEO, $first));
        self::assertCount(1, $this->revisions->forSubject(RevisionRepository::VIDEO, $second));
    }

    public function testOrphanedRevisionsArePruned(): void
    {
        $id = $this->video('Doomed');
        $this->revisions->record(RevisionRepository::VIDEO, $id);

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$id]);

        self::assertSame(1, $this->revisions->pruneOrphans());
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {revisions}'));
    }

    // ------------------------------------------------------------ differences

    public function testDifferencesReportWhatWouldChange(): void
    {
        $id = $this->video('Before');
        $this->revisions->record(RevisionRepository::VIDEO, $id);
        $this->videos->update($id, ['title' => 'After']);

        $revision = $this->revisions->forSubject(RevisionRepository::VIDEO, $id)[0];
        $diff = $this->revisions->differences(RevisionRepository::VIDEO, $id, $revision['data']);

        self::assertArrayHasKey('title', $diff);
        self::assertSame('After', $diff['title']['from']);
        self::assertSame('Before', $diff['title']['to']);
    }

    public function testAnUnchangedFieldIsNotReported(): void
    {
        $id = $this->video('Before');
        $this->revisions->record(RevisionRepository::VIDEO, $id);
        $this->videos->update($id, ['title' => 'After']);

        $revision = $this->revisions->forSubject(RevisionRepository::VIDEO, $id)[0];
        $diff = $this->revisions->differences(RevisionRepository::VIDEO, $id, $revision['data']);

        self::assertSame(['title'], array_keys($diff));
    }

    /**
     * A snapshot of something nobody has touched must report NO differences —
     * not one per boolean column.
     *
     * This is what makes the Restore button correctly disabled on a version
     * identical to the current one, and it is entirely about the round trip:
     * the connection returns a TINYINT as int 1, JSON carries it as 1, and any
     * comparison that is sloppy about how it renders the two ends up claiming
     * every flag changed on every revision.
     *
     * Asserted across ALL the captured fields at once, because the failure
     * mode is per-column and checking one column would miss it.
     */
    public function testAnUntouchedSubjectHasNoDifferencesAtAll(): void
    {
        $id = $this->video('Anything');
        $this->revisions->record(RevisionRepository::VIDEO, $id);

        $revision = $this->revisions->forSubject(RevisionRepository::VIDEO, $id)[0];

        self::assertSame(
            [],
            $this->revisions->differences(RevisionRepository::VIDEO, $id, $revision['data'])
        );

        // And the same for a subject whose flags are all the other way, so the
        // check is not passing because everything happens to be zero.
        $this->db()->execute(
            'UPDATE {videos} SET is_published = 1, member_only = 1, hidden = 1,
                                 featured = 1, pinned = 1, premiere = 1 WHERE id = ?',
            [$id]
        );

        $this->revisions->record(RevisionRepository::VIDEO, $id);
        $flagged = $this->revisions->forSubject(RevisionRepository::VIDEO, $id)[0];

        self::assertSame(
            [],
            $this->revisions->differences(RevisionRepository::VIDEO, $id, $flagged['data'])
        );
    }

    // --------------------------------------------------------------- restoring

    public function testRestoringPutsTheOldValuesBack(): void
    {
        $id = $this->video('Original');
        $this->revisions->record(RevisionRepository::VIDEO, $id);
        $this->videos->update($id, ['title' => 'Replaced', 'description' => 'New words']);

        $revision = $this->revisions->forSubject(RevisionRepository::VIDEO, $id)[0];
        $this->videos->update($id, $revision['data']);

        $video = $this->videos->find($id);
        self::assertSame('Original', $video?->title);
    }

    /**
     * Restoring goes through the ordinary update path, so a value that has
     * since become invalid is corrected rather than written blindly. The slug
     * is the case that bites: another video may have taken it.
     */
    public function testRestoringASlugSomethingElseTookDoesNotCollide(): void
    {
        $first = $this->video('First', slug: 'contested');
        $this->revisions->record(RevisionRepository::VIDEO, $first);

        $this->videos->update($first, ['slug' => 'moved-away']);
        $second = $this->video('Second', slug: 'contested');

        $revision = $this->revisions->forSubject(RevisionRepository::VIDEO, $first)[0];
        $this->videos->update($first, $revision['data']);

        self::assertNotSame(
            $this->videos->find($first)?->slug,
            $this->videos->find($second)?->slug,
            'Restoring produced two videos at the same address.'
        );
    }

    // -------------------------------------------------------------- categories

    public function testCategoriesHaveHistoryToo(): void
    {
        $id = $this->categories->create(['name' => 'Sermons'])->id;

        $this->revisions->record(RevisionRepository::CATEGORY, $id, 'editor@example.com');
        $this->categories->update($id, ['name' => 'Talks']);

        $history = $this->revisions->forSubject(RevisionRepository::CATEGORY, $id);

        self::assertCount(1, $history);
        self::assertSame('Sermons', $history[0]['data']['name']);
    }

    /** History is per kind AND per id, so a video 3 is not a category 3. */
    public function testHistoriesOfDifferentKindsDoNotMix(): void
    {
        $videoId = $this->video('A video');
        $categoryId = $this->categories->create(['name' => 'A category'])->id;

        $this->revisions->record(RevisionRepository::VIDEO, $videoId);
        $this->revisions->record(RevisionRepository::CATEGORY, $categoryId);

        self::assertCount(1, $this->revisions->forSubject(RevisionRepository::VIDEO, $videoId));
        self::assertCount(1, $this->revisions->forSubject(RevisionRepository::CATEGORY, $categoryId));
    }

    public function testFindReturnsWhatItBelongsTo(): void
    {
        $id = $this->video('Anything');
        $revisionId = $this->revisions->record(RevisionRepository::VIDEO, $id);

        $found = $this->revisions->find((int) $revisionId);

        self::assertNotNull($found);
        self::assertSame(RevisionRepository::VIDEO, $found['subjectType']);
        self::assertSame($id, $found['subjectId']);
    }

    public function testFindingSomethingThatIsNotThereIsNull(): void
    {
        self::assertNull($this->revisions->find(999999));
    }

    // --------------------------------------------------------------- fixtures

    private function video(string $title, ?string $slug = null): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => $slug ?? 'video-' . $suffix,
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
