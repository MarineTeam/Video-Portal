<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\ChapterParser;
use Portal\Content\ChapterRepository;

/**
 * Chapters against a real database.
 *
 * The parser is tested next door. What only a database can answer is whether
 * replacing a list leaves nothing of the old one behind, and whether the
 * unique key on the moment actually holds — that constraint is the reason the
 * repository can be as thin as it is.
 */
final class ChapterTest extends DatabaseTestCase
{
    private ChapterRepository $chapters;

    protected function setUp(): void
    {
        $this->truncate(['chapters', 'videos']);

        $this->chapters = new ChapterRepository($this->db());
    }

    public function testStoringAList(): void
    {
        $id = $this->video();

        $stored = $this->chapters->replace($id, ChapterParser::parse(
            "0:00 Welcome\n2:15 The reading\n14:30 Questions"
        ));

        self::assertSame(3, $stored);
        self::assertSame(
            ['Welcome', 'The reading', 'Questions'],
            array_column($this->chapters->forVideo($id), 'title')
        );
    }

    public function testChaptersComeBackInTimeOrder(): void
    {
        $id = $this->video();

        $this->chapters->replace($id, ChapterParser::parse("14:30 Questions\n0:00 Welcome"));

        self::assertSame([0, 870], array_column($this->chapters->forVideo($id), 'start'));
    }

    /** The list as submitted IS the answer; nothing of the old one survives. */
    public function testReplacingLeavesNothingOfTheOldList(): void
    {
        $id = $this->video();

        $this->chapters->replace($id, ChapterParser::parse("0:00 Welcome\n2:15 The reading"));
        $this->chapters->replace($id, ChapterParser::parse("5:00 Something else entirely"));

        $chapters = $this->chapters->forVideo($id);

        self::assertCount(1, $chapters);
        self::assertSame('Something else entirely', $chapters[0]['title']);
    }

    /** Clearing the box is how somebody removes chapters. */
    public function testStoringAnEmptyListClearsThem(): void
    {
        $id = $this->video();
        $this->chapters->replace($id, ChapterParser::parse("0:00 Welcome"));

        self::assertSame(0, $this->chapters->replace($id, []));
        self::assertSame([], $this->chapters->forVideo($id));
    }

    /**
     * The constraint the thin repository leans on. Without it a list that
     * somehow carried a duplicate moment would render two identical links.
     */
    public function testTwoChaptersCannotShareAMoment(): void
    {
        $id = $this->video();

        // Deliberately bypassing the parser, which already deduplicates — the
        // claim under test is the database's, not the parser's.
        $stored = $this->chapters->replace($id, [
            ['start' => 60, 'title' => 'First'],
            ['start' => 60, 'title' => 'Second'],
        ]);

        self::assertSame(1, $stored);
        self::assertSame('First', $this->chapters->forVideo($id)[0]['title']);
    }

    /** And a duplicate must not abort the save and lose the rest of the list. */
    public function testADuplicateDoesNotCostTheRestOfTheList(): void
    {
        $id = $this->video();

        $stored = $this->chapters->replace($id, [
            ['start' => 0, 'title' => 'Welcome'],
            ['start' => 0, 'title' => 'Welcome again'],
            ['start' => 135, 'title' => 'The reading'],
        ]);

        self::assertSame(2, $stored);
        self::assertSame(['Welcome', 'The reading'], array_column($this->chapters->forVideo($id), 'title'));
    }

    public function testChaptersAreScopedToTheirVideo(): void
    {
        $first = $this->video();
        $second = $this->video();

        $this->chapters->replace($first, ChapterParser::parse("0:00 Theirs"));
        $this->chapters->replace($second, ChapterParser::parse("0:00 Mine\n2:15 Also mine"));

        self::assertCount(1, $this->chapters->forVideo($first));
        self::assertCount(2, $this->chapters->forVideo($second));
    }

    public function testDeletingAVideoTakesItsChaptersWithIt(): void
    {
        $id = $this->video();
        $this->chapters->replace($id, ChapterParser::parse("0:00 Welcome"));

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$id]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {chapters}'));
    }

    public function testDeletingClearsThem(): void
    {
        $id = $this->video();
        $this->chapters->replace($id, ChapterParser::parse("0:00 Welcome"));

        $this->chapters->delete($id);

        self::assertSame([], $this->chapters->forVideo($id));
    }

    // --------------------------------------------------------------- fixtures

    private function video(): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => 'A video',
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
