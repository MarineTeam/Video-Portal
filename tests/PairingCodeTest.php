<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Tv\Pairing;
use Portal\Tv\PairingCode;

/**
 * The code a television shows and somebody types on their phone.
 */
final class PairingCodeTest extends TestCase
{
    // ------------------------------------------------------- the alphabet

    /**
     * THE RULE. The characters that collide are never SHOWN.
     *
     * I, L and O collide with 1 and 0, and somebody is reading this from a sofa
     * without their glasses. Asserted over many codes rather than one, because
     * a single code missing a letter proves nothing — the generator picks at
     * random and one sample is not evidence about the alphabet.
     */
    public function testNoLookalikeCharacterIsEverShown(): void
    {
        $seen = '';

        for ($i = 0; $i < 400; $i++) {
            $seen .= PairingCode::make();
        }

        foreach (['I', 'L', 'O', 'U'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $seen,
                "A CODE CONTAINED {$forbidden} — which somebody reading across a room "
                . 'cannot tell from another character'
            );
        }

        // And the sample really did cover the alphabet, or the check above is
        // an assertion about nothing.
        self::assertGreaterThan(
            25,
            count(array_unique(str_split($seen))),
            'the sample was too narrow to say anything about the alphabet'
        );
    }

    public function testACodeIsTheRightShape(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $code = PairingCode::make();

            self::assertSame(PairingCode::LENGTH, strlen($code));
            self::assertTrue(PairingCode::looksReal($code), $code);
        }
    }

    /** Two codes running are not the same code. */
    public function testCodesAreNotPredictable(): void
    {
        $codes = [];

        for ($i = 0; $i < 200; $i++) {
            $codes[] = PairingCode::make();
        }

        self::assertCount(
            200,
            array_unique($codes),
            'TWO TELEVISIONS WOULD SHOW THE SAME CODE — one person would approve the other\'s set'
        );
    }

    // ------------------------------------------------- the other half of it

    /**
     * THE OTHER RULE, and the half that is easy to leave out.
     *
     * Excluding O from the alphabet does not stop somebody TYPING one — they
     * read a 0 and type the letter, which is the commonest thing that happens.
     * Without the mapping, the code on the screen is unambiguous and the thing
     * typed still does not match, which is a feature that works in testing and
     * fails in a hall.
     */
    public function testTypedLookalikesAreMappedBackBeforeTheLookup(): void
    {
        // A code with a 0 and a 1 in it, read wrongly in every combination.
        self::assertSame('01ABCDEF', PairingCode::normalise('O1ABCDEF'), 'O was not read as 0');
        self::assertSame('01ABCDEF', PairingCode::normalise('0IABCDEF'), 'I was not read as 1');
        self::assertSame('01ABCDEF', PairingCode::normalise('0LABCDEF'), 'L was not read as 1');
        self::assertSame('01ABCDEF', PairingCode::normalise('OIABCDEF'), 'both together');
        self::assertSame('01ABCDEF', PairingCode::normalise('olabcdef'), 'lower case, both');
    }

    /**
     * A character that is IN the alphabet is not mapped away.
     *
     * The trap in extending the lookalike list: Q is round like O, and Q is a
     * real character the generator produces. Mapping it would make every code
     * containing one permanently unusable — a feature that works for 31 codes
     * in 32 and is impossible to reproduce.
     */
    public function testACharacterTheGeneratorProducesIsNeverMappedAway(): void
    {
        foreach (str_split(PairingCode::ALPHABET) as $character) {
            $code = str_pad($character, PairingCode::LENGTH, 'A');

            self::assertSame(
                $code,
                PairingCode::normalise($code),
                "THE CHARACTER {$character} IS REWRITTEN — every code containing one is dead"
            );
        }
    }

    /** What a phone keyboard adds is not the person getting it wrong. */
    public function testTheThingsAPhoneAddsAreForgiven(): void
    {
        foreach ([
            'abcd-2345',
            'ABCD 2345',
            '  abcd2345  ',
            "ABCD2345\n",
            'ABCD–2345',
        ] as $typed) {
            self::assertSame('ABCD2345', PairingCode::normalise($typed), $typed);
        }
    }

    /** And nonsense is refused before anything is queried. */
    public function testNonsenseNeverBecomesAQuery(): void
    {
        foreach (['', '   ', 'ABC', 'ABCD23456789', '!!!!!!!!', '________'] as $nonsense) {
            self::assertFalse(
                PairingCode::looksReal(PairingCode::normalise($nonsense)),
                json_encode($nonsense)
            );
        }
    }

    /** Grouped for display, so nobody loses their place halfway along. */
    public function testItIsGroupedForReadingAcrossARoom(): void
    {
        self::assertSame('ABCD-2345', PairingCode::forDisplay('ABCD2345'));

        // And the separator is cosmetic: what is displayed normalises back to
        // what is stored, so changing the grouping cannot invalidate a code
        // somebody is holding.
        self::assertSame(
            'ABCD2345',
            PairingCode::normalise(PairingCode::forDisplay('ABCD2345'))
        );
    }

    // ------------------------------------------ expiry before approval

    /**
     * THE RULE THIS FEATURE TURNS ON.
     *
     * A pairing that was approved and has since expired must read EXPIRED. Put
     * the approval check first and the first branch matches and returns, so the
     * television collects a session from a ten-minute credential that expired
     * last Tuesday.
     *
     * And this is not an unusual row: approved-then-expired is the NORMAL
     * shape. Somebody approves a pairing, the television is switched off before
     * it polls, and the row sits there approved and stale.
     */
    public function testAnExpiredPairingIsExpiredEvenThoughItWasApproved(): void
    {
        $row = [
            'id'          => 1,
            'approved_by' => 7,
            'approved_at' => '2026-09-01 10:00:00',
            'claimed_at'  => null,
            'expires_at'  => '2026-09-01 10:10:00',
            'attempts'    => 0,
        ];

        self::assertSame(
            Pairing::EXPIRED,
            Pairing::state($row, strtotime('2026-09-01 10:11:00')),
            'AN EXPIRED PAIRING STILL COUNTS AS APPROVED — the television gets a session '
            . 'from a credential that died a minute ago'
        );

        // A minute earlier it really was approved, or the rule above would be
        // "always expired" and would pass for the wrong reason.
        self::assertSame(
            Pairing::APPROVED,
            Pairing::state($row, strtotime('2026-09-01 10:09:00'))
        );
    }

    /** And a spent pairing is spent, whatever the clock says. */
    public function testAClaimedPairingIsNeverApprovedAgain(): void
    {
        $row = [
            'id'          => 1,
            'approved_by' => 7,
            'approved_at' => '2026-09-01 10:00:00',
            'claimed_at'  => '2026-09-01 10:00:05',
            'expires_at'  => '2026-09-01 10:10:00',
            'attempts'    => 0,
        ];

        self::assertSame(
            Pairing::CLAIMED,
            Pairing::state($row, strtotime('2026-09-01 10:01:00')),
            'A USED PAIRING COULD BE REPLAYED — the television keeps its device code for years'
        );
    }

    /** Half a row is not an approval. */
    public function testAnApprovalWithNobodyBehindItIsNotAnApproval(): void
    {
        $base = [
            'id' => 1, 'claimed_at' => null, 'attempts' => 0,
            'expires_at' => '2026-09-01 10:10:00',
        ];
        $now = strtotime('2026-09-01 10:01:00');

        self::assertSame(
            Pairing::PENDING,
            Pairing::state($base + ['approved_at' => '2026-09-01 10:00:00', 'approved_by' => null], $now),
            'A SESSION WOULD BE ISSUED FOR NOBODY'
        );

        self::assertSame(
            Pairing::PENDING,
            Pairing::state($base + ['approved_at' => null, 'approved_by' => 7], $now)
        );
    }

    /** A broken expiry fails CLOSED, unlike slow mode. */
    public function testAnUnreadableExpiryIsExpiredRatherThanForever(): void
    {
        foreach (['', 'soon', null] as $rubbish) {
            self::assertSame(
                Pairing::EXPIRED,
                Pairing::state([
                    'id' => 1, 'approved_by' => 7, 'approved_at' => '2026-09-01 10:00:00',
                    'claimed_at' => null, 'expires_at' => $rubbish, 'attempts' => 0,
                ], strtotime('2026-09-01 10:01:00')),
                json_encode($rubbish) . ' — A PAIRING THAT NEVER EXPIRES'
            );
        }
    }

    public function testTooManyWrongCodesBlocksThePairing(): void
    {
        $row = [
            'id' => 1, 'approved_by' => null, 'approved_at' => null, 'claimed_at' => null,
            'expires_at' => '2026-09-01 10:10:00', 'attempts' => Pairing::MAX_ATTEMPTS,
        ];

        self::assertSame(Pairing::BLOCKED, Pairing::state($row, strtotime('2026-09-01 10:01:00')));
        self::assertFalse(Pairing::isApprovable(Pairing::BLOCKED));
    }

    public function testNoSuchPairingIsUnknown(): void
    {
        self::assertSame(Pairing::UNKNOWN, Pairing::state(null));
        self::assertFalse(Pairing::isApprovable(Pairing::UNKNOWN));
    }

    /** Only a pending pairing can be approved. */
    public function testOnlyAPendingPairingIsApprovable(): void
    {
        self::assertTrue(Pairing::isApprovable(Pairing::PENDING));

        foreach ([
            Pairing::APPROVED, Pairing::EXPIRED, Pairing::CLAIMED,
            Pairing::BLOCKED, Pairing::UNKNOWN,
        ] as $state) {
            self::assertFalse(Pairing::isApprovable($state), $state);
        }
    }

    // ----------------------------------------------------------- the words

    /**
     * A device is told the specification's words, not prose.
     *
     * A television that received "Please wait a moment" would have to
     * string-match English to know whether to keep polling.
     */
    public function testTheDeviceIsToldWhatTheSpecificationSays(): void
    {
        self::assertSame('ok', Pairing::deviceAnswer(Pairing::APPROVED));
        self::assertSame('authorization_pending', Pairing::deviceAnswer(Pairing::PENDING));
        self::assertSame('expired_token', Pairing::deviceAnswer(Pairing::EXPIRED));
        self::assertSame('access_denied', Pairing::deviceAnswer(Pairing::CLAIMED));
        self::assertSame('access_denied', Pairing::deviceAnswer(Pairing::BLOCKED));
    }

    /**
     * And a person is told something they can act on, differently each time.
     *
     * "That code did not work" is true of five situations and useful in none.
     * The one people will actually hit is EXPIRED — they walk away from the
     * television and come back ten minutes later.
     */
    public function testEveryRefusalTellsAPersonSomethingDifferent(): void
    {
        $said = [];

        foreach ([
            Pairing::APPROVED, Pairing::EXPIRED, Pairing::CLAIMED,
            Pairing::BLOCKED, Pairing::UNKNOWN,
        ] as $state) {
            $words = Pairing::explain($state);

            self::assertNotSame('', $words, $state . ' says nothing');
            $said[] = $words;
        }

        self::assertSame(
            $said,
            array_unique($said),
            'TWO REFUSALS SAY THE SAME THING — one of them is sending somebody the wrong way'
        );

        self::assertSame('', Pairing::explain(Pairing::PENDING));
    }
}
