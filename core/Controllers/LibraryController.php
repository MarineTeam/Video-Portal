<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\CategoryRepository;
use Portal\Content\Video;
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
            $this->themes()->loader()->hierarchy('category', ['slug' => $category->slug]),
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
        $slug = $params['slug'] ?? '';

        $row = $this->db()->first('SELECT * FROM {series} WHERE slug = ?', [$slug]);
        if ($row === null) {
            throw HttpException::notFound('There is no series at that address.');
        }

        if (!$this->canSee(
            (bool) $row['is_published'],
            (bool) $row['member_only'],
            (bool) $row['hidden']
        )) {
            throw HttpException::notFound('There is no series at that address.');
        }

        $videos = $this->videos()->forSeries(
            (int) $row['id'],
            $this->guard()->can(Capability::MANAGE_VIDEOS)
        );

        return $this->view(
            $this->themes()->loader()->hierarchy('series', ['slug' => (string) $row['slug']]),
            [
                'title'               => (string) $row['title'],
                'heading'             => (string) $row['title'],
                'description'         => $row['description'] !== null ? (string) $row['description'] : null,
                'videos'              => $this->present($videos),
                'children'            => [],
                'thumbnailsAvailable' => $this->thumbnailsAvailable(),
                'pagination'          => ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null],
            ]
        );
    }

    public function search(Request $request): Response
    {
        return $this->index($request);
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
     * @param list<Video> $videos
     * @return list<array<string, mixed>>
     */
    private function present(array $videos): array
    {
        $provider = $this->videoProvider();

        $out = [];
        foreach ($videos as $video) {
            $thumbnail = null;

            if ($provider !== null) {
                try {
                    $thumbnail = $provider->thumbnailUrl($video->providerId, $video->thumbnailFile);
                } catch (Throwable) {
                    // A thumbnail is decoration; never fail a page over one.
                    $thumbnail = null;
                }
            }

            $out[] = [
                'id'             => $video->id,
                'title'          => $video->title,
                'url'            => $video->url(),
                'thumbnail'      => $thumbnail,
                'duration'       => $video->duration,
                'status'         => $video->status,
                'encodeProgress' => $video->encodeProgress,
            ];
        }

        /** @var list<array<string, mixed>> */
        return apply_filters('video_list', $out);
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

        $provider = $this->videoProvider();
        $out = [];

        foreach ($rows as $row) {
            $video = Video::fromRow($row);
            $duration = max(1, (int) $row['duration_seconds']);
            $percent = (int) round(((int) $row['position_seconds'] / $duration) * 100);

            $thumbnail = null;
            if ($provider !== null) {
                try {
                    $thumbnail = $provider->thumbnailUrl($video->providerId, $video->thumbnailFile);
                } catch (Throwable) {
                    $thumbnail = null;
                }
            }

            $out[] = [
                'id'              => $video->id,
                'title'           => $video->title,
                'url'             => $video->url(),
                'thumbnail'       => $thumbnail,
                'duration'        => $video->duration,
                'status'          => $video->status,
                'progressPercent' => max(0, min(100, $percent)),
            ];
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
        $configured = (int) ($this->themes()->setting('per-page') ?? 12);
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
