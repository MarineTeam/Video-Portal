<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Rota\PresentPlan;
use Portal\Rota\Slide;

/**
 * The order of service, ready for the screen at the front.
 */
final class PresentPlanTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            [
                'kind' => 'hymn', 'title' => 'Amazing Grace', 'reference' => '245',
                'note' => 'Margaret is playing — give her a moment to get to the organ',
                'book_id' => 3, 'song_number' => 245,
            ],
            [
                'kind' => 'reading', 'title' => 'Romans 8', 'reference' => 'Romans 8:1-11',
                'note' => 'Ask Peter, NOT David — David is away and does not know yet',
            ],
            [
                'kind' => 'item', 'title' => 'Notices', 'reference' => '',
                'note' => 'keep it to three minutes, the last one ran to twelve',
            ],
        ];
    }

    // ------------------------------------------------------------- THE RULE

    /**
     * THE RULE. A leader's note never reaches the screen at the front.
     *
     * The migration that made the column says it is not printed on the
     * congregation's copy, and present mode is the most public surface in this
     * product: a screen three hundred people are looking at, photographed by
     * some of them. The notes in the fixture above are the realistic kind —
     * one names somebody who has not been asked yet, one is a complaint about
     * how long a person spoke.
     *
     * Asserted against the SERIALISED slide, so the check does not depend on
     * knowing which property name a note would arrive under. A note added to
     * the type under any name fails this.
     */
    public function testALeadersNoteIsNowhereInAPresentableSlide(): void
    {
        $plan = PresentPlan::from($this->rows());

        $everything = (string) json_encode(array_map(
            static fn (Slide $slide): array => (array) $slide,
            $plan->slides
        ));

        foreach ($this->rows() as $row) {
            self::assertStringNotContainsString(
                (string) $row['note'],
                $everything,
                'A LEADER\'S NOTE IS ON THE WALL — it names somebody who has not been asked'
            );
        }

        // And the words that only appear in notes, in case one is chopped up.
        foreach (['Margaret', 'David', 'twelve minutes', 'organ'] as $fragment) {
            self::assertStringNotContainsString($fragment, $everything, $fragment);
        }
    }

    /**
     * The type has NO property that could hold one.
     *
     * Stronger than the check above, and the reason this class exists rather
     * than the template filtering: a Slide has nowhere to put a note, so no
     * template, theme or later refactor can put one on the screen. The test
     * fails the moment somebody adds the property, which is the moment to
     * argue about it rather than after a service.
     */
    public function testASlideHasNowhereToPutANote(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => strtolower($p->getName()),
            (new \ReflectionClass(Slide::class))->getProperties()
        );

        foreach ($properties as $name) {
            self::assertStringNotContainsString(
                'note',
                $name,
                'SLIDE GREW SOMEWHERE TO PUT A LEADER\'S NOTE — that is the one thing it must not have'
            );
        }

        // The check above is worthless if reflection found nothing.
        self::assertContains('title', $properties, 'no properties were examined at all');
    }

    /** And what it DOES carry is what the congregation needs. */
    public function testASlideCarriesWhatIsOnTheBoard(): void
    {
        $slide = PresentPlan::from($this->rows())->at(0);

        self::assertNotNull($slide);
        self::assertSame('Amazing Grace', $slide->title);
        self::assertSame('245', $slide->reference);
        self::assertSame('Hymn', $slide->label());
    }

    // --------------------------------------------------------------- moving

    public function testItMovesForwardsAndBackwardsAndStopsAtBothEnds(): void
    {
        $plan = PresentPlan::from($this->rows());

        self::assertSame(1, $plan->next(0));
        self::assertSame(2, $plan->next(1));
        self::assertNull($plan->next(2), 'there is a fourth slide in a three-item service');

        self::assertSame(1, $plan->previous(2));
        self::assertNull($plan->previous(0), 'going back from the first slide went somewhere');
    }

    /**
     * Asking for a slide that is not there gets NOTHING, not the nearest one.
     *
     * Clamping on the server would mean a stale link or a mis-sent remote press
     * silently presents a plausible slide — and plausible on the screen at the
     * front is worse than blank, because nobody in the room can tell it is
     * wrong.
     */
    public function testAskingForASlideThatIsNotThereGetsNothing(): void
    {
        $plan = PresentPlan::from($this->rows());

        self::assertNull($plan->at(99));
        self::assertNull($plan->at(-1));
        self::assertNull($plan->at(3));
    }

    /**
     * But a position out of range RESOLVES to the first slide.
     *
     * Deliberately different from at(): this is what a query string is put
     * through, where the input is a bookmark from last Sunday or a typo, and
     * starting at the beginning is the one answer that reads as a start rather
     * than as an ending somebody has to work out.
     */
    public function testAnImpossiblePositionResolvesToTheBeginning(): void
    {
        $plan = PresentPlan::from($this->rows());

        foreach (['99', '-4', 'third', '', null, '2; DROP TABLE', '1.9'] as $rubbish) {
            $resolved = $plan->resolve($rubbish);

            self::assertGreaterThanOrEqual(0, $resolved, json_encode($rubbish));
            self::assertLessThan($plan->total(), $resolved, json_encode($rubbish));
        }

        // A real position survives, or the rule above would be "always zero".
        self::assertSame(2, $plan->resolve('2'));
        self::assertSame(1, $plan->resolve(1));
    }

    // ------------------------------------------------------- what is dropped

    /**
     * A line with no title is dropped rather than presented empty.
     *
     * A blank screen with "Hymn" over it reads as the system having failed, and
     * the person who could explain it is at the front of a church.
     */
    public function testAnUntitledLineIsNotPresented(): void
    {
        $plan = PresentPlan::from([
            ['kind' => 'hymn', 'title' => 'Amazing Grace', 'reference' => '245'],
            ['kind' => 'hymn', 'title' => '   ', 'reference' => '246'],
            ['kind' => 'item', 'title' => 'Notices'],
        ]);

        self::assertSame(2, $plan->total(), 'an untitled line reached the screen');
        self::assertSame('Amazing Grace', $plan->at(0)?->title);
        self::assertSame('Notices', $plan->at(1)?->title, 'the order was disturbed by the drop');
    }

    /** An empty order is empty rather than an error. */
    public function testAnEmptyOrderPresentsNothingAtAll(): void
    {
        $plan = PresentPlan::from([]);

        self::assertTrue($plan->isEmpty());
        self::assertSame(0, $plan->total());
        self::assertNull($plan->at(0));
        self::assertSame(0, $plan->resolve('3'));
    }

    // ----------------------------------------------------------- the labels

    /**
     * An unknown kind presents as a plain item.
     *
     * The column was widened from an ENUM so that adding a kind needs no
     * migration, and the cost is that anything can arrive. A service that
     * refused to present because somebody typed "prayer" is a blank screen.
     */
    public function testAnUnknownKindStillPresents(): void
    {
        $plan = PresentPlan::from([
            ['kind' => 'prayer', 'title' => 'Intercessions'],
            ['kind' => '', 'title' => 'Offering'],
        ]);

        self::assertSame(2, $plan->total());
        self::assertSame('item', $plan->at(0)?->kind);
        self::assertSame('', $plan->at(0)?->label(), 'a label saying "Item" is a line doing no work');
    }

    /** A hymn this site holds can be opened in the reader; one it does not, cannot. */
    public function testAHymnIsOnlyLinkedToABookWhenThereIsOne(): void
    {
        $plan = PresentPlan::from([
            ['kind' => 'hymn', 'title' => 'Amazing Grace', 'book_id' => 3, 'song_number' => 245],
            ['kind' => 'hymn', 'title' => 'Something From A Sheet', 'reference' => 'insert'],
            // A book with no number, and a number with no book: neither is enough.
            ['kind' => 'hymn', 'title' => 'Half Known', 'book_id' => 3],
            ['kind' => 'hymn', 'title' => 'Other Half', 'song_number' => 99],
        ]);

        self::assertTrue($plan->at(0)?->isInABook());
        self::assertFalse($plan->at(1)?->isInABook());
        self::assertFalse($plan->at(2)?->isInABook(), 'a book with no number would open at page one');
        self::assertFalse($plan->at(3)?->isInABook(), 'a number with no book has nothing to open');
    }
}
