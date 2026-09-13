<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\AssetRepository;
use Portal\Content\FileKind;

/**
 * The file manager, on a real database and a real filesystem.
 */
final class FileLibraryTest extends DatabaseTestCase
{
    private AssetRepository $assets;
    private string $root;
    private int $videoId;

    protected function setUp(): void
    {
        $this->truncate(['books', 'file_assets', 'videos']);

        $this->root = sys_get_temp_dir() . '/portal-file-library-' . getmypid();
        $this->removeTree($this->root);
        mkdir($this->root, 0775, true);

        $this->assets = new AssetRepository($this->db(), $this->root);

        $now = date('Y-m-d H:i:s');
        $this->videoId = $this->db()->insert('videos', [
            'provider_id' => 'bunny-' . bin2hex(random_bytes(4)), 'slug' => 'romans-8',
            'title' => 'Romans 8', 'status' => 'ready', 'is_published' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function stored(string $name, string $contents = '%PDF-1.4 test'): array
    {
        $path = $this->root . '/incoming-' . bin2hex(random_bytes(6));
        file_put_contents($path, $contents);

        return $this->assets->store($this->videoId, $path, $name, 'editor@example.test');
    }

    /** @return list<int> */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    // ------------------------------------------------------------- the rule

    /**
     * THE RULE. A book's file is refused, and the refusal names the book.
     *
     * The foreign key is ON DELETE SET NULL, so the delete would not fail — it
     * would succeed and leave a hymnal with no pages. The file must still be
     * there afterwards, row AND bytes.
     */
    public function testABooksFileIsRefusedAndTheBookNamed(): void
    {
        $file = $this->stored('hymnal.pdf');
        $now = date('Y-m-d H:i:s');

        $this->db()->insert('books', [
            'slug' => 'ancient-modern', 'title' => 'Ancient & Modern', 'asset_id' => (int) $file['id'],
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $refusal = $this->assets->deleteUnlessInUse((int) $file['id']);

        self::assertNotNull($refusal, 'A BOOK\'S FILE WAS DELETED — the hymnal now has no pages');
        self::assertStringContainsString('Ancient & Modern', $refusal, 'the refusal did not say which book');

        self::assertNotNull($this->assets->find((int) $file['id']), 'the row went anyway');
        self::assertFileExists((string) $this->assets->absolutePath((string) $file['path']), 'the bytes went anyway');
    }

    /** And a file no book uses is deleted — row and bytes both. */
    public function testAnAttachmentIsDeletedWithItsBytes(): void
    {
        $file = $this->stored('notes.pdf');
        $path = (string) $this->assets->absolutePath((string) $file['path']);

        self::assertNull($this->assets->deleteUnlessInUse((int) $file['id']));

        self::assertNull($this->assets->find((int) $file['id']));
        self::assertFileDoesNotExist($path, 'the row went and the file stayed, filling the disk');
    }

    public function testDeletingSomethingAlreadyGoneSaysSo(): void
    {
        self::assertSame('no longer exists', $this->assets->deleteUnlessInUse(999999));
    }

    // -------------------------------------------------------------- listing

    public function testTheLibraryListsEveryFileWithWhatItBelongsTo(): void
    {
        $file = $this->stored('notes.pdf');

        $rows = $this->assets->library();

        self::assertCount(1, $rows);
        self::assertSame((int) $file['id'], (int) $rows[0]['id']);
        self::assertSame('Romans 8', $rows[0]['video_title']);
    }

    /**
     * LIKE wildcards are escaped. "notes_v2" must not match "notesXv2" — the
     * category-path bug this codebase already had once, where binding stopped
     * injection and did nothing about % and _.
     */
    public function testSearchTreatsWildcardsAsLetters(): void
    {
        $exact = $this->stored('notes_v2.pdf');
        $this->stored('notesXv2.pdf');
        $this->stored('100%.pdf');
        $this->stored('100 percent.pdf');

        self::assertSame([(int) $exact['id']], $this->ids($this->assets->library('notes_v2')), '_ MATCHED ANY CHARACTER');
        self::assertCount(1, $this->assets->library('100%'), '% MATCHED EVERYTHING');
    }

    /** Search also finds a file by what it is attached to. */
    public function testSearchFindsAFileByItsVideosTitle(): void
    {
        $file = $this->stored('handout.pdf');

        self::assertSame([(int) $file['id']], $this->ids($this->assets->library('Romans')));
        self::assertSame([], $this->assets->library('Galatians'));
    }

    /**
     * Cursor paging neither skips nor repeats — including across a deletion,
     * which is the reason it is not OFFSET.
     */
    public function testPagingSurvivesADeletionBetweenPages(): void
    {
        $made = [];
        for ($i = 0; $i < 5; $i++) {
            $made[] = (int) $this->stored("file-{$i}.pdf")['id'];
        }

        $first = $this->ids($this->assets->library('', '', 0, 2));
        self::assertCount(2, $first);

        // Somebody deletes a file on the first page before asking for the next.
        $this->assets->deleteUnlessInUse($first[0]);

        $second = $this->ids($this->assets->library('', '', min($first), 2));
        $third = $this->ids($this->assets->library('', '', min($second), 2));

        $seen = array_merge($first, $second, $third);

        self::assertSame(
            array_values(array_reverse($made)),
            $seen,
            'A FILE WAS SKIPPED OR SHOWN TWICE after a deletion between pages'
        );
    }

    // ---------------------------------------------------------------- kinds

    /**
     * The filter and the label cannot disagree: every file listed under a kind
     * is one FileKind::of() calls that kind, and OTHER catches what no named
     * kind does.
     */
    public function testTheKindFilterAgreesWithTheKindOfEachFile(): void
    {
        $now = date('Y-m-d H:i:s');

        /*
         * TWO types that are "other", and deliberately unrelated. With only one,
         * an OTHER written as its own hard-coded list containing that one type
         * passed — a mutation proved it. The drift that matters is a type nobody
         * anticipated falling out of every kind, so the fixture has to contain
         * one nobody would think to list.
         */
        foreach ([
            'application/pdf', 'audio/mpeg', 'image/png', 'video/mp4',
            'text/plain', 'application/zip', 'application/x-something-unforeseen',
        ] as $i => $type) {
            $this->db()->insert('file_assets', [
                'video_id' => $this->videoId, 'path' => "assets/k{$i}.bin", 'original_name' => "k{$i}",
                'content_type' => $type, 'size_bytes' => 10, 'created_at' => $now,
            ]);
        }

        $total = 0;

        foreach (array_keys(FileKind::labels()) as $kind) {
            $rows = $this->assets->library('', $kind);
            $total += count($rows);

            foreach ($rows as $row) {
                self::assertSame($kind, FileKind::of((string) $row['content_type']), "{$row['content_type']} listed under {$kind}");
            }
        }

        // Every file is under exactly one kind — none lost, none counted twice.
        self::assertSame(7, $total, 'a file fell between the kinds, or appeared under two');
    }

    public function testTotalsCountFilesAndBytes(): void
    {
        $this->stored('a.pdf', str_repeat('x', 100));
        $this->stored('b.pdf', str_repeat('x', 50));

        self::assertSame(['count' => 2, 'bytes' => 150], $this->assets->totals());
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
