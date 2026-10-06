<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

/**
 * Keeps cloud file paths scoped to the configured root folder (e.g. /PersonalDashboard).
 */
final class CloudPathGuard
{
    public static function normalize(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        while (str_contains($path, '//')) {
            $path = str_replace('//', '/', $path);
        }

        return $path;
    }

    public static function normalizeRoot(string $root): string
    {
        $root = self::normalize($root);
        if ($root === '/') {
            throw new \InvalidArgumentException('Cloud root folder is invalid.');
        }

        return $root;
    }

    public static function resolveUnderRoot(string $root, ?string $path): string
    {
        $root = self::normalizeRoot($root);

        $path = trim((string) $path);
        if ($path === '' || $path === '/') {
            return $root;
        }

        $path = self::normalize($path);
        if (!self::isUnderRoot($root, $path)) {
            throw new \InvalidArgumentException('Path is outside the cloud root folder.');
        }

        return $path;
    }

    public static function isUnderRoot(string $root, string $path): bool
    {
        $root = self::normalizeRoot($root);
        $path = self::normalize($path);

        if ($path === $root) {
            return true;
        }

        return str_starts_with($path, $root . '/');
    }

    public static function assertUnderRoot(string $root, string $path): void
    {
        if (!self::isUnderRoot($root, $path)) {
            throw new \InvalidArgumentException('Path is outside the cloud root folder.');
        }
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    public static function breadcrumbTrail(string $root, string $currentPath): array
    {
        $root         = self::normalizeRoot($root);
        $currentPath = self::resolveUnderRoot($root, $currentPath);

        if ($currentPath === $root) {
            return [['name' => self::segmentName($root), 'path' => $root]];
        }

        $relative = substr($currentPath, strlen($root));
        $relative = trim($relative, '/');
        if ($relative === '') {
            return [['name' => self::segmentName($root), 'path' => $root]];
        }

        $crumbs = [['name' => self::segmentName($root), 'path' => $root]];
        $acc    = $root;

        foreach (explode('/', $relative) as $part) {
            if ($part === '') {
                continue;
            }
            $acc      .= '/' . $part;
            $crumbs[] = ['name' => $part, 'path' => $acc];
        }

        return $crumbs;
    }

    private static function segmentName(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return '/';
        }

        $parts = explode('/', $path);

        return (string) end($parts);
    }
}
