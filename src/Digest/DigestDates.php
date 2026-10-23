<?php

declare(strict_types=1);

namespace Dashboard\Digest;

/** Dates that a listing glued onto the end of a note. */
final class DigestDates
{
    private const MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10,
        'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
    ];

    private const LABEL = '/^(?:(?P<mon>jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*(?:\s+(?P<day>\d{1,2}))?(?:,?\s+(?P<year>\d{4}))?|(?P<iso>\d{4}-\d{2}-\d{2}))$/iu';

    private const TRAIL = '/(?:^|(?<=[.!?])\s+|\s)(?P<token>(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*(?:\s+\d{1,2})?(?:,?\s+\d{4})?|\d{4}-\d{2}-\d{2})\s*$/iu';

    /**
     * @return array{text: string, published_on: ?string, token: string}
     */
    public static function peel(string $text, ?\DateTimeImmutable $today = null): array
    {
        $raw = self::norm($text);
        if (!preg_match(self::TRAIL, $raw, $match, PREG_OFFSET_CAPTURE)) {
            return ['text' => $raw, 'published_on' => null, 'token' => ''];
        }
        $token = (string) $match['token'][0];
        $start = (int) $match[0][1];
        if (preg_match('/^[A-Za-z]+$/u', $token) === 1 && $start > 0 && !in_array($raw[$start - 1] ?? '', ['.', '!', '?'], true)) {
            return ['text' => $raw, 'published_on' => null, 'token' => ''];
        }
        $today ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $found = self::label($token, $today);
        if ($found === null) {
            return ['text' => $raw, 'published_on' => null, 'token' => ''];
        }
        $cleaned = self::norm(substr($raw, 0, $start));
        if (in_array(strtolower($cleaned), ['product', 'company', 'read more', 'read more company'], true)) {
            $cleaned = '';
        }

        return ['text' => $cleaned, 'published_on' => $found, 'token' => $token];
    }

    public static function prefer(?string $existing, ?string $found): ?string
    {
        if (!self::valid($found)) {
            return self::valid($existing) ? $existing : null;
        }
        if (!self::valid($existing)) {
            return $found;
        }
        if (str_ends_with((string) $existing, '-01') && substr((string) $found, 0, 7) === substr((string) $existing, 0, 7) && !str_ends_with((string) $found, '-01')) {
            return $found;
        }

        return $existing;
    }

    public static function hash(string $title, ?string $published, string $summary): string
    {
        return hash('sha256', self::norm($title . "\n" . ($published ?? '') . "\n" . $summary));
    }

    /**
     * @return list<string>
     */
    public static function phrases(string $iso): array
    {
        $parts = explode('-', $iso);
        if (count($parts) !== 3) {
            return [$iso];
        }
        $year = (int) $parts[0];
        $month = (int) $parts[1];
        $day = (int) $parts[2];
        $phrases = [$iso];
        foreach (self::MONTHS as $name => $number) {
            if ($number !== $month) {
                continue;
            }
            $label = ucfirst($name);
            $phrases[] = $label . ' ' . $day . ', ' . $year;
            $phrases[] = $label . ' ' . $day . ' ' . $year;
        }

        return $phrases;
    }

    /**
     * @return array{summary: string, published_on: ?string, brief: string, brief_kind: string, content_hash: string}|null
     */
    public static function repair(string $title, ?string $published, string $summary, string $brief, string $kind, ?\DateTimeImmutable $today = null): ?array
    {
        $peeled = self::peel($summary, $today);
        $next = self::prefer($published !== '' ? $published : null, $peeled['published_on']);
        $briefPeel = self::peel($brief, $today);
        if ($briefPeel['published_on'] !== null) {
            $next = self::prefer($next, $briefPeel['published_on']);
        }
        $phrases = [];
        if ($peeled['token'] !== '') {
            $phrases[] = $peeled['token'];
        }
        if ($briefPeel['token'] !== '') {
            $phrases[] = $briefPeel['token'];
        }
        if ($peeled['published_on'] !== null) {
            $phrases = array_merge($phrases, self::phrases($peeled['published_on']));
        }
        if ($briefPeel['published_on'] !== null) {
            $phrases = array_merge($phrases, self::phrases($briefPeel['published_on']));
        }
        $copied = false;
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && stripos($brief, $phrase) !== false) {
                $copied = true;
                break;
            }
        }
        $newBrief = $brief;
        $newKind = $kind;
        if ($copied) {
            $newBrief = DigestBrief::excerpt($peeled['text'] !== '' ? $peeled['text'] : $briefPeel['text']);
            $newKind = $newBrief !== '' ? 'excerpt' : '';
        }
        $sameSummary = $peeled['text'] === $summary;
        $sameDate = $next === ($published !== '' ? $published : null);
        if ($sameSummary && $sameDate && $newBrief === $brief && $newKind === $kind) {
            return null;
        }

        return [
            'summary' => $peeled['text'],
            'published_on' => $next,
            'brief' => $newBrief,
            'brief_kind' => $newKind,
            'content_hash' => self::hash($title, $next, $peeled['text']),
        ];
    }

    private static function label(string $token, \DateTimeImmutable $today): ?string
    {
        if (!preg_match(self::LABEL, self::norm($token), $match)) {
            return null;
        }
        if (($match['iso'] ?? '') !== '') {
            return self::valid($match['iso']) ? $match['iso'] : null;
        }
        $mon = strtolower(substr((string) $match['mon'], 0, 3));
        if ($mon === 'sep' && str_starts_with(strtolower((string) $match['mon']), 'sept')) {
            $mon = 'sept';
        }
        $month = self::MONTHS[$mon] ?? self::MONTHS[strtolower(substr((string) $match['mon'], 0, 3))] ?? null;
        if ($month === null) {
            return null;
        }
        $day = (int) (($match['day'] ?? '') !== '' ? $match['day'] : 1);
        if (($match['year'] ?? '') !== '') {
            return self::ymd((int) $match['year'], $month, $day);
        }
        $year = (int) $today->format('Y');
        $nowMonth = (int) $today->format('n');
        if ($month > $nowMonth) {
            $year--;
        }

        return self::ymd($year, $month, $day);
    }

    private static function ymd(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year) || $year < 1990 || $year > 2100) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function valid(?string $value): bool
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $parts = explode('-', $value);

        return self::ymd((int) $parts[0], (int) $parts[1], (int) $parts[2]) === $value;
    }

    private static function norm(string $value): string
    {
        $text = preg_replace('/\s+/u', ' ', $value);

        return trim((string) $text);
    }
}
