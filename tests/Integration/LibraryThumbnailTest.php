<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\ThumbnailPolicy;
use Portal\Content\Video;
use Portal\Content\VideoPresenter;
use Portal\Content\VideoRepository;
use Portal\Tests\Support\RecordingVideoProvider;

require_once __DIR__ . '/../Support/RecordingVideoProvider.php';

/**
 * What a listing actually hands a template.
 *
 * This file exists because of a test that looked right and proved nothing. The
 * smoke test asserted that a signed-out visitor's HTML contained no CDN URL,
 * and it passed — but it kept passing after the guard was deliberately deleted,
 * because the bundled theme happens not to print a URL for a locked card. The
 * check was testing the theme.
 *
 * The guarantee worth having is one level up: a locked card carries no URL at
 * all, so no theme — including one somebody else wrote, which has never heard
 * of `membersOnly` — can reveal artwork by reading the obvious field.
 */
final class LibraryThumbnailTest extends DatabaseTestCase
{
    private RecordingVideoProvider $provider;
    private CategoryRepository $categories;
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'categories', 'videos']);

        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
        $this->provider = new RecordingVideoProvider();
    }

    // ---------------------------------------------------------------- locked

    public function testALockedCardCarriesNoThumbnailUrlAtAll(): void
    {
        $card = $this->card($this->lockedVideo(), canWatch: false);

        self::assertTrue($card['membersOnly']);
        self::assertNull(
            $card['thumbnail'],
            'Relying on the theme to withhold the URL leaves the artwork one careless template away.'
        );
    }

    /**
     * Stronger than the assertion above, and the one that would have caught the
     * original mistake: the provider is never even asked. A URL that is minted
     * and then discarded still existed, and the next refactor puts it back into
     * the array.
     */
    public function testTheProviderIsNeverAskedForALockedThumbnail(): void
    {
        $this->card($this->lockedVideo(), canWatch: false);

        self::assertSame(
            0,
            $this->provider->thumbnailCalls,
            'A visitor who cannot watch must not cause a signed thumbnail URL to be minted.'
        );
    }

    public function testSomeoneWhoCanWatchStillGetsTheArtwork(): void
    {
        $card = $this->card($this->lockedVideo(), canWatch: true);

        self::assertFalse($card['membersOnly']);
        self::assertSame(RecordingVideoProvider::THUMBNAIL, $card['thumbnail']);
    }

    /**
     * The title is deliberately still there. Withholding artwork is the whole
     * feature; hiding the video is `member_only`, a different setting with a
     * different meaning.
     */
    public function testALockedCardStillCarriesItsTitleAndLink(): void
    {
        $card = $this->card($this->lockedVideo(), canWatch: false);

        self::assertSame('A video', $card['title']);
        self::assertNotSame('', $card['url']);
    }

    // -------------------------------------------------------------- unlocked

    public function testAnOrdinaryVideoIsUnaffected(): void
    {
        $card = $this->card($this->video(), canWatch: false);

        self::assertFalse($card['membersOnly']);
        self::assertSame(RecordingVideoProvider::THUMBNAIL, $card['thumbnail']);
    }

    public function testTheSiteDefaultLocksEverythingWithNoOpinion(): void
    {
        $card = $this->card($this->video(), canWatch: false, siteDefault: true);

        self::assertTrue($card['membersOnly']);
        self::assertNull($card['thumbnail']);
        self::assertSame(0, $this->provider->thumbnailCalls);
    }

    // ------------------------------------------------------------ degradation

    /**
     * With no video provider configured at all — a fresh install, before the
     * Services step — a listing still renders. It did before this feature and
     * it must still.
     */
    public function testNoProviderStillProducesUsableCards(): void
    {
        $presenter = new VideoPresenter($this->videos, null);

        $cards = $presenter->cards([$this->video()], true, false);

        self::assertCount(1, $cards);
        self::assertNull($cards[0]['thumbnail']);
        self::assertFalse($cards[0]['membersOnly']);
    }

    // --------------------------------------------------------------- fixtures

    /** @return array<string, mixed> */
    private function card(Video $video, bool $canWatch, bool $siteDefault = false): array
    {
        $presenter = new VideoPresenter($this->videos, $this->provider);

        $cards = $presenter->cards([$video], $canWatch, $siteDefault);
        self::assertCount(1, $cards);

        return $cards[0];
    }

    private function lockedVideo(): Video
    {
        $category = $this->categories->create(['name' => 'Members ' . bin2hex(random_bytes(3))]);
        $this->categories->update($category->id, ['thumbnail_mode' => ThumbnailPolicy::MEMBERS]);

        $video = $this->video();
        $this->videos->setCategories($video->id, [$category->id]);

        return $video;
    }

    private function video(): Video
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $id = $this->db()->insert('videos', [
            'provider_id'    => 'bunny-' . $suffix,
            'slug'           => 'video-' . $suffix,
            'title'          => 'A video',
            'status'         => 'ready',
            'thumbnail_file' => 'thumbnail.jpg',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $video = $this->videos->find($id);
        self::assertNotNull($video);

        return $video;
    }
}
