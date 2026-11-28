<?php

declare(strict_types=1);

namespace Dashboard\Host;

/**
 * Reads the host. A prefix keeps tests on a fixture tree.
 * An overlay list is consumed one read at a time so a counter can move.
 */
final class HostFiles
{
    /**
     * @param array<string, string|list<string|null>> $overlay
     * @param array<string, array{total: int, free: int}> $disks
     */
    public function __construct(
        private readonly string $prefix = '',
        private array $overlay = [],
        private array $disks = [],
    ) {}

    public function canReadHost(): bool
    {
        if ($this->prefix !== '') {
            return true;
        }

        return @is_readable('/proc/stat');
    }

    public function read(string $path): ?string
    {
        if (array_key_exists($path, $this->overlay)) {
            $value = $this->overlay[$path];
            if (is_array($value)) {
                if ($value === []) {
                    return null;
                }
                $next = array_shift($this->overlay[$path]);

                return $next === null ? null : (string) $next;
            }

            return (string) $value;
        }
        $full = $this->full($path);
        if ($full === null || !is_file($full)) {
            return null;
        }
        $data = @file_get_contents($full);

        return $data === false ? null : $data;
    }

    public function exists(string $path): bool
    {
        if (array_key_exists($path, $this->overlay)) {
            return true;
        }
        $full = $this->full($path);

        return $full !== null && file_exists($full);
    }

    public function isDir(string $path): bool
    {
        $full = $this->full($path);

        return $full !== null && is_dir($full);
    }

    /**
     * @return list<string>
     */
    public function list(string $path): array
    {
        $full = $this->full($path);
        if ($full === null || !is_dir($full)) {
            return [];
        }
        $names = @scandir($full);
        if (!is_array($names)) {
            return [];
        }
        $out = [];
        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') {
                $out[] = $name;
            }
        }
        sort($out);

        return $out;
    }

    public function link(string $path): ?string
    {
        $full = $this->full($path);
        if ($full === null) {
            return null;
        }
        if (is_link($full)) {
            $target = readlink($full);

            return $target === false ? null : basename($target);
        }
        if (!is_file($full)) {
            return null;
        }
        $text = trim((string) file_get_contents($full));

        return $text === '' ? null : $text;
    }

    /**
     * @return array{total: int, free: int}|null
     */
    public function disk(string $mount): ?array
    {
        if (isset($this->disks[$mount])) {
            return $this->disks[$mount];
        }
        if ($this->prefix !== '') {
            return null;
        }
        $total = @disk_total_space($mount);
        $free = @disk_free_space($mount);
        if ($total === false || $free === false) {
            return null;
        }

        return ['total' => (int) $total, 'free' => (int) $free];
    }

    private function full(string $path): ?string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '..')) {
            return null;
        }

        return $this->prefix . $path;
    }
}

