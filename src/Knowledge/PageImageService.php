<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

use Dashboard\Audit\AuditLog;
use Dashboard\DB\Database;
use Dashboard\Files\FileStorage;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Raster images for a vault page. The file library's share links are not used.
 */
final class PageImageService
{
    public const MAX_BYTES = 8_388_608;

    public function __construct(
        private readonly Database $db,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array{id: int, url: string, alt: string, markdown: string}
     */
    public function fromUpload(int $userId, array $file, string $alt, string $actor = 'user', ?string $tool = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ValidationException::withFields(['file' => $this->uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE))]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw ValidationException::withFields(['file' => 'The image upload did not finish.']);
        }
        $size = (int) ($file['size'] ?? 0);
        return $this->store($userId, $tmp, (string) ($file['name'] ?? 'image'), $size, true, $alt, $actor, $tool);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, url: string, alt: string, markdown: string}
     */
    public function fromBase64(int $userId, array $input, string $actor = 'user', ?string $tool = null): array
    {
        $name = trim((string) ($input['filename'] ?? ''));
        $encoded = (string) ($input['content_base64'] ?? '');
        if ($name === '' || $encoded === '') {
            throw ValidationException::withFields(['content_base64' => 'Send filename and content_base64 for a JPEG, PNG, GIF, or WebP image.']);
        }
        if (strlen($encoded) > (int) ceil(self::MAX_BYTES / 3) * 4 + 8) {
            throw ValidationException::withFields(['content_base64' => 'Vault images must be 8 MB or smaller.']);
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw ValidationException::withFields(['content_base64' => 'content_base64 is not valid base64.']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'vaultimg');
        if ($tmp === false) {
            throw new \RuntimeException('Could not stage the vault image.');
        }
        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new \RuntimeException('Could not stage the vault image.');
            }
            return $this->store($userId, $tmp, $name, strlen($bytes), false, (string) ($input['alt'] ?? ''), $actor, $tool);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * @return array{path: string, mime: string, filename: string}
     */
    public function stream(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT storage_id, ext, mime_type FROM page_images WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Image not found.');
        }
        $path = FileStorage::vaultImagePath($userId, (string) $row['storage_id'], (string) $row['ext']);
        if ($path === null) {
            throw new NotFoundException('Image not found.');
        }
        return [
            'path'     => $path,
            'mime'     => (string) $row['mime_type'],
            'filename' => 'image.' . (string) $row['ext'],
        ];
    }

    /**
     * @return array{id: int, url: string, alt: string, markdown: string}
     */
    private function store(
        int $userId,
        string $source,
        string $originalName,
        int $size,
        bool $uploaded,
        string $alt,
        string $actor,
        ?string $tool,
    ): array {
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw ValidationException::withFields(['file' => 'Vault images must be 8 MB or smaller.']);
        }
        try {
            $checked = FileStorage::inspectImage($source, $originalName);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withFields(['file' => $e->getMessage()]);
        }
        $storageId = strtolower(FileStorage::generateStorageId());
        FileStorage::storeVaultImage($userId, $storageId, $source, $checked['ext'], $uploaded);
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO page_images (user_id, storage_id, ext, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$userId, $storageId, $checked['ext'], $checked['mime'], $size]
        );
        $alt = $this->altText($alt, $originalName);
        $url = '/api/v1/pages/images/' . $id;
        $this->audit->record($userId, $actor, $tool, 'page.image', 'page_image:' . $id, [
            'bytes' => $size,
            'ext'   => $checked['ext'],
        ]);
        return [
            'id'       => $id,
            'url'      => $url,
            'alt'      => $alt,
            'markdown' => '![' . $alt . '](' . $url . ')',
        ];
    }

    private function altText(string $alt, string $originalName): string
    {
        $alt = trim(str_replace(["\r", "\n", '[', ']'], '', $alt));
        if ($alt !== '') {
            return mb_substr($alt, 0, 200);
        }
        $base = pathinfo(FileStorage::sanitizeOriginalName($originalName), PATHINFO_FILENAME);
        $base = trim(str_replace(['[', ']'], '', $base));
        return $base !== '' && $base !== 'upload' ? mb_substr($base, 0, 200) : 'Image';
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Vault images must be 8 MB or smaller.',
            UPLOAD_ERR_PARTIAL => 'The image upload did not finish.',
            UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
            default => 'The image upload failed.',
        };
    }
}
