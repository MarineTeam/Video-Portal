<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\NoteSheetRepository;

/**
 * Note sheets against a real database — the version bump lives in one SQL
 * statement, and whether MySQL assigns its columns in the order written is a
 * property of the database, not of anything PHP can check.
 */
final class NoteSheetRepositoryTest extends DatabaseTestCase
{
    private NoteSheetRepository $sheets;

    protected function setUp(): void
    {
        $this->truncate(['note_sheet_answers', 'note_sheets', 'videos', 'users']);
        $this->sheets = new NoteSheetRepository($this->db());
    }

    public function testANewSheetIsVersionOne(): void
    {
        $video = $this->video();
        $this->sheets->saveOutline($video, 'Love is ___.');

        self::assertSame(['outline' => 'Love is ___.', 'version' => 1], $this->sheets->find($video));
    }

    public function testRewordingBumpsTheVersion(): void
    {
        $video = $this->video();
        $this->sheets->saveOutline($video, 'Love is ___.');
        $this->sheets->saveOutline($video, 'Love is ___ and ___.');

        self::assertSame(2, $this->sheets->find($video)['version']);
    }

    /**
     * Saving the same wording — the edit form re-saves the sheet whenever
     * anything else on the video changes — must not bump anything. This is the
     * test that fails if `fingerprint` is assigned before `version`.
     */
    public function testSavingTheSameWordingOrLayoutDoesNotBump(): void
    {
        $video = $this->video();
        $this->sheets->saveOutline($video, "Love is ___.\nGrace is ___.");
        $this->sheets->saveOutline($video, "Love is ___.\nGrace is ___.");
        $this->sheets->saveOutline($video, "  Love is ___.\r\n\r\nGrace   is ___.  ");

        self::assertSame(1, $this->sheets->find($video)['version']);
    }

    /**
     * Removing a sheet keeps its version, so putting one back cannot restart
     * at 1 and silently line old answers up against new gaps.
     */
    public function testRemovingAndRestoringDoesNotRestartTheVersion(): void
    {
        $video = $this->video();
        $this->sheets->saveOutline($video, 'First ___.');
        $this->sheets->saveOutline($video, '');

        self::assertNull($this->sheets->find($video), 'an empty outline is no sheet');

        $this->sheets->saveOutline($video, 'First ___.');
        self::assertSame(3, $this->sheets->find($video)['version']);
    }

    public function testAnswersAreKeptPerPersonWithTheirVersion(): void
    {
        $video = $this->video();
        $alice = $this->person();
        $bob = $this->person();

        $this->sheets->saveAnswers($alice, $video, 1, ['grace', 'é']);
        $this->sheets->saveAnswers($alice, $video, 2, ['mercy', '']);
        $this->sheets->saveAnswers($bob, $video, 1, ['truth', 'x']);

        self::assertSame(['version' => 2, 'answers' => ['mercy', '']], $this->sheets->answers($alice, $video));
        self::assertSame(['version' => 1, 'answers' => ['truth', 'x']], $this->sheets->answers($bob, $video));
        self::assertNull($this->sheets->answers($alice, $this->video()));
    }

    public function testAnswersGoWithTheAccount(): void
    {
        $video = $this->video();
        $alice = $this->person();
        $this->sheets->saveAnswers($alice, $video, 1, ['grace']);

        $this->db()->execute('DELETE FROM {users} WHERE id = ?', [$alice]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {note_sheet_answers}'));
    }

    private function video(): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('videos', [
            'provider_id' => 'bunny-' . $suffix, 'slug' => 'video-' . $suffix, 'title' => 'A talk',
            'status' => 'ready', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function person(): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('users', [
            'email' => 'sheet-' . bin2hex(random_bytes(4)) . '@example.test', 'name' => 'Member',
            'authorized' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
