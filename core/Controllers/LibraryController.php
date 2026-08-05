<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\CategoryRepository;
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

        return $this->view(['index'], [
            'title'               => $search !== '' ? "Search: {$search}" : 'Library',
            'videos'              => $this->present($result['items']),
            'continueWatching'    => $this->continueWatching(),
            'categories'          => $this->categoryChips(),
            'searchTerm'          => $search,
            'activeCategory'      => '',
            'thumbnailsAvailable' => $this->thumbnailsAvailable(),
            'pagination'          => $this->paginate($result['total'], $page, $request),
            'flash'               => $this->flash(),
        ]);
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
