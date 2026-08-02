<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Container;
use Portal\Db;
use Portal\Http\Router;
use Portal\Plugins\Comments\CommentPolicy;
use Portal\Plugins\Comments\CommentRepository;
use Portal\Plugins\Hooks;
use Portal\Plugins\PluginManager;

/**
 * The comments plugin against a real database.
 *
 * Activated from its own directory, so this also proves the plugin's migrations
 * run, its tables are created, and uninstalling takes them away again — the
 * lifecycle a third-party plugin depends on and which nothing had exercised
 * with real tables before.
 */
final class CommentsTest extends DatabaseTestCase
{
    private PluginManager $manager;
    private CommentRepository $comments;

    protected function setUp(): void
    {
        $this->truncate(['plugin_migrations', 'plugins', 'videos', 'users']);
        $this->db()->execute('DROP TABLE IF EXISTS {comment_reports}');
        $this->db()->execute('DROP TABLE IF EXISTS {comments}');

        Hooks::reset();
        Container::reset();

        $this->manager = new PluginManager(
            $this->db(),
            new Config('/nonexistent-config.php'),
            Hooks::instance(),
            new Router(),
        );

        $result = $this->manager->activate('comments');
        self::assertTrue($result['ok'], 'Could not activate comments: ' . $result['message']);

        $this->comments = new CommentRepository($this->db());
    }

    protected function tearDown(): void
    {
        Hooks::reset();
        Container::reset();
    }

    // ------------------------------------------------------------- lifecycle

    public function testActivatingCreatesItsTables(): void
    {
        self::assertTrue($this->db()->tableExists('comments'));
        self::assertTrue($this->db()->tableExists('comment_reports'));
    }

    public function testUninstallingTakesThemAway(): void
    {
        $this->manager->uninstall('comments');

        self::assertFalse($this->db()->tableExists('comments'));
        self::assertFalse($this->db()->tableExists('comment_reports'));
    }

    /**
     * Deactivating is what an admin reaches for to hide the feature; it must
     * never be the thing that loses the conversation.
     */
    public function testDeactivatingKeepsEveryComment(): void
    {
        $video = $this->video();
        $this->post($video, 'someone@example.com', 'Keep me.');

        $this->manager->deactivate('comments');

        self::assertTrue($this->db()->tableExists('comments'));
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {comments}'));
    }

    // ---------------------------------------------------------------- threads

    public function testAThreadNestsRepliesUnderTheirParent(): void
    {
        $video = $this->video();

        $parent = $this->post($video, 'a@example.com', 'Top level.');
        $this->post($video, 'b@example.com', 'A reply.', $parent);
        $this->post($video, 'c@example.com', 'Another reply.', $parent);
        $this->post($video, 'd@example.com', 'Second top level.');

        $thread = $this->comments->thread($video);

        self::assertCount(2, $thread, 'Replies must not appear at the top level.');
        self::assertCount(2, $thread[0]['replies']);
        self::assertSame('A reply.', $thread[0]['replies'][0]['body']);
    }

    public function testPendingCommentsAreNotShownToReaders(): void
    {
        $video = $this->video();

        $this->post($video, 'seen@example.com', 'Approved.');
        $this->post($video, 'held@example.com', 'Waiting.', null, CommentPolicy::STATUS_PENDING);

        $thread = $this->comments->thread($video);

        self::assertCount(1, $thread);
        self::assertSame('Approved.', $thread[0]['body']);
    }

    /**
     * A removed comment with replies stays as a tombstone, so the answers under
     * it still make sense. With nothing under it, it simply goes.
     */
    public function testARemovedCommentSurvivesAsATombstoneOnlyWhileItHasReplies(): void
    {
        $video = $this->video();

        $withReplies = $this->post($video, 'a@example.com', 'Removed but answered.');
        $this->post($video, 'b@example.com', 'The answer.', $withReplies);
        $alone = $this->post($video, 'c@example.com', 'Removed and alone.');

        $this->comments->setStatus($withReplies, CommentPolicy::STATUS_REMOVED);
        $this->comments->setStatus($alone, CommentPolicy::STATUS_REMOVED);

        $thread = $this->comments->thread($video);

        self::assertCount(1, $thread);
        self::assertTrue($thread[0]['removed']);
        self::assertCount(1, $thread[0]['replies']);
    }

    /**
     * A reply to a comment on a different video is a stale form or an edited
     * one. It becomes a top-level comment rather than being lost.
     */
    public function testAReplyToTheWrongVideoBecomesTopLevel(): void
    {
        $first = $this->video();
        $second = $this->video();

        $elsewhere = $this->post($first, 'a@example.com', 'Over here.');
        $this->post($second, 'b@example.com', 'Confused reply.', $elsewhere);

        $thread = $this->comments->thread($second);

        self::assertCount(1, $thread);
        self::assertNull($thread[0]['parentId']);
        self::assertSame('Confused reply.', $thread[0]['body']);
    }

    /** One level deep, so a reply cannot itself be replied to. */
    public function testAReplyToAReplyIsFlattened(): void
    {
        $video = $this->video();

        $parent = $this->post($video, 'a@example.com', 'Top.');
        $reply = $this->post($video, 'b@example.com', 'Middle.', $parent);
        $this->post($video, 'c@example.com', 'Would be third level.', $reply);

        $thread = $this->comments->thread($video);

        self::assertCount(2, $thread, 'The third-level comment should have become top-level.');
    }

    // ------------------------------------------------------------- moderation

    public function testApprovingAnAuthorClearsEverythingTheyHaveWaiting(): void
    {
        $video = $this->video();

        $this->post($video, 'newcomer@example.com', 'One.', null, CommentPolicy::STATUS_PENDING);
        $this->post($video, 'newcomer@example.com', 'Two.', null, CommentPolicy::STATUS_PENDING);
        $this->post($video, 'other@example.com', 'Not mine.', null, CommentPolicy::STATUS_PENDING);

        $approved = $this->comments->approveAuthor('newcomer@example.com');

        self::assertSame(2, $approved);
        self::assertCount(2, $this->comments->thread($video));
    }

    public function testTheApprovedCountDrivesTheNewcomerRule(): void
    {
        $video = $this->video();

        self::assertSame(0, $this->comments->approvedCountFor('fresh@example.com'));

        $this->post($video, 'fresh@example.com', 'First.');

        self::assertSame(1, $this->comments->approvedCountFor('fresh@example.com'));
    }

    /** Counted by address, so a deleted and recreated account is still known. */
    public function testTheApprovedCountIgnoresAddressCasing(): void
    {
        $video = $this->video();
        $this->post($video, 'Mixed@Example.COM', 'Hello.');

        self::assertSame(1, $this->comments->approvedCountFor('mixed@example.com'));
    }

    // ---------------------------------------------------------------- reports

    public function testReportingRaisesTheCount(): void
    {
        $video = $this->video();
        $comment = $this->post($video, 'a@example.com', 'Objectionable.');

        self::assertTrue($this->comments->report($comment, 'reporter@example.com', 'rude'));

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT report_count FROM {comments} WHERE id = ?', [$comment])
        );
    }

    /**
     * Without the unique index one determined visitor could run the count up
     * and make an ordinary comment look like a crisis.
     */
    public function testTheSamePersonCannotReportTwice(): void
    {
        $video = $this->video();
        $comment = $this->post($video, 'a@example.com', 'Objectionable.');

        self::assertTrue($this->comments->report($comment, 'reporter@example.com', 'rude'));
        self::assertFalse($this->comments->report($comment, 'reporter@example.com', 'still rude'));

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT report_count FROM {comments} WHERE id = ?', [$comment])
        );
    }

    public function testDifferentPeopleEachCount(): void
    {
        $video = $this->video();
        $comment = $this->post($video, 'a@example.com', 'Objectionable.');

        $this->comments->report($comment, 'one@example.com', '');
        $this->comments->report($comment, 'two@example.com', '');

        self::assertSame(
            2,
            (int) $this->db()->value('SELECT report_count FROM {comments} WHERE id = ?', [$comment])
        );
    }

    // -------------------------------------------------------------- deletion

    public function testDeletingAVideoTakesItsCommentsWithIt(): void
    {
        $video = $this->video();
        $this->post($video, 'a@example.com', 'Goodbye.');

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$video]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {comments}'));
    }

    public function testDeletingACommentTakesItsRepliesWithIt(): void
    {
        $video = $this->video();
        $parent = $this->post($video, 'a@example.com', 'Parent.');
        $this->post($video, 'b@example.com', 'Reply.', $parent);

        $this->comments->delete($parent);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {comments}'));
    }

    // --------------------------------------------------------------- fixtures

    private function post(
        int $videoId,
        string $email,
        string $body,
        ?int $parentId = null,
        string $status = CommentPolicy::STATUS_APPROVED
    ): int {
        return $this->comments->create(
            $videoId,
            $parentId,
            'Someone',
            $email,
            $body,
            $status,
            '203.0.113.1'
        )['id'];
    }

    private function video(): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id' => 'bunny-' . $suffix,
            'slug'        => 'video-' . $suffix,
            'title'       => 'A video',
            'status'      => 'ready',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }
}
