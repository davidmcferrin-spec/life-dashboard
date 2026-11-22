<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\Support\ValidationException;

/** Taste-profile blocklist. Playlist builders skip these tracks, artists, and titles. */
final class TasteProfile
{
    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function normalize(array $profile): array
    {
        $profile['dislikes'] = self::dislikes($profile['dislikes'] ?? []);
        return $profile;
    }

    /**
     * @return array{tracks: list<string>, artists: list<string>, titles: list<string>}
     */
    public static function dislikes(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return self::empty();
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : ['titles' => [$raw]];
        }
        if (!is_array($raw)) {
            throw ValidationException::withFields(['dislikes' => 'Dislikes must list tracks, artists, or titles.']);
        }
        if (array_is_list($raw)) {
            return self::fromParts([], [], $raw);
        }
        return self::fromParts($raw['tracks'] ?? [], $raw['artists'] ?? [], $raw['titles'] ?? []);
    }

    /**
     * @param array{tracks?: list<string>, artists?: list<string>, titles?: list<string>}|array<string, mixed> $current
     * @param array<string, mixed>|list<mixed> $incoming
     * @return array{tracks: list<string>, artists: list<string>, titles: list<string>}
     */
    public static function merge(array $current, array $incoming, bool $replace): array
    {
        $right = self::dislikes($incoming);
        if ($replace) {
            return $right;
        }
        $left = self::dislikes($current);
        return self::fromParts(
            [...$left['tracks'], ...$right['tracks']],
            [...$left['artists'], ...$right['artists']],
            [...$left['titles'], ...$right['titles']],
        );
    }

    /**
     * @param array{tracks?: list<string>, artists?: list<string>, titles?: list<string>} $dislikes
     * @param array<string, mixed> $track
     */
    public static function blocked(array $dislikes, array $track): bool
    {
        $lists = self::dislikes($dislikes);
        $id = self::trackId($track);
        if ($id !== '' && in_array('spotify:track:' . $id, $lists['tracks'], true)) {
            return true;
        }
        $title = self::fold((string) ($track['name'] ?? ''));
        if ($title !== '') {
            foreach ($lists['titles'] as $blocked) {
                $needle = self::fold($blocked);
                // A short fragment would match unrelated titles. Longer phrases match inside the name.
                if ($needle !== '' && ($title === $needle || (strlen($needle) >= 3 && str_contains($title, $needle)))) {
                    return true;
                }
            }
        }
        $artists = array_map(self::fold(...), self::artistNames($track['artists'] ?? []));
        foreach ($lists['artists'] as $blocked) {
            $needle = self::fold($blocked);
            if ($needle !== '' && in_array($needle, $artists, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    public static function artistNames(mixed $artists): array
    {
        if (is_string($artists)) {
            $decoded = json_decode($artists, true);
            $artists = is_array($decoded) ? $decoded : [$artists];
        }
        if (!is_array($artists)) {
            return [];
        }
        $names = [];
        foreach ($artists as $artist) {
            if (is_string($artist)) {
                $names[] = $artist;
            } elseif (is_array($artist) && isset($artist['name'])) {
                $names[] = (string) $artist['name'];
            }
        }
        return $names;
    }

    /**
     * @return array{tracks: list<string>, artists: list<string>, titles: list<string>}
     */
    private static function empty(): array
    {
        return ['tracks' => [], 'artists' => [], 'titles' => []];
    }

    /**
     * @return array{tracks: list<string>, artists: list<string>, titles: list<string>}
     */
    private static function fromParts(mixed $tracks, mixed $artists, mixed $titles): array
    {
        return [
            'tracks' => self::tracks($tracks),
            'artists' => self::labels($artists, 'artists', 120),
            'titles' => self::labels($titles, 'titles', 200),
        ];
    }

    /**
     * @return list<string>
     */
    private static function tracks(mixed $value): array
    {
        $out = [];
        $seen = [];
        foreach (self::items($value, 'tracks') as $item) {
            if (preg_match('/^spotify:track:([A-Za-z0-9]+)$/', $item, $m) === 1) {
                $uri = 'spotify:track:' . $m[1];
            } elseif (preg_match('/^[A-Za-z0-9]+$/', $item) === 1) {
                $uri = 'spotify:track:' . $item;
            } else {
                throw ValidationException::withFields(['tracks' => 'Each disliked track must be a spotify:track URI or id.']);
            }
            if (isset($seen[$uri])) {
                continue;
            }
            $seen[$uri] = true;
            $out[] = $uri;
        }
        if (count($out) > 200) {
            throw ValidationException::withFields(['tracks' => 'At most 200 disliked tracks.']);
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private static function labels(mixed $value, string $field, int $max): array
    {
        $out = [];
        $seen = [];
        foreach (self::items($value, $field) as $item) {
            if (strlen($item) > $max) {
                throw ValidationException::withFields([$field => ucfirst($field) . ' must be ' . $max . ' characters or fewer.']);
            }
            $key = self::fold($item);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        if (count($out) > 200) {
            throw ValidationException::withFields([$field => 'At most 200 disliked ' . $field . '.']);
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private static function items(mixed $value, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value) ?: [];
        }
        if (!is_array($value)) {
            throw ValidationException::withFields([$field => ucfirst($field) . ' must be a list.']);
        }
        $out = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $track
     */
    private static function trackId(array $track): string
    {
        $uri = trim((string) ($track['uri'] ?? ''));
        if (preg_match('/^spotify:track:([A-Za-z0-9]+)$/', $uri, $m) === 1) {
            return $m[1];
        }
        $id = trim((string) ($track['spotify_track_id'] ?? $track['id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9]+$/', $id) === 1) {
            return $id;
        }
        return '';
    }

    private static function fold(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['’', '\''], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        return trim($value);
    }
}
