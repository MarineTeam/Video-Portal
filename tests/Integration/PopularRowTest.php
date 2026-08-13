<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Content\ViewRepository;
use Portal\Plugins\Popular\PopularRow;

require_once dirname(__DIR__, 2) . '/plugins/popular/src/PopularPolicy.php';
require_once dirname(__DIR__, 2) . '/plugins/popular/src/PopularRow.php';

/**
 * The most-watched row against a real database.
 *
 * The policy tests pin what happens where the ranking meets the permission.
 * What needs a database is that the two really are two: that the ranking comes
 * from {video_views} and the permission from the listing query, and that a
 * video the library would not list cannot reach the homepage through here.
 *
 * That last one is the whole risk in this plugin. topVideos() excludes nothing
 * but deleted rows — correctly, it is an analytics query — so if the row ever
 * rendered its results directly it would be a second way to see what the
 * listing hides.
 */
final class PopularRowTest extends DatabaseTestCase
{
    private ViewRepository $views;
    private PopularRow $row;

    protected function setUp(): void
    {
        $this->truncate(['video_views', 'video_categories', 'categories', 'videos']);

        $this->views = new ViewRepository($this->db());
        $this->row = new PopularRow(
            $this->views,
            new VideoRepository($this->db(), new CategoryRepository($this->db())),
        );
    }

    /** @param list<int> $expected */
    private function assertRowIs(array $expected, array $filters = [], int $count = 8): void
    {
        $actual = array_map(
            static fn (object $video): int => $video->id,
            $this->row->forViewer(30, $count, $filters)
        );

        self::assertSame($expected, $actual);
    }

    /**
     * The ranking is the point, and it is not the order the library would use.
     *
     * The listing sorts by pinned, then position, then newest. Here the least
     * recent video is the most watched and must lead — an implementation that
     * fetched the ids and then trusted the listing's own ORDER BY would produce
     * a row labelled "most watched" sorted by publication date, which is a
     * different claim under the same heading.
     */
    public function testTheRowIsOrderedByViewsAndNotByTheListing(): void
    {
        $oldest = $this->video('Oldest', createdAt: date('Y-m-d H:i:s', time() - (30 * 86400)));
        $middle = $this->video('Middle', createdAt: date('Y-m-d H:i:s', time() - (10 * 86400)));
        $newest = $this->video('Newest', createdAt: date('Y-m-d H:i:s', time() - 3600));

        $this->record($oldest, 25);
        $this->record($middle, 5);
        $this->record($newest, 12);

        $this->assertRowIs([$oldest, $newest, $middle]);
    }

    /**
     * THE test. A members-only video can be the most watched thing on the site
     * — its audience is the people who watch most — and a signed-out visitor
     * must not be told its name.
     */
    public function testAMembersOnlyVideoIsNotNamedToAStranger(): void
    {
        $open = $this->video('Open');
        $restricted = $this->video('Members only', memberOnly: true);
        $second = $this->video('Also open');

        $this->record($restricted, 90);
        $this->record($open, 20);
        $this->record($second, 10);

        $this->assertRowIs([$open, $second], ['includeMemberOnly' => false]);
        $this->assertRowIs([$restricted, $open, $second], ['includeMemberOnly' => true]);
    }

    /** Unpublished, hidden and still-encoding videos are nobody's popular row. */
    public function testWhatTheLibraryWillNotListDoesNotAppearHere(): void
    {
        $shown = $this->video('Published');
        $draft = $this->video('Draft', published: false);
        $hidden = $this->video('Hidden', hidden: true);
        $encoding = $this->video('Encoding', status: 'processing');

        foreach ([$draft, $hidden, $encoding] as $id) {
            $this->record($id, 100);
        }
        $this->record($shown, 1);

        $this->assertRowIs([$shown]);
    }

    /**
     * A video scheduled for next week has no business on the homepage, however
     * many views its row somehow carries.
     */
    public function testAScheduledVideoStaysScheduled(): void
    {
        $now = $this->video('Available now');
        $later = $this->video('Next week', publishedAt: date('Y-m-d H:i:s', time() + (7 * 86400)));

        $this->record($later, 50);
        $this->record($now, 5);

        $this->assertRowIs([$now]);
    }

    /** A video with no views at all is not "least watched"; it is not in the row. */
    public function testAVideoNobodyOpenedIsNotRanked(): void
    {
        $watched = $this->video('Watched');
        $this->video('Never opened');

        $this->record($watched, 3);

        $this->assertRowIs([$watched]);
    }

    public function testTheRowStopsAtTheRequestedCount(): void
    {
        $ids = [];
        foreach ([50, 40, 30, 20, 10] as $i => $views) {
            $ids[] = $id = $this->video('Video ' . $i);
            $this->record($id, $views);
        }

        $this->assertRowIs(array_slice($ids, 0, 3), [], 3);
    }

    public function testNoViewsAtAllMeansNoRow(): void
    {
        $this->video('Nobody watched this');

        self::assertSame([], $this->row->forViewer(30, 8, []));
    }

    /**
     * Deleting a video removes its counts by cascade, so it leaves the row
     * rather than lingering as an id that resolves to nothing.
     */
    public function testADeletedVideoLeavesTheRow(): void
    {
        $kept = $this->video('Kept');
        $gone = $this->video('Deleted');

        $this->record($gone, 99);
        $this->record($kept, 1);

        $this->db()->execute('UPDATE {videos} SET deleted_at = NOW() WHERE id = ?', [$gone]);

        $this->assertRowIs([$kept]);
    }

    // --------------------------------------------------------------- fixtures

    private function record(int $videoId, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->views->record($videoId);
        }
    }

    private function video(
        string $title,
        ?string $createdAt = null,
        ?string $publishedAt = null,
        bool $published = true,
        bool $hidden = false,
        bool $memberOnly = false,
        string $status = 'ready',
    ): int {
        $suffix = bin2hex(random_bytes(4));
        $createdAt ??= date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'status'       => $status,
            'is_published' => $published ? 1 : 0,
            'published_at' => $publishedAt,
            'hidden'       => $hidden ? 1 : 0,
            'member_only'  => $memberOnly ? 1 : 0,
            'created_at'   => $createdAt,
            'updated_at'   => $createdAt,
        ]);
    }
}
