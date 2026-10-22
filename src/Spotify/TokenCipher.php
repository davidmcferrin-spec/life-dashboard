<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

/**
 * AES-256-GCM tokens at rest. The blob is base64(iv || tag || ciphertext),
 * the same layout CredentialCrypto uses, with a Spotify-specific key.
 * Python services seal the same blob.
 */
final class TokenCipher
{
    public const CIPHER = 'aes-256-gcm';
    public const IV_LEN = 12;
    public const TAG_LEN = 16;

    public function encrypt(string $plaintext, ?string $iv = null): string
    {
        return self::seal($plaintext, self::key(), $iv);
    }

    public function decrypt(string $blob): string
    {
        return self::open($blob, self::key());
    }

    public static function seal(string $plaintext, string $key, ?string $iv = null): string
    {
        if (strlen($key) !== 32) {
            throw new \RuntimeException('Spotify token key must be 32 bytes.');
        }
        $iv = $iv ?? random_bytes(self::IV_LEN);
        if (strlen($iv) !== self::IV_LEN) {
            throw new \RuntimeException('Spotify token IV must be 12 bytes.');
        }
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || strlen($tag) !== self::TAG_LEN) {
            throw new \RuntimeException('Could not encrypt the Spotify token.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function open(string $blob, string $key): string
    {
        if (strlen($key) !== 32) {
            throw new \RuntimeException('Spotify token key must be 32 bytes.');
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN + 1) {
            throw new \RuntimeException('Could not read the stored Spotify token.');
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);
        $plain = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Could not read the stored Spotify token.');
        }
        return $plain;
    }

    public static function key(): string
    {
        return hash('sha256', TokenKey::secret(), true);
    }
}
