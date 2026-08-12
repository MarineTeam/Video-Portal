<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\TagRepository;

/**
 * Turning what somebody typed into tags.
 *
 * Pure text handling, so no database. The rules matter more than they look:
 * every one of them exists to stop the same idea becoming several tags, each
 * linking to a page carrying a fraction of the content.
 */
final class TagParsingTest extends TestCase
{
    public function testSplitsOnCommasAndTrims(): void
    {
        self::assertSame(
            ['prayer', 'advent', 'guest speaker'],
            TagRepository::parse('  prayer , advent,guest speaker  ')
        );
    }

    public function testDropsEmptyEntries(): void
    {
        self::assertSame(['prayer'], TagRepository::parse(',,  ,prayer,  ,'));
        self::assertSame([], TagRepository::parse(''));
        self::assertSame([], TagRepository::parse('   ,  , '));
    }

    /**
     * The whole reason the slug is the identity.
     *
     * Three spellings of one idea in a single submission collapse to one tag,
     * and the FIRST spelling is kept — so a person who types "Prayer" sees
     * "Prayer" back rather than having their capitalisation silently rewritten
     * by whatever order the parser happened to run in.
     */
    public function testCollapsesSpellingsOfOneTagKeepingTheFirst(): void
    {
        self::assertSame(['Prayer'], TagRepository::parse('Prayer, prayer,  PRAYER '));
    }

    /** Internal runs of whitespace are one space, so "guest   speaker" is one tag. */
    public function testCollapsesInternalWhitespace(): void
    {
        self::assertSame(['guest speaker'], TagRepository::parse("guest \t  speaker"));
    }

    /**
     * Something with no sluggable characters cannot be linked to, so it is
     * dropped rather than stored as a tag whose page can never be reached.
     */
    public function testDropsEntriesThatCannotBecomeASlug(): void
    {
        self::assertSame(['prayer'], TagRepository::parse('!!!, prayer, ???'));
    }

    public function testCapsTheNumberOfTags(): void
    {
        $input = implode(',', array_map(static fn (int $n): string => 'tag' . $n, range(1, 40)));
        $parsed = TagRepository::parse($input);

        self::assertCount(TagRepository::MAX_PER_ITEM, $parsed);
        self::assertSame('tag1', $parsed[0], 'The cap should keep the first ones, not a random slice.');
    }

    /**
     * Length is counted in CHARACTERS.
     *
     * The same mistake the password policy had: strlen would cut a Japanese tag
     * at 40 characters while letting an English one through at 120, and cutting
     * mid-character produces a broken UTF-8 string the database then rejects or
     * mangles.
     */
    public function testTruncatesByCharactersNotBytes(): void
    {
        $long = str_repeat('あ', 200);
        $parsed = TagRepository::parse($long);

        self::assertCount(1, $parsed);
        self::assertSame(TagRepository::MAX_LENGTH, mb_strlen($parsed[0]));
        self::assertSame($parsed[0], mb_convert_encoding($parsed[0], 'UTF-8', 'UTF-8'), 'Cut mid-character.');
    }
}
