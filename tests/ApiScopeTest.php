<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Api\Scope;

/**
 * What an API key is allowed to read.
 *
 * One rule carries this file: SCOPES HAVE NO HIERARCHY. Every instinct pulls
 * the other way — the names look nested, a prefix check would feel tidy, and
 * "read implies everything under it" would read as generous. Any of those hands
 * somebody's phone number to an integration that asked how full an event was,
 * and nothing would ever report it, because it would keep working.
 */
final class ApiScopeTest extends TestCase
{
    /**
     * THE RULE. These two share a prefix and nothing else: the difference
     * between them is a list of names, addresses and phone numbers.
     */
    public function testReadingEventsDoesNotImplyReadingWhoSignedUp(): void
    {
        $key = [Scope::EVENTS];

        self::assertTrue(Scope::allows($key, Scope::EVENTS));
        self::assertFalse(
            Scope::allows($key, Scope::REGISTRATIONS),
            'EVENTS:READ IMPLIED EVENTS:REGISTRATIONS — that is a list of phone numbers'
        );
    }

    /** And it does not work the other way round either. */
    public function testReadingWhoSignedUpDoesNotImplyReadingEvents(): void
    {
        self::assertFalse(Scope::allows([Scope::REGISTRATIONS], Scope::EVENTS));
    }

    /**
     * No scope implies any other, in either direction, for any pair.
     *
     * Written as every pair rather than a few examples, so a future scope
     * cannot be added with a name that accidentally nests inside an existing
     * one and go unnoticed.
     */
    public function testNoScopeImpliesAnyOther(): void
    {
        foreach (array_keys(Scope::all()) as $granted) {
            foreach (array_keys(Scope::all()) as $needed) {
                if ($granted === $needed) {
                    continue;
                }

                self::assertFalse(
                    Scope::allows([$granted], $needed),
                    sprintf('%s allowed %s', $granted, $needed)
                );
            }
        }
    }

    /** A prefix is not a permission. */
    public function testAPrefixIsNotAPermission(): void
    {
        self::assertFalse(Scope::allows(['events'], Scope::EVENTS));
        self::assertFalse(Scope::allows(['events:'], Scope::REGISTRATIONS));
        self::assertFalse(Scope::allows([Scope::EVENTS], 'events'));
    }

    /** Holding several works, and holding none allows nothing. */
    public function testHoldingSeveralScopesWorks(): void
    {
        $key = [Scope::EVENTS, Scope::CONTENT];

        self::assertTrue(Scope::allows($key, Scope::CONTENT));
        self::assertTrue(Scope::allows($key, Scope::EVENTS));
        self::assertFalse(Scope::allows($key, Scope::CALENDAR));
        self::assertFalse(Scope::allows([], Scope::CONTENT));
    }

    /**
     * A GROUP'S ADDRESS HAS NO SCOPE — not a special one, none.
     *
     * A permission that exists is a permission somebody grants by mistake, so
     * there is no string anybody can put in a scope list that produces the
     * address of the house a group meets in.
     */
    public function testThereIsNoScopeThatCouldGiveAGroupAddress(): void
    {
        foreach (['groups:address', 'groups:write', 'groups:all', 'groups:*', 'admin'] as $invented) {
            self::assertFalse(Scope::exists($invented), $invented);
        }

        self::assertSame([], Scope::clean(['groups:address']));
    }

    /**
     * An unrecognised scope is dropped rather than stored.
     *
     * Keeping one would put a line on the key's own screen describing a
     * permission that does not exist, and somebody would rely on it.
     */
    public function testAnUnknownScopeIsDroppedRatherThanStored(): void
    {
        self::assertSame(
            [Scope::EVENTS],
            Scope::clean([Scope::EVENTS, 'everything', '', 'events:read:all', ['nested']])
        );
    }

    public function testTheSameScopeTwiceIsOneScope(): void
    {
        self::assertSame([Scope::CONTENT], Scope::clean([Scope::CONTENT, Scope::CONTENT]));
    }

    public function testThereAreSixScopes(): void
    {
        self::assertCount(6, Scope::all());
    }

    /**
     * Exactly three reach identifiable people, and the screen marks them.
     *
     * Somebody choosing scopes for an integration should be able to see which
     * choices are consequential without reading the source.
     */
    public function testTheThreeThatCarryPersonalDataAreMarked(): void
    {
        $personal = array_keys(array_filter(
            Scope::all(),
            static fn (array $about): bool => $about['personal']
        ));

        self::assertSame(
            [Scope::REGISTRATIONS, Scope::CALENDAR],
            $personal,
            'the scopes carrying personal data are not the ones marked as such'
        );

        self::assertTrue(Scope::touchesPeople([Scope::CALENDAR, Scope::CONTENT]));
        self::assertFalse(Scope::touchesPeople([Scope::CONTENT, Scope::EVENTS, Scope::ANALYTICS]));
    }
}
