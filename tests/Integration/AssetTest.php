<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\AssetPolicy;
use Portal\Content\AssetRepository;
use Portal\Http\HttpException;

/**
 * Attachments, on a real filesystem and a real database.
 *
 * The policy is tested next door. What only this can answer is whether the row
 * and the file stay in step, and whether a path out of the database can be
 * talked into pointing somewhere it should not.
 */
final class AssetTest extends DatabaseTestCase
{
    private AssetRepository $assets;
    private string $root;

    protected function setUp(): void
    {
        $this->truncate(['file_assets', 'videos']);

        // A scratch storage root per test class, so a stray file cannot follow
        // one run into the next.
        $this->root = sys_get_temp_dir() . '/portal-asset-test-' . getmypid();
        $this->removeTree($this->root);
        mkdir($this->root, 0775, true);

        $this->assets = new AssetRepository($this->db(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    // -------------------------------------------------------------- storing

    public function testStoringAFileWritesBothTheRowAndTheFile(): void
    {
        $video = $this->video();

        $row = $this->assets->store($video, $this->upload('Hello.'), 'notes.pdf', 'editor@example.com');

        self::assertSame('notes.pdf', $row['original_name']);
        self::assertSame('application/pdf', $row['content_type']);
        self::assertSame(6, (int) $row['size_bytes']);
        self::assertSame('editor@example.com', $row['uploaded_by']);

        $path = $this->assets->absolutePath((string) $row['path']);
        self::assertNotNull($path);
        self::assertFileExists($path);
        self::assertSame('Hello.', file_get_contents($path));
    }

    /** Never derived from what was uploaded. */
    public function testTheFileOnDiskIsNotNamedAfterTheUpload(): void
    {
        $video = $this->video();

        $row = $this->assets->store($video, $this->upload('x'), 'Sermon notes.pdf');

        self::assertStringNotContainsString('Sermon', (string) $row['path']);
        self::assertStringNotContainsString(' ', (string) $row['path']);
        self::assertStringEndsWith('.pdf', (string) $row['path']);
    }

    public function testTwoUploadsOfTheSameNameDoNotCollide(): void
    {
        $video = $this->video();

        $first = $this->assets->store($video, $this->upload('one'), 'notes.pdf');
        $second = $this->assets->store($video, $this->upload('two'), 'notes.pdf');

        self::assertNotSame($first['path'], $second['path']);
        self::assertSame('one', file_get_contents($this->assets->absolutePath((string) $first['path'])));
        self::assertSame('two', file_get_contents($this->assets->absolutePath((string) $second['path'])));
    }

    public function testAnUnacceptableTypeIsRefusedAndNothingIsWritten(): void
    {
        $video = $this->video();

        try {
            $this->assets->store($video, $this->upload('<?php echo 1;'), 'shell.php');
            self::fail('A .php was accepted.');
        } catch (HttpException) {
            // expected
        }

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {file_assets}'));
        self::assertFalse(is_dir($this->root . '/assets'), 'A refused upload created a directory.');
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $video = $this->video();

        $this->expectException(HttpException::class);
        $this->assets->store($video, $this->upload(''), 'notes.pdf');
    }

    public function testAFileOverTheLimitIsRefused(): void
    {
        $video = $this->video();

        $this->expectException(HttpException::class);
        $this->assets->store($video, $this->upload(str_repeat('x', AssetPolicy::MAX_BYTES + 1)), 'notes.pdf');
    }

    public function testAttachmentsComeBackInOrder(): void
    {
        $video = $this->video();

        $this->assets->store($video, $this->upload('a'), 'first.pdf');
        $this->assets->store($video, $this->upload('b'), 'second.pdf');

        self::assertSame(
            ['first.pdf', 'second.pdf'],
            array_column($this->assets->forVideo($video), 'original_name')
        );
    }

    public function testAttachmentsAreScopedToTheirVideo(): void
    {
        $first = $this->video();
        $second = $this->video();

        $this->assets->store($first, $this->upload('a'), 'theirs.pdf');
        $this->assets->store($second, $this->upload('b'), 'mine.pdf');

        self::assertCount(1, $this->assets->forVideo($first));
        self::assertSame(1, $this->assets->countFor($second));
    }

    // ------------------------------------------------------------- deleting

    public function testDeletingRemovesTheRowAndTheFile(): void
    {
        $video = $this->video();
        $row = $this->assets->store($video, $this->upload('x'), 'notes.pdf');
        $path = $this->assets->absolutePath((string) $row['path']);

        $this->assets->delete((int) $row['id']);

        self::assertNull($this->assets->find((int) $row['id']));
        self::assertFileDoesNotExist($path);
    }

    public function testDeletingSomethingAlreadyGoneIsHarmless(): void
    {
        $this->assets->delete(999999);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {file_assets}'));
    }

    /**
     * The cascade removes the ROW when a video goes, which is correct and
     * leaves the file behind. Without a sweep, deleting videos slowly fills the
     * disk with files nothing references and nothing can name.
     */
    public function testDeletingAVideoLeavesAnOrphanThatPruningFinds(): void
    {
        $video = $this->video();
        $row = $this->assets->store($video, $this->upload('x'), 'notes.pdf');
        $path = $this->assets->absolutePath((string) $row['path']);

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$video]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {file_assets}'));
        self::assertFileExists($path, 'The cascade cannot delete files; that is what pruning is for.');

        self::assertSame(1, $this->assets->pruneOrphanedFiles());
        self::assertFileDoesNotExist($path);
    }

    /** And pruning must never touch a file something still points at. */
    public function testPruningLeavesLiveFilesAlone(): void
    {
        $video = $this->video();
        $row = $this->assets->store($video, $this->upload('x'), 'notes.pdf');

        self::assertSame(0, $this->assets->pruneOrphanedFiles());
        self::assertFileExists($this->assets->absolutePath((string) $row['path']));
    }

    // ------------------------------------------------------------------ paths

    /**
     * The value comes from a column this code wrote, but a path assembled from
     * a database string and handed to readfile() is exactly the shape of bug
     * that turns a later mistake into arbitrary file disclosure.
     */
    public function testAPathCannotEscapeTheStorageRoot(): void
    {
        // Something real to aim at, outside the root.
        $outside = dirname($this->root) . '/portal-asset-outside-' . getmypid() . '.txt';
        file_put_contents($outside, 'secret');

        try {
            self::assertNull($this->assets->absolutePath('../' . basename($outside)));
            self::assertNull($this->assets->absolutePath('../../../../etc/passwd'));
            self::assertNull($this->assets->absolutePath('/etc/passwd'));
        } finally {
            @unlink($outside);
        }
    }

    public function testAPathToNothingIsNull(): void
    {
        self::assertNull($this->assets->absolutePath('assets/2026/03/nothing.pdf'));
        self::assertNull($this->assets->absolutePath(''));
    }

    // --------------------------------------------------------------- fixtures

    /** A file standing in for an upload's tmp_name. */
    private function upload(string $contents): string
    {
        $path = $this->root . '/incoming-' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);

        return $path;
    }

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

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
