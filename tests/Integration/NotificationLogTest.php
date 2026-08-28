<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\NotificationLog;

/**
 * The record of what the site has told somebody.
 *
 * Against a real database, because the property worth proving is that one
 * person cannot touch another person's rows — and that is enforced by an
 * email in a WHERE clause rather than by anything a fake would exercise.
 */
final class NotificationLogTest extends DatabaseTestCase
{
    private NotificationLog $log;

    protected function setUp(): void
    {
        $this->truncate(['notifications']);
        $this->log = new NotificationLog($this->db());
    }

    // ------------------------------------------------------------- recording

    public function testItKeepsWhatWasSent(): void
    {
        $this->log->record('reader@example.com', NotificationLog::EMAIL, 'A new sermon', '/watch/a-new-sermon');

        $rows = $this->log->forEmail('reader@example.com');

        self::assertCount(1, $rows);
        self::assertSame('A new sermon', $rows[0]['title']);
        self::assertSame('/watch/a-new-sermon', $rows[0]['url']);
        self::assertSame('email', $rows[0]['channel']);
        self::assertNull($rows[0]['read_at']);
    }

    /**
     * The address is normalised on the way in, so a subscription created as
     * "Reader@Example.com" is still found by an account signing in as
     * "reader@example.com". Without this the record would silently belong to
     * nobody.
     */
    public function testTheAddressIsNormalised(): void
    {
        $this->log->record('  Reader@Example.COM ', NotificationLog::EMAIL, 'Mixed case');

        self::assertCount(1, $this->log->forEmail('reader@example.com'));
    }

    /** An unknown channel is stored as email rather than as itself. */
    public function testTheChannelIsConstrained(): void
    {
        $this->log->record('reader@example.com', 'carrier-pigeon', 'Nope');

        self::assertSame('email', $this->log->forEmail('reader@example.com')[0]['channel']);
    }

    /**
     * Recording must never be able to break the thing it is recording.
     *
     * A title longer than the column is the realistic way that happens, and it
     * has to be truncated here rather than left to the database — which in
     * strict mode raises "Data too long" and would turn a delivered
     * notification into a failed cron job.
     */
    public function testAnOverlongTitleDoesNotThrow(): void
    {
        $this->log->record('reader@example.com', NotificationLog::EMAIL, str_repeat('x', 900));

        $rows = $this->log->forEmail('reader@example.com');
        self::assertCount(1, $rows);
        self::assertSame(300, mb_strlen((string) $rows[0]['title']));
    }

    // -------------------------------------------------------------- ownership

    /**
     * The property this class exists to get right.
     *
     * Ids are sequential, so an action taking only an id would let anybody
     * mark — and, worse, DELETE — a stranger's row by counting. Each of the
     * three mutating methods is checked against somebody else's id rather than
     * only against their own.
     */
    public function testOneReaderCannotTouchAnothersRows(): void
    {
        $this->log->record('mine@example.com', NotificationLog::EMAIL, 'Mine');
        $this->log->record('theirs@example.com', NotificationLog::EMAIL, 'Theirs');

        $theirs = (int) $this->log->forEmail('theirs@example.com')[0]['id'];

        $this->log->markRead($theirs, 'mine@example.com');
        self::assertNull(
            $this->log->forEmail('theirs@example.com')[0]['read_at'],
            'marking read ignored the address and reached across accounts'
        );

        $this->log->delete($theirs, 'mine@example.com');
        self::assertCount(
            1,
            $this->log->forEmail('theirs@example.com'),
            'delete ignored the address and destroyed another account\'s row'
        );

        // And clearing is scoped too — the loudest way to get this wrong.
        $this->log->clear('mine@example.com');
        self::assertCount(1, $this->log->forEmail('theirs@example.com'));
        self::assertCount(0, $this->log->forEmail('mine@example.com'));
    }

    public function testMarkingReadAndCounting(): void
    {
        $this->log->record('reader@example.com', NotificationLog::EMAIL, 'One');
        $this->log->record('reader@example.com', NotificationLog::PUSH, 'Two');

        self::assertSame(2, $this->log->unreadCount('reader@example.com'));

        $first = (int) $this->log->forEmail('reader@example.com')[0]['id'];
        $this->log->markRead($first, 'reader@example.com');

        self::assertSame(1, $this->log->unreadCount('reader@example.com'));

        self::assertSame(1, $this->log->markAllRead('reader@example.com'));
        self::assertSame(0, $this->log->unreadCount('reader@example.com'));
    }

    /**
     * Marking read twice must not keep moving the timestamp. read_at is when
     * they first saw it, not when they last loaded the page.
     */
    public function testMarkingReadIsIdempotent(): void
    {
        $this->log->record('reader@example.com', NotificationLog::EMAIL, 'One');
        $id = (int) $this->log->forEmail('reader@example.com')[0]['id'];

        $this->log->markRead($id, 'reader@example.com');
        $first = $this->log->forEmail('reader@example.com')[0]['read_at'];

        $this->log->markRead($id, 'reader@example.com');
        self::assertSame($first, $this->log->forEmail('reader@example.com')[0]['read_at']);
    }

    /** Newest first — a list that started with the oldest would be unreadable. */
    public function testNewestFirst(): void
    {
        foreach (['Oldest', 'Middle', 'Newest'] as $title) {
            $this->log->record('reader@example.com', NotificationLog::EMAIL, $title);
        }

        $titles = array_map(
            static fn (array $r): string => (string) $r['title'],
            $this->log->forEmail('reader@example.com')
        );

        self::assertSame(['Newest', 'Middle', 'Oldest'], $titles);
    }

    /** An address with nothing recorded is an empty list, never an error. */
    public function testAnUnknownAddressHasNothing(): void
    {
        self::assertSame([], $this->log->forEmail('nobody@example.com'));
        self::assertSame(0, $this->log->unreadCount('nobody@example.com'));
    }
}
