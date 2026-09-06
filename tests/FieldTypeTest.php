<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Forms\FieldType;

/**
 * What counts as an answer.
 *
 * The rule this exists for: THE SERVER HAS THE LAST WORD. The page a response
 * came from proves nothing — it may have been saved, edited, or never rendered
 * by this site — so a choice is checked against the options stored on the
 * question rather than against whatever the request claims was on offer.
 */
final class FieldTypeTest extends TestCase
{
    private const SERVICES = ['9am', '11am', 'Evening'];

    /** @param list<string> $options */
    private function check(string $type, mixed $raw, array $options = [], bool $required = false): array
    {
        return FieldType::check($type, $options, $required, $raw);
    }

    // ------------------------------------------------- the last word on choice

    /**
     * THE RULE. A crafted request cannot invent a fourth answer to a three-way
     * question, which matters most for the answers somebody acts on.
     */
    public function testAnAnswerNobodyOfferedIsRefused(): void
    {
        $verdict = $this->check(FieldType::CHOICE, 'Midnight', self::SERVICES);

        self::assertFalse($verdict['ok'], 'the server took an answer it never offered');
        self::assertNull($verdict['value']);
    }

    public function testARealChoiceIsKept(): void
    {
        self::assertSame('11am', $this->check(FieldType::DROPDOWN, '11am', self::SERVICES)['value']);
    }

    /** And the same rule for the many-answer type, one option at a time. */
    public function testInventedTicksAreDroppedAndRealOnesKept(): void
    {
        $verdict = $this->check(
            FieldType::CHECKBOXES,
            ['9am', 'Midnight', 'Evening'],
            self::SERVICES
        );

        self::assertTrue($verdict['ok']);
        self::assertSame(['9am', 'Evening'], json_decode((string) $verdict['value'], true));
    }

    /**
     * Dropped rather than refused, deliberately.
     *
     * A box whose option was retired between the page loading and the form
     * being sent is the ordinary case, and refusing the whole response over it
     * throws away everything else the person wrote.
     */
    public function testATickWithNoRealOptionsLeftIsEmptyRatherThanAnError(): void
    {
        $verdict = $this->check(FieldType::CHECKBOXES, ['Midnight'], self::SERVICES);

        self::assertTrue($verdict['ok']);
        self::assertNull($verdict['value']);
    }

    /** The same box sent six times is one answer, or a count means nothing. */
    public function testTheSameBoxTickedRepeatedlyIsOneAnswer(): void
    {
        $verdict = $this->check(FieldType::CHECKBOXES, ['9am', '9am', '9am'], self::SERVICES);

        self::assertSame(['9am'], json_decode((string) $verdict['value'], true));
    }

    // ----------------------------------------------------------- required

    public function testARequiredQuestionRefusesNothing(): void
    {
        self::assertFalse($this->check(FieldType::TEXT, '   ', [], true)['ok']);
        self::assertFalse($this->check(FieldType::CHECKBOXES, [], self::SERVICES, true)['ok']);
    }

    public function testAnOptionalQuestionIsHappyWithNothing(): void
    {
        $verdict = $this->check(FieldType::TEXT, '');

        self::assertTrue($verdict['ok']);
        self::assertNull($verdict['value']);
    }

    // -------------------------------------------------------- the types

    public function testAnEmailIsCheckedAndLowercased(): void
    {
        self::assertSame('jane@example.test', $this->check(FieldType::EMAIL, ' Jane@Example.Test ')['value']);
        self::assertFalse($this->check(FieldType::EMAIL, 'jane at example')['ok']);
    }

    /**
     * A phone number is checked loosely on purpose.
     *
     * Real ones are written with spaces, brackets, dots and a leading plus. A
     * strict pattern refuses a number somebody typed correctly, and the cost of
     * that is a person who cannot send the form and gives up.
     */
    public function testAPhoneNumberIsAllowedToLookLikeAPhoneNumber(): void
    {
        foreach (['+44 7700 900123', '(01234) 567 890', '01234-567890'] as $written) {
            self::assertTrue($this->check(FieldType::PHONE, $written)['ok'], $written);
        }

        self::assertFalse($this->check(FieldType::PHONE, 'ring the office')['ok']);
    }

    public function testANumberIsANumber(): void
    {
        self::assertSame('3', $this->check(FieldType::NUMBER, ' 3 ')['value']);
        self::assertFalse($this->check(FieldType::NUMBER, 'three')['ok']);
    }

    /** Stored one way round, so a listing and a CSV cannot read it differently. */
    public function testADateIsStoredOneWayRound(): void
    {
        self::assertSame('2026-09-06', $this->check(FieldType::DATE, '2026-09-06')['value']);
        self::assertFalse($this->check(FieldType::DATE, 'sometime')['ok']);
    }

    public function testYesOrNoIsOnlyYesOrNo(): void
    {
        self::assertSame('yes', $this->check(FieldType::YES_NO, 'Yes')['value']);
        self::assertSame('no', $this->check(FieldType::YES_NO, 'NO')['value']);
        self::assertFalse($this->check(FieldType::YES_NO, 'maybe')['ok']);
    }

    /** A type this does not know is refused rather than stored as free text. */
    public function testAQuestionOfAnUnknownTypeCannotBeAnswered(): void
    {
        self::assertFalse($this->check('signature-pad', 'anything')['ok']);
    }

    public function testThereAreTenTypes(): void
    {
        self::assertCount(10, FieldType::all());
    }

    // -------------------------------------------------------- reading back

    /**
     * One function decides how an answer reads, so the responses table and the
     * spreadsheet cannot write a multi-choice answer two different ways.
     */
    public function testHowAnAnswerReads(): void
    {
        self::assertSame(
            '9am, Evening',
            FieldType::display(FieldType::CHECKBOXES, (string) json_encode(['9am', 'Evening']))
        );

        self::assertSame('Yes', FieldType::display(FieldType::YES_NO, 'yes'));
        self::assertSame('', FieldType::display(FieldType::TEXT, null));
    }

    /**
     * Options are one per line, because a comma-separated box cannot hold an
     * option with a comma in it — and "Sunday, 9am" is a normal thing to want.
     */
    public function testOptionsAreOnePerLine(): void
    {
        self::assertSame(
            ['Sunday, 9am', 'Sunday, 11am'],
            FieldType::parseOptions("Sunday, 9am\r\n  Sunday, 11am  \n\n")
        );
    }

    public function testTheSameOptionTwiceIsOneOption(): void
    {
        self::assertSame(['9am'], FieldType::parseOptions("9am\n9am"));
    }
}
