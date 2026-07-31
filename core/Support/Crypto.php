<?php

declare(strict_types=1);

namespace Portal\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * Symmetric encryption for provider credentials at rest, plus the HMAC
 * primitives used by the share gate and signed cookies.
 *
 * Why encrypt credentials at all, given the key sits in config.php next door?
 * Because the realistic leak on shared hosting is a database dump — a stray
 * phpMyAdmin export, a backup left in the docroot, a SQL injection in some
 * other app sharing the same MySQL user. Those hand over the tables without
 * handing over the filesystem. Encrypting at rest means a dumped `providers`
 * table is inert.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    /** Binary 32-byte key derived from the base64 app key. */
    private readonly string $key;

    public function __construct(#[SensitiveParameter] string $appKey)
    {
        $appKey = trim($appKey);
        if ($appKey === '') {
            throw new RuntimeException('app_key is missing from config.php. Re-run the installer.');
        }

        $raw = base64_decode($appKey, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException(
                'app_key in config.php is not a valid 32-byte base64 key. '
                . 'Generate a new one and re-encrypt provider credentials from the admin screen.'
            );
        }
        $this->key = $raw;
    }

    /** Generate a fresh application key, base64-encoded for config.php. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** URL-safe random token — share ids, gate nonces, cron secrets. */
    public static function token(int $bytes = 16): string
    {
        return self::base64url(random_bytes($bytes));
    }

    /**
     * Encrypt to a self-describing string: v1.<iv>.<tag>.<ciphertext>.
     * The version prefix is what lets us change cipher later without a
     * migration that has to guess at the old format.
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $iv = random_bytes(12); // 96-bit nonce, the GCM standard
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed. Is the OpenSSL extension working?');
        }

        return 'v1.' . self::base64url($iv) . '.' . self::base64url($tag) . '.' . self::base64url($ciphertext);
    }

    /** Returns null rather than throwing — a tampered or key-rotated value is a data problem, not a crash. */
    public function decrypt(string $payload): ?string
    {
        $parts = explode('.', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            return null;
        }

        $iv = self::base64urlDecode($parts[1]);
        $tag = self::base64urlDecode($parts[2]);
        $ciphertext = self::base64urlDecode($parts[3]);

        if ($iv === null || $tag === null || $ciphertext === null || strlen($iv) !== 12) {
            return null;
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        // GCM authenticates: a wrong key or a modified ciphertext returns false
        // here rather than yielding garbage.
        return $plaintext === false ? null : $plaintext;
    }

    // --------------------------------------------------------------- HMAC

    /**
     * Keyed hash used for share-gate grants and signed cookies.
     *
     * $secret is passed explicitly rather than reusing the app key so that
     * rotating GATE_SECRET invalidates every outstanding magic link without
     * also making every stored credential unreadable.
     */
    public static function hmac(string $message, #[SensitiveParameter] string $secret): string
    {
        if (trim($secret) === '') {
            // Fail loud. An empty secret makes every signature forgeable, and
            // the failure mode is silent unless we shout about it here.
            throw new RuntimeException('Refusing to sign with an empty secret.');
        }
        return self::base64url(hash_hmac('sha256', $message, $secret, true));
    }

    /** Constant-time comparison. Always use this on anything attacker-supplied. */
    public static function verify(string $expected, string $given): bool
    {
        return hash_equals($expected, $given);
    }

    // ------------------------------------------------------------- encoding

    public static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $encoded): ?string
    {
        $padded = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }
}
