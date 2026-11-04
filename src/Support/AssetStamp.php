<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * Cache-bust stamp for CSS and JS. It is the newest mtime under the app
 * asset folders, so a stylesheet or script edit changes ?v= without a manual
 * bump in the document head.
 */
final class AssetStamp
{
    public static function current(): string
    {
        static $stamp = null;
        if ($stamp !== null) {
            return $stamp;
        }
        $max = 0;
        foreach ([PUBLIC_DIR . '/assets/css', PUBLIC_DIR . '/assets/js'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if ($ext !== 'css' && $ext !== 'js') {
                    continue;
                }
                $mtime = $file->getMTime();
                if ($mtime > $max) {
                    $max = $mtime;
                }
            }
        }
        foreach ([
            PUBLIC_DIR . '/assets/vendor/gridstack-12.6.0/gridstack-all.js',
            PUBLIC_DIR . '/assets/vendor/gridstack-12.6.0/gridstack.min.css',
        ] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $mtime = filemtime($path);
            if ($mtime !== false && $mtime > $max) {
                $max = $mtime;
            }
        }

        return $stamp = $max > 0 ? (string) $max : '0';
    }
}
