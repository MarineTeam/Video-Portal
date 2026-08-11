<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Auth\Capability;
use Portal\Content\BulkAction;

/**
 * What a bulk action on the video library is allowed to be.
 *
 * The interesting failure here is not a wrong query, it is a permission. A bulk
 * endpoint is where somebody eventually forgets to check one, and the cost of
 * forgetting is the whole library rather than one row.
 */
final class BulkActionTest extends TestCase
{
    public function testTheKnownActionsAreKnown(): void
    {
        foreach (['publish', 'unpublish', 'categorise', 'trash'] as $action) {
            self::assertTrue(BulkAction::isKnown($action), $action);
        }
    }

    /**
     * An unrecognised action must not fall through to something that acts.
     */
    public function testAnythingElseIsRefused(): void
    {
        foreach (['', 'delete-everything', 'PUBLISH', 'save', '0'] as $action) {
            self::assertFalse(BulkAction::isKnown($action), $action);
            self::assertNull(BulkAction::capability($action), $action);
        }
    }

    /**
     * The claim worth pinning: publishing in bulk needs the publishing
     * permission, not the editing one. Those are separate in this product, and
     * an editor who may write but not publish must not gain publishing by
     * ticking two boxes instead of pressing one button.
     */
    public function testEachActionRequiresWhatTheSingleRowButtonRequires(): void
    {
        self::assertSame(Capability::PUBLISH_CONTENT, BulkAction::capability('publish'));
        self::assertSame(Capability::PUBLISH_CONTENT, BulkAction::capability('unpublish'));
        self::assertSame(Capability::MANAGE_CATEGORIES, BulkAction::capability('categorise'));
        self::assertSame(Capability::MANAGE_VIDEOS, BulkAction::capability('trash'));
    }

    // -------------------------------------------------------------- selection

    public function testIdsAreCleanedAndDeduplicated(): void
    {
        self::assertSame([3, 7, 12], BulkAction::ids(['7', 3, '12', '7', 3]));
    }

    public function testRubbishInASelectionIsDropped(): void
    {
        self::assertSame([5], BulkAction::ids(['5', '0', -2, 'abc', '', null, ['nested']]));
    }

    public function testANonArraySelectionIsEmpty(): void
    {
        self::assertSame([], BulkAction::ids('12'));
        self::assertSame([], BulkAction::ids(null));
    }

    /**
     * The cap is a timeout rule, not a safety one: each item is a query, and a
     * selection of ten thousand on shared hosting stops halfway through with no
     * message saying where it got to.
     */
    public function testTheSelectionIsCapped(): void
    {
        $many = range(1, BulkAction::MAX_PER_REQUEST + 50);

        self::assertCount(BulkAction::MAX_PER_REQUEST, BulkAction::ids($many));
        self::assertTrue(BulkAction::wasTruncated($many));
    }

    public function testAnOrdinarySelectionIsNotReportedAsTruncated(): void
    {
        self::assertFalse(BulkAction::wasTruncated(['1', '2', '3']));
        self::assertFalse(BulkAction::wasTruncated([]));
    }

    /**
     * Duplicates must not push a selection over the cap. Ten ids posted twice
     * is ten videos, and telling somebody their selection was cut short when it
     * was not would send them splitting a list that did not need splitting.
     */
    public function testDuplicatesDoNotCountTowardsTheCap(): void
    {
        $doubled = array_merge(range(1, 150), range(1, 150));

        self::assertCount(150, BulkAction::ids($doubled));
        self::assertFalse(BulkAction::wasTruncated($doubled));
    }

    // ----------------------------------------------------------------- report

    public function testTheReportCountsAndNamesTheAction(): void
    {
        self::assertSame('3 videos published.', BulkAction::report('publish', 3));
        self::assertSame('1 video moved to the trash.', BulkAction::report('trash', 1));
    }

    /**
     * "12 of 14" is the only version somebody can act on. A bare "Done" hides
     * the two that did not work.
     */
    public function testFailuresAreNamedNotSwallowed(): void
    {
        $report = BulkAction::report('publish', 12, ['A: no', 'B: no']);

        self::assertStringContainsString('12 videos published', $report);
        self::assertStringContainsString('2 could not be', $report);
        self::assertStringContainsString('A: no', $report);
    }

    /**
     * And a long list of failures is summarised rather than pasted whole into
     * a flash message nobody can read.
     */
    public function testALongFailureListIsSummarised(): void
    {
        $report = BulkAction::report('publish', 0, ['a', 'b', 'c', 'd', 'e']);

        self::assertStringContainsString('and 2 more', $report);
    }

    public function testAnUnknownActionStillProducesASentence(): void
    {
        self::assertStringContainsString('changed', BulkAction::report('whatever', 2));
    }
}
