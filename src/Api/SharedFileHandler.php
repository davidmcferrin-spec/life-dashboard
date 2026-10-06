<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\CloudStorage\NodeRepository;
use Dashboard\CloudStorage\StorageService;
use Dashboard\CloudStorage\StorageShareRepository;
use Dashboard\Files\FileService;
use Dashboard\Files\FileShareService;
use Dashboard\DB\Database;
use Dashboard\Share\ShareDeniedResponse;

final class SharedFileHandler
{
    private FileService $files;
    private FileShareService $localShares;
    private StorageService $cloud;
    private StorageShareRepository $cloudShares;
    private NodeRepository $nodes;

    public function __construct(Database $db)
    {
        $this->files       = new FileService($db);
        $this->localShares = new FileShareService($db);
        $this->cloud       = new StorageService($db);
        $this->cloudShares = new StorageShareRepository($db);
        $this->nodes       = new NodeRepository($db);
    }

    /**
     * @param array<string, string> $params
     */
    public function get(array $params): void
    {
        $token = (string) ($params['token'] ?? '');

        $cloud = $this->cloudMetadataForToken($token);
        if ($cloud !== null) {
            jsonResponse(['file' => $cloud]);
            return;
        }

        $file = $this->files->metadataForShareToken($token);
        if ($file === null) {
            ShareDeniedResponse::json();
        }

        jsonResponse(['file' => $file]);
    }

    /**
     * @param array<string, string> $params
     */
    public function download(array $params): void
    {
        $token = (string) ($params['token'] ?? '');

        $link = $this->cloudShares->findLinkByToken($token);
        if ($link !== null) {
            if (empty($link['connection_active'])) {
                ShareDeniedResponse::html();
            }

            try {
                $nodeId = (int) ($link['storage_node_id'] ?? 0);
                $result = $this->cloud->downloadByNodeId($nodeId, 0, null, $token);
                $node   = $this->nodes->findById($nodeId);
                $this->cloudShares->touchLinkUsed((int) $link['id']);
                $this->streamCloud($result, (string) ($node['name'] ?? 'download'));
            } catch (\Throwable) {
                ShareDeniedResponse::html();
            }
            exit;
        }

        $meta = $this->files->streamMeta(0, null, $token);
        if ($meta === null) {
            ShareDeniedResponse::html();
        }

        $this->localShares->touchLinkByToken($token);

        $filename    = $this->safeFilename($meta['filename']);
        $disposition = ($meta['inline'] ?? false) ? 'inline' : 'attachment';

        header('Content-Type: ' . $meta['mime']);
        header('Content-Length: ' . (string) filesize($meta['path']));
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($meta['path']);
        exit;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cloudMetadataForToken(string $token): ?array
    {
        $link = $this->cloudShares->findLinkByToken($token);
        if ($link === null) {
            return null;
        }

        if (empty($link['connection_active'])) {
            return null;
        }

        $node = $this->nodes->findById((int) ($link['storage_node_id'] ?? 0));
        if ($node === null) {
            return null;
        }

        return [
            'original_name' => (string) ($node['name'] ?? 'file'),
            'mime_type'     => (string) ($node['mime_type'] ?? 'application/octet-stream'),
            'ext'           => (string) ($node['ext'] ?? ''),
            'size_bytes'    => (int) ($node['size_bytes'] ?? 0),
            'source'        => 'cloud',
        ];
    }

    /**
     * @param array{meta: \Dashboard\CloudStorage\StreamMeta, inline: bool} $result
     */
    private function streamCloud(array $result, string $filename): void
    {
        $meta        = $result['meta'];
        $disposition = ($result['inline'] ?? false) ? 'inline' : 'attachment';
        $safe        = $this->safeFilename($filename);

        header('Content-Type: ' . $meta->mime);
        if ($meta->size !== null) {
            header('Content-Length: ' . (string) $meta->size);
        }
        header('Content-Disposition: ' . $disposition . '; filename="' . $safe . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        $path = $meta->readPath();
        if ($path !== null) {
            readfile($path);
            @unlink($path);
        } else {
            echo $meta->readBody();
        }
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w\s\-\.\(\)\[\]]+/u', '', $name) ?? 'download';
        $name = trim($name);

        return $name !== '' ? $name : 'download';
    }
}
