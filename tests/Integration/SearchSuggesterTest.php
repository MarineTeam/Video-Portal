<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\SearchSuggester;
use Portal\Content\SeriesRepository;
use Portal\Content\SpeakerRepository;
use Portal\Content\VideoRepository;

/**
 * "Did you mean" — and the check that decides whether to say it.
 *
 * Against a real database because the vocabulary is a query, and because the
 * rule under test is about what the vocabulary is allowed to reveal. A double
 * would be handed whatever word list the test wanted and would prove nothing
 * about the one the application builds.
 */
final class SearchSuggesterTest extends DatabaseTestCase
{
    private SearchSuggester $suggester;
    private VideoRepository $videos;
    private CategoryRepository $categories;
    private SeriesRepository $series;
    private SpeakerRepository $speakers;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'categories', 'videos', 'series', 'speakers', 'tags']);

        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
        $this->series = new SeriesRepository($this->db());
        $this->speakers = new SpeakerRepository($this->db());
        $this->suggester = new SearchSuggester($this->db());
    }

    /**
     * The verification a real caller supplies: run the corrected query through
     * the ordinary listing and say how many it found.
     *
     * @param array<string, mixed> $filters what this viewer is allowed to see
     */
    private function seenBy(array $filters = []): callable
    {
        return fn (string $candidate): int => $this->videos->query(
            ['search' => $candidate] + $filters,
            1,
            1
        )['total'];
    }

    private function video(string $title, bool $memberOnly = false, bool $published = true): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => $published ? 1 : 0,
            'member_only'  => $memberOnly ? 1 : 0,
            'published_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    // -------------------------------------------------------- it suggests

    public function testAMisspelledTitleWordIsCorrected(): void
    {
        $this->video('On Marriage');

        self::assertSame('marriage', $this->suggester->suggest('marrige', $this->seenBy()));
    }

    public function testASpeakerNameIsPartOfTheVocabulary(): void
    {
        $speakerId = $this->speakers->create(['name' => 'Okonkwo'])->id;
        $this->db()->execute(
            'UPDATE {videos} SET speaker_id = ? WHERE id = ?',
            [$speakerId, $this->video('A talk')]
        );

        self::assertSame('okonkwo', $this->suggester->suggest('okonkwa', $this->seenBy()));
    }

    public function testASeriesTitleIsPartOfTheVocabulary(): void
    {
        $seriesId = $this->series->create(['title' => 'Ecclesiastes'])->id;
        $this->db()->execute(
            'UPDATE {videos} SET series_id = ? WHERE id = ?',
            [$seriesId, $this->video('Part one')]
        );

        self::assertSame('ecclesiastes', $this->suggester->suggest('eclesiastes', $this->seenBy()));
    }

    // ------------------------------------------------ THE RULE: verified

    /**
     * THE RULE. A correction drawn from a members-only title is never offered
     * to somebody who cannot see that title.
     *
     * The vocabulary contains the word — deliberately, because filtering the
     * vocabulary would mean writing the visibility rules a second time. What
     * stops the leak is that the corrected search is run as the asking viewer
     * and discarded when it finds nothing they can open.
     *
     * Both directions, because a check that only asserts the refusal cannot
     * tell a working guard from one that refuses everything.
     */
    public function testAMembersOnlyTitleIsNeverSuggestedToSomebodyWhoCannotSeeIt(): void
    {
        $this->video('Marriage Preparation', memberOnly: true);

        self::assertContains(
            'marriage',
            $this->suggester->vocabulary(),
            'the fixture is wrong: the word is not in the vocabulary, so the guard is untested'
        );

        self::assertNull(
            $this->suggester->suggest('marrige', $this->seenBy()),
            'a members-only title was named to a signed-out visitor'
        );

        // And the same word IS suggested to somebody allowed to see it, which
        // is what proves the refusal above was the rule and not a dead branch.
        self::assertSame(
            'marriage',
            $this->suggester->suggest('marrige', $this->seenBy(['includeMemberOnly' => true]))
        );
    }

    /** The same again for a draft, which is the other way a title is not public. */
    public function testAnUnpublishedTitleIsNotSuggestedEither(): void
    {
        $this->video('Covenant Renewal', published: false);

        self::assertNull($this->suggester->suggest('covenat renewal', $this->seenBy()));
        self::assertSame(
            'covenant renewal',
            $this->suggester->suggest('covenat renewal', $this->seenBy(['includeUnpublished' => true]))
        );
    }

    /**
     * A correction that finds nothing is not offered.
     *
     * The same check does both jobs: a suggestion nobody can act on is as
     * useless as one that leaks, and both are refused for the same reason.
     */
    public function testACorrectionThatFindsNothingIsWithheld(): void
    {
        $this->video('On Marriage');

        // The word exists, so a correction is available — but the caller says
        // nothing matched, which is what a filtered search would report.
        self::assertNull($this->suggester->suggest('marrige', static fn (string $q): int => 0));
    }

    // ---------------------------------------------------- the vocabulary

    /** A trashed video's words are noise — suggesting one leads to a 404. */
    public function testATrashedVideoDoesNotContributeWords(): void
    {
        $id = $this->video('Habakkuk');
        $this->videos->softDelete($id);

        self::assertNotContains('habakkuk', (new SearchSuggester($this->db()))->vocabulary());
    }

    public function testShortWordsAreNotKept(): void
    {
        self::assertSame(['marriage', 'preparation'], SearchSuggester::words('On Marriage: a Preparation'));
    }

    /** An apostrophe stays inside a word rather than splitting it. */
    public function testAnApostropheDoesNotSplitAWord(): void
    {
        self::assertSame(["god's", 'faithfulness'], SearchSuggester::words("God's faithfulness"));
    }

    public function testAnEmptyLibrarySuggestsNothing(): void
    {
        self::assertSame([], $this->suggester->vocabulary());
        self::assertNull($this->suggester->suggest('marrige', $this->seenBy()));
    }
}
