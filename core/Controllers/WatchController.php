<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\DownloadPolicy;
use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Content\WatchProgressRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * Playback and watch progress.
 *
 * The embed URL is minted per request and never stored. Every predecessor app
 * did the same, for the same reason: a stored URL outlives its expiry and then
 * produces a 403 that looks to a viewer like the video is broken.
 */
final class WatchController extends Controller
{
    /** Playback URLs last three hours — long enough for any single sitting. */
    private const EMBED_TTL = 10800;

    /** @var array<int, \Portal\Content\Series|null> */
    private array $seriesCache = [];

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $slug = $params['slug'] ?? '';
        $video = $videos->findBySlug($slug);

        if ($video === null) {
            // Honour an old slug with a permanent redirect.
            $aliased = $videos->findByAlias($slug);
            if ($aliased !== null) {
                return Response::redirect($this->config()->url($aliased->url()), 301);
            }
            throw HttpException::notFound('There is no video at that address.');
        }

        $canManage = $this->guard()->can(\Portal\Auth\Capability::MANAGE_VIDEOS);

        /*
         * A premiere is the one thing that is visible and not playable, so it
         * is checked before the generic visibility rule — which would 404 it,
         * that being what "scheduled" means for everything else.
         */
        $premiering = $video->isPremiering();

        if (!$premiering && !$video->isVisible() && !$canManage) {
            // Deliberately a 404 rather than a 403: telling an unauthorised
            // visitor that a video exists but is hidden is itself a leak.
            throw HttpException::notFound('There is no video at that address.');
        }

        /*
         * Restricted to named groups?
         *
         * The PHP half of the rule the listing query expresses in SQL. Both
         * exist because this page resolves one video by slug and never runs
         * that query — the arrangement scheduling has had since Phase 4 — and a
         * test asserts the two agree, because a disagreement means either a
         * video that is listed and 404s or one that is hidden and plays.
         *
         * The same 404, for the same reason: a person told "you are not in the
         * right group" has been told the video exists.
         */
        if (!$canManage && !$videos->audienceAllows($video, $this->viewerGroupIds())) {
            throw HttpException::notFound('There is no video at that address.');
        }

        /*
         * Locked because the episode before it has not been watched.
         *
         * Resolved BEFORE the embed URL is minted, and it suppresses it the
         * same way a premiere does — so there is no signed URL on the page to
         * find with developer tools. An editor bypasses it, as with premieres,
         * because reviewing a course means watching episode nine without
         * sitting through the first eight.
         */
        $locked = $canManage ? null : $this->lockState($video);

        $embedUrl = '';

        if ($canManage || (!$premiering && $locked === null)) {
            $provider = $this->container->get(VideoProvider::class);

            try {
                $embedUrl = $provider->embedUrl($video->providerId, self::EMBED_TTL);
            } catch (Throwable $e) {
                throw HttpException::upstream('The video service is not responding: ' . $e->getMessage());
            }

            /** @var string $embedUrl */
            $embedUrl = apply_filters('player_embed_url', $embedUrl, $video);
        }

        return $this->view(
            $this->themeManager()->loader()->hierarchy('video', ['slug' => $video->slug]),
            [
                'title' => $video->title,
                'video' => [
                    'id'          => $video->id,
                    // Carried so a plugin rendering under the video can build a
                    // link back to this page — the comments pager needs one.
                    'slug'        => $video->slug,
                    'title'       => $video->title,
                    'description' => $video->description,
                    'embedUrl'    => $embedUrl,
                    'duration'    => $video->duration,
                    'speaker'     => $this->speakerName($video),
                    'speakerLink' => $this->speakerLink($video),
                    'series'      => $this->seriesLink($video),
                    'recordedAt'  => $this->formatDate($video->recordedAt),
                    'resumeAt'    => $this->resumePosition($video->id),
                    /*
                     * Whether this person has finished it, so the theme can
                     * offer the mark or the unmark rather than a button whose
                     * effect nobody can predict.
                     */
                    'watched'     => $this->user() !== null
                        && $this->watchProgress()->isCompleted($this->user()->id, $video->id),
                    /*
                     * An explicit moment from a link, which beats resume.
                     *
                     * Somebody who followed a chapter or a transcript line
                     * asked for that moment; putting them back where they left
                     * off instead would ignore what they clicked. Clamped
                     * rather than trusted: the value is in a URL anybody can
                     * edit, and a negative or absurd number handed to the
                     * player is a seek to nowhere.
                     */
                    'startAt'     => $this->startPosition($request, $video->duration),
                    /*
                     * The embed URL is empty for a premiere, so a theme that
                     * ignores this flag renders an iframe with no source rather
                     * than a playable video. The failure is visible and inert,
                     * which is the right way round for something whose whole
                     * job is to not play yet.
                     */
                    'premiering'  => $premiering && !$canManage,
                    'premiereAt'  => $premiering ? $this->formatDate($video->publishedAt) : null,
                    /*
                     * Null when watchable. When it is not, it names the episode
                     * to watch first and links to it — "locked" on its own is a
                     * dead end, and the one thing the person needs is the way
                     * forward.
                     */
                    'locked'      => $locked,
                ],
                // Which of this viewer's lists the video is already on, so the
                // buttons can say "Saved" rather than offering to save
                // something that is already there.
                'attachments' => $this->attachments($video->id),
                'chapters'   => $this->chapters($video->id),
                'scripture'  => $this->scriptureLinks($video->id),
                'tags'       => $this->tagLinks($video->id),
                'note'       => $this->note($video->id),
                'transcript' => $this->transcriptCues($video->id),
                'savedLists' => $this->savedLists($video->id),
                'saveAction' => '/saved',
                'csrfField'  => '<input type="hidden" name="_token" value="'
                    . e($this->csrfToken()) . '">',
                /*
                 * The share panel, for somebody holding share_content on this
                 * video — site-wide, or through a grant on its category or
                 * series. Null for everybody else, so a theme that renders it
                 * unconditionally still shows nothing rather than a form that
                 * 403s.
                 *
                 * Asked with the video's scope rather than site-wide, because
                 * scoping is the reason this capability exists apart from
                 * manage_shares.
                 */
                'sharePanel' => $this->guard()->can(Capability::SHARE_CONTENT, 'video', $video->id)
                    ? [
                        'action'      => '/share/create',
                        'videoId'     => $video->id,
                        'maxHours'    => \Portal\Sharing\Share::MEMBER_MAX_HOURS,
                        'minimumPass' => \Portal\Sharing\SharePassword::MINIMUM,
                    ]
                    : null,
                /*
                 * The download link, when both halves say yes: the capability
                 * on this video, and the content policy resolved through its
                 * series and categories.
                 *
                 * Asked here so the control appears only when it would work. A
                 * link that 403s is worse than no link — it reads as the site
                 * being broken rather than as a setting, and there is nothing
                 * on the page to say which.
                 *
                 * Whether a FILE exists is deliberately not asked. That is
                 * `Mp4Locator`, and until the video row has been synced once it
                 * costs an API call — on a page render, for a control most
                 * people will not press. The route answers with the specific
                 * reason if there is none.
                 */
                'downloadUrl' => $this->canDownload($video)
                    ? '/download/' . rawurlencode($video->slug) . '.mp4'
                    : null,
                /*
                 * The slug on its own, for the offline-save script. The theme's
                 * $video is a prepared array rather than the model, and giving
                 * a template a value it has to parse out of a URL is how the
                 * two come to disagree the first time the URL changes shape.
                 */
                'downloadSlug' => $video->slug,
                /*
                 * The audio player's source, or null when the setting is off.
                 *
                 * Only the switch is asked here. Whether a FILE exists is the
                 * route's question, exactly as with downloads — asking it on a
                 * page render would cost an API call on any video that has not
                 * been synced, for a control most people will not press, and
                 * the route answers with the specific reason if there is none.
                 *
                 * Not offered for a premiere: there is no embed URL for one on
                 * purpose, and an audio player would be the hole in that.
                 */
                'listenUrl' => (!$premiering || $canManage)
                    && $this->config()->settingBool('audio_mode_enabled', false)
                        ? '/listen/' . rawurlencode($video->slug) . '.mp4'
                        : null,
                'pageMeta'     => $meta = $this->pageMeta($video),
                /*
                 * Artwork for the phone's lock screen while audio is playing.
                 *
                 * Reused from the preview card rather than minted again, which
                 * means it inherits that card's rule: nothing is offered for a
                 * video whose artwork is members-only. That is stricter than it
                 * strictly needs to be — the card is built as an anonymous
                 * unfurler, so a signed-in member gets no lock-screen image on
                 * a site that withholds artwork by default — and it errs the
                 * right way. No picture is a lock screen with the title on it;
                 * the wrong rule here would be a withheld frame handed to the
                 * operating system, which caches it.
                 */
                'lockScreenArtwork' => $meta->imageUrl ?? '',
                // The same trail the JSON-LD carries, so what a reader sees and
                // what a crawler is told cannot disagree.
                'breadcrumbs'  => $meta->breadcrumbs,
                'related' => $this->related($video),
                'backUrl' => '/',
            ]
        );
    }

    /**
     * What to watch after this one.
     *
     * The theme has rendered this section since Phase 1 and was handed an empty
     * array every time, so it has never appeared on a page. Filling it is the
     * whole change; the presentation was already written and already correct.
     *
     * Candidates are ranked here and then passed through the ordinary listing
     * query, which is what decides whether this viewer may see any of them.
     * Doing the visibility myself would be a second implementation of the rules
     * that keep unpublished and members-only videos off a public page, and two
     * implementations of that eventually disagree.
     *
     * Wrapped whole. This is the least important thing on the watch page and it
     * runs after everything that matters is already resolved; a failure here
     * should cost the section, not the video.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * What a link to this video looks like when somebody pastes it somewhere.
     *
     * THE IMAGE IS RESOLVED FOR AN ANONYMOUS VISITOR, NOT FOR THIS ONE, and
     * that is the whole subtlety. The person on this page can watch — that is
     * why they are on it — but the thing that fetches `og:image` is a
     * stranger's server with no session, and whatever it fetches it caches.
     * Asking "may THIS viewer see the artwork" would put a members-only
     * thumbnail on somebody else's infrastructure, which is precisely what the
     * setting exists to prevent.
     *
     * So the question asked is "would a signed-out visitor be shown this", and
     * a no means no image at all rather than a fallback to a site logo — an
     * absent card is a smaller loss than a leaked frame.
     */
    private function pageMeta(Video $video): \Portal\Support\PageMeta
    {
        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        try {
            $provider = $this->container->get(VideoProvider::class);
        } catch (Throwable) {
            // No video service configured. There is nothing to sign a
            // thumbnail with, and a card without one is still a card.
            $provider = null;
        }

        $card = (new \Portal\Content\VideoPresenter($videos, $provider))
            ->cards(
                [$video],
                // Deliberately false: the unfurler is anonymous.
                false,
                $this->config()->settingBool('members_thumbnail_default', false)
            )[0] ?? [];

        return \Portal\Support\PageMeta::forVideo(
            $video->title,
            (string) ($video->description ?? ''),
            is_string($card['thumbnail'] ?? null) ? $card['thumbnail'] : null,
            $this->config()->url('/watch/' . $video->slug),
            $video->publishedAt,
            $video->duration,
            $this->crumbs()->forVideo($video, $this->seriesOf($video), $this->categoryOf($video))
        );
    }

    /**
     * The one thing that builds a trail here, as in LibraryController.
     *
     * Handed this controller's own visibility answer rather than writing one:
     * a members-only section must not be named in the trail of a video
     * somebody is allowed to watch, and there must not be a second opinion
     * about which sections those are. See Breadcrumbs.
     */
    private function crumbs(): \Portal\Content\Breadcrumbs
    {
        $canManage = $this->guard()->can(Capability::MANAGE_VIDEOS);
        $user = $this->user();

        return new \Portal\Content\Breadcrumbs(
            $this->container->get(\Portal\Content\CategoryRepository::class),
            fn (string $path): string => $this->config()->url($path),
            static function (\Portal\Content\Category $category) use ($canManage, $user): bool {
                if ($canManage) {
                    return true;
                }
                if (!$category->isPublished || $category->hidden) {
                    return false;
                }
                if (!$category->memberOnly) {
                    return true;
                }

                return $user !== null && ($user->isAdmin() || $user->authorized);
            },
        );
    }

    /**
     * The series this video is in, read once per request.
     *
     * Memoised because two things want it — the lock and the breadcrumb trail
     * — and the docblock on lockState() has claimed since it was written that
     * this is "one field read from a series row already needed for the
     * breadcrumb". It was not, until now: adding the trail put the page over
     * its query budget and the smoke suite said so.
     */
    private function seriesOf(Video $video): ?\Portal\Content\Series
    {
        if ($video->seriesId === null) {
            return null;
        }

        if (array_key_exists($video->seriesId, $this->seriesCache)) {
            return $this->seriesCache[$video->seriesId];
        }

        try {
            $series = $this->container->get(\Portal\Content\SeriesRepository::class)->find($video->seriesId);
        } catch (Throwable) {
            $series = null;
        }

        return $this->seriesCache[$video->seriesId] = $series;
    }

    /**
     * One category for the trail, when a video may be in several.
     *
     * The schema has no primary category, so SOME answer has to be chosen and
     * every choice is arbitrary. Ordering by `path` makes it the shallowest and
     * leftmost — the one nearest the top of the tree, which is the one a reader
     * would call "where this lives" — and, more importantly, makes it stable:
     * picking whichever row the join table returned first would change the
     * trail when somebody edited an unrelated video.
     *
     * One query rather than a list of ids followed by a lookup. The watch page
     * is the heaviest in the product and has a query budget with a smoke check
     * on it, which this feature went over before it was written this way.
     */
    private function categoryOf(Video $video): ?\Portal\Content\Category
    {
        try {
            $row = $this->db()->first(
                'SELECT c.* FROM {video_categories} vc
                   JOIN {categories} c ON c.id = vc.category_id
                  WHERE vc.video_id = ? AND c.deleted_at IS NULL
                  ORDER BY c.path, c.id
                  LIMIT 1',
                [$video->id]
            );

            return $row === null ? null : \Portal\Content\Category::fromRow($row);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Both halves of a download decision, without the file lookup.
     *
     * WHO — the capability, scoped to this video, so a grant on one category
     * does not offer the button on everything else. WHAT — the content policy
     * resolved video → series → categories → site setting.
     *
     * The route asks the same two questions again rather than trusting this.
     * The answer here decides whether a control is DRAWN; the answer there
     * decides whether a file is handed over, and a control that has been on a
     * page since before a setting changed must not be the thing that grants it.
     */
    private function canDownload(Video $video): bool
    {
        if (!$this->guard()->can(Capability::DOWNLOAD_CONTENT, 'video', $video->id)) {
            return false;
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        return DownloadPolicy::allows($videos->downloadModeFor(
            $video,
            $this->config()->settingBool('downloads_enabled', false)
        ));
    }

    private function related(Video $video): array
    {
        try {
            /** @var VideoRepository $videos */
            $videos = $this->container->get(VideoRepository::class);

            $signals = $videos->relatednessSignals($video);
            if ($signals === []) {
                return [];
            }

            $ranked = \Portal\Content\Relatedness::rank($signals);
            if ($ranked === []) {
                return [];
            }

            $user = $this->user();
            $canWatch = $user !== null && ($user->isAdmin() || $user->authorized);

            $result = $videos->query([
                'ids'               => $ranked,
                'includeMemberOnly' => $canWatch,
                // A premiere is listed everywhere else on the site, and the
                // card says so. Hiding it here would make the section disagree
                // with the series page it sits next to.
                'includePremieres'  => true,
            ], 1, \Portal\Content\Relatedness::LIMIT);

            if ($result['items'] === []) {
                return [];
            }

            /*
             * query() returns its own curated order — pinned first, then the
             * arrangement an editor chose. That is right for a listing and
             * wrong here, where the ranking IS the answer. Restored to the
             * ranked order, with anything the query dropped simply absent.
             */
            $byId = [];
            foreach ($result['items'] as $item) {
                $byId[$item->id] = $item;
            }

            $ordered = [];
            foreach ($ranked as $id) {
                if (isset($byId[$id])) {
                    $ordered[] = $byId[$id];
                }
            }

            $presenter = new \Portal\Content\VideoPresenter(
                $videos,
                $this->container->get(VideoProvider::class)
            );

            return $presenter->cards(
                $ordered,
                $canWatch,
                $this->config()->settingBool('members_thumbnail_default', false)
            );
        } catch (Throwable $e) {
            error_log('Could not build the related videos: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The moment a ?t= link asked for, or zero.
     *
     * Clamped against the video's own duration where one is known. A value
     * past the end is not a seek anywhere useful, and the player's response to
     * one varies by browser — better to ignore it than to find out.
     */
    private function startPosition(Request $request, ?int $duration): int
    {
        $requested = (int) ($request->query('t') ?? 0);

        if ($requested <= 0) {
            return 0;
        }

        if ($duration !== null && $duration > 0 && $requested >= $duration) {
            return 0;
        }

        // A ceiling for the case where the duration is unknown — a video
        // longer than a day is not one this is being asked to seek into.
        return min($requested, 86400);
    }

    /**
     * Files attached to this video.
     *
     * Listed without re-checking permission: reaching this page already means
     * the video is watchable, and the download route checks again for itself.
     *
     * @return list<array{id: int, name: string, size: string}>
     */
    private function attachments(int $videoId): array
    {
        try {
            $rows = $this->container
                ->get(\Portal\Content\AssetRepository::class)
                ->forVideo($videoId);

            return array_map(static fn (array $row): array => [
                'id'   => (int) $row['id'],
                'name' => (string) $row['original_name'],
                'size' => \Portal\Content\AssetPolicy::formatSize((int) $row['size_bytes']),
            ], $rows);
        } catch (Throwable $e) {
            error_log('Could not read the attachments: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The chapters, if there are any.
     *
     * @return list<array{start: int, title: string}>
     */
    /**
     * Whether a sequential series is holding this video back.
     *
     * Returns null when it is watchable — including for every video that is not
     * in a sequential series at all, which is nearly all of them, so the cost
     * on an ordinary page is one field read from a series row already needed
     * for the breadcrumb.
     *
     * FAILS OPEN, deliberately, against this codebase's usual rule that access
     * checks fail closed. What it is gating is not secret: by the time this
     * runs, the members-only rules and the authorized flag have already said
     * this person may watch this video, and all this decides is whether they
     * may watch it YET. Failing closed on a database hiccup would shut somebody
     * out of a course they are entitled to, with no way to explain it. The real
     * boundary is underneath, and it still holds.
     *
     * @return array{title: string, url: string}|null
     */
    private function lockState(Video $video): ?array
    {
        if ($video->seriesId === null) {
            return null;
        }

        $user = $this->guard()->user();

        if ($user === null) {
            // Nobody is signed in, so there is no progress to have. Watching is
            // already governed by the guards above; a lock here would only
            // punish a public series for having an order.
            return null;
        }

        try {
            // Through the memo, so the trail and the lock read this row once
            // between them rather than once each.
            $series = $this->seriesOf($video);

            if ($series === null || !$series->sequential) {
                return null;
            }

            /*
             * The order this VIEWER can see, not the whole series. An episode
             * hidden from them is skipped rather than becoming a wall they can
             * never get past.
             */
            $episodes = $this->container->get(VideoRepository::class)->forSeries($series->id);
            $order = array_map(static fn ($v): int => $v->id, $episodes);

            $completed = $this->completedIn($user->id, $order);

            $state = \Portal\Content\UnlockPolicy::state($order, $completed, $video->id);

            if (!$state['locked']) {
                return null;
            }

            foreach ($episodes as $episode) {
                if ($episode->id === $state['requires']) {
                    return ['title' => $episode->title, 'url' => '/watch/' . $episode->slug];
                }
            }

            return null;
        } catch (Throwable $e) {
            error_log('Portal: could not resolve the unlock state, so the video is open: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Which of these videos this viewer has finished.
     *
     * One query for the whole series rather than one per episode — the mistake
     * the batched thumbnail modes exist to avoid.
     *
     * @param  list<int> $videoIds
     * @return list<int>
     */
    private function completedIn(int $userId, array $videoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $videoIds))));

        if ($ids === []) {
            return [];
        }

        $rows = $this->db()->all(
            'SELECT video_id FROM {watch_progress}
              WHERE user_id = ? AND completed_at IS NOT NULL
                AND video_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$userId, ...$ids]
        );

        return array_map(static fn (array $row): int => (int) $row['video_id'], $rows);
    }

    /**
     * The passages this video covers, each linking to everything else on it.
     *
     * The link is the point. A reference printed as text tells somebody what
     * was preached; a reference that is a link turns the archive into something
     * you can follow — which is the entire reason for indexing them.
     *
     * Links to the CHAPTER rather than the verse, because a verse page would be
     * one video deep on almost every site and a dead end reads as a broken
     * feature.
     *
     * @return list<array{label: string, url: string}>
     */
    /**
     * This video's tags, as links.
     *
     * Fails quiet, unlike the admin form's version. Here a missing tag list
     * costs a row of chips on a page whose actual job is playing a video; there
     * the same failure would render an empty field that, once saved, deletes
     * every tag on the record.
     *
     * @return list<array{label: string, url: string}>
     */
    private function tagLinks(int $videoId): array
    {
        try {
            $links = [];

            foreach ($this->container->get(\Portal\Content\TagRepository::class)->forItem('video', $videoId) as $tag) {
                $links[] = ['label' => $tag->name, 'url' => $tag->url()];
            }

            return $links;
        } catch (Throwable $e) {
            error_log('Could not read the tags: ' . $e->getMessage());

            return [];
        }
    }

    private function scriptureLinks(int $videoId): array
    {
        try {
            $links = [];

            foreach ($this->container->get(\Portal\Content\ScriptureRepository::class)->forVideo($videoId) as $row) {
                $links[] = [
                    'label' => \Portal\Content\ScriptureParser::format([
                        'book'       => (string) $row['book'],
                        'chapter'    => (int) $row['chapter'],
                        'verse'      => $row['verse'] === null ? null : (int) $row['verse'],
                        'endChapter' => (int) $row['end_chapter'],
                        'endVerse'   => $row['end_verse'] === null ? null : (int) $row['end_verse'],
                    ]),
                    'url'   => '/scripture/' . (string) $row['book'] . '/' . (int) $row['chapter'],
                ];
            }

            return $links;
        } catch (Throwable $e) {
            error_log('Could not read the scripture references: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * This viewer's own note on this video, if they have written one.
     *
     * Scoped to the session's user, which is the only access control notes
     * have — there is no capability that grants reading somebody else's, and no
     * screen anywhere that lists them.
     */
    private function note(int $videoId): string
    {
        $user = $this->guard()->user();

        if ($user === null) {
            return '';
        }

        try {
            return $this->container
                ->get(\Portal\Content\NoteRepository::class)
                ->body($user->id, $videoId);
        } catch (Throwable $e) {
            // On the request that applies migration 0017 the table is not there
            // yet, and losing the panel is better than losing the page.
            error_log('Could not read the note: ' . $e->getMessage());

            return '';
        }
    }

    private function chapters(int $videoId): array
    {
        try {
            return $this->container
                ->get(\Portal\Content\ChapterRepository::class)
                ->forVideo($videoId);
        } catch (Throwable $e) {
            // Navigation aids are an addition to the page, not the page.
            error_log('Could not read the chapters: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * The transcript, if there is one.
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    private function transcriptCues(int $videoId): array
    {
        try {
            return $this->container
                ->get(\Portal\Content\TranscriptRepository::class)
                ->cues($videoId);
        } catch (Throwable $e) {
            // A transcript is an addition to the page, not the page. Losing it
            // must never cost somebody the video.
            error_log('Could not read the transcript: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<string> the saved lists this video is on for this viewer
     */
    private function savedLists(int $videoId): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        try {
            return $this->container
                ->get(\Portal\Content\SavedVideoRepository::class)
                ->listsFor($user->id, $videoId);
        } catch (Throwable $e) {
            // A failure here must not take the player with it: not knowing
            // whether something is saved is an inconvenience, not being able to
            // watch it is the feature breaking.
            error_log('Could not read saved lists: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Record how far someone has watched.
     *
     * Called every few seconds by the player, so it is deliberately cheap: one
     * upsert, no reads, and a unique index doing the deduplication rather than
     * a read-then-write that would race between browser tabs.
     */
    public function saveProgress(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $videoId = (int) ($request->data('videoId') ?? 0);
        $position = (int) ($request->data('position') ?? 0);
        $duration = (int) ($request->data('duration') ?? 0);

        if ($videoId <= 0 || $position < 0 || $duration <= 0) {
            throw HttpException::badRequest('A video id, position, and duration are all required.');
        }

        // Clamp rather than reject: a player reporting a position slightly past
        // the end is normal, and failing the request would lose the fact that
        // the video was finished.
        $position = min($position, $duration);

        // Under ten seconds is not "watched" — it is someone clicking away.
        // Storing it would fill the continue-watching row with noise.
        if ($position < WatchProgressRepository::MIN_SECONDS) {
            return $this->json(['saved' => false]);
        }

        try {
            // The rule that a heartbeat may finish a video and never unfinish
            // one lives in the repository, so the manual mark cannot be
            // written against a different one. See WatchProgressRepository.
            $completed = $this->watchProgress()->record($user->id, $videoId, $position, $duration);
        } catch (Throwable $e) {
            error_log('Portal: could not save watch progress: ' . $e->getMessage());
            return $this->json(['saved' => false], 200);
        }

        $this->countView($videoId, $completed);

        return $this->json(['saved' => true, 'completed' => $completed]);
    }

    /**
     * Mark a video watched, or take the mark off.
     *
     * An ordinary form post, not an API call. The whole point is that this
     * works when the player did not — the sermon listened to in the car, the
     * one watched on somebody else's television, the one whose last two minutes
     * are credits so the heartbeat never reached 95%. A control that itself
     * needed JavaScript to run would be the same kind of promise.
     *
     * Behind auth.authorized, like every other watching route: the question
     * "have I watched this" only exists for somebody allowed to watch it.
     */
    public function mark(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $videoId = (int) ($request->input('video_id') ?? 0);
        $video = $videoId > 0 ? $videos->find($videoId) : null;

        if ($video === null) {
            return $this->back($request, 'That video does not exist.', 'error');
        }

        /*
         * The same three questions show() asks, calling the same three
         * predicates rather than restating any of them. An id in a form is an
         * id anybody can change, and without this the button is a way to mark
         * a video somebody was never allowed to open — which on a site with
         * sequential unlock is a way to unlock the next one.
         *
         * The lock is included deliberately, against its usual fail-open
         * habit: failing open here would mean marking episode one from a form
         * in order to reach episode two, which is the single thing the lock
         * exists to prevent.
         */
        $canManage = $this->guard()->can(Capability::MANAGE_VIDEOS);

        if (!$canManage) {
            $refused = !$video->isVisible()
                || !$videos->audienceAllows($video, $this->viewerGroupIds())
                || $this->lockState($video) !== null;

            if ($refused) {
                // A 404, matching show(): telling somebody a video exists but
                // is out of reach is the leak the 404 there exists to avoid.
                throw HttpException::notFound('There is no video at that address.');
            }
        }

        if (($request->input('action') ?? '') === 'unwatched') {
            $this->watchProgress()->markUnwatched($user->id, $video->id);

            return $this->back($request, 'Marked as not watched.');
        }

        $this->watchProgress()->markWatched($user->id, $video->id, $video->duration);

        return $this->back($request, 'Marked as watched.');
    }

    private function watchProgress(): WatchProgressRepository
    {
        return new WatchProgressRepository($this->db());
    }

    /**
     * Count this as a view, at most once per session per video.
     *
     * The player posts progress every ten seconds, so counting each one would
     * report an hour-long sermon as three hundred and sixty views. The marker
     * lives in the session rather than a table: it expires on its own, costs no
     * schema, and "a session" is the closest thing to "a viewing" that anything
     * here can actually observe.
     *
     * Deliberately after the progress write and wrapped separately. A failure
     * here is a lost number on a statistics screen; failing the request over it
     * would cost the viewer their resume position, which they would notice.
     */
    private function countView(int $videoId, bool $completed): void
    {
        try {
            /** @var \Portal\Auth\Session $session */
            $session = $this->container->get(\Portal\Auth\Session::class);

            $views = $this->container->get(\Portal\Content\ViewRepository::class);

            $seen = (array) ($session->get('viewed') ?? []);
            $key = (string) $videoId;

            if (!isset($seen[$key])) {
                $views->record($videoId, $completed);
                // 1 means counted, 2 means counted and finished, so the second
                // half below can tell the difference without another field.
                $seen[$key] = $completed ? 2 : 1;
                $session->put('viewed', $seen);

                return;
            }

            /*
             * Started earlier in this session and has now reached the end.
             * The view is already counted, so only the completion is added —
             * counting a second view would report twice the audience.
             */
            if ($completed && $seen[$key] !== 2) {
                $views->recordCompletion($videoId);
                $seen[$key] = 2;
                $session->put('viewed', $seen);
            }
        } catch (Throwable $e) {
            error_log('Portal: could not count a view: ' . $e->getMessage());
        }
    }

    public function getProgress(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $videoId = (int) ($request->query('videoId') ?? 0);
        if ($videoId <= 0) {
            throw HttpException::badRequest('A video id is required.');
        }

        return $this->json(['position' => $this->resumePosition($videoId)]);
    }

    // -------------------------------------------------------------- helpers

    /**
     * Where to resume from.
     *
     * Zero when the video was finished or barely started, so someone who
     * watched to the end gets a fresh start rather than being dropped back at
     * the closing credits.
     */
    private function resumePosition(int $videoId): int
    {
        $user = $this->user();
        if ($user === null) {
            return 0;
        }

        try {
            $row = $this->db()->first(
                'SELECT position_seconds, duration_seconds, completed_at
                   FROM {watch_progress} WHERE user_id = ? AND video_id = ?',
                [$user->id, $videoId]
            );
        } catch (Throwable) {
            return 0;
        }

        if ($row === null || $row['completed_at'] !== null) {
            return 0;
        }

        $position = (int) $row['position_seconds'];
        $duration = (int) $row['duration_seconds'];

        if ($position < 10 || ($duration > 0 && $position >= $duration * 0.95)) {
            return 0;
        }

        return $position;
    }

    private function speakerName(Video $video): ?string
    {
        if ($video->speakerId === null) {
            return null;
        }

        try {
            $name = $this->db()->value('SELECT name FROM {speakers} WHERE id = ?', [$video->speakerId]);
            return is_string($name) ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The speaker as a link to everything else they have said.
     *
     * Separate from speakerName() so the plain string stays available: a theme
     * that only wants to print a name should not have to strip markup, and a
     * name is safe to escape where a link is not.
     *
     * @return array{name: string, url: string}|null
     */
    private function speakerLink(Video $video): ?array
    {
        if ($video->speakerId === null) {
            return null;
        }

        try {
            $row = $this->db()->first('SELECT name, slug FROM {speakers} WHERE id = ?', [$video->speakerId]);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : [
            'name' => (string) $row['name'],
            'url'  => '/speaker/' . $row['slug'],
        ];
    }

    /** @return array{title: string, url: string}|null */
    private function seriesLink(Video $video): ?array
    {
        if ($video->seriesId === null) {
            return null;
        }

        try {
            $row = $this->db()->first('SELECT title, slug FROM {series} WHERE id = ?', [$video->seriesId]);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : [
            'title' => (string) $row['title'],
            'url'   => '/series/' . $row['slug'],
        ];
    }

    private function formatDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($date))->format('j F Y');
        } catch (Throwable) {
            return null;
        }
    }
}
