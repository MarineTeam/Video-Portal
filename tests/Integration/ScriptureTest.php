<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\ScriptureParser;
use Portal\Content\ScriptureRepository;

/**
 * Storing references and browsing by them.
 *
 * The parser is tested next door. What only this can answer is whether the two
 * sources stay out of each other's way, and whether a reference that spans
 * chapters is findable from the chapters in the middle of it — which is the
 * one query a person would get wrong by writing the obvious equality.
 */
final class ScriptureTest extends DatabaseTestCase
{
    private ScriptureRepository $scripture;

    protected function setUp(): void
    {
        $this->truncate(['scripture_refs', 'videos']);

        $this->scripture = new ScriptureRepository($this->db());
    }

    // -------------------------------------------------------------- storing

    public function testReferencesAreStoredAndComeBack(): void
    {
        $video = $this->video();

        $this->scripture->replace($video, ScriptureParser::parse('John 3:16 and Romans 8:28-30'));

        $rows = $this->scripture->forVideo($video);

        self::assertCount(2, $rows);
        self::assertSame(['john', 'romans'], array_column($rows, 'book'));
    }

    /**
     * Canonical order, not alphabetical. A list reading Genesis, John, Romans
     * is one somebody can scan; 1 Corinthians, Acts, Genesis is not.
     */
    public function testReferencesComeBackInTheOrderOfTheCanon(): void
    {
        $video = $this->video();

        $this->scripture->replace(
            $video,
            ScriptureParser::parse('Romans 1, Genesis 1, Revelation 1, Acts 1')
        );

        self::assertSame(
            ['genesis', 'acts', 'romans', 'revelation'],
            array_column($this->scripture->forVideo($video), 'book')
        );
    }

    /**
     * The same passage arriving twice is one passage, enforced by the unique
     * key rather than by reading first — which would double every reference on
     * a site where two requests save at once.
     */
    public function testTheSamePassageIsNotStoredTwice(): void
    {
        $video = $this->video();

        $references = ScriptureParser::parse('John 3:16');
        $this->scripture->replace($video, [...$references, ...$references]);

        self::assertCount(1, $this->scripture->forVideo($video));
    }

    /** A whole-chapter reference has a null verse, which the unique key must still handle. */
    public function testTwoWholeChapterReferencesDoNotCollide(): void
    {
        $video = $this->video();

        $this->scripture->replace($video, ScriptureParser::parse('Psalm 23 and Psalm 24'));

        self::assertCount(2, $this->scripture->forVideo($video));
    }

    // ------------------------------------------------------- the two sources

    /**
     * The reason the source column exists. An editor adds a reference precisely
     * because the description does not mention it, so a re-scan of the
     * description must not take it away.
     */
    public function testRescanningTheDescriptionLeavesManualReferencesAlone(): void
    {
        $video = $this->video();

        $this->scripture->replace($video, ScriptureParser::parse('Micah 6:8'), 'manual');
        $this->scripture->replace($video, ScriptureParser::parse('John 3:16'), 'parsed');

        self::assertCount(2, $this->scripture->forVideo($video));

        // The description changes and no longer says John.
        $this->scripture->replace($video, ScriptureParser::parse('Luke 15:11'), 'parsed');

        $books = array_column($this->scripture->forVideo($video), 'book');
        self::assertContains('micah', $books, 'an editor\'s reference was removed by a description edit');
        self::assertContains('luke', $books);
        self::assertNotContains('john', $books);
    }

    /** And the reverse: clearing the field must not clear what the description says. */
    public function testClearingTheFieldLeavesTheParsedOnesAlone(): void
    {
        $video = $this->video();

        $this->scripture->replace($video, ScriptureParser::parse('Micah 6:8'), 'manual');
        $this->scripture->replace($video, ScriptureParser::parse('John 3:16'), 'parsed');

        $this->scripture->replace($video, [], 'manual');

        self::assertSame(['john'], array_column($this->scripture->forVideo($video), 'book'));
    }

    // -------------------------------------------------------------- browsing

    public function testBooksInUseCountsOnlyWhatIsThere(): void
    {
        $this->scripture->replace($this->video(), ScriptureParser::parse('John 3:16'));
        $this->scripture->replace($this->video(), ScriptureParser::parse('John 14:6'));
        $this->scripture->replace($this->video(), ScriptureParser::parse('Romans 8:28'));

        $books = [];
        foreach ($this->scripture->booksInUse() as $row) {
            $books[(string) $row['book']] = (int) $row['videos'];
        }

        self::assertSame(['john' => 2, 'romans' => 1], $books);
    }

    /** A book with nothing under it is not listed at all. */
    public function testAnUnusedBookIsAbsentRatherThanZero(): void
    {
        $this->scripture->replace($this->video(), ScriptureParser::parse('John 3:16'));

        self::assertSame(['john'], array_column($this->scripture->booksInUse(), 'book'));
    }

    public function testAVideoIsFoundFromItsChapter(): void
    {
        $video = $this->video();
        $this->scripture->replace($video, ScriptureParser::parse('Romans 8:28'));

        self::assertSame([$video], $this->scripture->videoIds('romans', 8));
        self::assertSame([], $this->scripture->videoIds('romans', 9));
    }

    /**
     * The query somebody would get wrong.
     *
     * "Genesis 1:1-2:3" belongs on the Genesis 2 page as much as the Genesis 1
     * page — somebody looking for a sermon on Genesis 2 should find it. An
     * equality on the stored chapter finds it only under chapter 1, and the
     * omission is invisible because the page still has content.
     */
    public function testARangeIsFoundFromEveryChapterItTouches(): void
    {
        $video = $this->video();
        $this->scripture->replace($video, ScriptureParser::parse('Genesis 1:1-3:5'));

        self::assertSame([$video], $this->scripture->videoIds('genesis', 1));
        self::assertSame([$video], $this->scripture->videoIds('genesis', 2), 'the middle of the range');
        self::assertSame([$video], $this->scripture->videoIds('genesis', 3));
        self::assertSame([], $this->scripture->videoIds('genesis', 4));
    }

    /** And the chapter strip has to agree with the pages it links to. */
    public function testTheChapterListCoversTheWholeRange(): void
    {
        $this->scripture->replace($this->video(), ScriptureParser::parse('Genesis 1:1-3:5'));

        self::assertSame([1, 2, 3], array_keys($this->scripture->chaptersInUse('genesis')));
    }

    public function testAskingForABookWithNoChapterGetsEverythingInIt(): void
    {
        $first = $this->video();
        $second = $this->video();

        $this->scripture->replace($first, ScriptureParser::parse('John 3:16'));
        $this->scripture->replace($second, ScriptureParser::parse('John 14:6'));

        $ids = $this->scripture->videoIds('john');

        self::assertCount(2, $ids);
        self::assertContains($first, $ids);
        self::assertContains($second, $ids);
    }

    // ------------------------------------------------------------ visibility

    /**
     * The browse pages are public, so an unpublished video must not be
     * countable from them — a count of three on a page showing two is a leak of
     * the fact that something exists.
     */
    public function testHiddenAndUnpublishedVideosAreNotBrowsable(): void
    {
        $visible = $this->video();
        $draft = $this->video(['is_published' => 0]);
        $hidden = $this->video(['hidden' => 1]);
        $scheduled = $this->video(['published_at' => date('Y-m-d H:i:s', time() + 86400)]);

        foreach ([$visible, $draft, $hidden, $scheduled] as $id) {
            $this->scripture->replace($id, ScriptureParser::parse('John 3:16'));
        }

        self::assertSame([$visible], $this->scripture->videoIds('john'));
        self::assertSame(1, (int) $this->scripture->booksInUse()[0]['videos']);
    }

    // -------------------------------------------------------------- scanning

    public function testAVideoIsOnlyScannedOnce(): void
    {
        $video = $this->video(['description' => 'A sermon on John 3:16.']);

        self::assertSame([$video], array_column($this->scripture->unscanned(), 'id'));

        $this->scripture->markScanned($video);

        self::assertSame([], $this->scripture->unscanned());
    }

    /**
     * Including one that mentions nothing. Otherwise a library full of sermons
     * with no references is re-read on every run, forever — which is why the
     * stamp is a column rather than the presence of a reference.
     */
    public function testAVideoWithNoReferencesStopsBeingScanned(): void
    {
        $video = $this->video(['description' => 'No passages here.']);

        $this->scripture->markScanned($video);

        self::assertSame([], $this->scripture->unscanned());
    }

    /** Re-scanning the library withdraws the parser's work and nobody else's. */
    public function testRescanningEverythingKeepsManualReferences(): void
    {
        $video = $this->video(['description' => 'A sermon on John 3:16.']);

        $this->scripture->replace($video, ScriptureParser::parse('John 3:16'), 'parsed');
        $this->scripture->replace($video, ScriptureParser::parse('Micah 6:8'), 'manual');
        $this->scripture->markScanned($video);

        $this->scripture->rescanEverything();

        self::assertSame(['micah'], array_column($this->scripture->forVideo($video), 'book'));
        self::assertSame([$video], array_column($this->scripture->unscanned(), 'id'));
    }

    // --------------------------------------------------------------- cascade

    public function testDeletingAVideoTakesItsReferences(): void
    {
        $video = $this->video();
        $this->scripture->replace($video, ScriptureParser::parse('John 3:16'));

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$video]);

        self::assertSame(0, $this->scripture->count());
    }

    // -------------------------------------------------------------- fixtures

    /** @param array<string, mixed> $overrides */
    private function video(array $overrides = []): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', $overrides + [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => 'A video',
            'status'       => 'ready',
            'is_published' => 1,
            'hidden'       => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
