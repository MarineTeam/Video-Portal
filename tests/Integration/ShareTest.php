<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Sharing\Share;
use Portal\Sharing\ShareRepository;

/**
 * Share links.
 *
 * These pin behaviour the predecessor apps arrived at after real incidents,
 * and which is easy to "simplify" back into a bug:
 *
 *   - expiry is a comparison, so a lapsed link can still be extended
 *   - revoke is soft and idempotent; delete is separate and irreversible
 *   - extend refuses a revoked link rather than silently un-revoking it
 *   - a bulk share creates one independent link per pair, never a shared one
 *   - furthest-watched is a high-water mark, so seeking back cannot lower it
 */
final class ShareTest extends DatabaseTestCase
{
    private ShareRepository $shares;
    private VideoRepository $videos;
    private int $videoId;

    protected function setUp(): void
    {
        $this->truncate(['bundle_items', 'bundles', 'shares', 'video_categories', 'videos', 'categories']);

        $categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $categories);
        $this->shares = new ShareRepository($this->db(), $this->videos);

        $this->videoId = $this->makeVideo('A Sermon');
    }

    private function makeVideo(string $title): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider'     => 'bunny',
            'provider_id'  => bin2hex(random_bytes(8)),
            'slug'         => $this->videos->uniqueSlug($title),
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    // ------------------------------------------------------------- creating

    public function testCreatingAShare(): void
    {
        $share = $this->shares->create($this->videoId, 'Someone@Example.TEST');

        self::assertSame('someone@example.test', $share->recipientEmail, 'Recipient should be normalized.');
        self::assertSame('A Sermon', $share->videoTitle, 'Title is denormalized onto the share.');
        self::assertSame(Share::MODE_ACCOUNT, $share->accessMode);
        self::assertTrue($share->isLive());
        self::assertFalse($share->isRevoked());
    }

    public function testShareIdsAreUnguessableAndWellFormed(): void
    {
        $seen = [];

        for ($i = 0; $i < 50; $i++) {
            $share = $this->shares->create($this->videoId, "person{$i}@example.test");

            self::assertTrue(Share::isValidId($share->id));
            self::assertArrayNotHasKey($share->id, $seen, 'Share ids must not repeat.');
            $seen[$share->id] = true;
        }
    }

    public function testInvalidIdsAreRejectedWithoutQuerying(): void
    {
        foreach (['', 'short', '../../etc/passwd', "abc'; DROP TABLE shares;--", str_repeat('a', 200)] as $bad) {
            self::assertNull($this->shares->find($bad), "Should refuse: {$bad}");
        }
    }

    public function testARejectedEmailFailsLoudly(): void
    {
        $this->expectException(HttpException::class);
        $this->shares->create($this->videoId, 'not-an-email');
    }

    public function testExpiryIsClamped(): void
    {
        $tooLong = $this->shares->create($this->videoId, 'a@example.test', ['hours' => 99999]);
        $tooShort = $this->shares->create($this->videoId, 'b@example.test', ['hours' => 0]);

        $hours = static fn (Share $s): int => (int) round(
            ($s->expiresAt->getTimestamp() - $s->createdAt->getTimestamp()) / 3600
        );

        self::assertSame(Share::MAX_HOURS, $hours($tooLong));
        self::assertSame(1, $hours($tooShort));
    }

    public function testBothAccessModesAreSupported(): void
    {
        $account = $this->shares->create($this->videoId, 'a@example.test', ['accessMode' => 'account']);
        $gate = $this->shares->create($this->videoId, 'b@example.test', ['accessMode' => 'gate']);

        self::assertTrue($account->requiresAccount());
        self::assertFalse($gate->requiresAccount());
    }

    public function testAnUnknownAccessModeFallsBackToTheSaferOne(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test', ['accessMode' => 'nonsense']);

        self::assertSame(
            Share::MODE_ACCOUNT,
            $share->accessMode,
            'An unrecognised mode must fall back to requiring an account, not to the open one.'
        );
    }

    // ----------------------------------------------------------------- bulk

    /**
     * The rule that makes per-recipient revocation and tracking possible at
     * all: every pair gets its own link.
     */
    public function testBulkCreatesOneIndependentLinkPerPair(): void
    {
        $second = $this->makeVideo('Another Sermon');

        $result = $this->shares->createBulk(
            [$this->videoId, $second],
            ['a@example.test', 'b@example.test', 'c@example.test']
        );

        self::assertCount(6, $result['created']);

        $ids = array_map(static fn (Share $s): string => $s->id, $result['created']);
        self::assertCount(6, array_unique($ids), 'Every pair must get a distinct link.');
    }

    public function testRevokingOneLinkLeavesTheOthersAlone(): void
    {
        $result = $this->shares->createBulk([$this->videoId], ['a@example.test', 'b@example.test']);
        [$first, $second] = $result['created'];

        $this->shares->revoke($first->id);

        self::assertTrue($this->shares->find($first->id)?->isRevoked());
        self::assertFalse($this->shares->find($second->id)?->isRevoked());
        self::assertTrue($this->shares->find($second->id)?->isLive());
    }

    public function testBulkReportsBadAddressesWithoutSinkingTheBatch(): void
    {
        $result = $this->shares->createBulk(
            [$this->videoId],
            ['good@example.test', 'not-an-email', 'alsogood@example.test']
        );

        self::assertCount(2, $result['created']);
        self::assertArrayHasKey('not-an-email', $result['failed']);
    }

    public function testBulkRefusesAnUnreasonableCrossProduct(): void
    {
        $videos = [];
        for ($i = 0; $i < 40; $i++) {
            $videos[] = $this->makeVideo("Video {$i}");
        }

        $emails = [];
        for ($i = 0; $i < 40; $i++) {
            $emails[] = "person{$i}@example.test";
        }

        $this->expectException(HttpException::class);
        $this->shares->createBulk($videos, $emails);
    }

    public function testDuplicateRecipientsAreCollapsed(): void
    {
        $result = $this->shares->createBulk(
            [$this->videoId],
            ['same@example.test', 'SAME@example.test', ' same@example.test ']
        );

        self::assertCount(1, $result['created']);
    }

    // ------------------------------------------------------------ lifecycle

    public function testRevokeIsIdempotent(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        self::assertTrue($this->shares->revoke($share->id));
        self::assertTrue($this->shares->revoke($share->id), 'Revoking twice should still report success.');
    }

    public function testRestoreBringsBackTheOriginalExpiry(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test', ['hours' => 100]);
        $originalExpiry = $share->expiresAt->format('Y-m-d H:i:s');

        $this->shares->revoke($share->id);
        $result = $this->shares->restore($share->id);

        self::assertTrue($result['ok']);

        $restored = $this->shares->find($share->id);
        self::assertNotNull($restored);
        self::assertFalse($restored->isRevoked());
        self::assertSame(
            $originalExpiry,
            $restored->expiresAt->format('Y-m-d H:i:s'),
            'Restore must put back the original expiry, not invent a new one.'
        );
    }

    public function testRestoringSomethingNotRevokedIsRefused(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        $result = $this->shares->restore($share->id);

        self::assertFalse($result['ok']);
        self::assertSame('not_revoked', $result['reason']);
    }

    /**
     * The reason rows outlive their expiry. If expiry deleted the row, this
     * would be impossible to implement.
     */
    public function testALapsedLinkCanStillBeExtended(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE id = ?',
            [$share->id]
        );

        self::assertFalse($this->shares->find($share->id)?->isLive(), 'Precondition: it has lapsed.');

        $result = $this->shares->extend($share->id, 48);

        self::assertTrue($result['ok']);
        self::assertTrue($this->shares->find($share->id)?->isLive());
    }

    public function testExtendMeasuresFromNowNotFromTheOldExpiry(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ?',
            [$share->id]
        );

        $this->shares->extend($share->id, 24);

        $extended = $this->shares->find($share->id);
        self::assertNotNull($extended);

        $hoursFromNow = ($extended->expiresAt->getTimestamp() - time()) / 3600;

        self::assertGreaterThan(
            23,
            $hoursFromNow,
            'Extending from the old expiry would give a window that already elapsed.'
        );
    }

    /**
     * Otherwise Extend becomes a silent un-revoke, and someone who revoked a
     * link deliberately would have it quietly reinstated.
     */
    public function testExtendRefusesARevokedLink(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');
        $this->shares->revoke($share->id);

        $result = $this->shares->extend($share->id, 48);

        self::assertFalse($result['ok']);
        self::assertSame('revoked', $result['reason']);
        self::assertTrue($this->shares->find($share->id)?->isRevoked(), 'It must still be revoked.');
    }

    public function testPermanentDeleteRemovesTheRow(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        self::assertTrue($this->shares->deletePermanently($share->id));
        self::assertNull($this->shares->find($share->id));
    }

    public function testBulkActionsReportEachItemIndependently(): void
    {
        $result = $this->shares->createBulk([$this->videoId], ['a@example.test', 'b@example.test']);
        $ids = array_map(static fn (Share $s): string => $s->id, $result['created']);

        $outcome = $this->shares->bulk('revoke', [...$ids, 'notarealshareid00000']);

        self::assertCount(2, $outcome['ok']);
        self::assertCount(1, $outcome['failed']);
    }

    // ------------------------------------------------------------- tracking

    public function testViewsAccumulate(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        $this->shares->recordView($share->id);
        $this->shares->recordView($share->id);
        $this->shares->recordView($share->id);

        $tracked = $this->shares->find($share->id);
        self::assertNotNull($tracked);
        self::assertSame(3, $tracked->viewCount);
        self::assertNotNull($tracked->firstViewedAt);
        self::assertNotNull($tracked->lastViewedAt);
    }

    /** Viewing a link must never extend its life. */
    public function testViewingDoesNotChangeExpiry(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');
        $before = $share->expiresAt->format('Y-m-d H:i:s');

        $this->shares->recordView($share->id);

        self::assertSame($before, $this->shares->find($share->id)?->expiresAt->format('Y-m-d H:i:s'));
    }

    public function testFurthestWatchedIsAHighWaterMark(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        $this->shares->recordPlayback($share->id, 'progress', 60);
        $this->shares->recordPlayback($share->id, 'progress', 20);

        self::assertSame(
            60,
            $this->shares->find($share->id)?->furthestPercent,
            'Seeking backwards must not lower how much was watched.'
        );
    }

    public function testEndedMarksCompletion(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        $this->shares->recordPlayback($share->id, 'play');
        $this->shares->recordPlayback($share->id, 'ended');

        $tracked = $this->shares->find($share->id);
        self::assertNotNull($tracked);
        self::assertSame(1, $tracked->playCount);
        self::assertSame(100, $tracked->furthestPercent);
        self::assertNotNull($tracked->completedAt);
    }

    public function testPercentIsClamped(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        $this->shares->recordPlayback($share->id, 'progress', 5000);

        self::assertSame(100, $this->shares->find($share->id)?->furthestPercent);
    }

    // -------------------------------------------------------------- cleanup

    public function testCleanupSpareLinksInsideTheGracePeriod(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        // Expired a week ago: well past useful, well inside grace.
        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_SUB(NOW(), INTERVAL 7 DAY) WHERE id = ?',
            [$share->id]
        );

        self::assertSame(0, $this->shares->purgeableCount());
        self::assertSame(0, $this->shares->purgeExpired());
        self::assertNotNull($this->shares->find($share->id), 'Still extendable, so still present.');
    }

    public function testCleanupRemovesLinksPastTheGracePeriod(): void
    {
        $share = $this->shares->create($this->videoId, 'a@example.test');

        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_SUB(NOW(), INTERVAL 90 DAY) WHERE id = ?',
            [$share->id]
        );

        self::assertSame(1, $this->shares->purgeableCount());
        self::assertSame(1, $this->shares->purgeExpired());
        self::assertNull($this->shares->find($share->id));
    }

    public function testCleanupLeavesLiveLinksAlone(): void
    {
        $live = $this->shares->create($this->videoId, 'a@example.test');

        $this->shares->purgeExpired();

        self::assertNotNull($this->shares->find($live->id));
    }

    // ------------------------------------------------------------- matching

    public function testRecipientMatchingIsCaseAndSpaceInsensitive(): void
    {
        $share = $this->shares->create($this->videoId, 'Person@Example.test');

        self::assertTrue($share->isFor('person@example.test'));
        self::assertTrue($share->isFor('  PERSON@EXAMPLE.TEST  '));
        self::assertFalse($share->isFor('someone.else@example.test'));
    }
}
