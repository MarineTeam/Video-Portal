<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Portal\Auth\Auth0Provider;
use Portal\Auth\OidcProvider;
use Portal\Config;
use Portal\Mail\PhpMailProvider;
use Portal\Mail\ResendProvider;
use Portal\Mail\SmtpProvider;
use Portal\Providers\TestResult;
use Portal\Video\BunnyStreamProvider;
use Throwable;

/**
 * Provider self-tests must never throw.
 *
 * They run on the installer's Services step, where config.php does not exist
 * yet — so anything reading it, such as an OIDC provider building its callback
 * URL, hits a Config that legitimately refuses to guess. A throw there is
 * reported to the person installing as "The test threw an unexpected error",
 * which tells them nothing they can act on and stops the install dead.
 *
 * This happened for real with Auth0 on a live install, hence the test.
 *
 * These deliberately construct providers against an absent config and empty
 * credentials, which is the worst case the installer can present.
 */
final class ProviderTestSafetyTest extends TestCase
{
    private Config $emptyConfig;

    protected function setUp(): void
    {
        // A path that cannot exist, so isInstalled() is false and baseUrl()
        // throws — exactly the state during the Services step.
        $this->emptyConfig = new Config('/nonexistent/config-' . bin2hex(random_bytes(4)) . '.php');
    }

    public function testConfigStillRefusesToInventABaseUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->emptyConfig->baseUrl();
    }

    /**
     * The fix that makes the installer work: pending values can be supplied in
     * memory before anything is written to disk.
     */
    public function testOverlaySuppliesValuesWithoutAConfigFile(): void
    {
        $this->emptyConfig->overlay(['base_url' => 'https://example.test']);

        self::assertSame('https://example.test', $this->emptyConfig->baseUrl());
        self::assertSame('https://example.test/auth/callback', $this->emptyConfig->url('/auth/callback'));
    }

    /**
     * A real config.php is authoritative. Nothing should be able to shadow a
     * value that was actually written to disk.
     */
    public function testOverlayDoesNotOverrideAValueFromDisk(): void
    {
        $path = sys_get_temp_dir() . '/portal-overlay-' . bin2hex(random_bytes(4)) . '.php';

        try {
            $config = new Config($path);
            $config->write(['base_url' => 'https://real.example', 'app_key' => 'x']);

            $reloaded = new Config($path);
            $reloaded->overlay(['base_url' => 'https://impostor.example']);

            self::assertSame('https://real.example', $reloaded->baseUrl());
        } finally {
            @unlink($path);
        }
    }

    /**
     * Auth providers, with no config and no credentials.
     *
     */
    #[DataProvider('authProviders')]
    public function testAuthProviderTestsDoNotThrow(string $class): void
    {
        $session = new \Portal\Auth\Session(
            new \Portal\Db('mysql:host=127.0.0.1;dbname=__none__', '', '', '')
        );

        /** @var OidcProvider $provider */
        $provider = new $class([], $this->emptyConfig, $session);

        $this->assertSafe(fn (): TestResult => $provider->test(), $class);
    }

    /** @return list<array{class-string}> */
    public static function authProviders(): array
    {
        return [
            [Auth0Provider::class],
            [OidcProvider::class],
        ];
    }

    /**
     * With credentials that will not resolve, the test should still return a
     * result rather than blowing up on the network failure.
     */
    public function testAuth0WithUnreachableDomainReturnsAFailure(): void
    {
        $session = new \Portal\Auth\Session(
            new \Portal\Db('mysql:host=127.0.0.1;dbname=__none__', '', '', '')
        );

        $provider = new Auth0Provider([
            'domain'        => 'this-tenant-does-not-exist.invalid',
            'client_id'     => 'abc',
            'client_secret' => 'def',
        ], $this->emptyConfig, $session);

        $result = $this->assertSafe(fn (): TestResult => $provider->test(), Auth0Provider::class);

        self::assertFalse($result->ok);
        self::assertNotSame('', $result->message);
    }

    #[DataProvider('mailProviders')]
    public function testMailProviderTestsDoNotThrow(string $class): void
    {
        $provider = new $class([]);
        $this->assertSafe(fn (): TestResult => $provider->test(), $class);
    }

    /** @return list<array{class-string}> */
    public static function mailProviders(): array
    {
        return [
            [ResendProvider::class],
            [SmtpProvider::class],
            [PhpMailProvider::class],
        ];
    }

    public function testVideoProviderTestDoesNotThrow(): void
    {
        $provider = new BunnyStreamProvider([]);
        $this->assertSafe(fn (): TestResult => $provider->test(), BunnyStreamProvider::class);
    }

    /**
     * Every provider must describe itself without any credentials, because the
     * installer renders the form before anything has been entered.
     *
     */
    #[DataProvider('allProviders')]
    public function testProvidersDescribeThemselvesWithoutCredentials(string $class): void
    {
        self::assertNotSame('', $class::slug());
        self::assertNotSame('', $class::label());
        self::assertNotSame('', $class::description());

        foreach ($class::fields() as $field) {
            self::assertNotSame('', $field->key);
            self::assertNotSame('', $field->label);
        }
    }

    /** @return list<array{class-string}> */
    public static function allProviders(): array
    {
        return [
            [Auth0Provider::class],
            [OidcProvider::class],
            [\Portal\Auth\LocalProvider::class],
            [ResendProvider::class],
            [SmtpProvider::class],
            [PhpMailProvider::class],
            [BunnyStreamProvider::class],
        ];
    }

    /** @param callable(): TestResult $run */
    private function assertSafe(callable $run, string $class): TestResult
    {
        try {
            $result = $run();
        } catch (Throwable $e) {
            self::fail(sprintf(
                "%s::test() threw %s: %s\nA provider test must return a TestResult, never throw — "
                . 'the installer reports a throw as "unexpected error", which is useless to the person installing.',
                $class,
                $e::class,
                $e->getMessage()
            ));
        }

        self::assertInstanceOf(TestResult::class, $result);
        self::assertNotSame('', $result->message, "{$class} returned an empty message.");

        return $result;
    }
}
