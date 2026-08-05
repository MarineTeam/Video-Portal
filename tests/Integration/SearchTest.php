<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\SearchQuery;
use Portal\Content\SeriesRepository;
use Portal\Content\SpeakerRepository;
use Portal\Content\Video;
use Portal\Content\VideoRepository;

/**
 * Search against a real database.
 *
 * The scoring rule is written twice — once in PHP so it can be tested, once in
 * SQL because the ordering has to happen before the LIMIT or the top result is
 * merely the best of whichever page came back first. That duplication is the
 * risk this file exists to contain: the two would drift on the first change and
 * nothing else would notice which one was wrong.
 */
final class SearchTest extends DatabaseTestCase
{
    private VideoRepository $videos;
    private CategoryRepository $categories;
    private SeriesRepository $series;
    private SpeakerRepository $speakers;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'categories', 'videos', 'series', 'speakers']);

        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
        $this->series = new SeriesRepository($this->db());
        $this->speakers = new SpeakerRepository($this->db());
    }

    // ---------------------------------------------------------------- finding

    /**
     * The defect that motivated all of this.
     *
     * Two words in two different fields. The previous implementation put the
     * whole query into one LIKE, so this returned nothing — and a search box
     * that only works for single words is one people stop using.
     */
    public function testTwoWordsMatchingDifferentFieldsStillFindTheVideo(): void
    {
        $seriesId = $this->series->create(['title' => 'Grace Abounding'])->id;
        $this->video('Romans 8', seriesId: $seriesId);

        self::assertSame(['Romans 8'], $this->titles('grace romans'));
    }

    public function testAWordInTheDescriptionMatches(): void
    {
        $this->video('Untitled', description: 'A study of Habakkuk.');

        self::assertSame(['Untitled'], $this->titles('habakkuk'));
    }

    /** Searching somebody's name must find what they said. */
    public function testASpeakerNameMatches(): void
    {
        $speakerId = $this->speakers->create(['name' => 'Jane Okonkwo'])->id;
        $this->video('A talk', speakerId: $speakerId);

        self::assertSame(['A talk'], $this->titles('okonkwo'));
    }

    public function testACategoryNameMatches(): void
    {
        $categoryId = $this->categories->create(['name' => 'Missions'])->id;
        $id = $this->video('Report from abroad');
        $this->videos->setCategories($id, [$categoryId]);

        self::assertSame(['Report from abroad'], $this->titles('missions'));
    }

    public function testEveryTermMustMatch(): void
    {
        $this->video('Romans 8');

        self::assertSame([], $this->titles('romans leviticus'));
    }

    /** A three-letter word is exactly what a FULLTEXT index would have lost. */
    public function testAShortWordIsSearchable(): void
    {
        $this->video('The problem of sin');

        self::assertSame(['The problem of sin'], $this->titles('sin'));
    }

    /**
     * LIKE wildcards typed by a visitor are text, not syntax. Without escaping,
     * a single "%" matches the entire library.
     */
    public function testWildcardCharactersAreTreatedAsText(): void
    {
        $this->video('Plain title');
        $this->video('100% certain');

        self::assertSame(['100% certain'], $this->titles('100%'));

        // A lone "%" finds the one title that literally contains a per-cent
        // sign, and nothing else. Unescaped it would match the whole library,
        // which is the failure worth pinning — not that it matches nothing.
        self::assertSame(['100% certain'], $this->titles('%'));

        // And "_" is a character, not "any character". Unescaped, "_lain"
        // would match "Plain".
        self::assertSame(['Plain title'], $this->titles('plain'));
        self::assertSame([], $this->titles('_lain'));
    }

    public function testAQuotedPhraseMustAppearWhole(): void
    {
        $this->video('Sermon on the mount');
        $this->video('A sermon about the mountain rescue');

        self::assertSame(['Sermon on the mount'], $this->titles('"sermon on the mount"'));
    }

    // --------------------------------------------------------------- ordering

    /**
     * The reason relevance exists at all.
     *
     * The curated order puts pinned videos first. A search must not: somebody
     * who typed an exact title is not asking what the site would like them to
     * watch.
     */
    public function testAnExactTitleBeatsAPinnedVideoThatMerelyMentionsIt(): void
    {
        $pinned = $this->video('Something else entirely', description: 'talks about grace');
        $this->db()->execute('UPDATE {videos} SET pinned = 1 WHERE id = ?', [$pinned]);

        $this->video('Grace');

        self::assertSame(
            ['Grace', 'Something else entirely'],
            $this->titles('grace')
        );
    }

    public function testATitleMatchOutranksADescriptionMatch(): void
    {
        $this->video('Nothing relevant', description: 'a study of Romans');
        $this->video('Romans 8');

        self::assertSame(['Romans 8', 'Nothing relevant'], $this->titles('romans'));
    }

    /**
     * Both candidates match every term — the AND rule is not what is being
     * measured here — but one matches "grace" somewhere that counts for more.
     */
    public function testMatchingATermInAStrongerFieldRanksHigher(): void
    {
        $seriesId = $this->series->create(['title' => 'Grace Abounding'])->id;

        $this->video('Romans 8', description: 'a passing mention of grace');
        $this->video('Romans 9', description: 'a passing mention of grace', seriesId: $seriesId);

        self::assertSame(['Romans 9', 'Romans 8'], $this->titles('grace romans'));
    }

    /**
     * The SQL scorer and the PHP scorer, ranking the same library.
     *
     * Comparing the raw numbers would need a seam that exists only for this
     * test, and would fail on a change that alters nothing anybody can see —
     * halving every weight moves no result. So parity is asserted where it is
     * observable: given candidates hitting every weight tier, the order MySQL
     * produces must be the order SearchQuery::score() predicts.
     *
     * Every tier is represented deliberately. Leave one out and a weight can be
     * changed to any value between its neighbours with nothing to notice.
     */
    public function testMysqlRanksTheSameWayThePhpScorerDoes(): void
    {
        $smith = $this->speakers->create(['name' => 'Grace Smith'])->id;
        $seriesId = $this->series->create(['title' => 'Grace Abounding'])->id;
        $categoryId = $this->categories->create(['name' => 'Grace and Truth'])->id;

        /** @var array<string, array<string, string>> $candidates */
        $candidates = [
            // Exact title.
            'grace' => ['title' => 'grace'],
            // Title prefix.
            'Grace abounding to the chief of sinners' => [
                'title' => 'Grace abounding to the chief of sinners',
            ],
            // Title, mid-string.
            'Amazing grace explained' => ['title' => 'Amazing grace explained'],
            // Speaker only.
            'A talk with no keyword' => ['title' => 'A talk with no keyword', 'speaker' => 'Grace Smith'],
            // Series only.
            'Another talk' => ['title' => 'Another talk', 'series' => 'Grace Abounding'],
            // Category only.
            'A third talk' => ['title' => 'A third talk', 'categories' => 'Grace and Truth'],
            // Description only.
            'A fourth talk' => ['title' => 'A fourth talk', 'description' => 'all about grace'],
        ];

        foreach ($candidates as $title => $fields) {
            $id = $this->video(
                $title,
                description: $fields['description'] ?? null,
                speakerId: isset($fields['speaker']) ? $smith : null,
                seriesId: isset($fields['series']) ? $seriesId : null,
            );

            if (isset($fields['categories'])) {
                $this->videos->setCategories($id, [$categoryId]);
            }
        }

        $terms = SearchQuery::terms('grace');

        $expected = $candidates;
        uasort($expected, static function (array $a, array $b) use ($terms): int {
            return SearchQuery::score($terms, $b) <=> SearchQuery::score($terms, $a);
        });

        self::assertSame(
            array_keys($expected),
            $this->titles('grace'),
            'MySQL ranked these differently from SearchQuery::score().'
        );
    }

    // ------------------------------------------------------------- visibility

    /**
     * Search is a listing like any other, and the single most damaging way to
     * get it wrong is to let it become the one path that ignores the rules.
     */
    public function testSearchDoesNotRevealUnpublishedVideos(): void
    {
        $id = $this->video('Secret plans');
        $this->db()->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$id]);

        self::assertSame([], $this->titles('secret'));
        self::assertSame(['Secret plans'], $this->titles('secret', ['includeUnpublished' => true]));
    }

    public function testSearchDoesNotRevealHiddenVideos(): void
    {
        $id = $this->video('Hidden away');
        $this->db()->execute('UPDATE {videos} SET hidden = 1 WHERE id = ?', [$id]);

        self::assertSame([], $this->titles('hidden'));
    }

    public function testSearchDoesNotRevealTrashedVideos(): void
    {
        $id = $this->video('Deleted thing');
        $this->videos->softDelete($id);

        self::assertSame([], $this->titles('deleted'));
    }

    public function testSearchDoesNotRevealMemberOnlyVideosToStrangers(): void
    {
        $id = $this->video('Members only');
        $this->db()->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$id]);

        self::assertSame([], $this->titles('members'));
        self::assertSame(['Members only'], $this->titles('members', ['includeMemberOnly' => true]));
    }

    // ---------------------------------------------------------------- filters

    public function testFilteringBySpeakerNarrowsTheResults(): void
    {
        $one = $this->speakers->create(['name' => 'Alpha'])->id;
        $two = $this->speakers->create(['name' => 'Beta'])->id;

        $this->video('Romans by Alpha', speakerId: $one);
        $this->video('Romans by Beta', speakerId: $two);

        self::assertSame(['Romans by Alpha'], $this->titles('romans', ['speakerId' => $one]));
    }

    public function testFilteringBySeriesNarrowsTheResults(): void
    {
        $seriesId = $this->series->create(['title' => 'A Series'])->id;

        $this->video('In the series', seriesId: $seriesId);
        $this->video('Not in the series');

        self::assertSame(['In the series'], $this->titles('the', ['seriesId' => $seriesId]));
    }

    public function testFilteringByYearNarrowsTheResults(): void
    {
        $old = $this->video('Talk from 2019');
        $new = $this->video('Talk from 2024');

        $this->db()->execute('UPDATE {videos} SET published_at = ? WHERE id = ?', ['2019-06-01 10:00:00', $old]);
        $this->db()->execute('UPDATE {videos} SET published_at = ? WHERE id = ?', ['2024-06-01 10:00:00', $new]);

        self::assertSame(['Talk from 2024'], $this->titles('talk', [
            'from' => '2024-01-01 00:00:00',
            'to'   => '2024-12-31 23:59:59',
        ]));
    }

    /** Boundaries are where a range filter goes wrong. */
    public function testTheYearRangeIncludesItsFirstAndLastMoment(): void
    {
        $first = $this->video('First moment');
        $last = $this->video('Last moment');

        $this->db()->execute('UPDATE {videos} SET published_at = ? WHERE id = ?', ['2024-01-01 00:00:00', $first]);
        $this->db()->execute('UPDATE {videos} SET published_at = ? WHERE id = ?', ['2024-12-31 23:59:59', $last]);

        self::assertCount(2, $this->titles('moment', [
            'from' => '2024-01-01 00:00:00',
            'to'   => '2024-12-31 23:59:59',
        ]));
    }

    // ----------------------------------------------------------- other things

    public function testSeriesAreSearchableByName(): void
    {
        $this->series->create(['title' => 'Grace Abounding']);
        $this->series->create(['title' => 'Something Else']);

        $found = $this->series->search('grace');

        self::assertCount(1, $found);
        self::assertSame('Grace Abounding', $found[0]->title);
    }

    public function testAnUnpublishedSeriesIsNotOfferedToVisitors(): void
    {
        $id = $this->series->create(['title' => 'Grace Abounding'])->id;
        $this->db()->execute('UPDATE {series} SET is_published = 0 WHERE id = ?', [$id]);

        self::assertSame([], $this->series->search('grace'));
        self::assertCount(1, $this->series->search('grace', 5, includeUnpublished: true));
    }

    public function testSpeakersAreSearchableByName(): void
    {
        $id = $this->speakers->create(['name' => 'Jane Okonkwo'])->id;
        $this->video('A talk', speakerId: $id);

        $found = $this->speakers->search('okonkwo');

        self::assertCount(1, $found);
        self::assertSame('Jane Okonkwo', $found[0]->name);
    }

    /**
     * A directory entry made in advance of the first video is real to an editor
     * and noise to a visitor.
     */
    public function testASpeakerWithNoVideosIsNotOffered(): void
    {
        $this->speakers->create(['name' => 'Jane Okonkwo']);

        self::assertSame([], $this->speakers->search('okonkwo'));
    }

    public function testAnEmptyQueryFindsNoSeriesOrSpeakers(): void
    {
        $this->series->create(['title' => 'Grace Abounding']);
        $id = $this->speakers->create(['name' => 'Jane Okonkwo'])->id;
        $this->video('A talk', speakerId: $id);

        self::assertSame([], $this->series->search(''));
        self::assertSame([], $this->speakers->search('   '));
    }

    /**
     * Punctuation is not a search. Answering it with the whole library reads as
     * the filter having been ignored, which is exactly what used to happen.
     */
    public function testAQueryWithNoUsableTermsFindsNothing(): void
    {
        $this->video('Something');

        self::assertSame([], $this->titles('""'));
    }

    public function testAnEmptySearchIsNotASearchAtAll(): void
    {
        $this->video('Something');

        self::assertSame(['Something'], $this->titles(''));
    }

    // --------------------------------------------------------------- fixtures

    /**
     * @param  array<string, mixed> $filters
     * @return list<string>
     */
    private function titles(string $query, array $filters = []): array
    {
        $result = $this->videos->query(['search' => $query] + $filters, 1, 50);

        return array_map(static fn (Video $v): string => $v->title, $result['items']);
    }

    private function video(
        string $title,
        ?string $description = null,
        ?int $speakerId = null,
        ?int $seriesId = null,
    ): int {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'description'  => $description,
            'speaker_id'   => $speakerId,
            'series_id'    => $seriesId,
            'status'       => 'ready',
            'is_published' => 1,
            'published_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
