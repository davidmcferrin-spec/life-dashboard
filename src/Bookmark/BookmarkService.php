<?php

declare(strict_types=1);

namespace Dashboard\Bookmark;

use Dashboard\DB\Database;

final class BookmarkService
{
    public function __construct(
        private readonly BookmarkRepository $repo,
        private readonly BookmarkIconService $icons,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        $repo = new BookmarkRepository($db);

        return new self($repo, new BookmarkIconService($repo));
    }

    public function iconService(): BookmarkIconService
    {
        return $this->icons;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(int $userId): array
    {
        $folders = $this->repo->foldersForUser($userId);

        return array_map(function (array $folder) use ($userId): array {
            return [
                'id'         => (int) $folder['id'],
                'name'       => (string) $folder['name'],
                'icon'       => $folder['icon'] ?? null,
                'sort_order' => (int) $folder['sort_order'],
                'bookmarks'  => array_map(
                    fn (array $bm): array => $this->formatBookmark($bm),
                    $this->repo->bookmarksForUser($userId, (int) $folder['id'])
                ),
            ];
        }, $folders);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function unfiled(int $userId): array
    {
        return array_map(
            fn (array $bm): array => $this->formatBookmark($bm),
            $this->repo->bookmarksForUser($userId, null)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBookmarkRow(int $id, int $userId): ?array
    {
        return $this->repo->findBookmark($id, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function createFolder(int $userId, string $name, ?string $icon = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Folder name is required.');
        }

        $id  = $this->repo->createFolder($userId, $name, $icon ?: null, $this->repo->nextFolderSort($userId));
        $row = $this->repo->findFolder($id, $userId);

        if ($row === null) {
            throw new \RuntimeException('Failed to create folder.');
        }

        return [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'icon'       => $row['icon'] ?? null,
            'sort_order' => (int) $row['sort_order'],
            'bookmarks'  => [],
        ];
    }

    public function updateFolder(int $id, int $userId, string $name, ?string $icon = null): bool
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Folder name is required.');
        }

        return $this->repo->updateFolder($id, $userId, $name, $icon ?: null);
    }

    public function deleteFolder(int $id, int $userId): bool
    {
        $bookmarks = $this->repo->bookmarksForUser($userId, $id);
        foreach ($bookmarks as $bm) {
            BookmarkIconStorage::deleteAll($userId, (int) $bm['id']);
        }

        return $this->repo->deleteFolder($id, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function createBookmark(
        int $userId,
        string $title,
        string $url,
        ?int $folderId = null,
        ?string $faviconUrl = null,
    ): array {
        $title = trim($title);
        $url   = $this->normalizeUrl(trim($url));

        if ($title === '' || $url === '') {
            throw new \InvalidArgumentException('Title and URL are required.');
        }

        if ($folderId !== null && $this->repo->findFolder($folderId, $userId) === null) {
            throw new \InvalidArgumentException('Folder not found.');
        }

        $id = $this->repo->createBookmark(
            $userId,
            $folderId,
            $title,
            $url,
            $faviconUrl ?: null,
            $this->repo->nextBookmarkSort($userId, $folderId),
        );

        $row = $this->repo->findBookmark($id, $userId);

        if ($row === null) {
            throw new \RuntimeException('Failed to create bookmark.');
        }

        return $this->formatBookmark($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchFavicon(int $id, int $userId): array
    {
        $row = $this->icons->fetchAndStore($id, $userId);

        return $this->formatBookmark($row);
    }

    /**
     * @return array<string, mixed>|null raw row
     */
    public function findBookmarkByUrl(int $userId, string $url): ?array
    {
        $needle = $this->normalizeUrlForMatch($url);

        foreach ($this->repo->allBookmarksForUser($userId) as $row) {
            if ($this->normalizeUrlForMatch((string) $row['url']) === $needle) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function faviconCandidates(int $id, int $userId): array
    {
        $row = $this->repo->findBookmark($id, $userId);
        if ($row === null) {
            throw new \InvalidArgumentException('Bookmark not found.');
        }

        $url = (string) $row['url'];

        try {
            return (new FaviconFetcher())->listCandidatesForPageUrl($url);
        } catch (\InvalidArgumentException) {
            return FaviconFetcher::defaultOriginIconPaths($url);
        }
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array<string, mixed>
     */
    public function uploadFavicon(int $id, int $userId, array $file): array
    {
        $row = $this->icons->storeFromUpload($id, $userId, $file);

        return $this->formatBookmark($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadFaviconBytes(int $id, int $userId, string $bytes, ?string $contentType = null): array
    {
        $row = $this->icons->storeFromBytes($id, $userId, $bytes, $contentType);

        return $this->formatBookmark($row);
    }

    public function updateBookmark(
        int $id,
        int $userId,
        string $title,
        string $url,
        ?int $folderId = null,
        ?string $faviconUrl = null,
    ): bool {
        if ($this->repo->findBookmark($id, $userId) === null) {
            return false;
        }

        $title = trim($title);
        $url   = $this->normalizeUrl(trim($url));

        if ($title === '' || $url === '') {
            throw new \InvalidArgumentException('Title and URL are required.');
        }

        if ($folderId !== null && $this->repo->findFolder($folderId, $userId) === null) {
            throw new \InvalidArgumentException('Folder not found.');
        }

        return $this->repo->updateBookmark($id, $userId, $folderId, $title, $url, $faviconUrl ?: null);
    }

    public function deleteBookmark(int $id, int $userId): bool
    {
        if ($this->repo->findBookmark($id, $userId) === null) {
            return false;
        }

        BookmarkIconStorage::deleteAll($userId, $id);

        return $this->repo->deleteBookmark($id, $userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBookmark(int $id, int $userId): ?array
    {
        $row = $this->repo->findBookmark($id, $userId);

        return $row !== null ? $this->formatBookmark($row) : null;
    }

    private function normalizeUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    private function normalizeUrlForMatch(string $url): string
    {
        $url = trim(strtolower($url));
        if ($url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }

        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port   = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path   = $parts['path'] ?? '/';
        $path   = rtrim($path, '/') ?: '/';

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatBookmark(array $row): array
    {
        $id          = (int) $row['id'];
        $faviconExt  = isset($row['favicon_ext']) && $row['favicon_ext'] !== ''
            ? (string) $row['favicon_ext']
            : null;
        $legacyUrl   = isset($row['favicon_url']) && $row['favicon_url'] !== ''
            ? (string) $row['favicon_url']
            : null;
        $hasStored   = $faviconExt !== null && BookmarkIconStorage::streamMetaForRow($row) !== null;
        $faviconHref = $hasStored ? '/api/bookmarks/' . $id . '/favicon' : $legacyUrl;

        return [
            'id'           => $id,
            'folder_id'    => $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
            'title'        => (string) $row['title'],
            'url'          => (string) $row['url'],
            'has_favicon'  => $faviconHref !== null,
            'favicon_href' => $faviconHref,
            'favicon_ext'  => $faviconExt,
            'sort_order'   => (int) $row['sort_order'],
        ];
    }
}
