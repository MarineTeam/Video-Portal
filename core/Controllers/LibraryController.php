<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\CategoryRepository;
use Portal\Content\HomeRowRepository;
use Portal\Content\PlaylistRepository;
use Portal\Content\SavedVideoRepository;
use Portal\Content\SeriesRepository;
use Portal\Content\SpeakerRepository;
use Portal\Content\Video;
use Portal\Content\VideoPresenter;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Video\BunnyStreamProvider;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * The public-facing library, category, series, and search pages.
 */
final class LibraryController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->query('q') ?? '');
        $page = max(1, (int) ($request->query('page') ?? 1));

        $result = $this->videos()->query($this->visibilityFilters(['search' => $search]), $page, $this->perPage());

        /*
         * Curated rows replace the plain listing, but only on the first page
         * and only when nobody is searching. Both of those are requests for a
         * specific list, and answering them with somebody's arrangement of the
         * front page would ignore what was asked.
         */
        $rows = ($search === '' && $page === 1) ? $this->homeRows() : [];

        return $this->view(['index'], [
            'title'               => $search !== '' ? "Search: {$search}" : 'Library',
            'videos'              => $this->present($result['items']),
            'continueWatching'    => $this->continueWatching(),
            'categories'          => $this->categoryChips(),
            'searchTerm'          => $search,
            'activeCategory'      => '',
            'playlists'           => $this->playlistChips(),
            'homeRows'            => $rows,
            'thumbnailsAvailable' => $this->thumbnailsAvailable(),
            'pagination'          => $this->paginate($result['total'], $page, $request),
            'flash'               => $this->flash(),
        ]);
    }

    /**
     * The curated homepage, or an empty list when nobody has curated one.
     *
     * Empty is the important case: it is what every existing install looks
     * like, and it has to keep the arrangement those sites already have rather
     * than turning the front page blank because a new table is empty.
     *
     * @return list<array{title: string, url: ?string, videos: list<array<string, mixed>>}>
     */
    private function homeRows(): array
    {
        try {
            /** @var HomeRowRepository $repo */
            $repo = $this->container->get(HomeRowRepository::class);

            if (!$repo->isConfigured()) {
                return [];
            }

            $filters = $this->visibilityFilters([]);
            $out = [];

            foreach ($repo->all() as $row) {
                /*
                 * Continue-watching is the one row the repository cannot fill:
                 * only the controller knows who is asking. It is also the one
                 * row that is empty for a stranger, and dropping it then is
                 * correct — a heading over nothing is worse than one row less.
                 */
                if ($row->isPersonal()) {
                    $watching = $this->continueWatching();
                    if ($watching !== []) {
                        $out[] = [
                            'title'  => $row->title !== '' ? $row->title : 'Continue watching',
                            'url'    => null,
                            'videos' => $watching,
                        ];
                    }
                    continue;
                }

                $resolved = $repo->resolve($row, $filters);
                if ($resolved === null) {
                    continue;
                }

                $out[] = [
                    'title'  => $resolved['title'],
                    'url'    => $resolved['url'],
                    'videos' => $this->present($resolved['videos']),
                ];
            }

            return $out;
        } catch (Throwable $e) {
            // Before migration 0005 has run — on the one request that applies
            // it — the table may not exist. The library matters more than the
            // arrangement.
            error_log('Could not build the homepage rows: ' . $e->getMessage());

            return [];
        }
    }

    /** @param array<string, string> $params */
    public function category(Request $request, array $params): Response
    {
        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        $slug = $params['slug'] ?? '';
        $category = $categories->findBySlug($slug);

        if ($category === null) {
            // An old slug still resolves, with a permanent redirect, so links
            // printed before a rename keep working.
            $aliasId = $this->db()->value(
                'SELECT target_id FROM {slug_aliases} WHERE target_type = "category" AND slug = ?',
                [$slug]
            );

            if ($aliasId !== null) {
                $current = $categories->find((int) $aliasId);
                if ($current !== null) {
                    return Response::redirect($this->config()->url($current->url()), 301);
                }
            }

            throw HttpException::notFound('There is no category at that address.');
        }

        if (!$this->canSee($category->isPublished, $category->memberOnly, $category->hidden)) {
            throw HttpException::notFound('There is no category at that address.');
        }

        $page = max(1, (int) ($request->query('page') ?? 1));
        $result = $this->videos()->query(
            $this->visibilityFilters(['categoryId' => $category->id]),
            $page,
            $this->perPage()
        );

        $children = [];
        foreach ($categories->children($category->id) as $child) {
            $children[] = [
                'name'  => $child->name,
                'slug'  => $child->slug,
                'url'   => $child->url(),
                'count' => 0,
            ];
        }

        return $this->view(
            $this->themeManager()->loader()->hierarchy('category', ['slug' => $category->slug]),
            [
                'title'               => $category->name,
                'heading'             => $category->name,
                'description'         => $category->description,
                'videos'              => $this->present($result['items']),
                'children'            => $children,
                'thumbnailsAvailable' => $this->thumbnailsAvailable(),
                'pagination'          => $this->paginate($result['total'], $page, $request),
            ]
        );
    }

    /** @param array<string, string> $params */
    public function series(Request $request, array $params): Response
    {
        /** @var SeriesRepository $repo */
        $repo = $this->container->get(SeriesRepository::class);

        $slug = $params['slug'] ?? '';
        $series = $repo->findBySlug($slug);

        if ($series === null) {
            // Honour an address from before a rename.
            $aliased = $repo->findByAlias($slug);
            if ($aliased !== null) {
                return Response::redirect($this->config()->url($aliased->url()), 301);
            }
            throw HttpException::notFound('There is no series at that address.');
        }

        if (!$this->canSee($series->isPublished, $series->memberOnly, $series->hidden)) {
            throw HttpException::notFound('There is no series at that address.');
        }

        $videos = $this->videos()->forSeries(
            $series->id,
            $this->guard()->can(Capability::MANAGE_VIDEOS)
        );

        return $this->view(
            $this->themeManager()->loader()->hierarchy('series', ['slug' => $series->slug]),
            [
                'title'               => $series->title,
                'heading'             => $series->title,
                'description'         => $series->description,
                'videos'              => $this->present($videos),
                'children'            => [],
                'thumbnailsAvailable' => $this->thumbnailsAvailable(),
                'pagination'          => ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null],
            ]
        );
    }

    /**
     * Everything by one speaker.
     *
     * No visibility flags of its own — a speaker is not content, just a name to
     * group by. What each visitor may see is decided by the videos, through the
     * same filters every other listing uses.
     *
     * @param array<string, string> $params
     */
    public function speaker(Request $request, array $params): Response
    {
        /** @var SpeakerRepository $repo */
        $repo = $this->container->get(SpeakerRepository::class);

        $slug = $params['slug'] ?? '';
        $speaker = $repo->findBySlug($slug);

        if ($speaker === null) {
            $aliased = $repo->findByAlias($slug);
            if ($aliased !== null) {
                return Response::redirect($this->config()->url($aliased->url()), 301);
            }
            throw HttpException::notFound('There is nobody at that address.');
        }

        $page = max(1, (int) ($request->query('page') ?? 1));
        $result = $this->videos()->query(
            $this->visibilityFilters(['speakerId' => $speaker->id]),
            $page,
            $this->perPage()
        );

        return $this->view(
            $this->themeManager()->loader()->hierarchy('speaker', ['slug' => $speaker->slug]),
            [
                'title'               => $speaker->name,
                'heading'             => $speaker->name,
                'description'         => $speaker->bio,
                'videos'              => $this->present($result['items']),
                'children'            => [],
                'thumbnailsAvailable' => $this->thumbnailsAvailable(),
                'pagination'          => $this->paginate($result['total'], $page, $request),
            ]
        );
    }

    /**
     * Hide one announcement for this browser.
     *
     * Deliberately without a CSRF token, which is a decision rather than an
     * omission. The state this changes is a cookie that hides a public notice;
     * an attacker who forges this has caused a victim to stop seeing a banner
     * they could restore by clearing their cookies. Requiring a token would
     * mean every anonymous page had to carry a session-bound one purely so a
     * notice could be dismissed, which buys nothing.
     *
     * Nothing else on this site accepts a POST without a token.
     */
    public function dismissAnnouncement(Request $request): Response
    {
        $id = (int) ($request->input('id') ?? 0);

        if ($id > 0) {
            $existing = [];
            $raw = $_COOKIE['portal_dismissed'] ?? '';

            if (is_string($raw) && $raw !== '') {
                foreach (explode(',', $raw) as $part) {
                    $value = (int) trim($part);
                    if ($value > 0) {
                        $existing[] = $value;
                    }
                }
            }

            $existing[] = $id;

            /*
             * Capped, and the newest kept. Without a limit this cookie grows
             * with every announcement the site ever makes and is sent on every
             * request forever — including to the CDN.
             */
            $existing = array_slice(array_values(array_unique($existing)), -50);

            setcookie('portal_dismissed', implode(',', $existing), [
                'expires'  => time() + 86400 * 180,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $request->isSecure(),
            ]);
        }

        return $this->back($request);
    }

    /**
     * A playlist, in the order somebody arranged it.
     *
     * @param array<string, string> $params
     */
    public function playlist(Request $request, array $params): Response
    {
        /** @var PlaylistRepository $repo */
        $repo = $this->container->get(PlaylistRepository::class);

        $slug = $params['slug'] ?? '';
        $playlist = $repo->findBySlug($slug);

        if ($playlist === null) {
            // Honour an address from before a rename.
            $aliased = $repo->findByAlias($slug);
            if ($aliased !== null) {
                return Response::redirect($this->config()->url($aliased->url()), 301);
            }
            throw HttpException::notFound('There is no playlist at that address.');
        }

        if (!$this->canSee($playlist->isPublished, $playlist->memberOnly, $playlist->hidden)) {
            throw HttpException::notFound('There is no playlist at that address.');
        }

        $videos = $repo->videos(
            $playlist->id,
            $this->guard()->can(Capability::MANAGE_VIDEOS),
            $this->canWatch()
        );

        return $this->view(
            $this->themeManager()->loader()->hierarchy('playlist', ['slug' => $playlist->slug]),
            [
                'title'               => $playlist->title,
                'heading'             => $playlist->title,
                'description'         => $playlist->description,
                'videos'              => $this->present($videos),
                'children'            => [],
                'thumbnailsAvailable' => $this->thumbnailsAvailable(),
                'pagination'          => ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null],
            ]
        );
    }

    /**
     * Everything this viewer has saved.
     *
     * Behind auth.authorized rather than merely auth: the page lists videos,
     * and somebody whose account has not been approved cannot see the library
     * either. Two lists on one page rather than two pages, because "did I
     * favourite this or save it for later" is a question nobody should have to
     * answer by visiting two addresses.
     */
    public function saved(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            return Response::redirect($this->config()->url('/auth/login'));
        }

        /** @var SavedVideoRepository $saved */
        $saved = $this->container->get(SavedVideoRepository::class);

        $canWatch = $this->canWatch();

        return $this->view(['saved', 'archive', 'index'], [
            'title'               => 'Saved',
            'heading'             => 'Saved',
            'description'         => null,
            'favorites'           => $this->present(
                $saved->videos($user->id, SavedVideoRepository::FAVORITE, $canWatch)
            ),
            'watchLater'          => $this->present(
                $saved->videos($user->id, SavedVideoRepository::WATCH_LATER, $canWatch)
            ),
            // archive.php renders $videos; the saved template renders the two
            // lists above. Both are supplied so a theme without a saved.php
            // still shows something rather than an empty page.
            'videos'              => $this->present(
                $saved->videos($user->id, SavedVideoRepository::FAVORITE, $canWatch)
            ),
            'children'            => [],
            'thumbnailsAvailable' => $this->thumbnailsAvailable(),
            'pagination'          => ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null],
            'flash'               => $this->flash(),
        ]);
    }

    /**
     * Save or unsave a video.
     *
     * One route and one button for both directions. A separate "unsave" URL
     * would double the surface for no gain, and the toggle is resolved in the
     * database so two tabs cannot leave it in a state neither person asked for.
     */
    public function toggleSaved(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();
        if ($user === null) {
            return Response::redirect($this->config()->url('/auth/login'));
        }

        $list = SavedVideoRepository::sanitizeList($request->input('list'));
        $videoId = (int) ($request->input('video') ?? 0);

        // Looked up rather than taken from the form: a redirect target a
        // visitor controls is an open redirect waiting to happen.
        $video = $videoId > 0 ? $this->videos()->find($videoId) : null;

        if ($list === null || $video === null) {
            return Response::redirect($this->config()->url('/'));
        }

        /** @var SavedVideoRepository $saved */
        $saved = $this->container->get(SavedVideoRepository::class);
        $nowSaved = $saved->toggle($user->id, $video->id, $list);

        $label = $list === SavedVideoRepository::FAVORITE ? 'favourites' : 'watch later';

        /*
         * back() returns them to the page they came from, so saving from a
         * listing does not throw away where they were reading. It sanitises the
         * referer through the same helper the auth flow uses rather than
         * trusting a form field, which is how a "return to" parameter becomes
         * an open redirect.
         */
        return $this->back(
            $request,
            $nowSaved ? "Added to your {$label}." : "Removed from your {$label}."
        );
    }

    /**
     * The search page.
     *
     * No longer index() under another name. Searching and browsing want
     * different orderings, different filters, and — the part index() could
     * never do — matching series and speakers surfaced above the videos,
     * because somebody typing a series name wants the series page and not
     * twelve of its episodes scattered through a ranking.
     */
    public function search(Request $request): Response
    {
        $term = trim($request->query('q') ?? '');
        $page = max(1, (int) ($request->query('page') ?? 1));

        $filters = $this->searchFilters($request);

        $result = $this->videos()->query(
            $this->visibilityFilters(['search' => $term] + $filters),
            $page,
            $this->perPage()
        );

        /** @var SeriesRepository $series */
        $series = $this->container->get(SeriesRepository::class);
        /** @var SpeakerRepository $speakers */
        $speakers = $this->container->get(SpeakerRepository::class);

        $canManage = $this->guard()->can(Capability::MANAGE_VIDEOS);

        return $this->view(['search', 'archive', 'index'], [
            'title'               => $term === '' ? 'Search' : "Search: {$term}",
            'videos'              => $this->present($result['items']),
            'continueWatching'    => [],
            'categories'          => $this->categoryChips(),
            'searchTerm'          => $term,
            'activeCategory'      => '',
            'matchedSeries'       => $term === '' ? [] : array_map(
                static fn ($item): array => [
                    'title' => $item->title,
                    'url'   => $item->url(),
                    'count' => $item->videoCount,
                ],
                $series->search($term, 5, $canManage)
            ),
            'matchedSpeakers'     => $term === '' ? [] : array_map(
                static fn ($item): array => [
                    'name'  => $item->name,
                    'url'   => $item->url(),
                    'count' => $item->videoCount,
                ],
                $speakers->search($term, 5)
            ),
            'seriesOptions'       => $this->filterOptions($series->all($canManage), 'title'),
            'speakerOptions'      => $this->filterOptions($speakers->all(), 'name'),
            'activeFilters'       => $filters,
            'thumbnailsAvailable' => $this->thumbnailsAvailable(),
            'pagination'          => $this->paginate($result['total'], $page, $request),
            'total'               => $result['total'],
            'flash'               => $this->flash(),
        ]);
    }

    /**
     * The narrowing controls, read from the query string.
     *
     * Each one is validated into the shape the repository expects rather than
     * passed through: `from=drop table` reaching a date comparison would be
     * bound safely and still produce a confusing empty page, and a year is the
     * only granularity the form offers.
     *
     * @return array<string, mixed>
     */
    private function searchFilters(Request $request): array
    {
        $filters = [];

        $seriesId = (int) ($request->query('series') ?? 0);
        if ($seriesId > 0) {
            $filters['seriesId'] = $seriesId;
        }

        $speakerId = (int) ($request->query('speaker') ?? 0);
        if ($speakerId > 0) {
            $filters['speakerId'] = $speakerId;
        }

        // Years, not dates. A date picker on a sermon archive is precision
        // nobody wants to supply, and "2024" is how people actually remember
        // when something was said.
        $year = (int) ($request->query('year') ?? 0);
        if ($year >= 1900 && $year <= (int) date('Y') + 1) {
            $filters['from'] = sprintf('%04d-01-01 00:00:00', $year);
            $filters['to'] = sprintf('%04d-12-31 23:59:59', $year);
            $filters['year'] = $year;
        }

        return $filters;
    }

    /**
     * @param  list<object> $items
     * @return list<array{id: int, label: string}>
     */
    private function filterOptions(array $items, string $labelProperty): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'id'    => (int) $item->id,
                'label' => (string) $item->{$labelProperty},
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------- helpers

    private function videos(): VideoRepository
    {
        return $this->container->get(VideoRepository::class);
    }

    /**
     * Visibility, decided in one place.
     *
     * An unapproved or anonymous visitor sees only public content. Someone who
     * can manage videos sees drafts too, so they can check work before
     * publishing without a separate preview mechanism.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function visibilityFilters(array $filters): array
    {
        $user = $this->user();

        /*
         * Premieres appear in listings before their date, which is the whole
         * point of marking one. Ordinary scheduled videos still do not — being
         * invisible until publication is what scheduling means, and a premiere
         * is the deliberate exception an editor asked for.
         *
         * Not applied to feeds, which build their own filters: an episode
         * announced in a podcast feed before it can be downloaded is an
         * episode every client reports as broken.
         */
        $filters['includePremieres'] = true;

        if ($user !== null && ($user->isAdmin() || $user->authorized)) {
            $filters['includeMemberOnly'] = true;
        }

        if ($this->guard()->can(Capability::MANAGE_VIDEOS)) {
            $filters['includeUnpublished'] = true;
            $filters['includeHidden'] = true;
        }

        return $filters;
    }

    private function canSee(bool $published, bool $memberOnly, bool $hidden): bool
    {
        if ($this->guard()->can(Capability::MANAGE_VIDEOS)) {
            return true;
        }
        if (!$published || $hidden) {
            return false;
        }
        if (!$memberOnly) {
            return true;
        }

        $user = $this->user();
        return $user !== null && ($user->isAdmin() || $user->authorized);
    }

    /**
     * Turn model objects into the flat arrays templates expect.
     *
     * Thumbnail URLs are minted here and never stored — they are signed and
     * expire, and a cached one produces a 403 that looks to a viewer like a
     * broken image.
     *
     * The card data itself is built by VideoPresenter, which is where the
     * members-only rule lives and is tested. This method's remaining job is to
     * answer the two questions only a request can answer — who is asking, and
     * what the site default is — and to fire the filter plugins extend.
     *
     * @param list<Video> $videos
     * @return list<array<string, mixed>>
     */
    private function present(array $videos): array
    {
        $presenter = new VideoPresenter($this->videos(), $this->videoProvider());

        $cards = $presenter->cards(
            $videos,
            $this->canWatch(),
            $this->config()->settingBool('members_thumbnail_default', false)
        );

        /** @var list<array<string, mixed>> */
        return apply_filters('video_list', $cards);
    }

    /**
     * Can this visitor actually play a video?
     *
     * The same test the /watch route applies, asked here so a listing can tell
     * the difference between "you may see this exists" and "you may see what it
     * looks like". Signing in is not enough: an account an administrator has
     * not approved yet cannot play anything, and that is the state every new
     * account starts in.
     */
    private function canWatch(): bool
    {
        if ($this->guard()->can(Capability::MANAGE_VIDEOS)) {
            return true;
        }

        $user = $this->user();

        return $user !== null && ($user->isAdmin() || $user->authorized);
    }

    /**
     * The continue-watching row.
     *
     * @return list<array<string, mixed>>
     */
    private function continueWatching(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        if (!apply_filters('show_continue_watching', true)) {
            return [];
        }

        try {
            $rows = $this->db()->all(
                'SELECT v.*, p.position_seconds, p.duration_seconds
                   FROM {watch_progress} p
                   JOIN {videos} v ON v.id = p.video_id
                  WHERE p.user_id = ?
                    AND p.completed_at IS NULL
                    AND p.position_seconds > 10
                    AND p.duration_seconds > 0
                    AND p.position_seconds < p.duration_seconds * 0.95
                    AND v.deleted_at IS NULL
                    AND v.status = "ready"
                  ORDER BY p.updated_at DESC
                  LIMIT 8',
                [$user->id]
            );
        } catch (Throwable) {
            return [];
        }

        // Presented through the same path as any other card, so someone whose
        // approval was withdrawn does not keep seeing artwork in their
        // continue-watching row that the rest of the site now withholds.
        $videos = array_map(static fn (array $row): Video => Video::fromRow($row), $rows);
        $cards = $this->present($videos);

        $progressById = [];
        foreach ($rows as $row) {
            $duration = max(1, (int) $row['duration_seconds']);
            $progressById[(int) $row['id']] = (int) round(((int) $row['position_seconds'] / $duration) * 100);
        }

        $out = [];
        foreach ($cards as $card) {
            $card['progressPercent'] = max(0, min(100, $progressById[(int) $card['id']] ?? 0));
            $out[] = $card;
        }

        return $out;
    }

    /** @return list<array{id: int, name: string, slug: string}> */
    private function categoryChips(): array
    {
        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        $chips = [];
        foreach ($categories->roots() as $category) {
            $chips[] = ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug];
        }

        return $chips;
    }

    /**
     * Published playlists, for the library page.
     *
     * Without this there is no way to reach a playlist except by knowing its
     * address — the exact "built but unreachable" shape this project has hit
     * five times already. Members-only ones are left out for visitors who could
     * not open them, because a link that 404s is worse than no link.
     *
     * @return list<array{title: string, url: string, count: int}>
     */
    private function playlistChips(): array
    {
        try {
            /** @var PlaylistRepository $repo */
            $repo = $this->container->get(PlaylistRepository::class);

            $canManage = $this->guard()->can(Capability::MANAGE_VIDEOS);
            $canWatch = $this->canWatch();

            $chips = [];
            foreach ($repo->all($canManage) as $playlist) {
                if ($playlist->memberOnly && !$canWatch) {
                    continue;
                }
                $chips[] = [
                    'title' => $playlist->title,
                    'url'   => $playlist->url(),
                    'count' => $playlist->videoCount,
                ];
            }

            return $chips;
        } catch (Throwable $e) {
            // Before migration 0003 has run — during an upgrade, on the one
            // request that applies it — the table may not exist yet. The
            // library is more important than the row of chips.
            error_log('Could not load playlists: ' . $e->getMessage());

            return [];
        }
    }

    private function videoProvider(): ?VideoProvider
    {
        try {
            return $this->container->get(VideoProvider::class);
        } catch (Throwable) {
            // No provider configured yet — the library still renders, just
            // without thumbnails.
            return null;
        }
    }

    /**
     * Are thumbnails usable at all?
     *
     * When they are not, the theme shows a title list rather than a grid of
     * empty boxes — a list reads as deliberate where broken images read as
     * broken.
     */
    private function thumbnailsAvailable(): bool
    {
        $provider = $this->videoProvider();

        if ($provider instanceof BunnyStreamProvider) {
            return $provider->thumbnailsConfigured();
        }

        return $provider !== null;
    }

    private function perPage(): int
    {
        $configured = (int) ($this->themeManager()->setting('per-page') ?? 12);
        /** @var int */
        return apply_filters('videos_per_page', max(1, min(100, $configured ?: 12)));
    }

    /** @return array{page: int, pages: int, prevUrl: ?string, nextUrl: ?string} */
    private function paginate(int $total, int $page, Request $request): array
    {
        $perPage = $this->perPage();
        $pages = max(1, (int) ceil($total / $perPage));

        $url = static function (int $target) use ($request): string {
            $query = $request->query;
            $query['page'] = $target;
            return $request->path . '?' . http_build_query($query);
        };

        return [
            'page'    => $page,
            'pages'   => $pages,
            'prevUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $pages ? $url($page + 1) : null,
        ];
    }
}
