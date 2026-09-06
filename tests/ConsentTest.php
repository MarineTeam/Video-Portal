<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Broadcast\Consent;
use Portal\Broadcast\PhoneNumber;

/**
 * Whether somebody may be reached, and on what.
 *
 * THREE RULES, DELIBERATELY NOT ONE. The point of these tests is that the three
 * channels answer DIFFERENTLY to the same person — a single "wants
 * announcements" flag would pass most of what anybody thinks to check and would
 * be sending unlawful texts.
 */
final class ConsentTest extends TestCase
{
    private const UK = '44';

    /** @param array<string, mixed> $extra */
    private function person(array $extra = []): array
    {
        return $extra + [
            'email'         => 'jane@example.test',
            'email_opt_out' => 0,
            'sms_opt_in'    => 0,
            'phone'         => null,
            'push_devices'  => 0,
        ];
    }

    // -------------------------------------------------------- email: opt-out

    /** An email costs nothing and arrives where things arrive. */
    public function testEmailGoesUnlessSomebodySaidNot(): void
    {
        self::assertTrue(Consent::allows(Consent::EMAIL, $this->person()));
        self::assertFalse(Consent::allows(Consent::EMAIL, $this->person(['email_opt_out' => 1])));
    }

    public function testEmailNeedsAnAddressThatIsOne(): void
    {
        self::assertFalse(Consent::allows(Consent::EMAIL, $this->person(['email' => ''])));
        self::assertFalse(Consent::allows(Consent::EMAIL, $this->person(['email' => 'jane at example'])));
    }

    // ----------------------------------------------------------- sms: opt-in

    /**
     * THE RULE. A text costs the church money and the recipient their
     * attention, and in most places sending one without consent is illegal.
     */
    public function testATextNeedsAnExplicitOptIn(): void
    {
        self::assertFalse(
            Consent::allows(Consent::SMS, $this->person(['phone' => '07700 900123']), self::UK),
            'HAVING SOMEBODY\'S NUMBER WAS TREATED AS PERMISSION TO TEXT IT'
        );

        /*
         * Built through person() rather than by adding to the array above:
         * PHP's `+` keeps the LEFT operand's value for a key that already
         * exists, so `$withNumber + ['sms_opt_in' => 1]` leaves the opt-in at
         * zero and asserts nothing.
         */
        self::assertTrue(
            Consent::allows(
                Consent::SMS,
                $this->person(['phone' => '07700 900123', 'sms_opt_in' => 1]),
                self::UK
            )
        );
    }

    /**
     * And a number this cannot read. Both halves are required, because an
     * opt-in with an unreadable number is a message the gateway charges for
     * and nobody receives.
     */
    public function testATextAlsoNeedsANumberThatCanBeDialled(): void
    {
        self::assertFalse(Consent::allows(
            Consent::SMS,
            $this->person(['sms_opt_in' => 1, 'phone' => 'ring the office']),
            self::UK
        ));

        self::assertFalse(Consent::allows(
            Consent::SMS,
            $this->person(['sms_opt_in' => 1, 'phone' => '']),
            self::UK
        ));
    }

    /**
     * The two rules are independent: neither half implies the other, so a
     * person can be refused for either reason on their own.
     */
    public function testTheTwoHalvesOfTheSmsRuleAreIndependent(): void
    {
        $optInOnly = $this->person(['sms_opt_in' => 1]);
        $numberOnly = $this->person(['phone' => '+447700900123']);

        self::assertFalse(Consent::allows(Consent::SMS, $optInOnly, self::UK));
        self::assertFalse(Consent::allows(Consent::SMS, $numberOnly, self::UK));
        self::assertTrue(Consent::allows(
            Consent::SMS,
            $this->person(['sms_opt_in' => 1, 'phone' => '+447700900123']),
            self::UK
        ));
    }

    // ------------------------------------------------------ push: a device

    /**
     * Not a preference. A subscription IS the consent, and its absence is not
     * something a setting can override — there is nowhere to send to.
     */
    public function testPushNeedsADeviceAndNothingElse(): void
    {
        self::assertFalse(Consent::allows(Consent::PUSH, $this->person()));
        self::assertTrue(Consent::allows(Consent::PUSH, $this->person(['push_devices' => 1])));

        // Turning email announcements off says nothing about a device somebody
        // deliberately registered.
        self::assertTrue(Consent::allows(
            Consent::PUSH,
            $this->person(['push_devices' => 2, 'email_opt_out' => 1])
        ));
    }

    // -------------------------------------------- the three are not one flag

    /**
     * The whole point, in one assertion: the same person, three channels,
     * three different answers.
     *
     * A single "wants announcements" flag gives the same answer to all three,
     * which is why this is the test that would fail if anybody ever folded
     * them together.
     */
    public function testOnePersonGetsThreeDifferentAnswers(): void
    {
        $person = $this->person([
            'email_opt_out' => 0,   // so: email yes
            'sms_opt_in'    => 0,   // so: sms no, even though...
            'phone'         => '+447700900123',
            'push_devices'  => 1,   // so: push yes
        ]);

        self::assertTrue(Consent::allows(Consent::EMAIL, $person, self::UK));
        self::assertFalse(Consent::allows(Consent::SMS, $person, self::UK));
        self::assertTrue(Consent::allows(Consent::PUSH, $person, self::UK));
    }

    /** A channel nobody has heard of is not one anybody consented to. */
    public function testAnUnknownChannelIsRefused(): void
    {
        self::assertFalse(Consent::allows('carrier-pigeon', $this->person(['push_devices' => 9])));
    }

    // ------------------------------------------------------------- reasons

    /**
     * The three refusals say different things, because they need different
     * answers: an opt-out is somebody's decision, a missing opt-in is worth
     * asking for, and an unreadable number is a typo somebody can fix.
     */
    public function testEachRefusalSaysWhichOneItIs(): void
    {
        self::assertSame(
            'turned announcements off',
            Consent::why(Consent::EMAIL, $this->person(['email_opt_out' => 1]))
        );

        self::assertSame(
            'has not opted in to texts',
            Consent::why(Consent::SMS, $this->person(['phone' => '+447700900123']), self::UK)
        );

        self::assertSame(
            'phone number cannot be read',
            Consent::why(Consent::SMS, $this->person(['sms_opt_in' => 1, 'phone' => 'x']), self::UK)
        );

        self::assertSame('no device registered', Consent::why(Consent::PUSH, $this->person()));
    }

    public function testSomebodyWhoIsAllowedHasNoReason(): void
    {
        self::assertSame('', Consent::why(Consent::EMAIL, $this->person()));
    }

    // ------------------------------------------------------- the number

    public function testAWrittenNumberBecomesOneThatCanBeDialled(): void
    {
        foreach ([
            '07700 900123'    => '+447700900123',
            '(07700) 900123'  => '+447700900123',
            '07700-900-123'   => '+447700900123',
            '+44 7700 900123' => '+447700900123',
            '00447700900123'  => '+447700900123',
        ] as $written => $expected) {
            self::assertSame($expected, PhoneNumber::e164((string) $written, self::UK), (string) $written);
        }
    }

    /**
     * The trunk zero is national notation and goes when the country code
     * arrives. Keeping it produces +44 07700900123 — a different number, and
     * one the gateway will happily charge for.
     */
    public function testTheTrunkZeroGoesWithTheCountryCode(): void
    {
        self::assertSame('+447700900123', PhoneNumber::e164('07700900123', '44'));
        self::assertSame('+17015550123', PhoneNumber::e164('7015550123', '1'));
    }

    /**
     * A national number with no country to read it in is not a number.
     * Guessing produces a real phone belonging to somebody else.
     */
    public function testANationalNumberWithNoCountryIsRefused(): void
    {
        self::assertNull(PhoneNumber::e164('07700900123'));

        // But an international one carries its own country and is fine.
        self::assertSame('+447700900123', PhoneNumber::e164('+447700900123'));
    }

    /**
     * Letters are refused rather than stripped. "07700 900123 (not before 6)"
     * would otherwise become a different phone with a 6 on the end.
     */
    public function testANoteBesideANumberIsNotANumber(): void
    {
        self::assertNull(PhoneNumber::e164('07700 900123 (not before 6)', self::UK));
        self::assertNull(PhoneNumber::e164('ring the office on 01234 567890', self::UK));
    }

    public function testSomethingTooShortOrTooLongIsRefused(): void
    {
        self::assertNull(PhoneNumber::e164('+1234', self::UK));
        self::assertNull(PhoneNumber::e164('+1234567890123456789', self::UK));
        self::assertNull(PhoneNumber::e164('', self::UK));
    }
}
