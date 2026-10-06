<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\Config;

/**
 * Encrypt/decrypt provider credential JSON at rest using APP_SECRET.
 */
final class CredentialCrypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;

    /**
     * @param array<string, mixed> $credentials
     */
    public function encrypt(array $credentials): string
    {
        $plaintext = json_encode($credentials, JSON_THROW_ON_ERROR);
        $iv        = random_bytes(self::IV_LEN);
        $tag       = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt storage credentials.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * @return array<string, mixed>
     */
    public function decrypt(string $encrypted): array
    {
        $raw = base64_decode($encrypted, true);

        if ($raw === false || strlen($raw) < self::IV_LEN + 16 + 1) {
            throw new \RuntimeException('Invalid encrypted storage credentials.');
        }

        $iv         = substr($raw, 0, self::IV_LEN);
        $tag        = substr($raw, self::IV_LEN, 16);
        $ciphertext = substr($raw, self::IV_LEN + 16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Could not decrypt storage credentials.');
        }

        $decoded = json_decode($plaintext, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Decrypted storage credentials are not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public static function encryptJson(array $credentials): string
    {
        return (new self())->encrypt($credentials);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decryptJson(string $encrypted): array
    {
        return (new self())->decrypt($encrypted);
    }

    private function key(): string
    {
        return hash('sha256', Config::get('APP_SECRET'), true);
    }
}
