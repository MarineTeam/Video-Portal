<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\NoteRepository;

/**
 * Notes somebody took while watching.
 *
 * The claim that matters most is the boring one: a note belongs to exactly one
 * person and nothing here will hand it to anybody else. Every method takes a
 * user id, and these tests exist largely to prove that is not decoration.
 */
final class NoteTest extends DatabaseTestCase
{
    private NoteRepository $notes;

    protected function setUp(): void
    {
        $this->truncate(['video_notes', 'videos', 'users']);

        $this->notes = new NoteRepository($this->db());
    }

    public function testANoteIsSavedAndComesBack(): void
    {
        $user = $this->user();
        $video = $this->video();

        self::assertTrue($this->notes->save($user, $video, 'Three points and a poem.'));
        self::assertSame('Three points and a poem.', $this->notes->body($user, $video));
    }

    /**
     * The panel autosaves, so several saves from one page are in flight at
     * once. Reading first and then inserting is what produces two rows, after
     * which the older text wins on the next read.
     */
    public function testSavingRepeatedlyKeepsOneNote(): void
    {
        $user = $this->user();
        $video = $this->video();

        $this->notes->save($user, $video, 'First thought.');
        $this->notes->save($user, $video, 'First thought, revised.');
        $this->notes->save($user, $video, 'Final.');

        self::assertSame('Final.', $this->notes->body($user, $video));
        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {video_notes}'),
            'the autosave produced more than one row'
        );
    }

    /**
     * The only access control notes have. There is no capability that grants
     * reading somebody else's and no screen that lists them, so if the scoping
     * were wrong nothing else would catch it.
     */
    public function testOneAccountCannotSeeAnother(): void
    {
        $mine = $this->user();
        $theirs = $this->user();
        $video = $this->video();

        $this->notes->save($mine, $video, 'Something personal.');

        self::assertSame('', $this->notes->body($theirs, $video));
        self::assertSame([], $this->notes->forUser($theirs));
        self::assertSame(0, $this->notes->count($theirs));
    }

    public function testTwoPeopleCanBothWriteOnTheSameVideo(): void
    {
        $first = $this->user();
        $second = $this->user();
        $video = $this->video();

        $this->notes->save($first, $video, 'Mine.');
        $this->notes->save($second, $video, 'Theirs.');

        self::assertSame('Mine.', $this->notes->body($first, $video));
        self::assertSame('Theirs.', $this->notes->body($second, $video));
    }

    /**
     * Emptying the box removes the note. That is how somebody deletes one, and
     * it keeps the notes page from listing a video with a blank entry under it
     * — which reads as a bug rather than as an empty note.
     */
    public function testEmptyingItRemovesTheNote(): void
    {
        $user = $this->user();
        $video = $this->video();

        $this->notes->save($user, $video, 'Written in error.');
        self::assertFalse($this->notes->save($user, $video, '   '));

        self::assertSame('', $this->notes->body($user, $video));
        self::assertSame(0, $this->notes->count($user));
    }

    public function testAVeryLongNoteIsCutRatherThanRefused(): void
    {
        $user = $this->user();
        $video = $this->video();

        // Refusing would lose everything somebody typed; cutting keeps the
        // part that fits, and the limit is far past any real note.
        $this->notes->save($user, $video, str_repeat('a', NoteRepository::MAX_LENGTH + 500));

        self::assertSame(NoteRepository::MAX_LENGTH, mb_strlen($this->notes->body($user, $video)));
    }

    // ------------------------------------------------------------- the list

    public function testTheListIsMostRecentFirst(): void
    {
        $user = $this->user();
        $older = $this->video();
        $newer = $this->video();

        $this->notes->save($user, $older, 'Older.');
        $this->db()->execute(
            'UPDATE {video_notes} SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE video_id = ?',
            [$older]
        );
        $this->notes->save($user, $newer, 'Newer.');

        self::assertSame(
            ['Newer.', 'Older.'],
            array_column($this->notes->forUser($user), 'body')
        );
    }

    public function testTheListCarriesTheVideoItBelongsTo(): void
    {
        $user = $this->user();
        $video = $this->video();

        $this->notes->save($user, $video, 'A note.');

        $row = $this->notes->forUser($user)[0];

        self::assertArrayHasKey('title', $row);
        self::assertArrayHasKey('slug', $row);
    }

    /**
     * A note on a video in the trash is not listed. The link would go nowhere,
     * and a list of dead links is worse than a shorter list.
     */
    public function testANoteOnADeletedVideoIsNotListed(): void
    {
        $user = $this->user();
        $video = $this->video();

        $this->notes->save($user, $video, 'On something since removed.');
        $this->db()->execute('UPDATE {videos} SET deleted_at = NOW() WHERE id = ?', [$video]);

        self::assertSame([], $this->notes->forUser($user));

        // But it is still there, so restoring the video brings the note back.
        self::assertSame('On something since removed.', $this->notes->body($user, $video));
    }

    // -------------------------------------------------------------- cascades

    public function testDeletingAnAccountTakesItsNotes(): void
    {
        $user = $this->user();
        $this->notes->save($user, $this->video(), 'Private.');

        $this->db()->execute('DELETE FROM {users} WHERE id = ?', [$user]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {video_notes}'));
    }

    /**
     * Permanently deleting a video takes the notes on it. That is the right way
     * round even though it loses somebody's writing: the note is about a thing
     * that no longer exists, and keeping it would leave a row pointing at
     * nothing that no screen could render.
     */
    public function testPermanentlyDeletingAVideoTakesTheNotesOnIt(): void
    {
        $user = $this->user();
        $video = $this->video();
        $this->notes->save($user, $video, 'About this one.');

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$video]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {video_notes}'));
    }

    // -------------------------------------------------------------- fixtures

    private function user(): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('users', [
            'email'      => 'note-' . bin2hex(random_bytes(4)) . '@example.com',
            'name'       => 'A viewer',
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
}
