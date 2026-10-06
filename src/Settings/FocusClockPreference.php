<?php

declare(strict_types=1);

namespace Dashboard\Settings;

final class FocusClockPreference
{
    public const KEY = 'focus_clock';

    public const SCALE_MIN = 50;
    public const SCALE_MAX = 150;
    public const SCALE_DEFAULT = 100;

    /** @var list<string> */
    public const FONTS = ['dseg7', 'inconsolata', 'space_mono', 'ibm_plex_mono', 'source_code_pro'];

    /** @var list<string> */
    public const DATE_POSITIONS = ['above', 'below', 'hidden'];

    /**
     * @return array<string, string> IANA id => label
     */
    public static function timezoneOptions(): array
    {
        return [
            'America/New_York'    => 'Eastern (ET)',
            'America/Chicago'     => 'Central (CT)',
            'America/Denver'      => 'Mountain (MT)',
            'America/Los_Angeles' => 'Pacific (PT)',
            'UTC'                 => 'UTC',
            'America/Anchorage'   => 'Alaska',
            'Pacific/Honolulu'    => 'Hawaii',
            'Europe/London'       => 'London',
            'Europe/Paris'        => 'Paris',
            'Asia/Tokyo'          => 'Tokyo',
            'Australia/Sydney'    => 'Sydney',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(string $dashboardClockFormat = '12h'): array
    {
        return [
            'timezone'            => 'America/Chicago',
            'timezone_auto'       => false,
            'format'              => in_array($dashboardClockFormat, PreferenceService::CLOCK_FORMATS, true)
                ? $dashboardClockFormat
                : '12h',
            'font'                => 'dseg7',
            'time_color'          => '#39ff14',
            'date_color'          => '#64748b',
            'colon_blink'         => true,
            'date_position'       => 'below',
            'show_broadcast_week' => false,
            'show_seconds'        => true,
            'scale_percent'       => self::SCALE_DEFAULT,
            'alarm'               => [
                'enabled' => false,
                'time'    => '07:00',
                'days'    => [1, 2, 3, 4, 5],
                'sound'   => 'builtin',
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $stored
     * @return array<string, mixed>
     */
    public static function merge(?array $stored, string $dashboardClockFormat = '12h'): array
    {
        $out = self::defaults($dashboardClockFormat);

        if ($stored === null) {
            return $out;
        }

        $tzOpts = self::timezoneOptions();
        if (isset($stored['timezone']) && is_string($stored['timezone']) && isset($tzOpts[$stored['timezone']])) {
            $out['timezone'] = $stored['timezone'];
        }

        if (array_key_exists('timezone_auto', $stored)) {
            $out['timezone_auto'] = (bool) $stored['timezone_auto'];
        }

        if (isset($stored['format']) && in_array($stored['format'], PreferenceService::CLOCK_FORMATS, true)) {
            $out['format'] = $stored['format'];
        }

        if (isset($stored['font']) && in_array($stored['font'], self::FONTS, true)) {
            $out['font'] = $stored['font'];
        }

        if (isset($stored['time_color']) && self::isHexColor($stored['time_color'])) {
            $out['time_color'] = strtolower((string) $stored['time_color']);
        }

        if (isset($stored['date_color']) && self::isHexColor($stored['date_color'])) {
            $out['date_color'] = strtolower((string) $stored['date_color']);
        }

        if (array_key_exists('colon_blink', $stored)) {
            $out['colon_blink'] = (bool) $stored['colon_blink'];
        }

        if (isset($stored['date_position']) && in_array($stored['date_position'], self::DATE_POSITIONS, true)) {
            $out['date_position'] = $stored['date_position'];
        }

        if (array_key_exists('show_broadcast_week', $stored)) {
            $out['show_broadcast_week'] = (bool) $stored['show_broadcast_week'];
        }

        if (array_key_exists('show_seconds', $stored)) {
            $out['show_seconds'] = (bool) $stored['show_seconds'];
        }

        if (isset($stored['scale_percent'])) {
            $out['scale_percent'] = max(
                self::SCALE_MIN,
                min(self::SCALE_MAX, (int) $stored['scale_percent']),
            );
        }

        if (isset($stored['alarm']) && is_array($stored['alarm'])) {
            $out['alarm'] = self::mergeAlarm($out['alarm'], $stored['alarm']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private static function mergeAlarm(array $defaults, array $stored): array
    {
        $out = $defaults;

        if (array_key_exists('enabled', $stored)) {
            $out['enabled'] = (bool) $stored['enabled'];
        }

        if (isset($stored['time']) && is_string($stored['time']) && self::isAlarmTime($stored['time'])) {
            $out['time'] = $stored['time'];
        }

        if (isset($stored['days']) && is_array($stored['days'])) {
            $days = [];
            foreach ($stored['days'] as $d) {
                $n = (int) $d;
                if ($n >= 0 && $n <= 6 && !in_array($n, $days, true)) {
                    $days[] = $n;
                }
            }
            sort($days);
            if ($days !== []) {
                $out['days'] = $days;
            }
        }

        if (isset($stored['sound']) && is_string($stored['sound'])) {
            $sound = $stored['sound'];
            if ($sound === 'custom') {
                $out['sound'] = 'mine';
            } elseif (in_array($sound, ['builtin', 'mine'], true)) {
                $out['sound'] = $sound;
            } elseif (preg_match('/^library:[a-f0-9]{32}$/i', $sound)) {
                $out['sound'] = 'library:' . strtolower(substr($sound, 8));
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function fromPost(array $post, string $dashboardClockFormat = '12h'): array
    {
        $stored = [
            'timezone'            => (string) ($post['focus_clock_timezone'] ?? ''),
            'timezone_auto'       => isset($post['focus_clock_timezone_auto']),
            'format'              => (string) ($post['focus_clock_format'] ?? ''),
            'font'                => (string) ($post['focus_clock_font'] ?? ''),
            'time_color'          => (string) ($post['focus_clock_time_color'] ?? ''),
            'date_color'          => (string) ($post['focus_clock_date_color'] ?? ''),
            'colon_blink'         => isset($post['focus_clock_colon_blink']),
            'date_position'       => (string) ($post['focus_clock_date_position'] ?? ''),
            'show_broadcast_week' => isset($post['focus_clock_show_broadcast_week']),
            'show_seconds'        => isset($post['focus_clock_show_seconds']),
            'scale_percent'       => (int) ($post['focus_clock_scale'] ?? self::SCALE_DEFAULT),
            'alarm'               => [
                'enabled' => isset($post['focus_clock_alarm_enabled']),
                'time'    => (string) ($post['focus_clock_alarm_time'] ?? '07:00'),
                'sound'   => (string) ($post['focus_clock_alarm_sound'] ?? 'builtin'),
                'days'    => [],
            ],
        ];

        for ($d = 0; $d <= 6; $d++) {
            if (isset($post['focus_clock_alarm_day_' . $d])) {
                $stored['alarm']['days'][] = $d;
            }
        }

        return self::merge($stored, $dashboardClockFormat);
    }

    public static function isHexColor(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
    }

    public static function isAlarmTime(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    /**
     * @param array<string, mixed> $prefs
     */
    public static function encode(array $prefs): string
    {
        return json_encode($prefs, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
