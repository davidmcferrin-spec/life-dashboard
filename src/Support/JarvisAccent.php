<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * JARVIS HUD accent. One stored #rrggbb drives every glow, edge, and gauge
 * through CSS variables (brighter, dimmer, ink, and rgb channels).
 */
final class JarvisAccent
{
    public const DEFAULT = '#00e5ff';

    public static function isValid(string $raw): bool
    {
        return (bool) preg_match('/^#?[0-9a-fA-F]{6}$/', trim($raw));
    }

    public static function normalize(?string $raw): string
    {
        $raw = strtolower(trim((string) $raw));
        if (preg_match('/^#([0-9a-f]{6})$/', $raw, $m) === 1) {
            return '#' . $m[1];
        }
        if (preg_match('/^([0-9a-f]{6})$/', $raw, $m) === 1) {
            return '#' . $m[1];
        }

        return self::DEFAULT;
    }

    /**
     * @return array{r: int, g: int, b: int}
     */
    public static function rgb(string $hex): array
    {
        $hex = self::normalize($hex);

        return [
            'r' => hexdec(substr($hex, 1, 2)),
            'g' => hexdec(substr($hex, 3, 2)),
            'b' => hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * @param array{r: int, g: int, b: int} $rgb
     */
    public static function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb['r'], $rgb['g'], $rgb['b']);
    }

    /**
     * @param array{r: int, g: int, b: int} $rgb
     * @param array{r: int, g: int, b: int} $toward
     * @return array{r: int, g: int, b: int}
     */
    public static function mix(array $rgb, array $toward, float $amount): array
    {
        $amount = max(0.0, min(1.0, $amount));
        $out = [];
        foreach (['r', 'g', 'b'] as $channel) {
            $out[$channel] = (int) round($rgb[$channel] + ($toward[$channel] - $rgb[$channel]) * $amount);
        }

        return $out;
    }

    public static function luminance(string $hex): float
    {
        $rgb = self::rgb($hex);
        $lin = static function (int $channel): float {
            $s = $channel / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin($rgb['r']) + 0.7152 * $lin($rgb['g']) + 0.0722 * $lin($rgb['b']);
    }

    /**
     * @return array{accent: string, rgb: string, bright: string, dim: string, ink: string}
     */
    public static function variants(string $hex): array
    {
        $accent = self::normalize($hex);
        $rgb = self::rgb($accent);
        $bright = self::mix($rgb, ['r' => 255, 'g' => 255, 'b' => 255], 0.32);
        $dim = self::mix($rgb, ['r' => 4, 'g' => 12, 'b' => 24], 0.45);
        $ink = self::luminance($accent) > 0.55 ? '#061018' : '#f4fbff';

        return [
            'accent' => $accent,
            'rgb'    => $rgb['r'] . ',' . $rgb['g'] . ',' . $rgb['b'],
            'bright' => self::toHex($bright),
            'dim'    => self::toHex($dim),
            'ink'    => $ink,
        ];
    }

    /** Inline style declaration. Only hex and integers, safe to print unescaped into a style attribute after e(). */
    public static function cssDeclaration(string $hex): string
    {
        $v = self::variants($hex);

        return '--hud-accent:' . $v['accent']
            . ';--hud-rgb:' . $v['rgb']
            . ';--hud-bright:' . $v['bright']
            . ';--hud-dim:' . $v['dim']
            . ';--hud-ink:' . $v['ink'] . ';';
    }
}
