<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Comments\CommentPolicy;

require_once PORTAL_PLUGINS . '/comments/src/CommentPolicy.php';

/**
 * What gets published, and what waits for a human.
 *
 * This is the difference between a comment section a small site can run and one
 * that becomes a second job. The failure is asymmetric: holding a real comment
 * annoys one person for a few hours, while publishing a spam run is visible to
 * everyone and stays until somebody notices.
 */
final class CommentPolicyTest extends TestCase
{
    private const NEWCOMERS = CommentPolicy::MODERATE_NEWCOMERS;
    private const ALL = CommentPolicy::MODERATE_ALL;
    private const NONE = CommentPolicy::MODERATE_NONE;

    private const PENDING = CommentPolicy::STATUS_PENDING;
    private const APPROVED = CommentPolicy::STATUS_APPROVED;

    // ------------------------------------------------------------ moderation

    public function testANewcomerIsHeldAndAnEstablishedAuthorIsNot(): void
    {
        self::assertSame(self::PENDING, CommentPolicy::initialStatus(self::NEWCOMERS, 0, 'Hello.'));
        self::assertSame(self::APPROVED, CommentPolicy::initialStatus(self::NEWCOMERS, 1, 'Hello again.'));
    }

    public function testHoldingEverythingHoldsEstablishedAuthorsToo(): void
    {
        self::assertSame(self::PENDING, CommentPolicy::initialStatus(self::ALL, 50, 'Hello.'));
    }

    public function testPublishingImmediatelyLetsANewcomerThrough(): void
    {
        self::assertSame(self::APPROVED, CommentPolicy::initialStatus(self::NONE, 0, 'Hello.'));
    }

    /**
     * The one rule that outranks the setting. Turning moderation off says "I
     * trust my audience", not "publish link farms unread".
     */
    public function testObviousSpamIsHeldEvenWithModerationOff(): void
    {
        $spam = 'Buy now http://a.com http://b.com http://c.com http://d.com';

        self::assertSame(self::PENDING, CommentPolicy::initialStatus(self::NONE, 999, $spam));
    }

    /** An unrecognised setting falls to the safe default, not to publishing. */
    public function testAnUnknownModeBehavesLikeTheNewcomerRule(): void
    {
        self::assertSame(self::PENDING, CommentPolicy::initialStatus('nonsense', 0, 'Hi.'));
        self::assertSame(self::APPROVED, CommentPolicy::initialStatus('nonsense', 3, 'Hi.'));
    }

    // ----------------------------------------------------------------- spam

    public function testAFewLinksAreFineAndManyAreNot(): void
    {
        self::assertFalse(CommentPolicy::looksLikeSpam('See http://example.com and http://other.com'));
        self::assertTrue(CommentPolicy::looksLikeSpam(
            'http://a.com http://b.com http://c.com http://d.com'
        ));
    }

    public function testAWallWithNoSpacesIsTreatedAsGenerated(): void
    {
        self::assertTrue(CommentPolicy::looksLikeSpam(str_repeat('x', 100)));
        self::assertFalse(CommentPolicy::looksLikeSpam(
            'A perfectly ordinary sentence that happens to be reasonably long but has spaces in it.'
        ));
    }

    public function testMarkupInACommentIsTreatedAsProbing(): void
    {
        self::assertTrue(CommentPolicy::looksLikeSpam('<a href="http://x.com">click</a>'));
        self::assertTrue(CommentPolicy::looksLikeSpam('[url=http://x.com]click[/url]'));
    }

    public function testOrdinaryWritingIsNotSpam(): void
    {
        self::assertFalse(CommentPolicy::looksLikeSpam(
            "Thanks for this — the section about Romans 8 was really helpful.\n\nLooking forward to next week."
        ));
    }

    // ------------------------------------------------------------ normalising

    public function testAnEmptyCommentIsRefused(): void
    {
        $result = CommentPolicy::normalize("   \n\n  ");

        self::assertFalse($result['ok']);
        self::assertSame('Write something first.', $result['error']);
    }

    public function testAnOverlongCommentIsRefusedWithTheNumbers(): void
    {
        $result = CommentPolicy::normalize(str_repeat('a ', 2000));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('the limit is 3000', (string) $result['error']);
    }

    public function testLineEndingsAreNormalisedAndBlankRunsCollapsed(): void
    {
        $result = CommentPolicy::normalize("one\r\n\r\n\r\n\r\ntwo");

        self::assertTrue($result['ok']);
        self::assertSame("one\n\ntwo", $result['body']);
    }

    /** Invisible characters are usually an attempt to confuse something. */
    public function testControlCharactersAreStripped(): void
    {
        $result = CommentPolicy::normalize("hello\x00\x07 there");

        self::assertTrue($result['ok']);
        self::assertSame('hello there', $result['body']);
    }

    public function testNewlinesAndTabsSurvive(): void
    {
        $result = CommentPolicy::normalize("one\n\ttwo");

        self::assertTrue($result['ok']);
        self::assertSame("one\n\ttwo", $result['body']);
    }

    // -------------------------------------------------------------- visibility

    public function testOnlyApprovedCommentsAreVisible(): void
    {
        self::assertTrue(CommentPolicy::isVisible(self::APPROVED, 0));
        self::assertFalse(CommentPolicy::isVisible(self::PENDING, 0));
        self::assertFalse(CommentPolicy::isVisible(CommentPolicy::STATUS_SPAM, 0));
    }

    /**
     * A removed comment with replies stays as a tombstone. Hiding it entirely
     * would leave answers to a question nobody can see.
     */
    public function testARemovedCommentSurvivesOnlyWhileItHasReplies(): void
    {
        self::assertTrue(CommentPolicy::isVisible(CommentPolicy::STATUS_REMOVED, 2));
        self::assertFalse(CommentPolicy::isVisible(CommentPolicy::STATUS_REMOVED, 0));
    }
}
