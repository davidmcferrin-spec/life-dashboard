<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\DB\Database;

final class StorageUsageService
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $conn
     * @return array<string, mixed>|null
     */
    public function forConnection(array $conn): ?array
    {
        if (!(bool) ($conn['is_active'] ?? 0)) {
            return null;
        }

        try {
            $provider = (string) ($conn['provider'] ?? '');
            $limit    = (new StorageService($this->db))->maxCloudBytes();

            if ($provider === ProviderFactory::PROVIDER_DROPBOX) {
                $impl = ProviderFactory::fromConnection($conn, $this->db);
                if ($impl instanceof DropboxProvider) {
                    $usage = $impl->getAccountSpaceUsage();

                    return [
                        'used_bytes'  => $usage['used_bytes'],
                        'limit_bytes' => $usage['limit_bytes'],
                        'label'       => 'Dropbox account',
                        'available'   => true,
                    ];
                }
            }

            if ($provider === ProviderFactory::PROVIDER_S3) {
                $impl = ProviderFactory::fromConnection($conn, $this->db);
                if ($impl instanceof S3Provider) {
                    $usage = $impl->getPrefixUsage(
                        StorageConnectionService::rootFolderName(),
                        $limit,
                    );

                    return $usage ?? ['available' => false];
                }
            }
        } catch (\Throwable) {
            return ['available' => false];
        }

        return null;
    }
}
