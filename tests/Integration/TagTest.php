<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\TagRepository;
use Portal\Content\VideoRepository;

/**
 * Tags, against a real database.
 *
 * The `{tags}` and `{taggables}` tables shipped in Phase 1 and nothing ever
 * touched them — the schema said the feature existed, which is exactly what
 * made the gap invisible. Found by auditing columns against the code.
 *
 * The property that matters most is the last one here: a tag page must not be a
 * second way to reach content the ordinary listing would hide.
 */
final class TagTest extends DatabaseTestCase
{
    private TagRepository $tags;
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['taggables', 'tags', 'video_categories', 'videos', 'categories']);

        $this->tags = new TagRepository($this->db());
        $this->videos = new VideoRepository($this->db(), new CategoryRepository($this->db()));
    }

    public function testTaggingCreatesTheTagOnFirstUse(): void
    {
        $video = $this->makeVideo('A sermon');

        $this->tags->setFor('video', $video, ['Prayer', 'Advent']);

        $names = array_map(static fn ($t): string => $t->name, $this->tags->forItem('video', $video));
        sort($names);

        self::assertSame(['Advent', 'Prayer'], $names);
    }

    /**
     * Saving is a REPLACE, because the field shows the complete current list.
     * A person who deletes a word from the box expects it gone — which is the
     * opposite of the bulk "add to category" button, and the difference is that
     * a bulk action names only what to add.
     */
    public function testSavingReplacesRatherThanMerging(): void
    {
        $video = $this->makeVideo('A sermon');

        $this->tags->setFor('video', $video, ['Prayer', 'Advent']);
        $this->tags->setFor('video', $video, ['Advent']);

        $names = array_map(static fn ($t): string => $t->name, $this->tags->forItem('video', $video));

        self::assertSame(['Advent'], $names);
    }

    public function testATagWithNoUsesLeftIsRemoved(): void
    {
        $video = $this->makeVideo('A sermon');

        $this->tags->setFor('video', $video, ['Prayer']);
        self::assertNotNull($this->tags->findBySlug('prayer'));

        $this->tags->setFor('video', $video, []);
        $this->tags->pruneUnused();

        self::assertNull(
            $this->tags->findBySlug('prayer'),
            'A tag nothing carries is a link to an empty page.'
        );
    }

    public function testPruningKeepsTagsSomethingElseStillUses(): void
    {
        $one = $this->makeVideo('One');
        $two = $this->makeVideo('Two');

        $this->tags->setFor('video', $one, ['Prayer']);
        $this->tags->setFor('video', $two, ['Prayer']);

        $this->tags->setFor('video', $one, []);
        $this->tags->pruneUnused();

        self::assertNotNull($this->tags->findBySlug('prayer'), 'The other video still carries it.');
    }

    /**
     * Two spellings of one idea is the normal reason to rename a tag, so a
     * rename onto an existing slug merges instead of failing on the unique key.
     * "That name is taken" would leave somebody with no way to combine them.
     */
    public function testRenamingOntoAnExistingTagMergesThem(): void
    {
        $one = $this->makeVideo('One');
        $two = $this->makeVideo('Two');

        $this->tags->setFor('video', $one, ['Prayer']);
        $this->tags->setFor('video', $two, ['Praying']);

        $praying = $this->tags->findBySlug('praying');
        self::assertNotNull($praying);

        self::assertTrue($this->tags->rename($praying->id, 'Prayer'));

        self::assertNull($this->tags->findBySlug('praying'), 'The merged-away tag should be gone.');

        $prayer = $this->tags->findBySlug('prayer');
        self::assertNotNull($prayer);

        // Both videos now carry the surviving tag.
        self::assertSame(
            2,
            (int) $this->db()->value(
                'SELECT COUNT(*) FROM {taggables} WHERE tag_id = ? AND taggable_type = "video"',
                [$prayer->id]
            )
        );
    }

    /** A video already carrying both must not produce a duplicate key row. */
    public function testMergingWhenOneItemCarriesBothTags(): void
    {
        $video = $this->makeVideo('One');

        $this->tags->setFor('video', $video, ['Prayer', 'Praying']);

        $praying = $this->tags->findBySlug('praying');
        self::assertNotNull($praying);

        self::assertTrue($this->tags->rename($praying->id, 'Prayer'));

        $names = array_map(static fn ($t): string => $t->name, $this->tags->forItem('video', $video));

        self::assertSame(['Prayer'], $names, 'The merge should collapse to one row, not fail or duplicate.');
    }

    public function testCountsExcludeTagsNothingCarries(): void
    {
        $one = $this->makeVideo('One');
        $two = $this->makeVideo('Two');

        $this->tags->setFor('video', $one, ['Prayer', 'Advent']);
        $this->tags->setFor('video', $two, ['Prayer']);

        $counts = [];
        foreach ($this->tags->withCounts() as $row) {
            $counts[$row['tag']->slug] = $row['uses'];
        }

        self::assertSame(['prayer' => 2, 'advent' => 1], $counts);
    }

    /** One query for a whole page, not one per card. */
    public function testTagsForManyItemsComeBackKeyedById(): void
    {
        $one = $this->makeVideo('One');
        $two = $this->makeVideo('Two');
        $three = $this->makeVideo('Three');

        $this->tags->setFor('video', $one, ['Prayer']);
        $this->tags->setFor('video', $two, ['Advent']);

        $batch = $this->tags->forItems('video', [$one, $two, $three]);

        self::assertSame(['Prayer'], array_map(static fn ($t): string => $t->name, $batch[$one]));
        self::assertSame(['Advent'], array_map(static fn ($t): string => $t->name, $batch[$two]));
        self::assertArrayNotHasKey($three, $batch, 'An item with no tags should be omitted, not empty.');
    }

    /**
     * THE property that matters.
     *
     * A tag page is a listing like any other, so it must not become a second
     * route to content the ordinary rules hide. Tagging an unpublished video
     * must not publish it.
     */
    public function testTheTagFilterCannotSurfaceHiddenContent(): void
    {
        $published = $this->makeVideo('Published', ['is_published' => 1]);
        $draft = $this->makeVideo('Draft', ['is_published' => 0]);
        $members = $this->makeVideo('Members only', ['is_published' => 1, 'member_only' => 1]);

        foreach ([$published, $draft, $members] as $id) {
            $this->tags->setFor('video', $id, ['Prayer']);
        }

        $tag = $this->tags->findBySlug('prayer');
        self::assertNotNull($tag);

        // What a signed-out visitor's listing asks for.
        $public = $this->videos->query(['tagId' => $tag->id], 1, 25);

        $titles = array_map(static fn ($v): string => $v->title, $public['items']);

        self::assertSame(['Published'], $titles, 'A tag page showed content the listing rules hide.');
        self::assertSame(1, $public['total'], 'The total counted rows the page does not show.');
    }

    /**
     * A video carrying several tags appears ONCE.
     *
     * The reason the filter is an EXISTS rather than a join: a join returns the
     * row per matching tag, which pagination then counts as several videos —
     * a page of nine with a total of fourteen.
     */
    public function testAVideoWithSeveralTagsIsNotCountedTwice(): void
    {
        $video = $this->makeVideo('Multi', ['is_published' => 1]);
        $this->tags->setFor('video', $video, ['Prayer', 'Advent', 'Guest speaker']);

        $tag = $this->tags->findBySlug('prayer');
        self::assertNotNull($tag);

        $result = $this->videos->query(['tagId' => $tag->id], 1, 25);

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
    }

    /**
     * Deleting the thing takes its tag rows with it.
     *
     * `{taggables}` is polymorphic — one table for videos, series and categories
     * — so `taggable_id` can carry no foreign key and ON DELETE CASCADE is not
     * available. Every other dependent row in this schema is cleaned up by a
     * constraint; this one is code, and code is the half that gets forgotten.
     *
     * Left behind, the row keeps its tag alive: pruneUnused() counts uses and
     * finds one, so a tag whose only video is gone stays in the vocabulary as a
     * link to an empty page.
     */
    public function testDeletingAVideoRemovesItsTagRows(): void
    {
        $video = $this->makeVideo('Doomed');
        $this->tags->setFor('video', $video, ['Prayer']);

        $this->videos->forceDelete($video);

        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {taggables} WHERE taggable_id = ?', [$video])
        );

        $this->tags->pruneUnused();
        self::assertNull(
            $this->tags->findBySlug('prayer'),
            'An orphaned row kept the tag alive after its only video was deleted.'
        );
    }

    public function testAnUnknownTaggableTypeIsRefusedRatherThanStored(): void
    {
        $video = $this->makeVideo('One');

        $this->tags->setFor('sermon', $video, ['Prayer']);

        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {taggables}'),
            'An unrecognised type reaches an ENUM and is silently dropped by MySQL.'
        );
        self::assertSame([], $this->tags->forItem('sermon', $video));
    }

    // ------------------------------------------------------------- fixtures

    /** @param array<string, mixed> $overrides */
    private function makeVideo(string $title, array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');

        /*
         * $overrides on the LEFT. PHP's + keeps the left-hand value for any
         * duplicate key, so defaults-first silently discards every override —
         * which made the visibility test above pass a draft video off as
         * published and report the code as leaking. Every other fixture helper
         * in this suite already had it this way round; this one did not.
         */
        return (int) $this->db()->insert('videos', $overrides + [
            'provider'     => 'bunny',
            'provider_id'  => bin2hex(random_bytes(8)),
            'slug'         => strtolower(str_replace(' ', '-', $title)) . '-' . bin2hex(random_bytes(3)),
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
