<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Plugins\Hooks;
use Portal\Themes\ThemeManager;
use Portal\Themes\ThemeManifest;

/**
 * Theme discovery, activation, template overriding, and customizer storage.
 *
 * Like the plugin tests, these write real theme folders to disk — template
 * resolution IS filesystem lookup, so mocking it would test nothing.
 */
final class ThemeManagerTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $fixtures = [];

    private ThemeManager $manager;
    private Hooks $hooks;

    protected function setUp(): void
    {
        $this->truncate(['theme_settings', 'themes']);

        Hooks::reset();
        $this->hooks = Hooks::instance();
        $this->freshManager();
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            $this->deleteDirectory(PORTAL_THEMES . '/' . $slug);
        }
        $this->fixtures = [];
        Hooks::reset();
    }

    // -------------------------------------------------------------- discovery

    public function testTheBundledDefaultThemeIsPresentAndValid(): void
    {
        $themes = $this->manager->discover();

        self::assertArrayHasKey('default', $themes, 'The default theme must always ship.');
        self::assertSame('Default', $themes['default']->name);
        self::assertTrue($themes['default']->bundled);
    }

    public function testDefaultThemeDeclaresItsCustomizerSettings(): void
    {
        $definitions = $this->manager->discover()['default']->settingDefinitions();

        self::assertArrayHasKey('accent', $definitions);
        self::assertArrayHasKey('site_name', $definitions);
        self::assertSame('#38bdf8', $this->manager->discover()['default']->defaults()['accent']);
    }

    public function testAThemeWithMalformedJsonIsSkippedRatherThanFatal(): void
    {
        $slug = 'brokenjson';
        $this->fixtures[] = $slug;
        @mkdir(PORTAL_THEMES . '/' . $slug, 0775, true);
        file_put_contents(PORTAL_THEMES . '/' . $slug . '/theme.json', '{ this is not json');

        $this->freshManager();

        self::assertArrayNotHasKey($slug, $this->manager->discover());
        // And the rest of the site still works.
        self::assertArrayHasKey('default', $this->manager->discover());
    }

    // -------------------------------------------------------------- activation

    public function testDefaultIsActiveWhenNothingHasBeenChosen(): void
    {
        self::assertSame('default', $this->manager->activeSlug());
    }

    public function testActivatingASecondTheme(): void
    {
        $this->makeTheme('minimal', ['name' => 'Minimal']);

        $result = $this->manager->activate('minimal');

        self::assertTrue($result['ok'], $result['message']);
        self::assertSame('minimal', $this->manager->activeSlug());
    }

    public function testActivatingAnUnknownThemeFails(): void
    {
        $result = $this->manager->activate('nonexistent');

        self::assertFalse($result['ok']);
        self::assertSame('default', $this->manager->activeSlug());
    }

    /**
     * A child theme whose parent is missing would silently fall through to
     * default for most templates, which looks like a broken theme rather than
     * a missing dependency. Refuse and say which.
     */
    public function testActivatingAChildWithoutItsParentIsRefused(): void
    {
        $this->makeTheme('orphan', ['name' => 'Orphan', 'parent' => 'missing-parent']);

        $result = $this->manager->activate('orphan');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('missing-parent', $result['message']);
    }

    /**
     * A row can point at a folder someone deleted over FTP. Falling back to
     * default keeps the site rendering instead of 500-ing on every page.
     */
    public function testFallsBackToDefaultWhenTheActiveThemeFolderVanishes(): void
    {
        $this->makeTheme('temporary', ['name' => 'Temporary']);
        $this->manager->activate('temporary');
        self::assertSame('temporary', $this->manager->activeSlug());

        $this->deleteDirectory(PORTAL_THEMES . '/temporary');
        $this->freshManager();

        self::assertSame('default', $this->manager->activeSlug());
    }

    // ------------------------------------------------------- template lookup

    public function testDefaultThemeProvidesTheCoreTemplates(): void
    {
        $loader = $this->manager->loader();

        foreach (['index', 'video', 'archive', 'single'] as $name) {
            self::assertTrue($loader->exists($name), "The default theme must ship {$name}.php");
        }
    }

    /**
     * The heart of theming: dropping a file into a theme replaces the core one
     * with no registration step at all.
     */
    public function testAThemeTemplateOverridesTheDefault(): void
    {
        $this->makeTheme('custom', ['name' => 'Custom'], [
            'video.php' => '<?php echo "CUSTOM VIDEO TEMPLATE";',
        ]);
        $this->manager->activate('custom');

        $resolved = $this->manager->loader()->resolve(['video']);

        self::assertNotNull($resolved);
        self::assertStringContainsString(
            'custom' . DIRECTORY_SEPARATOR . 'video.php',
            str_replace('/', DIRECTORY_SEPARATOR, $resolved)
        );
    }

    public function testTemplatesNotOverriddenStillResolveToDefault(): void
    {
        $this->makeTheme('partial', ['name' => 'Partial'], [
            'video.php' => '<?php echo "only video is overridden";',
        ]);
        $this->manager->activate('partial');

        $archive = $this->manager->loader()->resolve(['archive']);

        self::assertNotNull($archive);
        self::assertStringContainsString(
            'default' . DIRECTORY_SEPARATOR . 'archive.php',
            str_replace('/', DIRECTORY_SEPARATOR, $archive)
        );
    }

    public function testChildThemeResolvesThroughParentThenDefault(): void
    {
        $this->makeTheme('parenttheme', ['name' => 'Parent'], [
            'archive.php' => '<?php echo "parent archive";',
        ]);
        $this->makeTheme('childtheme', ['name' => 'Child', 'parent' => 'parenttheme'], [
            'video.php' => '<?php echo "child video";',
        ]);

        $this->manager->activate('childtheme');
        $loader = $this->manager->loader();

        self::assertStringContainsString('childtheme', (string) $loader->resolve(['video']));
        self::assertStringContainsString('parenttheme', (string) $loader->resolve(['archive']));
        self::assertStringContainsString('default', (string) $loader->resolve(['index']));
    }

    public function testHierarchyPrefersTheMostSpecificCandidate(): void
    {
        $this->makeTheme('specific', ['name' => 'Specific'], [
            'video-sermons.php' => '<?php echo "sermons only";',
            'video.php'         => '<?php echo "any video";',
        ]);
        $this->manager->activate('specific');

        $loader = $this->manager->loader();
        $candidates = $loader->hierarchy('video', ['slug' => 'sermons']);

        self::assertSame('video-sermons', $candidates[0]);
        self::assertStringContainsString('video-sermons.php', (string) $loader->resolve($candidates));
    }

    /**
     * Template names are built from user-supplied slugs, so traversal has to be
     * impossible rather than merely unlikely.
     */
    public function testTemplateNamesCannotEscapeTheThemeDirectory(): void
    {
        $loader = $this->manager->loader();

        foreach (['../../../config', '../../config.php', '/etc/passwd', '..'] as $evil) {
            self::assertNull($loader->resolve([$evil]), "Must refuse: {$evil}");
        }
    }

    public function testRenderingProducesOutput(): void
    {
        $this->makeTheme('rendering', ['name' => 'Rendering'], [
            'index.php' => '<?php echo "Hello " . e($name);',
        ]);
        $this->manager->activate('rendering');

        $html = $this->manager->loader()->render(['index'], ['name' => 'world']);

        self::assertSame('Hello world', $html);
    }

    public function testTemplateOutputIsEscaped(): void
    {
        $this->makeTheme('escaping', ['name' => 'Escaping'], [
            'index.php' => '<?php echo e($name);',
        ]);
        $this->manager->activate('escaping');

        $html = $this->manager->loader()->render(['index'], ['name' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // --------------------------------------------------------- customizer

    public function testSettingsFallBackToManifestDefaults(): void
    {
        self::assertSame('#38bdf8', $this->manager->setting('accent'));
    }

    public function testSavedSettingsOverrideDefaults(): void
    {
        $this->manager->saveSettings('default', ['accent' => '#ff0000']);
        $this->freshManager();

        self::assertSame('#ff0000', $this->manager->setting('accent'));
    }

    /**
     * Customizer values are interpolated into a <style> block, so a "colour"
     * that is not a colour is a CSS injection vector.
     */
    public function testInvalidColoursFallBackToTheDefaultRatherThanBeingStored(): void
    {
        $attacks = [
            'red; } body { display:none } .x {',
            '</style><script>alert(1)</script>',
            'javascript:alert(1)',
            'not-a-colour',
        ];

        foreach ($attacks as $attack) {
            $this->manager->saveSettings('default', ['accent' => $attack]);
            $this->freshManager();

            self::assertSame(
                '#38bdf8',
                $this->manager->setting('accent'),
                "Should have rejected: {$attack}"
            );
        }
    }

    public function testValidColourFormatsAreAccepted(): void
    {
        foreach (['#fff', '#FFFFFF', '#12345678'] as $valid) {
            $this->manager->saveSettings('default', ['accent' => $valid]);
            $this->freshManager();

            self::assertSame(strtolower($valid), $this->manager->setting('accent'));
        }
    }

    public function testSelectValuesOutsideTheDeclaredChoicesAreRejected(): void
    {
        $this->manager->saveSettings('default', ['card-radius' => '999px']);
        $this->freshManager();

        self::assertSame('12px', $this->manager->setting('card-radius'));
    }

    public function testUnknownKeysAreNotStored(): void
    {
        $this->manager->saveSettings('default', ['not_a_real_setting' => 'value']);

        self::assertNull($this->db()->value(
            'SELECT `value` FROM {theme_settings} WHERE theme_slug = ? AND `key` = ?',
            ['default', 'not_a_real_setting']
        ));
    }

    /** Switching away and back must not lose someone's customisation. */
    public function testSettingsArePerThemeAndSurviveSwitching(): void
    {
        $this->makeTheme('other', ['name' => 'Other']);

        $this->manager->saveSettings('default', ['accent' => '#ff0000']);
        $this->manager->activate('other');
        $this->manager->activate('default');
        $this->freshManager();

        self::assertSame('#ff0000', $this->manager->setting('accent'));
    }

    public function testCssVariablesRenderFromSettings(): void
    {
        $this->manager->saveSettings('default', ['accent' => '#abcdef']);
        $this->freshManager();

        $css = $this->manager->cssVariables();

        self::assertStringContainsString(':root{', $css);
        self::assertStringContainsString('--accent: #abcdef;', $css);
    }

    public function testSlugSanitisationStripsTraversal(): void
    {
        self::assertSame('etcpasswd', ThemeManifest::sanitizeSlug('../../etc/passwd'));
        self::assertSame('mytheme', ThemeManifest::sanitizeSlug('My Theme'));
    }

    // -------------------------------------------------------------- fixtures

    /**
     * @param array<string, mixed>  $manifest
     * @param array<string, string> $files
     */
    private function makeTheme(string $slug, array $manifest, array $files = []): void
    {
        $this->fixtures[] = $slug;

        $directory = PORTAL_THEMES . '/' . $slug;
        @mkdir($directory, 0775, true);

        file_put_contents(
            $directory . '/theme.json',
            json_encode($manifest + ['version' => '1.0.0'], JSON_PRETTY_PRINT)
        );

        foreach ($files as $name => $contents) {
            $path = $directory . '/' . $name;
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $contents);
        }

        $this->freshManager();
    }

    private function freshManager(): void
    {
        $this->manager = new ThemeManager(
            $this->db(),
            new Config('/nonexistent-config.php'),
            $this->hooks,
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->deleteDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
