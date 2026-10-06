<?php

declare(strict_types=1);

namespace Dashboard\Bookmark;

use Dashboard\DB\Database;

final class BookmarkIconService
{
    public function __construct(
        private readonly BookmarkRepository $repo,
        private readonly FaviconFetcher $fetcher = new FaviconFetcher(),
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self(new BookmarkRepository($db));
    }

    /**
     * @return array<string, mixed> raw bookmark row after save
     */
    public function fetchAndStore(int $bookmarkId, int $userId): array
    {
        $row = $this->repo->findBookmark($bookmarkId, $userId);
        if ($row === null) {
            throw new \InvalidArgumentException('Bookmark not found.');
        }

        try {
            $fetched = $this->fetcher->fetchForPageUrl((string) $row['url']);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not fetch favicon: ' . $e->getMessage(), 0, $e);
        }

        $saved = BookmarkIconStorage::saveBytes(
            $userId,
            $bookmarkId,
            $fetched['bytes'],
            $fetched['content_type'],
        );

        $this->repo->updateFaviconExt($bookmarkId, $userId, $saved['ext']);

        $fresh = $this->repo->findBookmark($bookmarkId, $userId);

        if ($fresh === null) {
            throw new \RuntimeException('Failed to reload bookmark after favicon save.');
        }

        return $fresh;
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array<string, mixed> raw bookmark row after save
     */
    public function storeFromUpload(int $bookmarkId, int $userId, array $file): array
    {
        $row = $this->repo->findBookmark($bookmarkId, $userId);
        if ($row === null) {
            throw new \InvalidArgumentException('Bookmark not found.');
        }

        $saved = BookmarkIconStorage::storeFromUpload($userId, $bookmarkId, $file);
        $this->repo->updateFaviconExt($bookmarkId, $userId, $saved['ext']);

        $fresh = $this->repo->findBookmark($bookmarkId, $userId);
        if ($fresh === null) {
            throw new \RuntimeException('Failed to reload bookmark after favicon upload.');
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed> raw bookmark row after save
     */
    public function storeFromBytes(int $bookmarkId, int $userId, string $bytes, ?string $contentType = null): array
    {
        $row = $this->repo->findBookmark($bookmarkId, $userId);
        if ($row === null) {
            throw new \InvalidArgumentException('Bookmark not found.');
        }

        $saved = BookmarkIconStorage::saveBytes($userId, $bookmarkId, $bytes, $contentType);
        $this->repo->updateFaviconExt($bookmarkId, $userId, $saved['ext']);

        $fresh = $this->repo->findBookmark($bookmarkId, $userId);
        if ($fresh === null) {
            throw new \RuntimeException('Failed to reload bookmark after favicon save.');
        }

        return $fresh;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{path: string, mime: string}|null
     */
    public function streamMeta(array $row): ?array
    {
        return BookmarkIconStorage::streamMetaForRow($row);
    }

    public function deleteForBookmark(int $bookmarkId, int $userId): void
    {
        BookmarkIconStorage::deleteAll($userId, $bookmarkId);
        $this->repo->updateFaviconExt($bookmarkId, $userId, null);
    }
}
