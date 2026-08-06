<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\SearchQuery;
use Portal\Content\TranscriptParser;
use Portal\Content\TranscriptRepository;
use Portal\Content\Video;
use Portal\Content\VideoRepository;

/**
 * Transcripts against a real database.
 *
 * The parser is tested next door. What only a database can answer is whether a
 * replacement is atomic, whether the flattened copy stays in step with the
 * cues it was derived from, and whether a transcript makes a video findable
 * without swamping the ranking — which is the whole reason to index one.
 */
final class TranscriptTest extends DatabaseTestCase
{
    private TranscriptRepository $transcripts;
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['transcript_cues', 'transcripts', 'video_categories', 'categories', 'videos']);

        $this->transcripts = new TranscriptRepository($this->db());
        $this->videos = new VideoRepository($this->db(), new CategoryRepository($this->db()));
    }

    // -------------------------------------------------------------- storing

    public function testStoringACueSet(): void
    {
        $id = $this->video('A sermon');

        $stored = $this->transcripts->replace($id, $this->cues(), 'Whisper');

        self::assertSame(3, $stored);
        self::assertTrue($this->transcripts->has($id));
        self::assertSame(3, (int) $this->transcripts->find($id)['cue_count']);
        self::assertSame('Whisper', $this->transcripts->find($id)['source']);
    }

    public function testCuesComeBackInOrder(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        self::assertSame(
            ['The first thing said.', 'The second thing said.', 'Something about grace.'],
            array_column($this->transcripts->cues($id), 'text')
        );
    }

    /**
     * Two transcripts of one recording are not something to reconcile — they
     * are one mistake. Replacing must leave no trace of the old take.
     */
    public function testReplacingRemovesTheOldCuesEntirely(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        $this->transcripts->replace($id, TranscriptParser::parse(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nA completely different take."
        ));

        $cues = $this->transcripts->cues($id);

        self::assertCount(1, $cues);
        self::assertSame('A completely different take.', $cues[0]['text']);
        self::assertSame(1, (int) $this->transcripts->find($id)['cue_count']);
    }

    /**
     * The flattened copy is derived. If it can drift from the cues, search
     * finds things the panel cannot show and vice versa.
     */
    public function testTheFlattenedBodyMatchesTheCues(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        self::assertSame(
            TranscriptParser::plainText($this->transcripts->cues($id)),
            (string) $this->transcripts->find($id)['body']
        );
    }

    /**
     * Nothing parsed. Leaving a summary row claiming a transcript exists would
     * render an empty panel and an admin screen saying "0 cues" as though that
     * were a state worth having.
     */
    public function testStoringNothingLeavesNoTranscriptAtAll(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        self::assertSame(0, $this->transcripts->replace($id, []));
        self::assertFalse($this->transcripts->has($id));
        self::assertSame([], $this->transcripts->cues($id));
    }

    public function testDeletingRemovesBothTables(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        $this->transcripts->delete($id);

        self::assertFalse($this->transcripts->has($id));
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {transcript_cues}'));
    }

    public function testDeletingAVideoTakesItsTranscriptWithIt(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$id]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {transcripts}'));
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {transcript_cues}'));
    }

    /** A listing that asked per card would be a query per video. */
    public function testExistenceIsAnsweredInOneQuery(): void
    {
        $with = $this->video('Has one');
        $without = $this->video('Has none');
        $this->transcripts->replace($with, $this->cues());

        $before = $this->db()->queryCount();
        $found = $this->transcripts->existingFor([$with, $without]);
        $after = $this->db()->queryCount();

        self::assertSame([$with], $found);
        self::assertSame(1, $after - $before);
    }

    public function testAskingAboutNoVideosCostsNoQueries(): void
    {
        $before = $this->db()->queryCount();
        self::assertSame([], $this->transcripts->existingFor([]));
        self::assertSame($before, $this->db()->queryCount());
    }

    // --------------------------------------------------------------- finding

    /**
     * The reason to index a transcript at all: finding the recording where
     * somebody said a particular thing, when nothing else on the video
     * mentions it.
     */
    public function testATranscriptMakesAVideoFindable(): void
    {
        $id = $this->video('Untitled recording');
        $this->transcripts->replace($id, $this->cues());

        self::assertSame(['Untitled recording'], $this->titles('grace'));
    }

    /**
     * And it must not swamp the ranking. A transcript is tens of thousands of
     * words, so almost every common word is in almost every one — weighting it
     * near a title would return the whole library in arbitrary order.
     */
    public function testATitleStillOutranksATranscript(): void
    {
        $spoken = $this->video('Untitled recording');
        $this->transcripts->replace($spoken, $this->cues());

        $this->video('Grace');

        self::assertSame(['Grace', 'Untitled recording'], $this->titles('grace'));
    }

    public function testAVideoWithNoTranscriptIsUnaffected(): void
    {
        $this->video('Grace');

        self::assertSame(['Grace'], $this->titles('grace'));
    }

    /** Timestamps must never be searchable, or "00" matches every transcript. */
    public function testTimestampsAreNotIndexed(): void
    {
        $id = $this->video('Untitled recording');
        $this->transcripts->replace($id, $this->cues());

        self::assertSame([], $this->titles('00:00:04'));
    }

    // -------------------------------------------------------------- moments

    /**
     * A search result points at a moment somebody can click, so every term has
     * to be in the SAME cue — a "moment" spanning two unrelated sentences is
     * not a moment.
     */
    public function testTheFirstMatchingCueIsTheMoment(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        $match = $this->transcripts->firstMatch($id, ['grace']);

        self::assertNotNull($match);
        self::assertSame(8, $match['start']);
        self::assertSame('Something about grace.', $match['text']);
    }

    public function testEveryTermMustBeInTheSameCue(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        // "first" and "grace" are each present, in different cues.
        self::assertNull($this->transcripts->firstMatch($id, ['first', 'grace']));
    }

    public function testNoTermsIsNoMatch(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        self::assertNull($this->transcripts->firstMatch($id, []));
    }

    /**
     * Built from the flattened body rather than a cue, because a phrase often
     * straddles two cues and a snippet cut at a boundary reads as though the
     * sentence was interrupted.
     */
    public function testASnippetCanSpanTwoCues(): void
    {
        $id = $this->video('A sermon');
        $this->transcripts->replace($id, $this->cues());

        $snippet = $this->transcripts->snippet($id, ['second']);

        self::assertStringContainsString('second thing said', $snippet);
        self::assertStringContainsString('grace', $snippet, 'The snippet stopped at a cue boundary.');
    }

    public function testASnippetOfNothingIsEmpty(): void
    {
        $id = $this->video('A sermon');

        self::assertSame('', $this->transcripts->snippet($id, ['anything']));
    }

    // --------------------------------------------------------------- fixtures

    /** @return list<string> */
    private function titles(string $query): array
    {
        return array_map(
            static fn (Video $v): string => $v->title,
            $this->videos->query(['search' => $query], 1, 50)['items']
        );
    }

    /** @return list<array{start: int, end: int, text: string}> */
    private function cues(): array
    {
        return TranscriptParser::parse(<<<VTT
        WEBVTT

        00:00:01.000 --> 00:00:04.000
        The first thing said.

        00:00:04.000 --> 00:00:08.000
        The second thing said.

        00:00:08.000 --> 00:00:12.000
        Something about grace.
        VTT);
    }

    private function video(string $title): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
