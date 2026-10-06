<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

/**
 * S3-compatible object storage (AWS, R2, B2, MinIO).
 *
 * @param array<string, mixed> $credentials access_key, secret_key, bucket, region, endpoint, prefix, path_style
 */
final class S3Provider implements CloudStorageProvider
{
    private S3SigV4Client $client;
    private string $bucket;
    private string $userPrefix;

    /** @param array<string, mixed> $credentials */
    public function __construct(array $credentials)
    {
        $endpoint = (string) ($credentials['endpoint'] ?? 'https://s3.amazonaws.com');
        $region   = (string) ($credentials['region'] ?? 'us-east-1');
        $this->bucket = (string) ($credentials['bucket'] ?? '');
        $basePrefix   = trim((string) ($credentials['prefix'] ?? ''), '/');
        $userId       = (string) ($credentials['user_id'] ?? '');
        $this->userPrefix = $basePrefix !== ''
            ? $basePrefix . '/' . $userId . '/'
            : 'personal-dashboard/' . $userId . '/';

        $this->client = new S3SigV4Client(
            (string) ($credentials['access_key'] ?? ''),
            (string) ($credentials['secret_key'] ?? ''),
            $region,
            $endpoint,
            (bool) ($credentials['path_style'] ?? false),
        );
    }

    public function ensureRootFolder(string $rootName): string
    {
        $marker = $this->userPrefix . trim($rootName, '/') . '/';
        $this->client->putObject($this->bucket, $marker . '.keep', '', 'text/plain');

        return '/' . trim($rootName, '/');
    }

    public function listFolder(string $parentPath, ?string $cursor = null): FolderListing
    {
        $rootName = $this->rootFolderName();
        $relative = trim($parentPath, '/');
        if ($relative === $rootName) {
            $relative = '';
        } elseif (str_starts_with($relative, $rootName . '/')) {
            $relative = substr($relative, strlen($rootName) + 1);
        }

        $prefix = $this->userPrefix . $rootName . '/';
        if ($relative !== '') {
            $prefix .= $relative . '/';
        }

        $response = $this->client->listObjects($this->bucket, $prefix, '/');
        if ($response['status'] >= 400) {
            throw new \RuntimeException('S3 list failed.');
        }

        $xml = simplexml_load_string($response['body']);
        if ($xml === false) {
            throw new \RuntimeException('Invalid S3 list response.');
        }

        $items = [];

        foreach ($xml->CommonPrefixes ?? [] as $cp) {
            $key  = (string) ($cp->Prefix ?? '');
            $name = trim(substr($key, strlen($prefix)), '/');
            if ($name === '' || str_contains($name, '/')) {
                continue;
            }
            $displayPath = '/' . $rootName . ($relative !== '' ? '/' . $relative : '') . '/' . $name;
            $items[] = new CloudNode(
                $name,
                CloudNode::TYPE_FOLDER,
                $displayPath,
                $key,
            );
        }

        foreach ($xml->Contents ?? [] as $obj) {
            $key = (string) ($obj->Key ?? '');
            if ($key === '' || str_ends_with($key, '/.keep')) {
                continue;
            }
            $name = basename($key);
            $displayPath = '/' . $rootName . ($relative !== '' ? '/' . $relative : '') . '/' . $name;
            $items[] = new CloudNode(
                $name,
                CloudNode::TYPE_FILE,
                $displayPath,
                $key,
                mimeType: 'application/octet-stream',
                sizeBytes: isset($obj->Size) ? (int) $obj->Size : null,
                ext: pathinfo($name, PATHINFO_EXTENSION) ?: null,
                modifiedAt: (string) ($obj->LastModified ?? ''),
            );
        }

        usort($items, static function (CloudNode $a, CloudNode $b): int {
            if ($a->isFolder() !== $b->isFolder()) {
                return $a->isFolder() ? -1 : 1;
            }

            return strcasecmp($a->name, $b->name);
        });

        return new FolderListing($items, false, FolderListing::trailForPath($parentPath), null);
    }

    public function createFolder(string $parentPath, string $name): CloudNode
    {
        $key = $this->keyForPath(rtrim($parentPath, '/') . '/' . $name) . '/.keep';
        $this->client->putObject($this->bucket, $key, '', 'text/plain');

        return new CloudNode(
            $name,
            CloudNode::TYPE_FOLDER,
            rtrim($parentPath, '/') . '/' . $name,
            $key,
        );
    }

    public function upload(string $parentPath, string $localTmpPath, string $name, ?string $mime = null): CloudNode
    {
        $body = file_get_contents($localTmpPath);
        if ($body === false) {
            throw new \RuntimeException('Could not read upload file.');
        }

        $key = $this->keyForPath(rtrim($parentPath, '/') . '/' . $name);
        $response = $this->client->putObject(
            $this->bucket,
            $key,
            $body,
            $mime ?: 'application/octet-stream',
        );

        if ($response['status'] >= 400) {
            throw new \RuntimeException('S3 upload failed.');
        }

        return new CloudNode(
            $name,
            CloudNode::TYPE_FILE,
            rtrim($parentPath, '/') . '/' . $name,
            $key,
            mimeType: $mime,
            sizeBytes: strlen($body),
            ext: pathinfo($name, PATHINFO_EXTENSION) ?: null,
        );
    }

    public function downloadStream(string $providerPath): StreamMeta
    {
        $key = $this->keyForPath($providerPath);
        $response = $this->client->getObject($this->bucket, $key);
        if ($response['status'] >= 400) {
            throw new \RuntimeException('S3 download failed.');
        }

        $name = basename($providerPath);
        $tmp  = tempnam(sys_get_temp_dir(), 's3_');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temp file.');
        }
        file_put_contents($tmp, $response['body']);

        return new StreamMeta($tmp, 'application/octet-stream', $name, false, strlen($response['body']));
    }

    public function delete(string $providerPath): void
    {
        $key = str_contains($providerPath, '/')
            ? $this->keyForPath($providerPath)
            : $providerPath;

        $this->client->deleteObject($this->bucket, $key);
    }

    /**
     * Sum object sizes under the dashboard root prefix (best-effort; S3 has no account quota API).
     *
     * @return array{used_bytes: int, limit_bytes: int, label: string, available: true}|null
     */
    public function getPrefixUsage(string $rootFolderName, int $limitBytes): ?array
    {
        $prefix = $this->userPrefix . trim($rootFolderName, '/') . '/';
        $total  = 0;
        $token  = null;
        $pages  = 0;

        do {
            $response = $this->client->listObjectsPage($this->bucket, $prefix, $token);
            if ($response['status'] >= 400) {
                return null;
            }

            $xml = simplexml_load_string($response['body']);
            if ($xml === false) {
                return null;
            }

            foreach ($xml->Contents ?? [] as $obj) {
                $total += (int) ($obj->Size ?? 0);
            }

            $truncated = strtolower((string) ($xml->IsTruncated ?? 'false')) === 'true';
            $token     = $truncated ? (string) ($xml->NextContinuationToken ?? '') : '';
            $pages++;
        } while ($truncated && $token !== '' && $pages < 200);

        return [
            'used_bytes'  => $total,
            'limit_bytes' => $limitBytes,
            'label'       => 'S3 prefix (' . trim($rootFolderName, '/') . ')',
            'available'   => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeCredentials(array $input, int $userId): array
    {
        return [
            'access_key' => trim((string) ($input['access_key'] ?? '')),
            'secret_key' => trim((string) ($input['secret_key'] ?? '')),
            'bucket'     => trim((string) ($input['bucket'] ?? '')),
            'region'     => trim((string) ($input['region'] ?? 'us-east-1')),
            'endpoint'   => trim((string) ($input['endpoint'] ?? 'https://s3.amazonaws.com')),
            'prefix'     => trim((string) ($input['prefix'] ?? 'personal-dashboard')),
            'path_style' => filter_var($input['path_style'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'user_id'    => (string) $userId,
        ];
    }

    public static function validateCredentials(array $credentials): void
    {
        foreach (['access_key', 'secret_key', 'bucket'] as $field) {
            if (($credentials[$field] ?? '') === '') {
                throw new \InvalidArgumentException("S3 {$field} is required.");
            }
        }

        $client = new S3SigV4Client(
            (string) $credentials['access_key'],
            (string) $credentials['secret_key'],
            (string) ($credentials['region'] ?? 'us-east-1'),
            (string) ($credentials['endpoint'] ?? 'https://s3.amazonaws.com'),
            (bool) ($credentials['path_style'] ?? false),
        );

        $prefix = trim((string) ($credentials['prefix'] ?? 'personal-dashboard'), '/') . '/';
        $response = $client->listObjects((string) $credentials['bucket'], $prefix, '/');
        if ($response['status'] >= 400) {
            throw new \InvalidArgumentException('Could not access S3 bucket with these credentials.');
        }
    }

    private function rootFolderName(): string
    {
        return 'PersonalDashboard';
    }

    private function keyForPath(string $displayPath): string
    {
        $path = trim($displayPath, '/');
        $root = $this->rootFolderName();
        if (str_starts_with($path, $root)) {
            $path = trim(substr($path, strlen($root)), '/');
        }

        return $this->userPrefix . $root . ($path !== '' ? '/' . $path : '');
    }
}
