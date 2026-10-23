<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\Support\ValidationException;

/**
 * Camelot compatibility, season windows, and an energy-arc order.
 * Spotify audio-features is unavailable, so this uses stored notes only.
 */
final class Mixtape
{
    /** @var array<string, int> */
    public const ARC = [
        'opener' => 3,
        'build' => 6,
        'peak' => 9,
        'breather' => 5,
        'closer' => 4,
    ];

    /** @var list<string> */
    public const SECTIONS = ['opener', 'build', 'peak', 'breather', 'closer'];

    private const BPM_STEP = 8.0;

    /** @var array<string, string> musical key => Camelot code */
    private const KEYS = [
        'ab minor' => '1A', 'g# minor' => '1A', 'b major' => '1B',
        'eb minor' => '2A', 'd# minor' => '2A', 'f# major' => '2B', 'gb major' => '2B',
        'bb minor' => '3A', 'a# minor' => '3A', 'db major' => '3B', 'c# major' => '3B',
        'f minor' => '4A', 'ab major' => '4B', 'g# major' => '4B',
        'c minor' => '5A', 'eb major' => '5B', 'd# major' => '5B',
        'g minor' => '6A', 'bb major' => '6B', 'a# major' => '6B',
        'd minor' => '7A', 'f major' => '7B',
        'a minor' => '8A', 'c major' => '8B',
        'e minor' => '9A', 'g major' => '9B',
        'b minor' => '10A', 'd major' => '10B',
        'f# minor' => '11A', 'gb minor' => '11A', 'a major' => '11B',
        'db minor' => '12A', 'c# minor' => '12A', 'e major' => '12B',
    ];

    public static function camelot(?string $code, ?string $musicalKey): ?string
    {
        $parsed = self::parseCamelot($code);
        if ($parsed !== null) {
            return $parsed;
        }
        $key = strtolower(trim((string) $musicalKey));
        $key = str_replace(['♯', '♭'], ['#', 'b'], $key);
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;
        // "min" is a prefix of "minor", so only expand a bare abbreviation.
        $key = preg_replace('/\bmin$/', 'minor', $key) ?? $key;
        $key = preg_replace('/\bmaj$/', 'major', $key) ?? $key;
        if ($key === '') {
            return null;
        }
        return self::KEYS[$key] ?? null;
    }

    public static function compatible(?string $left, ?string $right): bool
    {
        $a = self::parseCamelot($left);
        $b = self::parseCamelot($right);
        if ($a === null || $b === null) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $an = (int) substr($a, 0, -1);
        $bn = (int) substr($b, 0, -1);
        $al = substr($a, -1);
        $bl = substr($b, -1);
        if ($al === $bl) {
            $step = abs($an - $bn);
            return $step === 1 || $step === 11;
        }
        return $an === $bn;
    }

    /**
     * @param array{start_anchor?: string, start_month?: int|null, start_day?: int|null, end_month: int, end_day: int} $window
     */
    public static function inWindow(array $window, \DateTimeImmutable $day): bool
    {
        $year = (int) $day->format('Y');
        $start = self::boundary($window, $year, true);
        $end = self::boundary($window, $year, false);
        if ($end < $start) {
            $startPrev = self::boundary($window, $year - 1, true);
            $endThis = self::boundary($window, $year, false);
            $startThis = self::boundary($window, $year, true);
            $endNext = self::boundary($window, $year + 1, false);
            return ($day >= $startPrev && $day <= $endThis) || ($day >= $startThis && $day <= $endNext);
        }
        return $day >= $start && $day <= $end;
    }

    public static function thanksgiving(int $year): \DateTimeImmutable
    {
        $nov1 = new \DateTimeImmutable(sprintf('%04d-11-01', $year));
        $dow = (int) $nov1->format('N');
        $toThursday = $dow <= 4 ? 4 - $dow : 11 - $dow;
        return $nov1->modify('+' . ($toThursday + 21) . ' days');
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, int>
     */
    public static function arc(array $raw): array
    {
        $out = self::ARC;
        foreach (self::SECTIONS as $section) {
            if (!array_key_exists($section, $raw)) {
                continue;
            }
            $value = $raw[$section];
            if (is_array($value) && array_key_exists('energy', $value)) {
                $value = $value['energy'];
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_numeric($value) || (int) $value < 1 || (int) $value > 10) {
                throw ValidationException::withFields([$section => 'Energy for ' . $section . ' must be 1 to 10.']);
            }
            $out[$section] = (int) $value;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $tracks
     * @param array<string, int> $arc
     * @return array{order: list<array<string, mixed>>, rough: list<array<string, mixed>>, missing: list<array<string, mixed>>, arc: array<string, int>}
     */
    public static function plan(array $tracks, array $arc): array
    {
        $missing = [];
        $ready = [];
        foreach ($tracks as $track) {
            $uri = (string) ($track['uri'] ?? '');
            $fields = [];
            if (!isset($track['energy']) || $track['energy'] === null || $track['energy'] === '') {
                $fields[] = 'energy';
            }
            if (!isset($track['bpm']) || $track['bpm'] === null || $track['bpm'] === '') {
                $fields[] = 'bpm';
            }
            if (self::parseCamelot(isset($track['camelot']) ? (string) $track['camelot'] : null) === null) {
                $fields[] = 'camelot';
            }
            if (in_array('energy', $fields, true)) {
                $missing[] = ['uri' => $uri, 'fields' => $fields];
                continue;
            }
            $ready[] = $track;
            if ($fields !== []) {
                $missing[] = ['uri' => $uri, 'fields' => $fields];
            }
        }

        $counts = self::sectionCounts(count($ready));
        $order = [];
        $prevBpm = null;
        $prevKey = null;
        foreach (self::SECTIONS as $section) {
            $need = $counts[$section];
            for ($i = 0; $i < $need; $i++) {
                $pick = self::pick($ready, $arc[$section], $prevBpm, $prevKey);
                $ready = $pick['rest'];
                $chosen = $pick['track'];
                $bpm = isset($chosen['bpm']) && $chosen['bpm'] !== null && $chosen['bpm'] !== '' ? (float) $chosen['bpm'] : null;
                $key = self::parseCamelot(isset($chosen['camelot']) ? (string) $chosen['camelot'] : null);
                $order[] = [
                    'uri' => (string) $chosen['uri'],
                    'section' => $section,
                    'position' => count($order),
                    'energy' => (int) $chosen['energy'],
                    'bpm' => $bpm,
                    'camelot' => $key,
                ];
                $prevBpm = $bpm ?? $prevBpm;
                $prevKey = $key ?? $prevKey;
            }
        }

        $rough = [];
        for ($i = 1, $n = count($order); $i < $n; $i++) {
            $reasons = self::transition($order[$i - 1], $order[$i]);
            if ($reasons !== []) {
                $rough[] = [
                    'from' => $order[$i - 1]['uri'],
                    'to' => $order[$i]['uri'],
                    'reasons' => $reasons,
                ];
            }
        }

        return [
            'order' => $order,
            'rough' => $rough,
            'missing' => $missing,
            'arc' => $arc,
        ];
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    public static function tags(mixed $value, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value) ?: [];
        }
        if (!is_array($value)) {
            throw ValidationException::withFields([$field => 'Tags must be a list.']);
        }
        $out = [];
        foreach ($value as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag === '') {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $tag) !== 1) {
                throw ValidationException::withFields([$field => 'Use a short tag such as christmas.']);
            }
            $out[] = $tag;
        }
        if (count($out) > 12) {
            throw ValidationException::withFields([$field => 'At most 12 tags.']);
        }
        return array_values(array_unique($out));
    }

    public static function trackId(string $uri): string
    {
        if (preg_match('/^spotify:track:([A-Za-z0-9]+)$/', $uri, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^[A-Za-z0-9]+$/', $uri) === 1) {
            return $uri;
        }
        throw ValidationException::withFields(['uri' => 'Use a spotify:track URI.']);
    }

    private static function parseCamelot(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        if (preg_match('/^(1[0-2]|[1-9])([AB])$/', $code, $m) !== 1) {
            return null;
        }
        return (int) $m[1] . $m[2];
    }

    /**
     * @param array{start_anchor?: string, start_month?: int|null, start_day?: int|null, end_month: int, end_day: int} $window
     */
    private static function boundary(array $window, int $year, bool $start): \DateTimeImmutable
    {
        if ($start && (($window['start_anchor'] ?? '') === 'thanksgiving')) {
            return self::thanksgiving($year)->setTime(0, 0);
        }
        $month = (int) ($start ? ($window['start_month'] ?? 1) : $window['end_month']);
        $day = (int) ($start ? ($window['start_day'] ?? 1) : $window['end_day']);
        $month = max(1, min(12, $month));
        $day = max(1, min(31, $day));
        $date = \DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day);
        if ($date === false) {
            $date = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $date = $date->modify('last day of this month');
        }
        return $date->setTime(0, 0);
    }

    /**
     * @return array<string, int>
     */
    private static function sectionCounts(int $total): array
    {
        $counts = array_fill_keys(self::SECTIONS, intdiv($total, count(self::SECTIONS)));
        $extra = $total % count(self::SECTIONS);
        foreach (['peak', 'build', 'opener', 'closer', 'breather'] as $section) {
            if ($extra < 1) {
                break;
            }
            $counts[$section]++;
            $extra--;
        }
        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $ready
     * @return array{track: array<string, mixed>, rest: list<array<string, mixed>>}
     */
    private static function pick(array $ready, int $target, ?float $prevBpm, ?string $prevKey): array
    {
        $best = 0;
        $bestScore = null;
        foreach ($ready as $i => $track) {
            $energy = abs((int) $track['energy'] - $target) * 10;
            $bpm = isset($track['bpm']) && $track['bpm'] !== null && $track['bpm'] !== '' ? (float) $track['bpm'] : null;
            $bpmPenalty = ($prevBpm !== null && $bpm !== null) ? min(20.0, abs($bpm - $prevBpm) / 2) : 0.0;
            $key = self::parseCamelot(isset($track['camelot']) ? (string) $track['camelot'] : null);
            $keyPenalty = ($prevKey !== null && $key !== null && !self::compatible($prevKey, $key)) ? 15 : 0;
            $score = $energy + $bpmPenalty + $keyPenalty;
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }
        $chosen = $ready[$best];
        array_splice($ready, $best, 1);
        return ['track' => $chosen, 'rest' => array_values($ready)];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return list<string>
     */
    private static function transition(array $left, array $right): array
    {
        $reasons = [];
        $lb = $left['bpm'];
        $rb = $right['bpm'];
        if ($lb === null || $rb === null) {
            $reasons[] = 'missing_bpm';
        } elseif (abs((float) $lb - (float) $rb) > self::BPM_STEP) {
            $reasons[] = 'bpm';
        }
        $lk = $left['camelot'];
        $rk = $right['camelot'];
        if ($lk === null || $rk === null) {
            $reasons[] = 'missing_key';
        } elseif (!self::compatible((string) $lk, (string) $rk)) {
            $reasons[] = 'key';
        }
        return $reasons;
    }
}
