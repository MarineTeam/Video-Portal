<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Watermark\WatermarkPolicy;

require_once PORTAL_PLUGINS . '/watermark/src/WatermarkPolicy.php';

/**
 * The watermark resolution order.
 *
 * Worth pinning exhaustively rather than sampling: this decides whether a
 * leaked recording can be traced to a person, and every level of the order has
 * a case where getting it wrong is invisible — an unwatermarked video looks
 * completely normal, and nobody discovers the mistake until a leak they cannot
 * attribute.
 */
final class WatermarkPolicyTest extends TestCase
{
    private const ON      = WatermarkPolicy::MODE_ON;
    private const OFF     = WatermarkPolicy::MODE_OFF;
    private const DEFAULT = WatermarkPolicy::MODE_DEFAULT;

    // --------------------------------------------------------- the order

    public function testShareBeatsVideo(): void
    {
        self::assertTrue($this->decide(shareMode: self::ON, videoMode: self::OFF));
        self::assertFalse($this->decide(shareMode: self::OFF, videoMode: self::ON));
    }

    public function testVideoBeatsTheGlobalDefaultWhenTheShareDefers(): void
    {
        self::assertTrue($this->decide(videoMode: self::ON, globalDefault: false));
        self::assertFalse($this->decide(videoMode: self::OFF, globalDefault: true));
    }

    public function testTheGlobalDefaultAppliesWhenNothingElseSaysAnything(): void
    {
        self::assertTrue($this->decide(globalDefault: true));
        self::assertFalse($this->decide(globalDefault: false));
    }

    /**
     * The deliberate part of the order, and the one most likely to be
     * questioned later: an exemption names a person, and it outranks even an
     * explicit "always watermark this share".
     */
    public function testAnExemptionBeatsEverything(): void
    {
        self::assertFalse($this->decide(
            exempt: ['alice@example.com'],
            viewer: 'alice@example.com',
            shareMode: self::ON,
            videoMode: self::ON,
            globalDefault: true
        ));
    }

    /**
     * A plugin an admin switched off — globally, or for this one category —
     * draws nothing, whatever every other level says. Anything else would make
     * "deactivate" fail to deactivate.
     */
    public function testBeingDisabledForTheCategoryOverridesEveryMode(): void
    {
        self::assertFalse($this->decide(
            enabled: false,
            shareMode: self::ON,
            videoMode: self::ON,
            globalDefault: true
        ));
    }

    // ------------------------------------------------------- exemptions

    public function testExemptionIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertTrue(WatermarkPolicy::isExempt('  Alice@Example.COM ', ['alice@example.com']));
        self::assertTrue(WatermarkPolicy::isExempt('alice@example.com', [' ALICE@EXAMPLE.COM ']));
    }

    public function testADomainExemptionCoversEveryAddressInIt(): void
    {
        self::assertTrue(WatermarkPolicy::isExempt('bob@example.com', ['@example.com']));
        self::assertFalse(WatermarkPolicy::isExempt('bob@other.com', ['@example.com']));
    }

    /**
     * The suffix match must not treat a domain exemption as a plain substring,
     * or "@example.com" would exempt "someone@notexample.com" as well.
     */
    public function testADomainExemptionDoesNotMatchALookalikeDomain(): void
    {
        self::assertFalse(WatermarkPolicy::isExempt('bob@notexample.com', ['@example.com']));
    }

    /**
     * An unidentified viewer is never exempt. Everything that reaches the
     * overlay has already established who is watching, so a blank address is a
     * bug — and the safe response to "I do not know who this is" is to mark the
     * recording, not to leave it clean.
     */
    public function testAnEmptyViewerAddressIsNeverExempt(): void
    {
        self::assertFalse(WatermarkPolicy::isExempt('', ['']));
        self::assertFalse(WatermarkPolicy::isExempt('', ['alice@example.com']));
        self::assertTrue($this->decide(viewer: '', exempt: [''], globalDefault: true));
    }

    // ------------------------------------------------------------ input

    public function testTheListAcceptsCommasSemicolonsAndNewlines(): void
    {
        self::assertSame(
            ['a@x.com', 'b@x.com', 'c@x.com'],
            WatermarkPolicy::parseList("a@x.com, b@x.com;\n c@x.com\n")
        );
    }

    public function testTheListDeduplicatesAfterNormalising(): void
    {
        self::assertSame(['a@x.com'], WatermarkPolicy::parseList('a@x.com, A@X.com'));
    }

    // ----------------------------------------------------------- labels

    public function testTokensAreSubstituted(): void
    {
        self::assertSame(
            'alice@example.com — 1 Jan 2026',
            WatermarkPolicy::label('{email} — {date}', ['email' => 'alice@example.com', 'date' => '1 Jan 2026'])
        );
    }

    /** An unknown token is dropped, not left as literal braces that look broken. */
    public function testUnknownTokensAreRemoved(): void
    {
        self::assertSame('alice@example.com', WatermarkPolicy::label('{email} {nope}', ['email' => 'alice@example.com']));
    }

    /**
     * A watermark that renders nothing still darkens the video, so it looks
     * broken while protecting nothing. Never let the label end up empty.
     */
    public function testAnEmptyLabelFallsBackToSomethingVisible(): void
    {
        self::assertSame('confidential', WatermarkPolicy::label('{nope}', []));
        self::assertSame('confidential', WatermarkPolicy::label('   ', []));
    }

    public function testAnEmptyTemplateDefaultsToTheEmail(): void
    {
        self::assertSame('alice@example.com', WatermarkPolicy::label('', ['email' => 'alice@example.com']));
    }

    // ---------------------------------------------------------- opacity

    public function testOpacityIsClampedToAUsableRange(): void
    {
        self::assertSame(0.04, WatermarkPolicy::clampOpacity(0.0));
        self::assertSame(0.04, WatermarkPolicy::clampOpacity(-5));
        self::assertSame(0.40, WatermarkPolicy::clampOpacity(1.0));
        self::assertSame(0.20, WatermarkPolicy::clampOpacity('0.2'));
    }

    public function testOpacityFallsBackWhenItIsNotANumber(): void
    {
        self::assertSame(0.12, WatermarkPolicy::clampOpacity('lots'));
        self::assertSame(0.12, WatermarkPolicy::clampOpacity(null));
    }

    // --------------------------------------------------------- fixtures

    /** @param list<string> $exempt */
    private function decide(
        bool $enabled = true,
        array $exempt = [],
        string $viewer = 'viewer@example.com',
        string $shareMode = self::DEFAULT,
        string $videoMode = self::DEFAULT,
        bool $globalDefault = false
    ): bool {
        return WatermarkPolicy::shouldWatermark(
            $enabled,
            $exempt,
            $viewer,
            $shareMode,
            $videoMode,
            $globalDefault
        );
    }
}
