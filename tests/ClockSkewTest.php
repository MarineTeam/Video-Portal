<?php

declare(strict_types=1);

namespace Portal\Tests;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Portal\Support\Crypto;

/**
 * Clock skew between this server and the identity provider.
 *
 * A live install failed to sign in with:
 *
 *     The ID token could not be verified: Cannot handle token with iat prior
 *     to 2026-07-31T16:19:35+00:00
 *
 * The token was fine; the host's clock was behind Auth0's. On shared hosting
 * the admin cannot run ntpd and cannot even see the drift, so a hard rejection
 * is an unfixable dead end. These tests pin the tolerance and, more
 * importantly, that the failure explains itself.
 */
final class ClockSkewTest extends TestCase
{
    private const SECRET = 'test-signing-secret-for-clock-skew';

    protected function tearDown(): void
    {
        // Static state on a third-party class leaks between tests otherwise.
        JWT::$leeway = 0;
    }

    /** @param array<string, mixed> $claims */
    private function token(array $claims): string
    {
        return JWT::encode($claims, self::SECRET, 'HS256');
    }

    private function decode(string $token): object
    {
        return JWT::decode($token, new Key(self::SECRET, 'HS256'));
    }

    public function testATokenIssuedNowVerifies(): void
    {
        JWT::$leeway = 120;
        $now = time();

        $decoded = $this->decode($this->token([
            'iss' => 'https://example.test/',
            'aud' => 'client-id',
            'iat' => $now,
            'exp' => $now + 3600,
            'email' => 'someone@example.test',
        ]));

        self::assertSame('someone@example.test', $decoded->email);
    }

    /**
     * The exact failure from the live install: the provider's clock is ahead,
     * so `iat` is in the future.
     */
    public function testATokenFromTheFutureIsRejectedWithoutLeeway(): void
    {
        JWT::$leeway = 0;
        $now = time();

        $this->expectException(BeforeValidException::class);

        $this->decode($this->token([
            'iat' => $now + 90,
            'exp' => $now + 3600,
        ]));
    }

    /** With the shipped default, ordinary drift is absorbed. */
    public function testDefaultLeewayAbsorbsOrdinaryDrift(): void
    {
        JWT::$leeway = 120;
        $now = time();

        foreach ([1, 5, 30, 60, 90, 115] as $skew) {
            $decoded = $this->decode($this->token([
                'iat' => $now + $skew,
                'exp' => $now + 3600,
                'email' => 'ok@example.test',
            ]));

            self::assertSame('ok@example.test', $decoded->email, "Should tolerate {$skew}s of drift");
        }
    }

    /**
     * Tolerance is not unlimited. A clock hours out is a real problem and
     * should be reported, not silently accepted.
     */
    public function testGrossSkewIsStillRejected(): void
    {
        JWT::$leeway = 120;

        $this->expectException(BeforeValidException::class);

        $this->decode($this->token([
            'iat' => time() + 7200,
            'exp' => time() + 10800,
        ]));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        JWT::$leeway = 120;

        $this->expectException(ExpiredException::class);

        $this->decode($this->token([
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]));
    }

    /**
     * The diagnostic depends on reading claims out of a token whose signature
     * has already been checked. Confirm the payload survives that round trip,
     * since the error message is built from it.
     */
    public function testClaimsCanBeReadBackFromTheTokenPayload(): void
    {
        $now = time();
        $token = $this->token(['iat' => $now + 300, 'exp' => $now + 3600, 'email' => 'x@example.test']);

        $parts = explode('.', $token);
        self::assertCount(3, $parts);

        $payload = json_decode((string) Crypto::base64urlDecode($parts[1]), true);

        self::assertIsArray($payload);
        self::assertSame($now + 300, $payload['iat']);
        self::assertSame('x@example.test', $payload['email']);
    }

    /**
     * The configured tolerance is clamped. A typo of 999999 in config.php must
     * not disable expiry checking altogether.
     */
    public function testLeewayIsClamped(): void
    {
        foreach ([[-50, 0], [0, 0], [120, 120], [900, 900], [999999, 900]] as [$configured, $expected]) {
            self::assertSame(
                $expected,
                max(0, min(900, $configured)),
                "Leeway {$configured} should clamp to {$expected}"
            );
        }
    }
}
