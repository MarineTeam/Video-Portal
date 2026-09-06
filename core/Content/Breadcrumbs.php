<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Where this page sits, said once.
 *
 * The trail was being assembled in two controllers, flat — "Library / 2019",
 * with the section it is actually inside missing — and rendered nowhere except
 * as invisible JSON-LD. So the site knew its own shape and never showed it to
 * anybody, which on a library nested three deep is the difference between
 * browsing and guessing at URLs.
 *
 * # THE RULE: a trail never names a section the reader may not see
 *
 * A public category can sit inside a members-only one — "2019" published,
 * under "Members' Teaching" that is not — and /category/2019 resolves directly
 * for anybody. Printing the ancestor chain naively puts the restricted
 * section's NAME on a stranger's page, which is the leak this project has
 * written down several times in other places: the title is a leak too.
 *
 * A hidden ancestor is dropped and the trail closes over the gap, rather than
 * being truncated. The descendant is legitimately visible and its own link
 * works; cutting the trail short at the first restriction would also remove
 * the Library link above it, which is the one crumb that is always safe.
 *
 * Visibility is not decided here. The caller passes the predicate it already
 * uses for everything else, so there is one implementation of "may this person
 * see this" rather than a second one that eventually disagrees with the first.
 */
final class Breadcrumbs
{
    /**
     * Where the trail starts. Always present, always safe, and the reason a
     * dropped ancestor leaves a usable trail rather than a dead end.
     */
    public const ROOT = 'Library';

    /**
     * @param callable(string): string  $url     turns a path into an absolute URL
     * @param callable(Category): bool  $visible may this reader see this category
     */
    public function __construct(
        private readonly CategoryRepository $categories,
        private $url,
        private $visible,
    ) {
    }

    /**
     * The trail for a category page, ancestors included.
     *
     * @return list<array{name: string, url: string}>
     */
    public function forCategory(Category $category): array
    {
        return $this->build($this->categoryChain($category));
    }

    /**
     * The trail for a series, through the category it is filed under.
     *
     * A series has one category rather than many, so there is no question of
     * which chain to follow — unlike a video, below.
     *
     * @return list<array{name: string, url: string}>
     */
    public function forSeries(Series $series, ?Category $category): array
    {
        $chain = $category === null ? [] : $this->categoryChain($category);
        $chain[] = ['name' => $series->title, 'url' => '/series/' . $series->slug];

        return $this->build($chain);
    }

    /**
     * The trail for one video.
     *
     * A video can be in several categories and the trail can only show one, so
     * the SERIES wins when there is one: somebody watching part three of a
     * course is in the course, and the category is where the course is filed.
     * Falling back to a category, the caller picks which — there is no
     * "primary" in the schema, and inventing one here would make the answer
     * depend on the order a join table came back in.
     *
     * @return list<array{name: string, url: string}>
     */
    public function forVideo(Video $video, ?Series $series, ?Category $category): array
    {
        if ($series !== null) {
            $chain = $category === null ? [] : $this->categoryChain($category);
            $chain[] = ['name' => $series->title, 'url' => '/series/' . $series->slug];
        } else {
            $chain = $category === null ? [] : $this->categoryChain($category);
        }

        $chain[] = ['name' => $video->title, 'url' => '/watch/' . $video->slug];

        return $this->build($chain);
    }

    /** A trail with nothing but the root — for the library itself. */
    public function root(): array
    {
        return $this->build([]);
    }

    /**
     * A trail of crumbs the caller already has, with the root in front.
     *
     * For the pages that are not part of the category tree at all — a tag, a
     * speaker — where there is no chain to walk and nothing to hide.
     *
     * @param list<array{name: string, url: string}> $crumbs relative paths
     * @return list<array{name: string, path: string, url: string}>
     */
    public function rootedAt(array $crumbs): array
    {
        return $this->build($crumbs);
    }

    /**
     * A category and every ancestor above it, root first, restricted ones
     * dropped.
     *
     * ancestorsOf() returns them ordered by depth, so no sorting is needed and
     * none is done — a trail assembled in the wrong order is worse than none,
     * and the ordering belongs to the query that already guarantees it.
     *
     * ancestorsOf() rather than ancestors(), because the caller is holding the
     * category and the id-taking version would re-read it. One query per trail
     * rather than two, which the watch page's budget noticed.
     *
     * @return list<array{name: string, url: string}>
     */
    private function categoryChain(Category $category): array
    {
        $chain = [];

        foreach ($this->categories->ancestorsOf($category) as $ancestor) {
            // THE RULE. A restricted ancestor is skipped, not truncated at.
            if (($this->visible)($ancestor)) {
                $chain[] = ['name' => $ancestor->name, 'url' => $ancestor->url()];
            }
        }

        /*
         * The category itself is asked too, though the caller has usually
         * already answered it to render the page at all. Asking again costs
         * nothing and means this method cannot be the one that leaks if a
         * future caller is less careful.
         */
        if (($this->visible)($category)) {
            $chain[] = ['name' => $category->name, 'url' => $category->url()];
        }

        return $chain;
    }

    /**
     * @param list<array{name: string, url: string}> $chain
     * @return list<array{name: string, url: string}>
     */
    private function build(array $chain): array
    {
        /*
         * Every crumb carries BOTH forms, because the trail has two consumers
         * that want different things.
         *
         * `url` is absolute, built from BASE_URL: a BreadcrumbList is read by a
         * machine with no page to resolve a relative path against, and the base
         * comes from configuration rather than the request host — the rule this
         * codebase has had since a host-header-poisoning fix in the app it
         * replaces.
         *
         * `path` is what a person's browser follows, and it is relative for the
         * same reason every other link in the theme is: a site reachable at
         * more than one address should not send a reader to the canonical one
         * halfway through browsing. A smoke check caught this by looking for
         * the href it expected and finding an absolute URL instead.
         */
        $out = [['name' => self::ROOT, 'path' => '/', 'url' => ($this->url)('/')]];

        foreach ($chain as $crumb) {
            $out[] = [
                'name' => $crumb['name'],
                'path' => $crumb['url'],
                'url'  => ($this->url)($crumb['url']),
            ];
        }

        return $out;
    }
}
