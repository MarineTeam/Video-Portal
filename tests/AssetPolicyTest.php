<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\AssetPolicy;

/**
 * What may be attached, and what it is called.
 *
 * Every decision in this class is a security decision, so these are mostly
 * tests of refusals. The two threats: executing an upload on the server, and
 * executing it in somebody's browser. Storing outside the document root
 * handles the first; everything here is about the second and about a name
 * escaping the directory it was meant for.
 */
final class AssetPolicyTest extends TestCase
{
    // ------------------------------------------------------------ extensions

    public function testOrdinaryDocumentsAreAllowed(): void
    {
        foreach (['notes.pdf', 'slides.pptx', 'sheet.xlsx', 'handout.docx', 'audio.mp3'] as $name) {
            self::assertTrue(AssetPolicy::isAllowed($name), "Refused: {$name}");
        }
    }

    /**
     * The oldest upload bypass there is. Taking the FIRST dot-segment would
     * read this as a pdf.
     */
    public function testADoubleExtensionIsReadFromTheEnd(): void
    {
        self::assertSame('php', AssetPolicy::extension('notes.pdf.php'));
        self::assertFalse(AssetPolicy::isAllowed('notes.pdf.php'));
    }

    public function testExecutableTypesAreRefused(): void
    {
        foreach ([
            'shell.php', 'shell.PHP', 'shell.phtml', 'thing.exe', 'run.sh',
            'page.html', 'page.htm', 'script.js', 'config.htaccess',
        ] as $name) {
            self::assertFalse(AssetPolicy::isAllowed($name), "Allowed: {$name}");
        }
    }

    /**
     * SVG is an image everywhere else and a script container here — one that
     * runs in the origin of whoever opens it.
     */
    public function testSvgIsRefused(): void
    {
        self::assertFalse(AssetPolicy::isAllowed('diagram.svg'));
    }

    public function testAFileWithNoExtensionIsRefused(): void
    {
        self::assertNull(AssetPolicy::extension('README'));
        self::assertNull(AssetPolicy::extension('notes.'));
        self::assertFalse(AssetPolicy::isAllowed('README'));
    }

    public function testTheExtensionIsLowercased(): void
    {
        self::assertSame('pdf', AssetPolicy::extension('NOTES.PDF'));
        self::assertTrue(AssetPolicy::isAllowed('NOTES.PDF'));
    }

    /** A path must not have its directories taken seriously, even for a dot. */
    public function testATraversingNameIsReadAsItsBasename(): void
    {
        self::assertSame('pdf', AssetPolicy::extension('../../../etc/notes.pdf'));
        self::assertSame('pdf', AssetPolicy::extension('..\\..\\windows\\notes.pdf'));
    }

    public function testAnAbsurdExtensionIsRefused(): void
    {
        self::assertNull(AssetPolicy::extension('notes.' . str_repeat('x', 40)));
        self::assertNull(AssetPolicy::extension('notes.p df'));
    }

    // ---------------------------------------------------------- content type

    /**
     * The type comes from the allowlist, never from the upload. A browser's
     * declared type is attacker-controlled.
     */
    public function testTheContentTypeComesFromTheExtension(): void
    {
        self::assertSame('application/pdf', AssetPolicy::contentType('notes.pdf'));
        self::assertSame('image/png', AssetPolicy::contentType('chart.png'));
    }

    /** A type nobody claimed is a download and nothing else. */
    public function testAnUnknownTypeIsAnOctetStream(): void
    {
        self::assertSame('application/octet-stream', AssetPolicy::contentType('thing.php'));
        self::assertSame('application/octet-stream', AssetPolicy::contentType('README'));
    }

    // ----------------------------------------------------------- stored name

    /** Never derived from what was uploaded. */
    public function testTheStoredNameIsRandom(): void
    {
        $first = AssetPolicy::storedName('notes.pdf');
        $second = AssetPolicy::storedName('notes.pdf');

        self::assertNotNull($first);
        self::assertNotSame($first, $second);
        self::assertStringEndsWith('.pdf', $first);
        self::assertStringNotContainsString('notes', $first);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $first);
    }

    public function testARefusedTypeGetsNoStoredName(): void
    {
        self::assertNull(AssetPolicy::storedName('shell.php'));
        self::assertNull(AssetPolicy::storedName('notes.pdf.php'));
        self::assertNull(AssetPolicy::storedName('README'));
    }

    public function testThePathIsSplitByYearAndMonth(): void
    {
        $path = AssetPolicy::relativePath('abc.pdf', new \DateTimeImmutable('2026-03-04'));

        self::assertSame('assets/2026/03/abc.pdf', $path);
    }

    // ---------------------------------------------------------- display name

    /**
     * The original is kept only for display and for the download filename. A
     * filename reaching a header can inject one if it carries a quote or a
     * newline.
     */
    public function testADisplayNameCannotBreakAHeader(): void
    {
        $name = AssetPolicy::displayName("notes\r\nX-Injected: yes\".pdf");

        self::assertStringNotContainsString("\r", $name);
        self::assertStringNotContainsString("\n", $name);
        self::assertStringNotContainsString('"', $name);
    }

    public function testADisplayNameCarriesNoDirectories(): void
    {
        self::assertSame('notes.pdf', AssetPolicy::displayName('../../etc/notes.pdf'));
        self::assertSame('notes.pdf', AssetPolicy::displayName('C:\\Users\\me\\notes.pdf'));
    }

    public function testADisplayNameIsNeverEmpty(): void
    {
        self::assertSame('attachment', AssetPolicy::displayName(''));
        self::assertSame('attachment', AssetPolicy::displayName('///'));
        self::assertSame('attachment', AssetPolicy::displayName('...'));
    }

    public function testAnOrdinaryNameSurvivesIntact(): void
    {
        self::assertSame(
            'Sermon notes - 4 March 2026.pdf',
            AssetPolicy::displayName('Sermon notes - 4 March 2026.pdf')
        );
    }

    public function testALongNameIsTruncated(): void
    {
        $name = AssetPolicy::displayName(str_repeat('a', 400) . '.pdf');

        self::assertLessThanOrEqual(AssetPolicy::MAX_NAME_LENGTH, mb_strlen($name));
    }

    // ----------------------------------------------------------------- sizes

    public function testSizesReadTheWayPeopleWriteThem(): void
    {
        self::assertSame('512 B', AssetPolicy::formatSize(512));
        self::assertSame('2 KB', AssetPolicy::formatSize(2048));
        self::assertSame('1.5 MB', AssetPolicy::formatSize((int) (1.5 * 1024 * 1024)));
    }
}
