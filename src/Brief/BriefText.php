<?php

declare(strict_types=1);

namespace Dashboard\Brief;

/**
 * Sentences for the morning brief. Every number comes from the facts array.
 * A missing source is named. It is not filled in.
 */
final class BriefText
{
    /**
     * @param array<string, mixed> $facts
     */
    public static function render(array $facts): string
    {
        $lines = [
            self::calendar($facts['calendar'] ?? []),
            self::digests($facts['digests'] ?? []),
            self::money($facts['money'] ?? []),
            self::home($facts['home'] ?? []),
            self::music($facts['music'] ?? []),
            self::healthSection($facts['health'] ?? []),
        ];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        return $lines === [] ? 'Nothing stored was ready for this brief.' : implode("\n", $lines);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function health(string $day, ?array $row, string $today): string
    {
        if ($row === null) {
            return 'Health: no vitals are stored for ' . $day . '.';
        }
        $bits = [];
        if ($row['sleep_minutes'] !== null) {
            $hours = round(((float) $row['sleep_minutes']) / 60, 2);
            $bits[] = 'sleep ' . self::num($hours) . ' h (' . (string) ($row['sleep_authority'] ?? 'unknown') . ')';
        }
        if ($row['hrv_ms'] !== null) {
            $bits[] = 'HRV ' . self::num((float) $row['hrv_ms']) . ' ms (' . (string) ($row['hrv_authority'] ?? 'unknown') . ')';
        }
        if ($row['steps'] !== null) {
            $bits[] = 'steps ' . self::num((float) $row['steps']) . ' (' . (string) ($row['steps_authority'] ?? 'unknown') . ')';
        }
        if ($row['resting_hr'] !== null) {
            $bits[] = 'resting heart rate ' . self::num((float) $row['resting_hr']) . ' bpm (' . (string) ($row['resting_authority'] ?? 'unknown') . ')';
        }
        $when = $day === $today ? 'today' : $day;
        if ($bits === []) {
            $line = 'Health for ' . $when . ': no sleep, HRV, steps, or resting heart rate is stored.';
        } else {
            $line = 'Health for ' . $when . ': ' . implode(', ', $bits) . '.';
        }
        if ($row['readiness_score'] !== null) {
            $used = isset($row['used']) && is_array($row['used']) ? implode(', ', $row['used']) : '';
            $line .= ' Readiness ' . (int) $row['readiness_score']
                . ' (confidence ' . self::num((float) $row['readiness_confidence']) . ')';
            $line .= $used !== '' ? ' from ' . $used . '.' : '.';
        } else {
            $line .= ' Readiness was not scored.';
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function calendar(array $facts): string
    {
        if (!empty($facts['connected'])) {
            return 'Calendar: connected, and no events were returned.';
        }

        return 'Calendar: no calendar is connected on this server.';
    }

    /**
     * @param mixed $items
     */
    private static function digests(mixed $items): string
    {
        if (!is_array($items) || $items === []) {
            return 'Digests: no entries are stored.';
        }
        $bits = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['title'])) {
                continue;
            }
            $bit = (string) $item['title'];
            if (isset($item['summary']) && is_string($item['summary']) && trim($item['summary']) !== '') {
                $bit .= ' — ' . trim($item['summary']);
            }
            $bits[] = $bit;
            if (count($bits) >= 3) {
                break;
            }
        }
        if ($bits === []) {
            return 'Digests: no entries are stored.';
        }

        return 'Digests: ' . implode(' ', $bits);
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function money(array $facts): string
    {
        if (($facts['omitted'] ?? '') === 'needs-grant') {
            return 'Money was left out: this key needs a money grant. Nothing was transferred.';
        }
        if (!empty($facts['error'])) {
            return 'Payday pressure did not load. Nothing was transferred.';
        }
        if (empty($facts['configured'])) {
            return 'Payday: not saved yet. Nothing was transferred.';
        }
        $line = 'Payday';
        if (!empty($facts['next_payday'])) {
            $line .= ' ' . (string) $facts['next_payday'];
        }
        $line .= ':';
        if (isset($facts['bill_count'])) {
            $line .= ' ' . (int) $facts['bill_count'] . ' bill' . ((int) $facts['bill_count'] === 1 ? '' : 's');
        }
        if (isset($facts['pulls_total'])) {
            $line .= ', bills ' . self::moneyNum((float) $facts['pulls_total']);
        }
        if (isset($facts['debt_total'])) {
            $line .= ', debt minimums ' . self::moneyNum((float) $facts['debt_total']);
        }
        if (array_key_exists('unassigned', $facts) && $facts['unassigned'] !== null) {
            $line .= ', unassigned ' . self::moneyNum((float) $facts['unassigned']);
        } else {
            $line .= ', unassigned was not calculated';
        }
        $names = [];
        foreach ($facts['bills'] ?? [] as $bill) {
            if (!is_array($bill) || empty($bill['name'])) {
                continue;
            }
            $names[] = (string) $bill['name'];
            if (count($names) >= 8) {
                break;
            }
        }
        if ($names !== []) {
            $line .= ' (' . implode(', ', $names) . ')';
        }

        return $line . '. Nothing was transferred.';
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function home(array $facts): string
    {
        if (($facts['omitted'] ?? '') === 'needs-grant') {
            return 'Home was left out: this key needs a home grant.';
        }
        if (empty($facts['configured'])) {
            return 'Home: Home Assistant is not connected.';
        }
        if (empty($facts['reachable'])) {
            $detail = isset($facts['detail']) ? (string) $facts['detail'] : 'Home Assistant did not answer.';

            return 'Home: ' . $detail;
        }
        $line = 'Home: Home Assistant answered';
        if (isset($facts['entity_count'])) {
            $line .= ', ' . (int) $facts['entity_count'] . ' entities';
        }
        $people = [];
        foreach ($facts['people'] ?? [] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $name = (string) ($person['name'] !== '' ? $person['name'] : ($person['entity_id'] ?? ''));
            $state = (string) ($person['state'] ?? '');
            if ($name === '') {
                continue;
            }
            $people[] = $state === '' ? $name : $name . ' ' . $state;
        }
        if ($people !== []) {
            $line .= '. People: ' . implode(', ', $people);
        }

        return $line . '.';
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function music(array $facts): string
    {
        if (!empty($facts['error'])) {
            return 'Music: Spotify did not answer.';
        }
        if (empty($facts['connected'])) {
            return 'Music: Spotify is not connected.';
        }
        if (empty($facts['playing']) || empty($facts['name'])) {
            return 'Music: nothing is playing.';
        }
        $line = 'Music: ' . (string) $facts['name'];
        if (!empty($facts['artists']) && is_array($facts['artists'])) {
            $names = [];
            foreach ($facts['artists'] as $artist) {
                if (is_string($artist) && $artist !== '') {
                    $names[] = $artist;
                }
            }
            if ($names !== []) {
                $line .= ' — ' . implode(', ', $names);
            }
        }

        return $line . '.';
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function healthSection(array $facts): string
    {
        if (($facts['omitted'] ?? '') === 'needs-grant') {
            return 'Health was left out: this key needs a health grant.';
        }
        if (empty($facts['configured']) && empty($facts['stored'])) {
            return 'Health: Open Wearables is not connected, and no vitals are stored.';
        }
        if (!empty($facts['text'])) {
            return (string) $facts['text'];
        }
        if (empty($facts['configured'])) {
            return 'Health: Open Wearables is not connected.';
        }

        return 'Health: Open Wearables is connected, and no vitals are stored yet.';
    }

    private static function num(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    private static function moneyNum(float $value): string
    {
        return '$' . number_format($value, 2, '.', '');
    }
}
