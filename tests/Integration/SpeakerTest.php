<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\SpeakerRepository;

/**
 * The speaker directory.
 *
 * Small, but with one decision worth pinning: deleting a speaker keeps their
 * videos. Refusing to delete anyone who has videos would sound safer and would
 * leave an admin permanently unable to tidy a duplicate they made by a typo.
 */
final class SpeakerTest extends DatabaseTestCase
{
    private SpeakerRepository $speakers;

    protected function setUp(): void
    {
        $this->truncate(['videos', 'speakers', 'slug_aliases']);

        $this->speakers = new SpeakerRepository($this->db());
    }

    public function testCreatingDerivesASlug(): void
    {
        $speaker = $this->speakers->create(['name' => 'Jordan Ellis']);

        self::assertSame('jordan-ellis', $speaker->slug);
    }

    public function testASpeakerNeedsAName(): void
    {
        $this->expectExceptionMessage('A speaker needs a name.');
        $this->speakers->create(['name' => '  ']);
    }

    /** Two people really can share a name. */
    public function testDuplicateNamesGetDistinctAddresses(): void
    {
        $first = $this->speakers->create(['name' => 'Sam Taylor']);
        $second = $this->speakers->create(['name' => 'Sam Taylor']);

        self::assertSame('sam-taylor', $first->slug);
        self::assertSame('sam-taylor-2', $second->slug);
        self::assertNotSame($first->id, $second->id);
    }

    public function testRenamingKeepsTheOldAddressWorking(): void
    {
        $speaker = $this->speakers->create(['name' => 'Jordan Elis']);
        $this->speakers->update($speaker->id, ['slug' => 'jordan-ellis']);

        self::assertSame($speaker->id, $this->speakers->findByAlias('jordan-elis')?->id);
    }

    /**
     * The decision worth defending: attribution is lost, content is not.
     */
    public function testDeletingASpeakerKeepsTheirVideos(): void
    {
        $speaker = $this->speakers->create(['name' => 'Guest']);
        $video = $this->video($speaker->id);

        self::assertSame(1, $this->speakers->videoCount($speaker->id));

        $this->speakers->delete($speaker->id);

        self::assertNull($this->speakers->find($speaker->id));
        self::assertNotNull(
            $this->db()->first('SELECT id FROM {videos} WHERE id = ?', [$video]),
            'Removing a speaker must not remove their videos.'
        );
        self::assertNull(
            $this->db()->value('SELECT speaker_id FROM {videos} WHERE id = ?', [$video]),
            'The video should simply have no speaker now.'
        );
    }

    /**
     * The count drives a confirmation message telling an admin how much
     * attribution they are about to lose, so it needs to be right.
     */
    public function testTheVideoCountIsReportedWithTheList(): void
    {
        $busy = $this->speakers->create(['name' => 'Busy']);
        $this->speakers->create(['name' => 'Quiet']);

        $this->video($busy->id);
        $this->video($busy->id);

        $byName = [];
        foreach ($this->speakers->all() as $speaker) {
            $byName[$speaker->name] = $speaker->videoCount;
        }

        self::assertSame(2, $byName['Busy']);
        self::assertSame(0, $byName['Quiet']);
    }

    /** A deleted video is not attribution anyone still owes. */
    public function testTrashedVideosAreNotCounted(): void
    {
        $speaker = $this->speakers->create(['name' => 'Counted']);
        $video = $this->video($speaker->id);

        $this->db()->execute('UPDATE {videos} SET deleted_at = NOW() WHERE id = ?', [$video]);

        self::assertSame(0, $this->speakers->videoCount($speaker->id));
    }

    private function video(?int $speakerId): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id' => 'bunny-' . $suffix,
            'slug'        => 'video-' . $suffix,
            'title'       => 'A video',
            'status'      => 'ready',
            'speaker_id'  => $speakerId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }
}
