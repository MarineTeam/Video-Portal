<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Reader\BookRepository;
use Portal\Reader\SongRepository;

/**
 * What a licence return is allowed to say.
 *
 * The rule: a return counts USES and never lookups. It is a legal document
 * with money attached, and one that overstates is worse than one that is late.
 *
 * Against a real database because the other half of it is a UNIQUE key —
 * "saving a plan three times is one Sunday" is a property of the index.
 */
final class SongReportTest extends DatabaseTestCase
{
    private BookRepository $books;
    private SongRepository $songs;
    private int $bookId;

    protected function setUp(): void
    {
        $this->truncate([
            'song_uses', 'book_songs', 'hymn_lookups', 'book_contents', 'books',
            'service_plan_items', 'rota_services', 'rota_teams', 'users',
        ]);

        $this->books = new BookRepository($this->db());
        $this->songs = new SongRepository($this->db());

        $this->bookId = $this->books->create('Hymns Ancient and Modern');
        $this->books->update($this->bookId, ['_whole_form' => true, 'is_hymnal' => true, 'is_published' => true]);
        $this->books->addEntry($this->bookId, 'Abide with me', 30, 27);
        $this->books->addEntry($this->bookId, 'And can it be', 40, 28);
    }

    // ------------------------------------------ a return counts uses only

    /**
     * THE RULE. Somebody opening a hymn on a phone is not a performance, and a
     * return that counts it overstates.
     */
    public function testLookupsDoNotReachTheReport(): void
    {
        $this->songs->recordUse($this->bookId, 27, '2026-09-06');

        // Fifty people look hymn 28 up and nobody sings it.
        for ($n = 0; $n < 50; $n++) {
            $this->books->countLookup($this->bookId, 28);
        }

        $report = $this->songs->report('2026-09-01', '2026-09-30');

        self::assertSame(1, $report['total'], 'A LOOKUP WAS COUNTED AS A PERFORMANCE');
        self::assertSame(27, (int) $report['songs'][0]['number']);
        self::assertSame(1, (int) $report['songs'][0]['times']);
    }

    /** And the numbers on it do not move when lookups pile up. */
    public function testTheReportIsUnchangedByLookups(): void
    {
        $this->songs->recordUse($this->bookId, 27, '2026-09-06');

        $before = $this->songs->report('2026-09-01', '2026-09-30');

        for ($n = 0; $n < 20; $n++) {
            $this->books->countLookup($this->bookId, 27);
        }

        self::assertEquals(
            $before,
            $this->songs->report('2026-09-01', '2026-09-30'),
            'lookups moved a number on a document somebody signs'
        );
    }

    // --------------------------------------------------- counting Sundays

    /**
     * Saving a plan three times is one Sunday.
     *
     * The return has to be a count of services, not of button presses, and the
     * unique key is what makes that true rather than a hope about callers.
     */
    public function testRecordingTheSameServiceTwiceIsOneUse(): void
    {
        self::assertTrue($this->songs->recordUse($this->bookId, 27, '2026-09-06', 5, SongRepository::FROM_PLAN));
        self::assertFalse($this->songs->recordUse($this->bookId, 27, '2026-09-06', 5, SongRepository::FROM_PLAN));

        self::assertSame(1, (int) $this->songs->report('2026-09-01', '2026-09-30')['songs'][0]['times']);
    }

    /**
     * But the same song at two different services on one day is two uses, and
     * a return should say so.
     */
    public function testTheSameSongAtTwoServicesIsTwoUses(): void
    {
        $this->songs->recordUse($this->bookId, 27, '2026-09-06', 5, SongRepository::FROM_PLAN);
        $this->songs->recordUse($this->bookId, 27, '2026-09-06', 6, SongRepository::FROM_PLAN);

        self::assertSame(2, (int) $this->songs->report('2026-09-01', '2026-09-30')['songs'][0]['times']);
    }

    public function testTheRangeBoundsTheReport(): void
    {
        $this->songs->recordUse($this->bookId, 27, '2026-08-30');
        $this->songs->recordUse($this->bookId, 28, '2026-09-06');

        $report = $this->songs->report('2026-09-01', '2026-09-30');

        self::assertSame(1, $report['total']);
        self::assertSame(28, (int) $report['songs'][0]['number']);
    }

    // --------------------------------------------- what it cannot include

    /**
     * A song with no CCLI number cannot go on a return. Leaving it out quietly
     * would produce one that looks complete and is not.
     */
    public function testSongsWithNoCcliNumberAreCountedSeparatelyAndNotDropped(): void
    {
        $this->songs->saveSong($this->bookId, 27, ['ccli_number' => '43190', 'author' => 'H F Lyte']);
        $this->songs->recordUse($this->bookId, 27, '2026-09-06');
        $this->songs->recordUse($this->bookId, 28, '2026-09-06');

        $report = $this->songs->report('2026-09-01', '2026-09-30');

        self::assertSame(2, $report['total'], 'a song with no number vanished from the report');
        self::assertSame(1, $report['withCcli']);
        self::assertSame(1, $report['withoutCcli']);
    }

    public function testTheMetadataReachesTheReport(): void
    {
        $this->songs->saveSong($this->bookId, 27, [
            'author'      => 'H F Lyte',
            'copyright'   => 'Public domain',
            'ccli_number' => '43190',
            'song_key'    => 'Eb',
            'tempo'       => '72',
        ]);
        $this->songs->recordUse($this->bookId, 27, '2026-09-06');

        $song = $this->songs->report('2026-09-01', '2026-09-30')['songs'][0];

        self::assertSame('H F Lyte', (string) $song['author']);
        self::assertSame('43190', (string) $song['ccli_number']);
        self::assertSame('Eb', (string) $song['song_key']);
        self::assertSame('Abide with me', (string) $song['song_title'], 'the title came from the contents');
    }

    // ------------------------------------------- metadata survives a re-index

    /**
     * THE OTHER LOAD-BEARING RULE. Re-indexing a book deletes and rewrites
     * every contents row — metadata hung off one would go with them, and
     * somebody would re-index a hymnal and silently lose every CCLI number.
     */
    public function testReindexingABookKeepsEveryCcliNumber(): void
    {
        $this->songs->saveSong($this->bookId, 27, ['ccli_number' => '43190', 'author' => 'H F Lyte']);

        $this->books->replaceContents($this->bookId, [
            ['title' => 'Abide with me', 'pdf_page' => 31, 'number' => 27],
            ['title' => 'And can it be', 'pdf_page' => 41, 'number' => 28],
        ]);

        $song = (array) $this->songs->song($this->bookId, 27);

        self::assertSame('43190', (string) $song['ccli_number'], 'RE-INDEXING LOST THE CCLI NUMBERS');
        self::assertSame('H F Lyte', (string) $song['author']);
    }

    // --------------------------------------------------- from a service plan

    /**
     * ONLY LINKED ITEMS ARE COUNTED. The reference is free text — "245",
     * "H&M 245", "insert" — and parsing it would be guessing. A guess on a
     * document somebody signs is worse than a gap.
     */
    public function testAnUnlinkedHymnOnAPlanIsNotCountedAndIsReported(): void
    {
        $serviceId = $this->service('2026-09-06');

        $this->planItem($serviceId, 'Abide with me', 'H&M 27', $this->bookId, 27);
        $this->planItem($serviceId, 'Something else', '245', null, null);

        $result = $this->songs->recordFromService($serviceId);

        self::assertSame(1, $result['recorded']);
        self::assertSame(1, $result['unlinked'], 'an unlinked hymn was guessed at or silently ignored');

        $report = $this->songs->report('2026-09-01', '2026-09-30');

        self::assertSame(1, $report['total']);
        self::assertSame(27, (int) $report['songs'][0]['number']);
    }

    /** The date comes from the service, not from when somebody pressed the button. */
    public function testTheUseIsDatedByTheService(): void
    {
        $serviceId = $this->service('2026-09-06');
        $this->planItem($serviceId, 'Abide with me', 'H&M 27', $this->bookId, 27);

        $this->songs->recordFromService($serviceId);

        self::assertSame(
            '2026-09-06',
            (string) $this->db()->value('SELECT on_date FROM {song_uses}')
        );
    }

    /** Recording a plan twice is still one Sunday. */
    public function testRecordingAPlanTwiceIsOneSunday(): void
    {
        $serviceId = $this->service('2026-09-06');
        $this->planItem($serviceId, 'Abide with me', 'H&M 27', $this->bookId, 27);

        $this->songs->recordFromService($serviceId);
        $second = $this->songs->recordFromService($serviceId);

        self::assertSame(0, $second['recorded']);
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {song_uses}'));
    }

    /** Only hymns. A reading is not a song and does not go on a music licence. */
    public function testAReadingOnThePlanIsNotASong(): void
    {
        $serviceId = $this->service('2026-09-06');

        $this->db()->insert('service_plan_items', [
            'service_id'  => $serviceId,
            'kind'        => 'reading',
            'title'       => 'Romans 8',
            'book_id'     => $this->bookId,
            'song_number' => 27,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        self::assertSame(0, $this->songs->recordFromService($serviceId)['recorded']);
    }

    // ----------------------------------------------------------- fixtures

    private function service(string $date): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('rota_services', [
            'title'      => 'Morning service',
            'starts_at'  => $date . ' 10:30:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function planItem(
        int $serviceId,
        string $title,
        string $reference,
        ?int $bookId,
        ?int $number
    ): void {
        $now = date('Y-m-d H:i:s');

        $this->db()->insert('service_plan_items', [
            'service_id'  => $serviceId,
            'kind'        => 'hymn',
            'title'       => $title,
            'reference'   => $reference,
            'book_id'     => $bookId,
            'song_number' => $number,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }
}
