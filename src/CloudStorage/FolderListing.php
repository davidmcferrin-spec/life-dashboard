<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

/**
 * Paginated folder contents from a cloud storage provider.
 */
final class FolderListing
{
    /**
     * @param list<CloudNode> $items
     * @param list<array{name: string, path: string}> $breadcrumb
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasMore,
        public readonly array $breadcrumb,
        public readonly ?string $cursor = null,
    ) {}

    /**
     * @return list<array{name: string, path: string}>
     */
    public static function trailForPath(string $path): array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return [];
        }

        $crumbs = [];
        $acc    = '';
        foreach (explode('/', $path) as $part) {
            if ($part === '') {
                continue;
            }
            $acc      .= '/' . $part;
            $crumbs[] = ['name' => $part, 'path' => $acc];
        }

        return $crumbs;
    }
}
