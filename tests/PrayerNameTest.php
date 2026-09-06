<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Prayer\PrayerName;
use Portal\Prayer\PrayerRequest;

/**
 * The one function that may produce a name for a prayer request.
 *
 * # WHY THIS EXISTS SEPARATELY FROM PrayerTest
 *
 * That one goes through the repository, which never STORES a name for an
 * anonymous request — so it proves the storage rule and cannot reach the
 * display rule at all. Deleting the `is_anonymous` check from this class left
 * every one of those tests green, because the name it would have leaked was
 * already null.
 *
 * They are two different rules and the database allows the row the second one
 * guards: `is_anonymous = 1` with a name beside it. An import could write it, a
 * moderator screen that let somebody anonymise a posted request would write it,
 * and either way the guard is what stops the name reaching a page.
 *
 * So the guard is tested where it can be reached, which is here, directly —
 * the same answer this project reached for Notifier::claim() and
 * VisitTracker::roll().
 */
final class PrayerNameTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function row(array $extra = []): array
    {
        return $extra + [
            'id'            => 1,
            'body'          => 'Please pray.',
            'requester_name' => null,
            'is_anonymous'  => 0,
            'visibility'    => 'members',
            'status'        => 'approved',
            'answer_note'   => null,
            'answered_at'   => null,
            'prayed_count'  => 0,
            'created_at'    => '2026-09-06 09:00:00',
            'approved_by'   => null,
        ];
    }

    /**
     * THE RULE, at the only level it can be seen: a row carrying BOTH a name
     * and the anonymous flag shows the label, not the name.
     */
    public function testAnAnonymousRowWithANameStillShowsAnonymous(): void
    {
        $name = PrayerName::for($this->row([
            'is_anonymous'   => 1,
            'requester_name' => 'Jane Cole',
        ]));

        self::assertSame(PrayerName::ANONYMOUS, $name, 'THE NAME LEAKED');
    }

    /** And so does the type every screen actually receives. */
    public function testTheVisibleTypeCarriesTheLabelRatherThanTheName(): void
    {
        $request = PrayerRequest::from($this->row([
            'is_anonymous'   => 1,
            'requester_name' => 'Jane Cole',
        ]));

        self::assertSame(PrayerName::ANONYMOUS, $request->name);
        self::assertStringNotContainsString('Jane', (string) json_encode($request));
    }

    /**
     * There is no argument that turns anonymity off, and there will not be
     * one. "Anonymous except to the people who run the church" is the version
     * people assume they are getting and are not.
     */
    public function testThereIsNoWayToAskForTheRealName(): void
    {
        $method = new \ReflectionMethod(PrayerName::class, 'for');

        self::assertCount(1, $method->getParameters(), 'PrayerName::for() takes a second argument');
    }

    public function testANamedRequestKeepsItsName(): void
    {
        self::assertSame(
            'Sam Ives',
            PrayerName::for($this->row(['requester_name' => 'Sam Ives']))
        );
    }

    /**
     * A blank name is the label rather than a gap. Somebody who left the box
     * empty did not choose to be identified either, and a nameless space on a
     * wall invites people to guess.
     */
    public function testABlankNameIsTheLabelRatherThanAGap(): void
    {
        self::assertSame(PrayerName::ANONYMOUS, PrayerName::for($this->row(['requester_name' => '   '])));
        self::assertSame(PrayerName::ANONYMOUS, PrayerName::for($this->row()));
    }
}
