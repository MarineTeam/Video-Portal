<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\DeployStamp;

/**
 * Noticing a deploy.
 *
 * The decision this drives is "clear the opcode cache", which is invisible when
 * it works and produces a mixture of two releases when it does not. Neither
 * outcome is something a test can observe from the outside, so what is tested
 * is the judgement: given these files and this stored value, has the code
 * moved?
 */
final class DeployStampTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/portal-stamp-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/core', 0777, true);
        mkdir($this->root . '/vendor/composer', 0777, true);

        file_put_contents($this->root . '/core/App.php', '<?php // one');
        file_put_contents($this->root . '/vendor/composer/installed.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        foreach (['/core/App.php', '/vendor/composer/installed.php'] as $file) {
            @unlink($this->root . $file);
        }
        @rmdir($this->root . '/core');
        @rmdir($this->root . '/vendor/composer');
        @rmdir($this->root . '/vendor');
        @rmdir($this->root);
    }

    private function touchFile(string $relative, int $mtime): void
    {
        touch($this->root . '/' . $relative, $mtime);
    }

    public function testTheSameTreeStampsTheSame(): void
    {
        $this->touchFile('core/App.php', 1_700_000_000);
        $this->touchFile('vendor/composer/installed.php', 1_700_000_000);

        self::assertSame(DeployStamp::of($this->root), DeployStamp::of($this->root));
    }

    /**
     * The case the whole thing exists for: a release that rewrote the composer
     * metadata and nothing else this list watches. Every release build does
     * exactly that, so missing it would mean missing most deploys.
     */
    public function testTouchingOnlyTheComposerMetadataIsADeploy(): void
    {
        $this->touchFile('core/App.php', 1_700_000_000);
        $this->touchFile('vendor/composer/installed.php', 1_700_000_000);
        $before = DeployStamp::of($this->root);

        $this->touchFile('vendor/composer/installed.php', 1_700_000_900);

        self::assertNotSame($before, DeployStamp::of($this->root));
    }

    public function testTouchingOnlyCoreIsADeploy(): void
    {
        $this->touchFile('core/App.php', 1_700_000_000);
        $this->touchFile('vendor/composer/installed.php', 1_700_000_000);
        $before = DeployStamp::of($this->root);

        $this->touchFile('core/App.php', 1_700_000_900);

        self::assertNotSame($before, DeployStamp::of($this->root));
    }

    /**
     * A file appearing is a change. An upgrade that adds a vendored dependency
     * looks exactly like this, and skipping absent files would make it
     * invisible.
     */
    public function testAFileAppearingChangesTheStamp(): void
    {
        $absent = DeployStamp::of($this->root, ['vendor/composer/platform_check.php']);

        file_put_contents($this->root . '/vendor/composer/platform_check.php', '<?php');
        $present = DeployStamp::of($this->root, ['vendor/composer/platform_check.php']);

        @unlink($this->root . '/vendor/composer/platform_check.php');

        self::assertNotSame($absent, $present);
    }

    public function testAMissingTreeStillProducesAStampRatherThanFailing(): void
    {
        // Before vendor/ exists at all — a source checkout, or a half-finished
        // upload. A stamp is still an answer; an exception here would take the
        // site down on the request that noticed.
        self::assertNotSame('', DeployStamp::of($this->root . '/nowhere'));
    }

    // ------------------------------------------------------------- the verdict

    public function testADifferentStampIsAChange(): void
    {
        self::assertTrue(DeployStamp::changed('aaaa', 'bbbb'));
    }

    public function testAnIdenticalStampIsNot(): void
    {
        self::assertFalse(DeployStamp::changed('aaaa', 'aaaa'));
    }

    /**
     * The first request on an install that has never recorded one is not a
     * deploy. Treating it as one would clear the cache on the request that
     * ships this feature — harmless once, and misleading forever after, because
     * it would report a deployment that never happened.
     */
    public function testNothingRecordedYetIsNotAChange(): void
    {
        self::assertFalse(DeployStamp::changed(null, 'bbbb'));
        self::assertFalse(DeployStamp::changed('', 'bbbb'));
    }
}
