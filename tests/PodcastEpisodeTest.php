<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\PodcastEpisode;
use Portal\Content\Series;
use Portal\Content\Video;

/**
 * Whether a video is a podcast episode, and why a ticked one is held back.
 */
final class PodcastEpisodeTest extends TestCase
{
    /** @param array<string, mixed> $row */
    private function video(array $row = []): Video
    {
        return Video::fromRow($row + [
            'id' => 1, 'provider_id' => 'x', 'slug' => 'x', 'title' => 'Romans 8',
            'status' => 'ready', 'is_published' => 1, 'member_only' => 0, 'hidden' => 0,
            'in_podcast' => 0,
        ]);
    }

    /**
     * THE RULE. Nothing is an episode until somebody ticks it — however public
     * it is.
     */
    public function testAPublicVideoIsNotAnEpisodeUntilChosen(): void
    {
        self::assertSame(
            PodcastEpisode::OUT,
            PodcastEpisode::state($this->video(), true),
            'A PUBLIC VIDEO WENT INTO THE PODCAST WITHOUT ANYBODY CHOOSING IT'
        );
    }

    public function testATickedPublicVideoIsInTheFeed(): void
    {
        self::assertSame(PodcastEpisode::IN, PodcastEpisode::state($this->video(['in_podcast' => 1]), true));
    }

    /**
     * Ticked but held back is its own state, not "out". The intent survives, so
     * the episode returns by itself when the video is public again.
     */
    public function testATickedButHiddenVideoIsPendingNotOut(): void
    {
        self::assertSame(
            PodcastEpisode::PENDING,
            PodcastEpisode::state($this->video(['in_podcast' => 1, 'member_only' => 1]), false),
            'the editor\'s choice was lost the moment the video stopped being public'
        );
    }

    /** The reason names the setting to change, most specific first. */
    public function testTheReasonNamesWhatToChange(): void
    {
        $now = strtotime('2026-09-12 12:00:00');

        self::assertSame('it is a draft', PodcastEpisode::heldBackBecause($this->video(['is_published' => 0]), null, $now));
        self::assertSame('it is hidden', PodcastEpisode::heldBackBecause($this->video(['hidden' => 1]), null, $now));
        self::assertSame('it is members-only', PodcastEpisode::heldBackBecause($this->video(['member_only' => 1]), null, $now));
        self::assertStringStartsWith(
            'it is not published until',
            PodcastEpisode::heldBackBecause($this->video(['published_at' => '2026-10-01 09:00:00']), null, $now)
        );
        self::assertStringStartsWith(
            'its run ended on',
            PodcastEpisode::heldBackBecause($this->video(['unpublish_at' => '2026-09-01 09:00:00']), null, $now)
        );

        $membersSeries = Series::fromRow([
            'id' => 2, 'slug' => 's', 'title' => 'S', 'is_published' => 1, 'member_only' => 1, 'hidden' => 0,
        ]);

        self::assertSame(
            'its series is members-only',
            PodcastEpisode::heldBackBecause($this->video(['series_id' => 2]), $membersSeries, $now)
        );

        // The video's own setting is named before its series', since that is
        // the one on the screen somebody is looking at.
        self::assertSame(
            'it is members-only',
            PodcastEpisode::heldBackBecause($this->video(['member_only' => 1, 'series_id' => 2]), $membersSeries, $now)
        );
    }

    /**
     * When nothing on the video or series explains it, the answer is vague on
     * purpose rather than a precise-sounding guess that sends somebody to change
     * the wrong setting.
     */
    public function testAnUnnamedCauseIsNotGuessedAt(): void
    {
        self::assertSame('it is not publicly visible', PodcastEpisode::heldBackBecause($this->video(), null));
    }
}
