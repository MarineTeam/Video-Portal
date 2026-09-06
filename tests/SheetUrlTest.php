<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Schedules\SheetUrl;

/**
 * Turning what somebody pastes into something that can be fetched.
 *
 * Whoever keeps a rota pastes what is in the address bar. Asking them to build
 * an export URL by hand is asking most of them to give up.
 */
final class SheetUrlTest extends TestCase
{
    private const ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz';

    public function testAnEditAddressBecomesACsvExport(): void
    {
        self::assertSame(
            'https://docs.google.com/spreadsheets/d/' . self::ID . '/export?format=csv',
            SheetUrl::toCsv('https://docs.google.com/spreadsheets/d/' . self::ID . '/edit?usp=sharing')
        );
    }

    /**
     * The tab comes with it.
     *
     * A sheet with four tabs exports the first one unless told otherwise, and
     * "it synced the wrong tab" is a confusing thing to be told.
     */
    public function testTheTabIsCarriedAcross(): void
    {
        $expected = 'https://docs.google.com/spreadsheets/d/' . self::ID . '/export?format=csv&gid=1893';

        // In the fragment, which is where an /edit address keeps it...
        self::assertSame(
            $expected,
            SheetUrl::toCsv('https://docs.google.com/spreadsheets/d/' . self::ID . '/edit#gid=1893')
        );

        // ...and in the query, which is where a link somebody was sent has it.
        self::assertSame(
            $expected,
            SheetUrl::toCsv('https://docs.google.com/spreadsheets/d/' . self::ID . '/edit?gid=1893')
        );
    }

    /** Somebody who built an export URL deliberately knows which tab they meant. */
    public function testAnExportAddressIsLeftExactlyAsItIs(): void
    {
        $already = 'https://docs.google.com/spreadsheets/d/' . self::ID . '/export?format=csv&gid=4';

        self::assertSame($already, SheetUrl::toCsv($already));
    }

    /**
     * ONLY GOOGLE. Any host would mean an SSRF check on a job that runs
     * unattended, and a hostname that resolved publicly on Tuesday can resolve
     * to 127.0.0.1 on Wednesday. Restricting the source makes the question not
     * arise rather than answering it every fifteen minutes.
     */
    public function testNothingButAGoogleSheetIsAccepted(): void
    {
        foreach ([
            'https://example.com/rota.csv',
            'http://docs.google.com/spreadsheets/d/' . self::ID . '/edit',
            'https://docs.google.com.evil.test/spreadsheets/d/' . self::ID . '/edit',
            'https://169.254.169.254/latest/meta-data/',
            'file:///etc/passwd',
            'https://docs.google.com/document/d/' . self::ID . '/edit',
            '',
        ] as $notASheet) {
            self::assertNull(SheetUrl::toCsv($notASheet), $notASheet);
        }
    }
}
