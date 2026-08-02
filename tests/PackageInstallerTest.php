<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\PackageInstaller;
use ZipArchive;

/**
 * ZIP installation, and everything it must refuse.
 *
 * The most security-sensitive code in the application. A plugin is PHP this
 * site executes, so a completed install is arbitrary code execution by design —
 * that cannot be defended against and is not what these tests are about. What
 * they pin is everything around it: that a crafted archive cannot write outside
 * its own folder, cannot fill the disk, and cannot half-install over a working
 * copy on its way to failing.
 *
 * Real archives on disk, not mocks. The thing under test is how PHP's own zip
 * extension behaves with hostile input, which a fake ZipArchive would not tell
 * us anything about.
 */
final class PackageInstallerTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('The zip extension is not available.');
        }

        $this->scratch = sys_get_temp_dir() . '/portal-pkg-' . bin2hex(random_bytes(6));
        @mkdir($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->scratch);

        // Every slug any archive in this file names, including the ones only a
        // BROKEN installer would create. Cleaning up only the expected ones is
        // how a mutation run left plugins/evil and plugins/ok behind, where
        // PluginManager's discovery in unrelated tests would have found them.
        foreach (['zipplugin', 'ziptheme', 'replaceme', 'evil', 'ok', 'one', 'two'] as $slug) {
            $this->deleteDirectory(PORTAL_PLUGINS . '/' . $slug);
            $this->deleteDirectory(PORTAL_THEMES . '/' . $slug);
        }

        @unlink(PORTAL_PUBLIC . '/hack.php');
    }

    // ---------------------------------------------------------- the happy path

    public function testAValidPluginArchiveInstalls(): void
    {
        $zip = $this->archive('plugin.zip', [
            'zipplugin/plugin.php' => "<?php\n/**\n * Plugin Name: Zip Plugin\n */\n",
            'zipplugin/readme.txt' => 'hello',
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertTrue($result['ok'], $result['message']);
        self::assertSame('zipplugin', $result['slug']);
        self::assertFalse($result['replaced']);
        self::assertFileExists(PORTAL_PLUGINS . '/zipplugin/plugin.php');
        self::assertFileExists(PORTAL_PLUGINS . '/zipplugin/readme.txt');
    }

    /** Installing over an existing copy is how a self-hosted site updates. */
    public function testInstallingOverAnExistingPluginReplacesIt(): void
    {
        @mkdir(PORTAL_PLUGINS . '/replaceme', 0775, true);
        file_put_contents(PORTAL_PLUGINS . '/replaceme/plugin.php', "<?php\n/**\n * Plugin Name: Old\n */\n");
        file_put_contents(PORTAL_PLUGINS . '/replaceme/stale.txt', 'from the old version');

        $zip = $this->archive('update.zip', [
            'replaceme/plugin.php' => "<?php\n/**\n * Plugin Name: New\n * Version: 2.0.0\n */\n",
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertTrue($result['ok'], $result['message']);
        self::assertTrue($result['replaced']);
        self::assertStringContainsString('Plugin Name: New', (string) file_get_contents(PORTAL_PLUGINS . '/replaceme/plugin.php'));
        self::assertFileDoesNotExist(
            PORTAL_PLUGINS . '/replaceme/stale.txt',
            'A replaced plugin should not keep files the new version dropped.'
        );
    }

    public function testAValidThemeArchiveInstalls(): void
    {
        $zip = $this->archive('theme.zip', [
            'ziptheme/theme.json' => json_encode(['name' => 'Zip Theme', 'version' => '1.0.0']),
            'ziptheme/index.php'  => '<?php // template',
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_THEME))->installArchive($zip);

        self::assertTrue($result['ok'], $result['message']);
        self::assertFileExists(PORTAL_THEMES . '/ziptheme/theme.json');
    }

    // ------------------------------------------------------------- traversal

    /**
     * The attack this whole class exists to stop. Refused from the table of
     * contents, before a single byte is written — checking afterwards means the
     * traversal already happened.
     */
    public function testAnArchiveThatEscapesItsFolderIsRefused(): void
    {
        $zip = $this->archive('evil.zip', [
            'evil/plugin.php'           => "<?php\n/**\n * Plugin Name: Evil\n */\n",
            'evil/../../public/hack.php' => '<?php echo "pwned";',
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('outside its own folder', $result['message']);
        self::assertFileDoesNotExist(PORTAL_PUBLIC . '/hack.php');
    }

    public function testAnAbsolutePathIsRefused(): void
    {
        $zip = $this->archive('absolute.zip', [
            'ok/plugin.php'   => "<?php\n/**\n * Plugin Name: Ok\n */\n",
            '/etc/cron.d/bad' => 'nope',
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('absolute path', $result['message']);
    }

    /**
     * A backslash is a directory separator on Windows and an ordinary filename
     * character elsewhere, so an archive using them can mean two different
     * things depending on where it is unpacked.
     */
    public function testABackslashTraversalIsRefused(): void
    {
        $zip = $this->archive('backslash.zip', [
            'ok/plugin.php'      => "<?php\n/**\n * Plugin Name: Ok\n */\n",
            'ok\\..\\..\\bad.php' => 'nope',
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('outside its own folder', $result['message']);
    }

    // ------------------------------------------------------------ well-formed

    public function testAnArchiveWithSeveralTopLevelFoldersIsRefused(): void
    {
        $zip = $this->archive('two.zip', [
            'one/plugin.php' => "<?php\n/**\n * Plugin Name: One\n */\n",
            'two/plugin.php' => "<?php\n/**\n * Plugin Name: Two\n */\n",
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('exactly one folder', $result['message']);
    }

    public function testAnEmptyArchiveIsRefused(): void
    {
        // The canonical empty ZIP: an end-of-central-directory record and
        // nothing else. Built by hand because ZipArchive will not save an
        // archive with no entries, and deleting the last one leaves a file it
        // then cannot reopen — which would test the wrong refusal.
        $path = $this->scratch . '/empty.zip';
        file_put_contents($path, "PK\x05\x06" . str_repeat("\0", 18));

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($path);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('empty', $result['message']);
    }

    public function testSomethingThatIsNotAZipIsRefused(): void
    {
        $path = $this->scratch . '/notazip.zip';
        file_put_contents($path, 'I am definitely not a zip file');

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($path);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('could not be opened', $result['message']);
    }

    public function testAFileWithoutAZipExtensionIsRefused(): void
    {
        $zip = $this->archive('thing.tar.gz', [
            'ok/plugin.php' => "<?php\n/**\n * Plugin Name: Ok\n */\n",
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip, 'thing.tar.gz');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('not a .zip', $result['message']);
    }

    // -------------------------------------------------------- is it the thing

    /**
     * Without this a package installs as a folder nothing ever loads — a
     * silent no-op that looks like success.
     */
    public function testAnArchiveWithNoPluginFileIsRefused(): void
    {
        $zip = $this->archive('notaplugin.zip', ['zipplugin/readme.txt' => 'just docs']);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no plugin.php', $result['message']);
        self::assertDirectoryDoesNotExist(PORTAL_PLUGINS . '/zipplugin');
    }

    public function testAPluginWithNoNameHeaderIsRefused(): void
    {
        $zip = $this->archive('headerless.zip', ['zipplugin/plugin.php' => "<?php\n// nothing\n"]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Plugin Name', $result['message']);
    }

    public function testAPluginNeedingANewerPortalIsRefusedBeforeItLands(): void
    {
        $zip = $this->archive('future.zip', [
            'zipplugin/plugin.php' => "<?php\n/**\n * Plugin Name: Future\n * Requires: 99.0.0\n */\n",
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertDirectoryDoesNotExist(
            PORTAL_PLUGINS . '/zipplugin',
            'An incompatible plugin must not be left on disk to be found later.'
        );
    }

    public function testAThemeWithNoManifestIsRefused(): void
    {
        $zip = $this->archive('nomanifest.zip', ['ziptheme/index.php' => '<?php']);

        $result = (new PackageInstaller(PackageInstaller::KIND_THEME))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no theme.json', $result['message']);
    }

    /**
     * A child theme with no parent installed resolves half its templates to
     * nothing, which reads as a broken site rather than a missing dependency.
     */
    public function testAChildThemeWithoutItsParentIsRefused(): void
    {
        $zip = $this->archive('child.zip', [
            'ziptheme/theme.json' => json_encode([
                'name'    => 'Child',
                'version' => '1.0.0',
                'parent'  => 'a-theme-that-is-not-here',
            ]),
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_THEME))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('not installed', $result['message']);
    }

    /** A child of a theme that IS present is fine. */
    public function testAChildOfAnInstalledThemeIsAccepted(): void
    {
        $zip = $this->archive('goodchild.zip', [
            'ziptheme/theme.json' => json_encode([
                'name'    => 'Child of Default',
                'version' => '1.0.0',
                'parent'  => 'default',
            ]),
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_THEME))->installArchive($zip);

        self::assertTrue($result['ok'], $result['message']);
    }

    // -------------------------------------------------------------- resources

    /**
     * A shared host's disk is small and shared with the customer's own files,
     * so filling it is a real denial of service rather than a theoretical one.
     */
    public function testAHighlyCompressibleArchiveIsRefused(): void
    {
        // Ten megabytes of zeroes compresses to almost nothing — the classic
        // shape of a zip bomb, at a size this test can build quickly.
        $zip = $this->archive('bomb.zip', [
            'zipplugin/plugin.php' => "<?php\n/**\n * Plugin Name: Bomb\n */\n",
            'zipplugin/payload.bin' => str_repeat("\0", 10 * 1024 * 1024),
        ]);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('compressed suspiciously', $result['message']);
        self::assertDirectoryDoesNotExist(PORTAL_PLUGINS . '/zipplugin');
    }

    public function testAnArchiveWithTooManyEntriesIsRefused(): void
    {
        $entries = ['zipplugin/plugin.php' => "<?php\n/**\n * Plugin Name: Many\n */\n"];
        for ($i = 0; $i < 2100; $i++) {
            // Distinct contents, so the ratio check does not fire first and
            // make this test pass for the wrong reason.
            $entries['zipplugin/f' . $i . '.txt'] = 'file number ' . $i . ' ' . bin2hex(random_bytes(8));
        }

        $zip = $this->archive('many.zip', $entries);

        $result = (new PackageInstaller(PackageInstaller::KIND_PLUGIN))->installArchive($zip);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('more than a plugin should need', $result['message']);
    }

    // --------------------------------------------------------------- fixtures

    /** @param array<string, string> $entries */
    private function archive(string $name, array $entries): string
    {
        $path = $this->scratch . '/' . $name;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $entryName => $contents) {
            $zip->addFromString($entryName, $contents);
        }

        $zip->close();

        return $path;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->deleteDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
