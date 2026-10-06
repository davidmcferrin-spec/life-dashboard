<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\DB\Database;

final class ProviderFactory
{
    public const PROVIDER_DROPBOX = 'dropbox';
    public const PROVIDER_S3      = 's3';

    /** @return list<string> */
    public static function supported(): array
    {
        return [self::PROVIDER_DROPBOX, self::PROVIDER_S3];
    }

    public static function isValid(string $provider): bool
    {
        return in_array($provider, self::supported(), true);
    }

    /**
     * @param array<string, mixed> $connectionRow
     */
    public static function fromConnection(array $connectionRow, Database $db): CloudStorageProvider
    {
        $provider = (string) ($connectionRow['provider'] ?? '');
        $creds    = CredentialCrypto::decryptJson((string) ($connectionRow['credentials_encrypted'] ?? ''));

        return match ($provider) {
            self::PROVIDER_DROPBOX => new DropboxProvider($creds, $db, (int) $connectionRow['id']),
            self::PROVIDER_S3      => new S3Provider($creds),
            default                => throw new \InvalidArgumentException('Unknown storage provider.'),
        };
    }
}
