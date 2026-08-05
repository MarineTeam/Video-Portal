<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\Announcement;
use Portal\Content\AnnouncementRepository;
use Portal\Http\HttpException;

/**
 * Site banners.
 *
 * Two rules carry it: the window decides when, and the audience decides who.
 * The audience is not a security boundary — it decides who is bothered by a
 * message, not who could read one — and the tests say so explicitly, because a
 * later reader might otherwise take these for access control and put something
 * confidential in a banner.
 */
final class AnnouncementTest extends DatabaseTestCase
{
    private AnnouncementRepository $announcements;

    protected function setUp(): void
    {
        $this->truncate(['announcements']);

        $this->announcements = new AnnouncementRepository($this->db());
    }

    // ---------------------------------------------------------------- window

    public function testAnAnnouncementWithNoDatesShowsNow(): void
    {
        $this->announcements->create(['body' => 'Hello.']);

        self::assertCount(1, $this->showing());
    }

    public function testOneThatHasNotStartedIsNotShowing(): void
    {
        $this->announcements->create(['body' => 'Later.', 'starts_at' => '+2 days']);

        self::assertSame([], $this->showing());
    }

    public function testOneThatHasEndedIsNotShowing(): void
    {
        $this->announcements->create([
            'body'      => 'Over.',
            'starts_at' => '-2 days',
            'ends_at'   => '-1 day',
        ]);

        self::assertSame([], $this->showing());
    }

    public function testOneInsideItsWindowIsShowing(): void
    {
        $this->announcements->create([
            'body'      => 'Now.',
            'starts_at' => '-1 hour',
            'ends_at'   => '+1 hour',
        ]);

        self::assertCount(1, $this->showing());
    }

    /** Switching it off is not the same as it having ended. */
    public function testAnInactiveAnnouncementIsNotShowing(): void
    {
        $created = $this->announcements->create(['body' => 'Hidden.']);
        $this->announcements->update($created->id, ['is_active' => false]);

        self::assertSame([], $this->showing());
        self::assertCount(1, $this->announcements->all());
    }

    // -------------------------------------------------------------- audience

    public function testEverybodySeesAPublicNotice(): void
    {
        $this->announcements->create(['body' => 'Open day.', 'audience' => Announcement::EVERYONE]);

        self::assertCount(1, $this->showing(approved: false, admin: false));
        self::assertCount(1, $this->showing(approved: true, admin: false));
        self::assertCount(1, $this->showing(approved: false, admin: true));
    }

    public function testAMemberNoticeSkipsStrangers(): void
    {
        $this->announcements->create(['body' => 'Members meeting.', 'audience' => Announcement::MEMBERS]);

        self::assertSame([], $this->showing(approved: false, admin: false));
        self::assertCount(1, $this->showing(approved: true, admin: false));
    }

    /**
     * An administrator sees a member-facing notice even if their own account
     * was never explicitly approved. Otherwise the person who wrote it cannot
     * see it, which is how a broken banner goes unnoticed.
     */
    public function testAnAdministratorSeesAMemberNotice(): void
    {
        $this->announcements->create(['body' => 'Members meeting.', 'audience' => Announcement::MEMBERS]);

        self::assertCount(1, $this->showing(approved: false, admin: true));
    }

    public function testAnAdminNoticeIsForAdminsOnly(): void
    {
        $this->announcements->create(['body' => 'Migration tonight.', 'audience' => Announcement::ADMINS]);

        self::assertSame([], $this->showing(approved: false, admin: false));
        self::assertSame([], $this->showing(approved: true, admin: false));
        self::assertCount(1, $this->showing(approved: false, admin: true));
    }

    /**
     * An unrecognised audience narrows rather than widens. Getting this wrong
     * should under-share.
     */
    public function testAnUnknownAudienceFallsBackToAdminsOnly(): void
    {
        self::assertSame(Announcement::ADMINS, Announcement::sanitizeAudience('everybody-ish'));
        self::assertSame(Announcement::ADMINS, Announcement::sanitizeAudience(null));
        self::assertSame(Announcement::ADMINS, Announcement::sanitizeAudience(''));

        self::assertSame(Announcement::EVERYONE, Announcement::sanitizeAudience('everyone'));
    }

    /** An unknown tone is cosmetic, so it falls back to the quiet one. */
    public function testAnUnknownLevelFallsBackToInformation(): void
    {
        self::assertSame(Announcement::INFO, Announcement::sanitizeLevel('catastrophe'));
        self::assertSame(Announcement::WARNING, Announcement::sanitizeLevel('warning'));
    }

    // ---------------------------------------------------------------- order

    public function testAnnouncementsComeBackInOrder(): void
    {
        $this->announcements->create(['body' => 'First.']);
        $this->announcements->create(['body' => 'Second.']);

        self::assertSame(
            ['First.', 'Second.'],
            array_map(static fn (Announcement $a): string => $a->body, $this->showing())
        );
    }

    // --------------------------------------------------------------- writing

    public function testAnEmptyMessageIsRefused(): void
    {
        $this->expectException(HttpException::class);
        $this->announcements->create(['body' => '   ']);
    }

    public function testEmptyingAnExistingMessageIsRefused(): void
    {
        $created = $this->announcements->create(['body' => 'Something.']);

        $this->expectException(HttpException::class);
        $this->announcements->update($created->id, ['body' => '']);
    }

    /** A window that never opens produces a banner nobody ever sees. */
    public function testABackwardsWindowIsRefused(): void
    {
        $this->expectException(HttpException::class);
        $this->announcements->create([
            'body'      => 'Impossible.',
            'starts_at' => '2026-09-30 10:00:00',
            'ends_at'   => '2026-09-01 10:00:00',
        ]);
    }

    /** And refused when only one half is being changed. */
    public function testABackwardsWindowIsRefusedOnUpdateToo(): void
    {
        $created = $this->announcements->create([
            'body'      => 'Fine for now.',
            'starts_at' => '2026-09-30 10:00:00',
        ]);

        $this->expectException(HttpException::class);
        $this->announcements->update($created->id, ['ends_at' => '2026-09-01 10:00:00']);
    }

    public function testAnUnusableDateIsRefused(): void
    {
        $this->expectException(HttpException::class);
        $this->announcements->create(['body' => 'When?', 'starts_at' => 'next Thorsday']);
    }

    public function testDatesRoundTrip(): void
    {
        $created = $this->announcements->create([
            'body'      => 'Timed.',
            'starts_at' => '2026-09-01T10:30',
            'ends_at'   => '2026-09-30T23:00',
        ]);

        self::assertSame('2026-09-01 10:30:00', $created->startsAt);
        self::assertSame('2026-09-30 23:00:00', $created->endsAt);
    }

    public function testClearingADateWorks(): void
    {
        $created = $this->announcements->create(['body' => 'Timed.', 'ends_at' => '+1 day']);

        $updated = $this->announcements->update($created->id, ['ends_at' => '']);

        self::assertNull($updated->endsAt);
    }

    public function testDeletingRemovesIt(): void
    {
        $created = $this->announcements->create(['body' => 'Temporary.']);
        $this->announcements->delete($created->id);

        self::assertSame([], $this->announcements->all());
    }

    // --------------------------------------------------------------- fixtures

    /** @return list<Announcement> */
    private function showing(bool $approved = true, bool $admin = true): array
    {
        return $this->announcements->showing($approved, $admin);
    }
}
