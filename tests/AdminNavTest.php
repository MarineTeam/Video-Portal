<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Admin\AdminView;

/**
 * How the admin sidebar decides where you are.
 *
 * The rendering is tested rather than the data because the rendering is where
 * the decision is made: `adminNav()` says which screens a link owns, and the
 * shell turns that into the one highlight a person actually reads. Feeding it
 * a hand-built menu keeps this a pure function of its input — no database, no
 * capabilities — so the capability filtering is left to the smoke run, which
 * drives it through a real session.
 *
 * The claim under test throughout is "exactly one link says it is the current
 * page, and it is the right one". Asserting that some expected href appears
 * would pass just as happily with six of them lit up at once, which is the
 * same defect as none.
 */
final class AdminNavTest extends TestCase
{
    /**
     * A menu shaped like the one adminNav() returns: a section that is a page
     * in its own right, and a section that is only a heading over children.
     *
     * @return list<array<string, mixed>>
     */
    private function menu(): array
    {
        return [
            [
                'label' => 'Dashboard', 'path' => '/admin', 'key' => 'dashboard',
                'icon' => 'home', 'screens' => ['dashboard'], 'children' => [],
            ],
            [
                'label' => 'Content', 'path' => '/admin/videos', 'key' => 'content',
                'icon' => 'film',
                'screens' => ['videos', 'video-edit', 'categories', 'category-edit'],
                'children' => [
                    ['label' => 'Videos', 'path' => '/admin/videos', 'key' => 'videos', 'screens' => ['videos', 'video-edit']],
                    ['label' => 'Categories', 'path' => '/admin/categories', 'key' => 'categories', 'screens' => ['categories', 'category-edit']],
                ],
            ],
        ];
    }

    /** @param list<array<string, mixed>>|null $menu */
    private function render(string $screen, ?array $menu = null): string
    {
        return (new AdminView())->shell('<p>body</p>', [
            'screen'   => $screen,
            'siteName' => 'Test Portal',
            'nav'      => $menu ?? $this->menu(),
        ]);
    }

    /** @return list<string> every href carrying aria-current, in document order */
    private function currentLinks(string $html): array
    {
        preg_match_all('~<a [^>]*href="([^"]*)"[^>]*aria-current="page"~', $html, $matches);

        return $matches[1];
    }

    public function testASectionWithNoChildrenIsItsOwnCurrentPage(): void
    {
        self::assertSame(['/admin'], $this->currentLinks($this->render('dashboard')));
    }

    public function testAChildScreenHighlightsThatChildAndNothingElse(): void
    {
        self::assertSame(['/admin/videos'], $this->currentLinks($this->render('videos')));
    }

    /**
     * The whole reason the menu carries screen names instead of matching its
     * own keys.
     *
     * Video, category, series and playlist editing are four of the most-used
     * screens in the product, and under the previous rule every one of them
     * rendered a navigation with nothing highlighted at all — no answer to
     * "where am I" on exactly the pages where losing your place costs the most.
     */
    public function testAnEditScreenStillHighlightsTheListItBelongsTo(): void
    {
        self::assertSame(['/admin/videos'], $this->currentLinks($this->render('video-edit')));
        self::assertSame(['/admin/categories'], $this->currentLinks($this->render('category-edit')));
    }

    /**
     * A heading is not a destination.
     *
     * Content's own path is /admin/videos, so marking the heading current as
     * well would put aria-current on two links pointing at the same place —
     * and tell a screen reader that a heading is the page you are on.
     */
    public function testASectionHeadingNeverClaimsToBeTheCurrentPage(): void
    {
        $html = $this->render('video-edit');

        self::assertStringContainsString('class="section current"', $html);
        self::assertCount(1, $this->currentLinks($html));
    }

    /**
     * Only the section you are in opens.
     *
     * This is the property that keeps eight headings from being twenty-six
     * links again: every other section's children exist in the markup — they
     * have to, so the section has them ready when you open it — but the CSS
     * only unfolds the one carrying `current`. If every section rendered as
     * current, the menu would still be correct HTML and completely useless.
     */
    public function testExactlyOneSectionIsOpenAtATime(): void
    {
        self::assertSame(1, substr_count($this->render('videos'), 'class="section current"'));
        self::assertSame(1, substr_count($this->render('dashboard'), 'class="section current"'));
    }

    /**
     * A screen nothing claims leaves the menu unhighlighted rather than
     * guessing. Plugin pages that pass no nav key land here, and a wrong
     * highlight is worse than none: it says you are somewhere you are not.
     */
    public function testAnUnclaimedScreenHighlightsNothing(): void
    {
        $html = $this->render('some-plugin-screen-nobody-registered');

        self::assertSame([], $this->currentLinks($html));
        self::assertStringNotContainsString('class="section current"', $html);
    }

    /**
     * Every link is present whether or not its section is open.
     *
     * The CSS hides the closed ones, so a link missing from the markup is a
     * page with no route to it at all — the exact defect that once made a
     * plugin's settings screen findable only by reading the source.
     */
    public function testClosedSectionsStillCarryTheirLinks(): void
    {
        $html = $this->render('dashboard');

        self::assertStringContainsString('href="/admin/videos"', $html);
        self::assertStringContainsString('href="/admin/categories"', $html);
    }

    public function testLabelsAndPathsAreEscaped(): void
    {
        $html = $this->render('x', [[
            'label'    => 'Bad "><script>alert(1)</script>',
            'path'     => '/admin/x?a="><script>',
            'key'      => 'x-group',
            'icon'     => 'cog',
            'screens'  => [],
            'children' => [],
        ]]);

        self::assertStringNotContainsString('<script>', $html);
    }

    /**
     * A menu entry with no children renders no empty list.
     *
     * An empty <ul> would still take the submenu's padding, so landing on
     * Analytics would open a gap under it with nothing in it.
     */
    public function testAChildlessSectionRendersNoSubmenu(): void
    {
        $html = $this->render('dashboard', [[
            'label' => 'Analytics', 'path' => '/admin/analytics', 'key' => 'analytics',
            'icon' => 'chart', 'screens' => ['analytics'], 'children' => [],
        ]]);

        self::assertStringNotContainsString('class="submenu"', $html);
    }
}
