<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Auth\AccessRequestMailer;
use Portal\Auth\AccessRequests;

/**
 * The message an administrator receives about a stranger.
 *
 * Every value in it but the site name was chosen by somebody the site has not
 * approved — their name comes from whatever they typed at the identity
 * provider, and the note is free text. This is the only place in the product
 * where unapproved input reaches somebody's inbox, so it is worth its own
 * tests rather than being covered incidentally by a send that never happens
 * without a live mail provider.
 */
final class AccessRequestMessageTest extends TestCase
{
    private const LINK = 'https://videos.example.org/admin/users';

    public function testTheNoteIsEscapedIntoTheHtmlPart(): void
    {
        $html = AccessRequestMailer::html(
            'Someone',
            'someone@example.com',
            '<script>alert(1)</script>',
            'Marine Team Videos',
            self::LINK
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * The display name comes from the identity provider, which is to say from
     * the person. It is as untrusted as the note and is escaped the same way.
     */
    public function testTheNameIsEscapedToo(): void
    {
        $html = AccessRequestMailer::html(
            '</strong><img src=x onerror=alert(1)>',
            'someone@example.com',
            'hello',
            'Marine Team Videos',
            self::LINK
        );

        self::assertStringNotContainsString('<img', $html);
    }

    public function testTheAddressIsEscaped(): void
    {
        $html = AccessRequestMailer::html(
            'Someone',
            'a"><b>@example.com',
            'hello',
            'Marine Team Videos',
            self::LINK
        );

        self::assertStringNotContainsString('<b>', $html);
    }

    /**
     * Paragraph breaks become line breaks. The note is stored with real
     * newlines, and an HTML email that ignored them would run two thoughts
     * together — which matters here, because the message is what an
     * administrator decides on.
     */
    public function testLineBreaksInTheNoteSurviveIntoTheHtml(): void
    {
        $html = AccessRequestMailer::html(
            'Someone',
            'someone@example.com',
            "I'm on the Thursday team.\n\nSam sent me.",
            'Marine Team Videos',
            self::LINK
        );

        self::assertStringContainsString('<br', $html);
    }

    /**
     * Asking without saying anything is still asking, and the message has to
     * make that distinction — otherwise a blank quote reads as though the
     * request itself is empty.
     */
    public function testAnEmptyNoteIsCalledOutRatherThanLeftBlank(): void
    {
        $html = AccessRequestMailer::html('Someone', 'someone@example.com', '', 'Site', self::LINK);
        $text = AccessRequestMailer::plain('Someone', 'someone@example.com', '', 'Site', self::LINK);

        self::assertStringContainsString('did not leave a message', $html);
        self::assertStringContainsString('did not leave a message', $text);
    }

    /**
     * The text part is not escaped, deliberately. HTML-escaping a text/plain
     * body is how a reader ends up with &amp; in the middle of a sentence.
     */
    public function testThePlainPartIsNotHtmlEscaped(): void
    {
        $text = AccessRequestMailer::plain(
            'Sam & Alex',
            'sam@example.com',
            'We run the 5 & 7 o\'clock services',
            'Site',
            self::LINK
        );

        self::assertStringContainsString('Sam & Alex', $text);
        self::assertStringContainsString('5 & 7', $text);
        self::assertStringNotContainsString('&amp;', $text);
    }

    public function testBothPartsCarryTheLinkToActOn(): void
    {
        $html = AccessRequestMailer::html('S', 's@example.com', 'hi', 'Site', self::LINK);
        $text = AccessRequestMailer::plain('S', 's@example.com', 'hi', 'Site', self::LINK);

        self::assertStringContainsString(self::LINK, $html);
        self::assertStringContainsString(self::LINK, $text);
    }

    /**
     * The two parts of one message have to say the same thing. A text fallback
     * that omitted the note would quietly strip the reason for the decision
     * from anybody whose client prefers plain text.
     */
    public function testTheTwoPartsAgreeOnWhatWasSaid(): void
    {
        $note = 'I help with the sound desk on Sundays';

        self::assertStringContainsString(
            $note,
            AccessRequestMailer::plain('S', 's@example.com', $note, 'Site', self::LINK)
        );
        self::assertStringContainsString(
            $note,
            AccessRequestMailer::html('S', 's@example.com', $note, 'Site', self::LINK)
        );
    }

    /**
     * A note that reached the message has already been through sanitize(), so
     * the two are tested together: control characters must not survive the
     * journey into somebody's mail client.
     */
    public function testASanitizedNoteCarriesNoControlCharacters(): void
    {
        $note = AccessRequests::sanitize("Hello\x00 there\x1b[31m");
        $text = AccessRequestMailer::plain('S', 's@example.com', $note, 'Site', self::LINK);

        self::assertSame('Hello there[31m', $note);
        self::assertStringNotContainsString("\x1b", $text);
    }
}
