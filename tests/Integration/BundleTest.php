<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Sharing\Bundle;
use Portal\Sharing\BundleRepository;
use Portal\Sharing\Share;
use Portal\Sharing\ShareRepository;

/**
 * Bundles.
 *
 * The property worth defending: a bundle stores share IDS, never titles or
 * status. Everything shown is read from the shares on each render, so revoking
 * a share removes it from the page immediately with no bundle write and no
 * possibility of the two disagreeing. A cached title would eventually display
 * a video that had been revoked, which is the one failure a private-sharing
 * feature cannot afford.
 */
final class BundleTest extends DatabaseTestCase
{
    private ShareRepository $shares;
    private BundleRepository $bundles;
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['bundle_items', 'bundles', 'shares', 'video_categories', 'videos', 'categories']);

        $categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $categories);
        $this->shares = new ShareRepository($this->db(), $this->videos);
        $this->bundles = new BundleRepository($this->db(), $this->shares);
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

    /** @return list<Share> */
    private function shareVideos(string $email, int $count): array
    {
        $shares = [];

        for ($i = 1; $i <= $count; $i++) {
            $shares[] = $this->shares->create($this->makeVideo("Video {$i}"), $email);
        }

        return $shares;
    }

    // ------------------------------------------------------------- threshold

    public function testOneShareDoesNotGetABundle(): void
    {
        $this->shareVideos('a@example.test', 1);

        self::assertNull(
            $this->bundles->ensureFor('a@example.test'),
            'A single share is better served by a direct link than by an index page with one row.'
        );
    }

    public function testASecondShareCreatesABundle(): void
    {
        $this->shareVideos('a@example.test', 2);

        $bundle = $this->bundles->ensureFor('a@example.test');

        self::assertNotNull($bundle);
        self::assertSame('a@example.test', $bundle->recipientEmail);
        self::assertCount(2, $this->bundles->liveItems($bundle->id));
    }

    /**
     * Crossing the threshold must sweep in what the recipient already has,
     * or their first bundle lists two of the five videos they can watch.
     */
    public function testCreatingABundleSweepsInExistingShares(): void
    {
        // Three shares exist before any bundle does.
        $this->shareVideos('a@example.test', 3);

        $bundle = $this->bundles->ensureFor('a@example.test');

        self::assertNotNull($bundle);
        self::assertCount(3, $this->bundles->liveItems($bundle->id));
    }

    /**
     * Enforced by a unique index rather than a check-then-insert, so two
     * shares created in the same instant cannot both create one.
     */
    public function testARecipientOnlyEverHasOneBundle(): void
    {
        $this->shareVideos('a@example.test', 2);

        $first = $this->bundles->ensureFor('a@example.test');
        $second = $this->bundles->ensureFor('a@example.test');
        $third = $this->bundles->ensureFor('a@example.test');

        self::assertNotNull($first);
        self::assertSame($first->id, $second?->id);
        self::assertSame($first->id, $third?->id);

        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {bundles}'));
    }

    public function testDifferentRecipientsGetDifferentBundles(): void
    {
        $this->shareVideos('a@example.test', 2);
        $this->shareVideos('b@example.test', 2);

        $a = $this->bundles->ensureFor('a@example.test');
        $b = $this->bundles->ensureFor('b@example.test');

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame($a->id, $b->id);
    }

    public function testRecipientLookupIsCaseInsensitive(): void
    {
        $this->shareVideos('Person@Example.TEST', 2);

        $bundle = $this->bundles->ensureFor('person@example.test');

        self::assertNotNull($bundle);
        self::assertSame($bundle->id, $this->bundles->forRecipient('PERSON@EXAMPLE.TEST')?->id);
    }

    // --------------------------------------------------- live status is read

    /** The whole point of storing ids. */
    public function testRevokingAShareRemovesItFromTheBundleImmediately(): void
    {
        $shares = $this->shareVideos('a@example.test', 3);
        $bundle = $this->bundles->ensureFor('a@example.test');
        self::assertNotNull($bundle);

        self::assertCount(3, $this->bundles->liveItems($bundle->id));

        $this->shares->revoke($shares[0]->id);

        self::assertCount(
            2,
            $this->bundles->liveItems($bundle->id),
            'A revoked share must vanish from the bundle with no bundle write.'
        );
    }

    public function testAnExpiredShareDisappearsFromTheBundle(): void
    {
        $shares = $this->shareVideos('a@example.test', 3);
        $bundle = $this->bundles->ensureFor('a@example.test');
        self::assertNotNull($bundle);

        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?',
            [$shares[1]->id]
        );

        $live = $this->bundles->liveItems($bundle->id);

        self::assertCount(2, $live);
        self::assertNotContains($shares[1]->id, array_map(static fn (Share $s): string => $s->id, $live));
    }

    /** Restoring one puts it straight back, again with no bundle write. */
    public function testRestoringAShareReturnsItToTheBundle(): void
    {
        $shares = $this->shareVideos('a@example.test', 2);
        $bundle = $this->bundles->ensureFor('a@example.test');
        self::assertNotNull($bundle);

        $this->shares->revoke($shares[0]->id);
        self::assertCount(1, $this->bundles->liveItems($bundle->id));

        $this->shares->restore($shares[0]->id);
        self::assertCount(2, $this->bundles->liveItems($bundle->id));
    }

    public function testTitlesAreNotStoredOnTheBundle(): void
    {
        $this->shareVideos('a@example.test', 2);
        $bundle = $this->bundles->ensureFor('a@example.test');
        self::assertNotNull($bundle);

        // bundle_items carries ids and a timestamp. Nothing else.
        $columns = $this->db()->all('SHOW COLUMNS FROM {bundle_items}');
        $names = array_map(static fn (array $c): string => (string) $c['Field'], $columns);

        sort($names);
        self::assertSame(['added_at', 'bundle_id', 'share_id'], $names);
    }

    public function testItemsAreOrderedBySoonestExpiry(): void
    {
        $shares = $this->shareVideos('a@example.test', 3);
        $bundle = $this->bundles->ensureFor('a@example.test');
        self::assertNotNull($bundle);

        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?',
            [$shares[0]->id]
        );
        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?',
            [$shares[1]->id]
        );

        $live = $this->bundles->liveItems($bundle->id);

        self::assertSame($shares[1]->id, $live[0]->id, 'The most urgent item should come first.');
    }

    // ----------------------------------------------------------------- expiry

    /**
     * The bundle page must never be the thing that expires first, or someone
     * loses their index while the links it lists still work.
     */
    public function testBundleExpiryCoversItsLongestLivedShare(): void
    {
        $this->shares->create($this->makeVideo('Short'), 'a@example.test', ['hours' => 2]);
        $this->shares->create($this->makeVideo('Long'), 'a@example.test', ['hours' => 500]);

        $bundle = $this->bundles->ensureFor('a@example.test');

        self::assertNotNull($bundle);

        $hours = ($bundle->expiresAt->getTimestamp() - time()) / 3600;
        self::assertGreaterThan(499, $hours);
    }

    public function testBundleExpiryOnlyGrows(): void
    {
        $this->shares->create($this->makeVideo('Long'), 'a@example.test', ['hours' => 500]);
        $this->shares->create($this->makeVideo('Also long'), 'a@example.test', ['hours' => 500]);

        $bundle = $this->bundles->ensureFor('a@example.test');
        self::assertNotNull($bundle);
        $before = $bundle->expiresAt->format('Y-m-d H:i:s');

        // A short-lived share arriving later must not pull the bundle in.
        $this->shares->create($this->makeVideo('Short'), 'a@example.test', ['hours' => 1]);
        $after = $this->bundles->ensureFor('a@example.test');

        self::assertNotNull($after);
        self::assertSame($before, $after->expiresAt->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------- cleanup

    public function testABundleWithNothingLiveIsRemoved(): void
    {
        $shares = $this->shareVideos('a@example.test', 2);
        $bundle = $this->bundles->ensureFor('a@example.test');
        self::assertNotNull($bundle);

        foreach ($shares as $share) {
            $this->shares->revoke($share->id);
        }

        self::assertSame(1, $this->bundles->purgeEmpty());
        self::assertNull($this->bundles->find($bundle->id));
    }

    public function testCleanupLeavesUsefulBundlesAlone(): void
    {
        $this->shareVideos('a@example.test', 2);
        $bundle = $this->bundles->ensureFor('a@example.test');
        self::assertNotNull($bundle);

        self::assertSame(0, $this->bundles->purgeEmpty());
        self::assertNotNull($this->bundles->find($bundle->id));
    }

    // -------------------------------------------------------------- identity

    public function testBundleIdsAreUnguessable(): void
    {
        $seen = [];

        for ($i = 0; $i < 25; $i++) {
            $this->shareVideos("person{$i}@example.test", 2);
            $bundle = $this->bundles->ensureFor("person{$i}@example.test");

            self::assertNotNull($bundle);
            self::assertTrue(Bundle::isValidId($bundle->id));
            self::assertArrayNotHasKey($bundle->id, $seen);
            $seen[$bundle->id] = true;
        }
    }

    public function testMalformedBundleIdsAreRefusedWithoutQuerying(): void
    {
        foreach (['', 'short', '../../etc/passwd', "x'; DROP TABLE bundles;--"] as $bad) {
            self::assertNull($this->bundles->find($bad));
            self::assertSame([], $this->bundles->itemIds($bad));
        }
    }

    public function testRecipientMatching(): void
    {
        $this->shareVideos('a@example.test', 2);
        $bundle = $this->bundles->ensureFor('a@example.test');

        self::assertNotNull($bundle);
        self::assertTrue($bundle->isFor('A@Example.TEST'));
        self::assertFalse($bundle->isFor('someone.else@example.test'));
    }
}
