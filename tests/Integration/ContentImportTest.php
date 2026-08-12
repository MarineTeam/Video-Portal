<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\ContentExport;
use Portal\Content\ContentImport;

/**
 * Reading a library export back in.
 *
 * Tested as a ROUND TRIP wherever possible: export and import only mean
 * anything as a pair, and a test that feeds the importer hand-written JSON
 * proves it can read a format nobody writes. Every fixture here goes out
 * through ContentExport first.
 */
final class ContentImportTest extends DatabaseTestCase
{
    private CategoryRepository $categories;

    protected function setUp(): void
    {
        $this->truncate([
            'transcripts', 'video_categories', 'videos',
            'series', 'speakers', 'categories', 'scripture_refs',
        ]);

        $this->categories = new CategoryRepository($this->db());
    }

    /**
     * The whole point: a library out, an empty site, the library back.
     */
    public function testARoundTripRestoresTheLibrary(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $advent = $this->categories->create(['name' => 'Advent', 'parent_id' => $sermons->id]);
        $seriesId = $this->makeSeries('Romans');
        $speakerId = $this->makeSpeaker('Jordan Ellis');
        $videoId = $this->makeVideo('Romans One', ['series_id' => $seriesId, 'speaker_id' => $speakerId]);

        $this->db()->insert('video_categories', [
            'video_id' => $videoId, 'category_id' => $advent->id, 'is_primary' => 1, 'position' => 0,
        ]);

        $file = $this->exportToFile();

        // Wipe it, exactly as losing a database would.
        $this->truncate(['video_categories', 'videos', 'series', 'speakers', 'categories']);

        $result = $this->import($file);

        self::assertSame(2, $result['counts']['categories']);
        self::assertSame(1, $result['counts']['series']);
        self::assertSame(1, $result['counts']['speakers']);
        self::assertSame(1, $result['counts']['videos']);
        self::assertSame(0, $result['counts']['failed'], implode('; ', $result['problems']));

        $video = $this->db()->first('SELECT * FROM {videos} WHERE slug = ?', ['romans-one']);
        self::assertNotNull($video);

        // The references were REMAPPED, not copied: the new ids differ from the
        // old ones, and the video still points at the right things.
        $newSeries = (int) $this->db()->value('SELECT id FROM {series} WHERE slug = ?', ['romans']);
        $newSpeaker = (int) $this->db()->value('SELECT id FROM {speakers} WHERE slug = ?', ['jordan-ellis']);

        self::assertSame($newSeries, (int) $video['series_id']);
        self::assertSame($newSpeaker, (int) $video['speaker_id']);

        $newAdvent = (int) $this->db()->value('SELECT id FROM {categories} WHERE slug = ?', ['advent']);
        self::assertSame(
            1,
            (int) $this->db()->value(
                'SELECT COUNT(*) FROM {video_categories} WHERE video_id = ? AND category_id = ?',
                [(int) $video['id'], $newAdvent]
            )
        );
    }

    /**
     * Paths are RECOMPUTED, not carried.
     *
     * `path` caches the ancestor chain as "/1/7/" using ids, so a copied path
     * describes the old site's tree. Descendant lookups are a LIKE on that
     * prefix, and the failure is a category silently inheriting a stranger's
     * permissions — which this codebase has already had once, from a different
     * cause, and fixed with escapeLike.
     */
    public function testCategoryPathsAreRebuiltForTheNewIds(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $this->categories->create(['name' => 'Advent', 'parent_id' => $sermons->id]);

        $file = $this->exportToFile();

        $this->truncate(['video_categories', 'videos', 'categories']);

        // Burn some ids, so a copied path could not accidentally be correct.
        for ($i = 0; $i < 5; $i++) {
            $this->categories->create(['name' => 'Filler ' . $i]);
        }

        $this->import($file);

        $parent = $this->db()->first('SELECT id, path FROM {categories} WHERE slug = ?', ['sermons']);
        $child = $this->db()->first('SELECT id, path, depth, parent_id FROM {categories} WHERE slug = ?', ['advent']);

        self::assertNotNull($parent);
        self::assertNotNull($child);

        self::assertSame((int) $parent['id'], (int) $child['parent_id']);
        self::assertSame('/' . $parent['id'] . '/', $parent['path']);
        self::assertSame('/' . $parent['id'] . '/' . $child['id'] . '/', $child['path']);
        self::assertSame(1, (int) $child['depth']);

        // And the materialised path actually works for the thing it exists for.
        $descendants = $this->categories->descendantIds((int) $parent['id']);
        self::assertContains((int) $child['id'], $descendants);
    }

    /**
     * THE safety property. Nothing is ever overwritten.
     *
     * The cost of getting a replace wrong is a year of cataloguing gone with no
     * way back on a host with no database console, so there is no replace.
     */
    public function testAnythingAlreadyHereIsSkippedRatherThanOverwritten(): void
    {
        $this->makeVideo('Romans One');
        $file = $this->exportToFile();

        // Change it, as somebody would after the export was taken.
        $this->db()->execute('UPDATE {videos} SET title = ? WHERE slug = ?', ['A Better Title', 'romans-one']);

        $result = $this->import($file);

        self::assertSame(0, $result['counts']['videos']);
        self::assertSame(1, $result['counts']['skipped']);
        self::assertSame(
            'A Better Title',
            (string) $this->db()->value('SELECT title FROM {videos} WHERE slug = ?', ['romans-one']),
            'The import overwrote work done after the export was taken.'
        );
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {videos}'));
    }

    /**
     * The same video under a different address is still the same video.
     *
     * Slug OR provider id. Inserting on a provider-id match would make two rows
     * for one file, and only one of them can hold the address people have links
     * to.
     */
    public function testAVideoIsMatchedOnItsProviderIdAsWellAsItsSlug(): void
    {
        $id = $this->makeVideo('Romans One');
        $providerId = (string) $this->db()->value('SELECT provider_id FROM {videos} WHERE id = ?', [$id]);

        $file = $this->exportToFile();

        $this->db()->execute('UPDATE {videos} SET slug = ? WHERE id = ?', ['renamed-since', $id]);

        $result = $this->import($file);

        self::assertSame(0, $result['counts']['videos'], 'A renamed video was imported a second time.');
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {videos} WHERE provider_id = ?', [$providerId]));
    }

    /**
     * A truncated file is N valid records and one partial line.
     *
     * That is the property NDJSON was chosen for, and refusing the whole file
     * over the partial line would throw away the N that are fine.
     */
    public function testATruncatedFileImportsEverythingBeforeTheBreak(): void
    {
        $this->categories->create(['name' => 'Sermons']);
        $this->makeSeries('Romans');

        $whole = (string) file_get_contents($this->exportToFile());
        $cut = substr($whole, 0, (int) (strlen($whole) * 0.8)) . '{"type":"video","slug":"trunc';

        $this->truncate(['video_categories', 'videos', 'series', 'categories']);

        $file = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents((string) $file, $cut);

        $result = $this->import((string) $file);
        @unlink((string) $file);

        self::assertSame(1, $result['counts']['categories'], 'Records before the break were lost.');
        self::assertGreaterThan(0, $result['counts']['failed'], 'The partial line should be counted.');
    }

    /**
     * A category that was hidden comes back hidden.
     *
     * The visibility flags were missing from the export until an importer made
     * the gap a harm rather than an omission: restoring a members-only category
     * as public republishes something taken down on purpose.
     */
    public function testVisibilityFlagsSurviveTheRoundTrip(): void
    {
        $this->categories->create(['name' => 'Private', 'member_only' => true, 'is_published' => false]);

        $file = $this->exportToFile();
        $this->truncate(['video_categories', 'videos', 'categories']);
        $this->import($file);

        $row = $this->db()->first('SELECT is_published, member_only FROM {categories} WHERE slug = ?', ['private']);

        self::assertNotNull($row);
        self::assertSame(0, (int) $row['is_published'], 'An unpublished category came back published.');
        self::assertSame(1, (int) $row['member_only'], 'A members-only category came back public.');
    }

    /** A video in the trash comes back in the trash, not republished. */
    public function testATrashedVideoStaysTrashed(): void
    {
        $this->makeVideo('Deleted One', ['deleted_at' => date('Y-m-d H:i:s')]);

        $file = $this->exportToFile();
        $this->truncate(['video_categories', 'videos']);
        $this->import($file);

        self::assertNotNull(
            $this->db()->value('SELECT deleted_at FROM {videos} WHERE slug = ?', ['deleted-one']),
            'Restoring somebody\'s bin as live content republishes what they took down.'
        );
    }

    /** A file that is not one of ours reads as nothing, not as a crash. */
    public function testAnUnrelatedFileImportsNothingAndDoesNotThrow(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'import');
        file_put_contents((string) $file, "not json at all\n{\"unrelated\":true}\n");

        $result = $this->import((string) $file);
        @unlink((string) $file);

        self::assertSame(0, $result['counts']['videos']);
        self::assertSame(0, $result['counts']['categories']);
    }

    // ------------------------------------------------------------- fixtures

    private function exportToFile(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'export');
        $handle = fopen($file, 'wb');
        self::assertNotFalse($handle);

        foreach ((new ContentExport($this->db()))->records(true) as $record) {
            fwrite($handle, ContentExport::line($record));
        }

        fclose($handle);

        return $file;
    }

    /** @return array{counts: array<string, int>, problems: list<string>, version: string} */
    private function import(string $file): array
    {
        $handle = fopen($file, 'rb');
        self::assertNotFalse($handle);

        try {
            return (new ContentImport($this->db(), new CategoryRepository($this->db())))->read($handle);
        } finally {
            fclose($handle);
        }
    }

    private function makeSeries(string $title): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('series', [
            'slug' => strtolower($title), 'title' => $title,
            'is_published' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function makeSpeaker(string $name): int
    {
        return (int) $this->db()->insert('speakers', [
            'slug' => str_replace(' ', '-', strtolower($name)),
            'name' => $name,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeVideo(string $title, array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');

        // $overrides first: PHP's + keeps the LEFT value for duplicate keys.
        return (int) $this->db()->insert('videos', $overrides + [
            'provider'     => 'bunny',
            'provider_id'  => bin2hex(random_bytes(8)),
            'slug'         => str_replace(' ', '-', strtolower($title)),
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
