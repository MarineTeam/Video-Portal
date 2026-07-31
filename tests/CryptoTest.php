<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\Crypto;
use RuntimeException;

/**
 * Provider credentials are encrypted at rest specifically so that a leaked
 * database dump is inert. These tests pin the properties that claim depends on:
 * authenticated encryption, a fresh nonce per message, and a decrypt that
 * refuses rather than returning plausible garbage.
 */
final class CryptoTest extends TestCase
{
    private Crypto $crypto;

    protected function setUp(): void
    {
        $this->crypto = new Crypto(Crypto::generateKey());
    }

    public function testRoundTrip(): void
    {
        $secret = 'bunny-api-key-9f8e7d6c';

        self::assertSame($secret, $this->crypto->decrypt($this->crypto->encrypt($secret)));
    }

    public function testRoundTripSurvivesUnicodeAndBinary(): void
    {
        foreach (['pässwörd — with em dash', "\x00\x01\x02binary\xff", '', str_repeat('x', 10000)] as $value) {
            self::assertSame($value, $this->crypto->decrypt($this->crypto->encrypt($value)));
        }
    }

    /**
     * Encrypting the same value twice must not produce the same ciphertext.
     * If it did, anyone with the table could tell which two providers share a
     * key, and could spot when a credential was rotated back to a former value.
     */
    public function testCiphertextDiffersEachTime(): void
    {
        $plaintext = 'same-value-every-time';

        $first  = $this->crypto->encrypt($plaintext);
        $second = $this->crypto->encrypt($plaintext);

        self::assertNotSame($first, $second);
        self::assertSame($plaintext, $this->crypto->decrypt($first));
        self::assertSame($plaintext, $this->crypto->decrypt($second));
    }

    /**
     * GCM authenticates. A modified ciphertext must fail closed rather than
     * decrypting to something the application might then act on.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        $payload = $this->crypto->encrypt('api-key');
        $parts = explode('.', $payload);

        // Flip a byte in the ciphertext segment.
        $raw = Crypto::base64urlDecode($parts[3]);
        self::assertIsString($raw);
        $raw[0] = chr(ord($raw[0]) ^ 0xFF);
        $parts[3] = Crypto::base64url($raw);

        self::assertNull($this->crypto->decrypt(implode('.', $parts)));
    }

    public function testWrongKeyReturnsNullRatherThanGarbage(): void
    {
        $payload = $this->crypto->encrypt('api-key');
        $other = new Crypto(Crypto::generateKey());

        self::assertNull($other->decrypt($payload));
    }

    public function testMalformedPayloadsAreRejected(): void
    {
        foreach (['', 'not-encrypted', 'v1.only.three', 'v2.a.b.c', 'v1....'] as $bad) {
            self::assertNull($this->crypto->decrypt($bad), "Should reject: {$bad}");
        }
    }

    public function testKeyMustBeValid(): void
    {
        foreach (['', 'too-short', base64_encode('only-16-bytes!!!')] as $bad) {
            try {
                new Crypto($bad);
                self::fail("Should have rejected key: {$bad}");
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    // --------------------------------------------------------------- HMAC

    public function testHmacIsDeterministicAndKeyDependent(): void
    {
        $a = Crypto::hmac('share-123|user@example.com', 'secret-one');
        $b = Crypto::hmac('share-123|user@example.com', 'secret-one');
        $c = Crypto::hmac('share-123|user@example.com', 'secret-two');

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }

    /**
     * An empty gate secret would make every magic link forgeable, and the
     * failure would be completely silent. Refusing to sign is the only safe
     * behaviour.
     */
    public function testHmacRefusesAnEmptySecret(): void
    {
        foreach (['', '   ', "\n"] as $empty) {
            try {
                Crypto::hmac('message', $empty);
                self::fail('Signing with an empty secret must throw.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testVerifyRejectsMismatches(): void
    {
        $signature = Crypto::hmac('message', 'secret');

        self::assertTrue(Crypto::verify($signature, $signature));
        self::assertFalse(Crypto::verify($signature, $signature . 'x'));
        self::assertFalse(Crypto::verify($signature, ''));
        self::assertFalse(Crypto::verify($signature, strrev($signature)));
    }

    // ----------------------------------------------------------- encoding

    public function testBase64UrlIsUrlSafeAndReversible(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $binary = random_bytes(random_int(1, 64));
            $encoded = Crypto::base64url($binary);

            self::assertStringNotContainsString('+', $encoded);
            self::assertStringNotContainsString('/', $encoded);
            self::assertStringNotContainsString('=', $encoded);
            self::assertSame($binary, Crypto::base64urlDecode($encoded));
        }
    }

    /**
     * Share ids appear in URLs and are validated by a regex before any lookup.
     * If token() ever emitted a character outside that set, every new share
     * would 404.
     */
    public function testTokenMatchesTheShareIdFormat(): void
    {
        for ($i = 0; $i < 100; $i++) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,64}$/', Crypto::token(16));
        }
    }

    public function testTokensAreUnique(): void
    {
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $seen[Crypto::token(16)] = true;
        }

        self::assertCount(1000, $seen);
    }
}
