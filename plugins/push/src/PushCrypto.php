<?php

declare(strict_types=1);

namespace Portal\Plugins\Push;

use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Web push, encrypted the way the standard requires.
 *
 * RFC 8291 for the payload and RFC 8292 for VAPID, hand-rolled on core PHP —
 * no vendored library.
 *
 * That is the same call this project made when it dropped Guzzle and the Auth0
 * SDK, and for the same reason: a vendored package can only be security-patched
 * by cutting a whole new release of this app, so each one has to earn its
 * place. minishlink/web-push is a good library and it would bring a dependency
 * tree for work PHP already does — openssl generates the P-256 keys and does
 * the ECDH and the AES-128-GCM, hash_hkdf has been built in since 7.1, and the
 * VAPID token is an ES256 JWT, which firebase/php-jwt is already vendored for.
 *
 * The cost is real and worth naming: this is cryptography, and cryptography
 * that is subtly wrong fails by silently not delivering rather than by
 * throwing. That is why the encryption is tested against the worked example in
 * RFC 8291 itself rather than against its own output — a round-trip test would
 * pass just as happily on a scheme nobody else can read.
 */
final class PushCrypto
{
    /**
     * The DER prefix for an uncompressed P-256 public key.
     *
     * A browser hands over its key as 65 raw bytes and openssl wants a
     * SubjectPublicKeyInfo. For one fixed curve the wrapper is a constant, so
     * this is the whole conversion — the alternative is an ASN.1 encoder for a
     * structure that never varies.
     */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** RFC 8291 fixes both of these. */
    private const SALT_BYTES = 16;
    private const KEY_BYTES = 16;
    private const NONCE_BYTES = 12;

    /**
     * A VAPID key pair, base64url-encoded.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generateVapidKeys(): array
    {
        $key = @openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            /*
             * Almost always a host whose openssl.cnf is missing rather than a
             * PHP without EC support, and the error openssl gives for that says
             * "no such file" about a path nobody mentioned. Named here because
             * the alternative is an admin staring at "could not generate keys".
             */
            throw new RuntimeException(
                'This server could not generate an elliptic-curve key. That usually means '
                . 'openssl has no configuration file rather than that the extension is missing — '
                . 'ask your host about OPENSSL_CONF.'
            );
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new RuntimeException('The generated key was not an elliptic-curve key.');
        }

        return [
            'publicKey'  => self::base64url(self::rawPublicKey($details)),
            // Left-padded to 32 bytes. openssl trims leading zeroes, and a
            // 31-byte private key is rejected by every push service with a
            // message that says nothing about length.
            'privateKey' => self::base64url(str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * The Authorization header a push service wants.
     *
     * A JWT signed with the VAPID private key, saying who is sending and where
     * to complain. `aud` is the push service's ORIGIN, not the endpoint — a
     * token audienced to the full endpoint is rejected, and the message says
     * only "invalid JWT provided".
     */
    public static function vapidHeader(string $endpoint, string $subject, string $privateKey, string $publicKey): string
    {
        $origin = self::origin($endpoint);

        $token = JWT::encode(
            [
                'aud' => $origin,
                // Twelve hours. The spec allows 24 and some services reject
                // anything close to it once clock skew is counted.
                'exp' => time() + 43200,
                'sub' => $subject,
            ],
            self::privateKeyPem($privateKey, $publicKey),
            'ES256'
        );

        return 'vapid t=' . $token . ', k=' . $publicKey;
    }

    /**
     * Encrypt a payload for one subscription, in aes128gcm form.
     *
     * The body that comes back is the whole thing a push service expects: a
     * header carrying the salt and our one-time public key, then the ciphertext.
     *
     * @param string $p256dh the subscriber's public key, base64url
     * @param string $auth   the subscriber's auth secret, base64url
     * @param string $salt   supplied only by the tests, which need the RFC's
     */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?string $salt = null, ?array $localKeys = null): string
    {
        $clientPublic = self::base64urlDecode($p256dh);
        $authSecret = self::base64urlDecode($auth);

        if ($clientPublic === null || strlen($clientPublic) !== 65 || $clientPublic[0] !== "\x04") {
            throw new RuntimeException('That subscription key is not an uncompressed P-256 point.');
        }

        if ($authSecret === null || strlen($authSecret) !== 16) {
            throw new RuntimeException('That subscription auth secret is not 16 bytes.');
        }

        $salt ??= random_bytes(self::SALT_BYTES);

        // A fresh key pair per message. Reusing one would let anybody who saw
        // two messages to the same subscriber link them, and the standard
        // requires it besides.
        [$localPrivate, $localPublicRaw] = $localKeys ?? self::ephemeralKeys();

        $shared = @openssl_pkey_derive(self::publicKeyFromRaw($clientPublic), $localPrivate, 32);

        if ($shared === false || $shared === '') {
            throw new RuntimeException('The shared secret could not be derived.');
        }

        /*
         * The order of the two public keys in this info string is the single
         * easiest thing to get wrong, and getting it wrong produces a message
         * the browser silently discards. RFC 8291 section 3.3: the RECIPIENT's
         * key comes first, then the sender's.
         */
        $prk = hash_hkdf(
            'sha256',
            $shared,
            32,
            "WebPush: info\x00" . $clientPublic . $localPublicRaw,
            $authSecret
        );

        $contentEncryptionKey = hash_hkdf('sha256', $prk, self::KEY_BYTES, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $prk, self::NONCE_BYTES, "Content-Encoding: nonce\x00", $salt);

        /*
         * The padding delimiter. aes128gcm ends the plaintext with 0x02 for the
         * last record — 0x01 means "another record follows", and a service that
         * reads one will wait for a record that never comes.
         */
        $plaintext = $payload . "\x02";

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-128-gcm',
            $contentEncryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('The payload could not be encrypted.');
        }

        /*
         * The aes128gcm header, from RFC 8188: salt, record size, then the
         * sender's key with its length. The record size is the whole body, so
         * one record is always enough for a notification.
         */
        return $salt
            . pack('N', 4096)
            . chr(strlen($localPublicRaw))
            . $localPublicRaw
            . $ciphertext . $tag;
    }

    // --------------------------------------------------------------- internals

    /**
     * A fresh P-256 pair for one message.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string} the key, and its raw public point
     */
    private static function ephemeralKeys(): array
    {
        $key = @openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('This server cannot generate an elliptic-curve key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('The generated key could not be read.');
        }

        return [$key, self::rawPublicKey($details)];
    }

    /**
     * A key pair built from a known private scalar.
     *
     * Only the tests use it, and only so the RFC's worked example can be
     * reproduced exactly — every value in that example is fixed, including the
     * sender's key, and a test that generated its own would be checking that
     * this code agrees with itself.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    public static function keysFromPrivateScalar(string $privateKeyBase64Url, string $publicKeyBase64Url): array
    {
        $pem = self::privateKeyPem($privateKeyBase64Url, $publicKeyBase64Url);
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new RuntimeException('That private key could not be read.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('That private key could not be inspected.');
        }

        return [$key, self::rawPublicKey($details)];
    }

    /** @param array<string, mixed> $details */
    private static function rawPublicKey(array $details): string
    {
        /** @var array{x: string, y: string} $ec */
        $ec = $details['ec'];

        // Both coordinates left-padded to 32 bytes: openssl trims leading
        // zeroes, and a 64-byte point that is one byte short is not a point.
        return "\x04"
            . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** @return \OpenSSLAsymmetricKey */
    private static function publicKeyFromRaw(string $raw)
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $raw;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new RuntimeException('That public key could not be read.');
        }

        return $key;
    }

    /**
     * A key pair as a PEM openssl will accept.
     *
     * Built by hand for the same reason as the public wrapper: for one fixed
     * curve the DER is a constant with two values dropped into it, and an ASN.1
     * writer for a structure that never varies is more to go wrong, not less.
     *
     * BOTH halves are required, and that is the interesting part. A SEC1
     * ECPrivateKey may in principle omit the public point, and openssl will
     * then refuse the key with "unable to validate key" rather than computing
     * it — recovering a public point from a scalar is elliptic-curve
     * multiplication, which is exactly the arithmetic this class exists to
     * avoid writing. Since the public key is stored beside the private one
     * anyway, it is simply passed in.
     */
    private static function privateKeyPem(string $privateKeyBase64Url, string $publicKeyBase64Url): string
    {
        $scalar = self::base64urlDecode($privateKeyBase64Url);
        $point = self::base64urlDecode($publicKeyBase64Url);

        if ($scalar === null || $scalar === '') {
            throw new RuntimeException('That VAPID private key is not readable.');
        }

        if ($point === null || strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('That VAPID public key is not an uncompressed P-256 point.');
        }

        $scalar = str_pad($scalar, 32, "\0", STR_PAD_LEFT);

        /*
         * SEC1 ECPrivateKey. The outer length, 0x77, counts all four parts:
         *   02 01 01                     version                     3 bytes
         *   04 20 <32-byte scalar>       the private key            34 bytes
         *   a0 0a <prime256v1 OID>       the curve                  12 bytes
         *   a1 44 03 42 00 <65-byte pt>  the public key             70 bytes
         */
        $der = hex2bin('30770201010420')
             . $scalar
             . hex2bin('a00a06082a8648ce3d030107')
             . hex2bin('a1440342' . '00')
             . $point;

        return "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
    }

    private static function origin(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if (!isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('That push endpoint is not a URL.');
        }

        return $parts['scheme'] . '://' . $parts['host']
             . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
