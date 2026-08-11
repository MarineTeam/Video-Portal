<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;

/**
 * Moving a category among its siblings.
 *
 * Reordering has produced a vacuous assertion three times in this project —
 * series episodes twice and playlist items once — and every time for the same
 * reason: the test looked at a rendered listing, or used a fixture where the
 * wrong answer and the right one happened to coincide. So these assert the
 * stored positions of raw rows, and every case is built so that a broken
 * implementation gives a visibly different answer rather than the same one by
 * luck.
 */
final class CategoryOrderTest extends DatabaseTestCase
{
    private CategoryRepository $categories;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'videos', 'categories']);
        $this->categories = new CategoryRepository($this->db());
    }

    /** @return list<int> ids in stored order, read from the table not a listing */
    private function order(?int $parentId): array
    {
        return array_map('intval', $this->db()->column(
            'SELECT id FROM {categories}
              WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?') . '
              ORDER BY position ASC, name ASC',
            $parentId === null ? [] : [$parentId]
        ));
    }

    private function make(string $name, ?int $parentId = null): int
    {
        return $this->categories->create(['name' => $name, 'parent_id' => $parentId])->id;
    }

    public function testMovingUpSwapsWithThePrecedingSibling(): void
    {
        $a = $this->make('Alpha');
        $b = $this->make('Bravo');
        $c = $this->make('Charlie');

        self::assertSame([$a, $b, $c], $this->order(null));
        self::assertTrue($this->categories->move($c, -1));
        self::assertSame([$a, $c, $b], $this->order(null));
    }

    public function testMovingDownSwapsWithTheFollowingSibling(): void
    {
        $a = $this->make('Alpha');
        $b = $this->make('Bravo');
        $c = $this->make('Charlie');

        self::assertTrue($this->categories->move($a, 1));
        self::assertSame([$b, $a, $c], $this->order(null));
    }

    /**
     * The ends. Not an error — somebody pressing the button on the first row is
     * doing a normal thing — but it must report that nothing happened, or the
     * screen claims a change it did not make.
     */
    public function testMovingPastEitherEndDoesNothingAndSaysSo(): void
    {
        $a = $this->make('Alpha');
        $b = $this->make('Bravo');

        self::assertFalse($this->categories->move($a, -1));
        self::assertFalse($this->categories->move($b, 1));
        self::assertSame([$a, $b], $this->order(null));
    }

    /**
     * The case that would be invisible in a rendered tree.
     *
     * Two families of siblings, each in its own order. A move that ignored the
     * parent would reach across and reorder the other family — and a listing
     * drawn from a tree walk would still look plausible, because each branch
     * renders in whatever order its own rows came back in.
     */
    public function testMovingWithinOneParentLeavesOtherFamiliesUntouched(): void
    {
        $left = $this->make('Left');
        $right = $this->make('Right');

        $l1 = $this->make('L1', $left);
        $l2 = $this->make('L2', $left);
        $r1 = $this->make('R1', $right);
        $r2 = $this->make('R2', $right);

        $before = $this->order($right);

        self::assertTrue($this->categories->move($l2, -1));

        self::assertSame([$l2, $l1], $this->order($left));
        self::assertSame($before, $this->order($right), 'the move reached into another parent');
        self::assertSame([$r1, $r2], $this->order($right));
    }

    /**
     * And the top level is a family too. A move among root categories must not
     * be treated as "no parent, so all of them".
     */
    public function testTheTopLevelIsItsOwnFamily(): void
    {
        $root1 = $this->make('Root one');
        $root2 = $this->make('Root two');
        $child = $this->make('Child', $root1);

        self::assertTrue($this->categories->move($root2, -1));

        self::assertSame([$root2, $root1], $this->order(null));
        self::assertSame([$child], $this->order($root1), 'a child was dragged into the root ordering');
    }

    public function testAnUnknownCategoryMovesNothing(): void
    {
        $a = $this->make('Alpha');

        self::assertFalse($this->categories->move(999999, -1));
        self::assertSame([$a], $this->order(null));
    }

    /**
     * Only -1 and 1 mean anything. A stray value must not be read as a
     * direction and jump a category several places.
     */
    public function testOnlyTheTwoDirectionsAreAccepted(): void
    {
        $a = $this->make('Alpha');
        $b = $this->make('Bravo');
        $c = $this->make('Charlie');

        foreach ([0, 2, -5, 100] as $direction) {
            self::assertFalse($this->categories->move($c, $direction), (string) $direction);
        }

        self::assertSame([$a, $b, $c], $this->order(null));
    }

    /**
     * Moving is repeatable. Positions are rewritten as 10, 20, 30… on every
     * reorder, so a category cannot drift into a position that collides with a
     * sibling and then stop moving.
     */
    public function testACategoryCanBeWalkedFromLastToFirst(): void
    {
        $a = $this->make('Alpha');
        $b = $this->make('Bravo');
        $c = $this->make('Charlie');

        self::assertTrue($this->categories->move($c, -1));
        self::assertTrue($this->categories->move($c, -1));
        self::assertFalse($this->categories->move($c, -1));

        self::assertSame([$c, $a, $b], $this->order(null));
    }
}
