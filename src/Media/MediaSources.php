<?php

declare(strict_types=1);

namespace Dashboard\Media;

use Dashboard\Support\ValidationException;

/**
 * Turns the widget's source box into a list of channels, subs, or accounts.
 * One entry per line. Blank lines and # comments are skipped.
 */
final class MediaSources
{
    public const MAX = 12;

    /**
     * @return list<array{key: string, label: string, channel_id: ?string, handle: ?string, page: ?string}>
     */
    public static function parse(string $dataset, string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        $bad = [];
        foreach ($lines as $i => $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $source = match ($dataset) {
                'youtube' => self::youtube($line),
                'reddit'  => self::reddit($line),
                'x'       => self::account($line),
                default   => null,
            };
            if ($source === null) {
                $bad[] = 'Line ' . ($i + 1) . ' is not a ' . self::noun($dataset) . '.';
                continue;
            }
            $out[$source['key']] = $source;
        }
        if ($bad !== []) {
            throw ValidationException::withFields(['config.sources' => implode(' ', $bad)]);
        }
        if (count($out) > self::MAX) {
            throw ValidationException::withFields(['config.sources' => 'A widget can list up to ' . self::MAX . ' ' . self::noun($dataset) . 's.']);
        }
        return array_values($out);
    }

    /**
     * @return array{key: string, label: string, channel_id: ?string, handle: ?string, page: ?string}|null
     */
    public static function youtube(string $line): ?array
    {
        if (preg_match('/^UC[\w-]{22}$/', $line) === 1) {
            return self::row($line, $line, $line, null, null);
        }
        if (preg_match('~channel_id=(UC[\w-]{22})~', $line, $m) === 1) {
            return self::row($m[1], $m[1], $m[1], null, null);
        }
        if (preg_match('~(?:youtube\.com|youtu\.be)/channel/(UC[\w-]{22})~i', $line, $m) === 1) {
            return self::row($m[1], $m[1], $m[1], null, null);
        }
        if (preg_match('~(?:youtube\.com/@|@)([A-Za-z0-9._-]{3,30})\b~', $line, $m) === 1
            || preg_match('/^@([A-Za-z0-9._-]{3,30})$/', $line, $m) === 1) {
            return self::row('handle:' . strtolower($m[1]), '@' . $m[1], null, $m[1], null);
        }
        if (preg_match('~youtube\.com/(c|user)/([A-Za-z0-9._-]{2,40})\b~i', $line, $m) === 1) {
            $page = 'https://www.youtube.com/' . strtolower($m[1]) . '/' . $m[2];
            return self::row(strtolower($m[1]) . ':' . strtolower($m[2]), $m[2], null, null, $page);
        }
        return null;
    }

    /**
     * @return array{key: string, label: string, channel_id: ?string, handle: ?string, page: ?string}|null
     */
    public static function reddit(string $line): ?array
    {
        if (preg_match('~reddit\.com/r/([A-Za-z0-9_]{2,21})\b~i', $line, $m) === 1
            || preg_match('~^(?:r/)?([A-Za-z0-9_]{2,21})$~i', $line, $m) === 1) {
            return self::row(strtolower($m[1]), 'r/' . $m[1], null, null, null);
        }
        return null;
    }

    /**
     * @return array{key: string, label: string, channel_id: ?string, handle: ?string, page: ?string}|null
     */
    public static function account(string $line): ?array
    {
        $handle = null;
        if (preg_match('~(?:x|twitter)\.com/([A-Za-z0-9_]{1,15})\b~i', $line, $m) === 1) {
            $handle = $m[1];
        } elseif (preg_match('/^@?([A-Za-z0-9_]{1,15})$/', $line, $m) === 1) {
            $handle = $m[1];
        }
        if ($handle === null) {
            return null;
        }
        $key = strtolower($handle);
        $reserved = ['home', 'search', 'explore', 'settings', 'i', 'intent', 'share', 'hashtag', 'messages', 'compose'];
        if (in_array($key, $reserved, true)) {
            return null;
        }
        return self::row($key, '@' . $handle, null, $handle, null);
    }

    /**
     * @return array{key: string, label: string, channel_id: ?string, handle: ?string, page: ?string}
     */
    private static function row(string $key, string $label, ?string $channelId, ?string $handle, ?string $page): array
    {
        return [
            'key'        => $key,
            'label'      => $label,
            'channel_id' => $channelId,
            'handle'     => $handle,
            'page'       => $page,
        ];
    }

    private static function noun(string $dataset): string
    {
        return match ($dataset) {
            'youtube' => 'channel',
            'reddit'  => 'subreddit',
            default   => 'account',
        };
    }
}
