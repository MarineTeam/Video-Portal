<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Push\PushCrypto;

require_once dirname(__DIR__) . '/plugins/push/src/PushCrypto.php';

/**
 * Web push encryption, against the standard rather than against itself.
 *
 * This is the whole reason the test exists in this shape. A round-trip test —
 * encrypt, decrypt, compare — would pass on a scheme nobody else in the world
 * can read, and the failure would show up as notifications that are accepted by
 * the push service and silently discarded by the browser. So the load-bearing
 * test reproduces the worked example in RFC 8291 section 5 byte for byte: same
 * plaintext, same keys, same salt, same expected ciphertext.
 *
 * Every value below is from that document.
 */
final class PushCryptoTest extends TestCase
{
    /** The RFC's example, section 5. */
    private const PLAINTEXT = 'When I grow up, I want to be a watermelon';

    private const CLIENT_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcx'
        . 'aOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const CLIENT_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';

    /** The sender's key, which the RFC also fixes so the output is reproducible. */
    private const SERVER_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';

    private const SERVER_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIg'
        . 'Dll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';

    /**
     * The RFC's final encrypted message body.
     *
     * Copied from https://www.rfc-editor.org/rfc/rfc8291.txt section 5, not
     * from anything this code produced. That distinction is the entire value of
     * this test — and it nearly went wrong: the first version of this constant
     * was written from memory, disagreed with the implementation, and the
     * IMPLEMENTATION turned out to be right. Checking the source rather than
     * "fixing" the code is what stopped correct crypto being broken to match a
     * fabricated vector.
     */
    private const EXPECTED = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27ml'
        . 'mlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPT'
        . 'pK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    /**
     * The one that matters.
     *
     * If this passes, a browser can read what this produces. If it fails,
     * nothing else in this file is worth anything.
     */
    public function testTheRfcWorkedExampleIsReproducedExactly(): void
    {
        $body = PushCrypto::encrypt(
            self::PLAINTEXT,
            self::CLIENT_PUBLIC,
            self::CLIENT_AUTH,
            salt: PushCrypto::base64urlDecode(self::SALT),
            localKeys: PushCrypto::keysFromPrivateScalar(self::SERVER_PRIVATE, self::SERVER_PUBLIC)
        );

        self::assertSame(
            self::EXPECTED,
            PushCrypto::base64url($body),
            'the encryption no longer matches RFC 8291, so browsers will discard every notification'
        );
    }

    // ------------------------------------------------------- the header shape

    public function testTheBodyCarriesTheSaltAndTheSenderKey(): void
    {
        $body = PushCrypto::encrypt('hello', self::CLIENT_PUBLIC, self::CLIENT_AUTH);

        // RFC 8188: 16-byte salt, 4-byte record size, 1-byte key length, key.
        self::assertSame(16, strlen(substr($body, 0, 16)));
        self::assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        self::assertSame(65, ord($body[20]), 'the sender key length byte');
        self::assertSame("\x04", $body[21], 'an uncompressed point');
    }

    /**
     * Reusing one key pair across messages would let anybody who saw two
     * notifications to the same subscriber link them — and the standard
     * requires a fresh one besides.
     */
    public function testEveryMessageUsesAFreshSenderKey(): void
    {
        $first = PushCrypto::encrypt('hello', self::CLIENT_PUBLIC, self::CLIENT_AUTH);
        $second = PushCrypto::encrypt('hello', self::CLIENT_PUBLIC, self::CLIENT_AUTH);

        self::assertNotSame(
            substr($first, 21, 65),
            substr($second, 21, 65),
            'the same one-time key was used twice'
        );
        self::assertNotSame(substr($first, 0, 16), substr($second, 0, 16), 'the salt repeated');
    }

    public function testTheSamePlaintextEncryptsDifferentlyEachTime(): void
    {
        self::assertNotSame(
            PushCrypto::encrypt('hello', self::CLIENT_PUBLIC, self::CLIENT_AUTH),
            PushCrypto::encrypt('hello', self::CLIENT_PUBLIC, self::CLIENT_AUTH)
        );
    }

    // ------------------------------------------------------------- refusals

    public function testASubscriptionKeyOfTheWrongShapeIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/P-256/');

        PushCrypto::encrypt('hello', PushCrypto::base64url(random_bytes(32)), self::CLIENT_AUTH);
    }

    public function testACompressedPointIsRefused(): void
    {
        // 65 bytes but starting 0x02 rather than 0x04.
        $compressed = PushCrypto::base64url("\x02" . random_bytes(64));

        $this->expectExceptionMessageMatches('/P-256/');

        PushCrypto::encrypt('hello', $compressed, self::CLIENT_AUTH);
    }

    public function testAnAuthSecretOfTheWrongLengthIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/16 bytes/');

        PushCrypto::encrypt('hello', self::CLIENT_PUBLIC, PushCrypto::base64url(random_bytes(8)));
    }

    // ------------------------------------------------------------------ VAPID

    public function testAKeyPairIsGeneratedInTheRightShape(): void
    {
        $keys = $this->generateOrSkip();

        $public = PushCrypto::base64urlDecode($keys['publicKey']);
        $private = PushCrypto::base64urlDecode($keys['privateKey']);

        self::assertNotNull($public);
        self::assertNotNull($private);
        self::assertSame(65, strlen($public), 'a VAPID public key is an uncompressed P-256 point');
        self::assertSame("\x04", $public[0]);
        self::assertSame(
            32,
            strlen($private),
            'openssl trims leading zeroes; a 31-byte key is rejected with a message about nothing'
        );
    }

    public function testTwoKeyPairsDiffer(): void
    {
        $first = $this->generateOrSkip();
        $second = $this->generateOrSkip();

        self::assertNotSame($first['privateKey'], $second['privateKey']);
    }

    /**
     * The audience is the push service's ORIGIN, not the endpoint. A token
     * audienced to the full endpoint is rejected, and the only thing the
     * service says about it is "invalid JWT provided".
     */
    public function testTheVapidTokenIsAudiencedToTheOriginOnly(): void
    {
        $keys = $this->generateOrSkip();

        $header = PushCrypto::vapidHeader(
            'https://fcm.googleapis.com/fcm/send/abc123',
            'mailto:admin@example.com',
            $keys['privateKey'],
            $keys['publicKey']
        );

        self::assertStringStartsWith('vapid t=', $header);
        self::assertStringContainsString(', k=' . $keys['publicKey'], $header);

        $claims = $this->claims($header);

        self::assertSame('https://fcm.googleapis.com', $claims['aud']);
        self::assertSame('mailto:admin@example.com', $claims['sub']);
    }

    public function testTheVapidTokenExpiresWithinADay(): void
    {
        $keys = $this->generateOrSkip();

        $claims = $this->claims(PushCrypto::vapidHeader(
            'https://updates.push.services.mozilla.com/wpush/v2/abc',
            'mailto:admin@example.com',
            $keys['privateKey'],
            $keys['publicKey']
        ));

        self::assertGreaterThan(time(), $claims['exp']);
        self::assertLessThanOrEqual(
            time() + 86400,
            $claims['exp'],
            'the spec caps this at 24 hours and services reject anything close to it'
        );
    }

    public function testAPortOnTheEndpointStaysInTheAudience(): void
    {
        $keys = $this->generateOrSkip();

        $claims = $this->claims(PushCrypto::vapidHeader(
            'https://push.example.com:8443/wpush/abc',
            'mailto:admin@example.com',
            $keys['privateKey'],
            $keys['publicKey']
        ));

        self::assertSame('https://push.example.com:8443', $claims['aud']);
    }

    // --------------------------------------------------------------- base64url

    public function testBase64urlRoundTrips(): void
    {
        $binary = random_bytes(65);

        $encoded = PushCrypto::base64url($binary);

        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
        self::assertSame($binary, PushCrypto::base64urlDecode($encoded));
    }

    /** Browsers send unpadded; some libraries send padded. Both have to work. */
    public function testPaddedInputStillDecodes(): void
    {
        $binary = random_bytes(16);

        self::assertSame($binary, PushCrypto::base64urlDecode(base64_encode($binary)));
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{publicKey: string, privateKey: string} */
    private function generateOrSkip(): array
    {
        try {
            return PushCrypto::generateVapidKeys();
        } catch (\RuntimeException $e) {
            // A machine whose openssl has no config file cannot generate an EC
            // key. That is worth skipping over rather than failing on: the
            // RFC vector above still runs, because it uses a fixed key.
            self::markTestSkipped($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function claims(string $header): array
    {
        preg_match('/t=([^,]+)/', $header, $m);

        $parts = explode('.', $m[1]);
        $payload = PushCrypto::base64urlDecode($parts[1]);

        return json_decode((string) $payload, true);
    }
}
