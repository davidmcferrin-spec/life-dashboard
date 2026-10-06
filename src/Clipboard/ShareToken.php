<?php

declare(strict_types=1);

namespace Dashboard\Clipboard;

/**
 * Short, human-typable clipboard share tokens (12 chars, no ambiguous letters).
 */
final class ShareToken
{
    public const LENGTH = 12;

    /** Lowercase; excludes 0/o, 1/l/i for easier typing */
    private const CHARS = '23456789abcdefghjkmnpqrstuvwxyz';

    public static function generate(ClipboardShareRepository $shares): string
    {
        return self::generateUnique(fn (string $token): bool => $shares->tokenExists($token));
    }

    public static function generateUnique(callable $tokenExists): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $token = self::random();
            if (!$tokenExists($token)) {
                return $token;
            }
        }

        throw new \RuntimeException('Could not generate a unique share token.');
    }

    public static function normalize(string $token): string
    {
        return strtolower(trim($token));
    }

    public static function isValid(string $token): bool
    {
        $token = self::normalize($token);

        if (strlen($token) !== self::LENGTH) {
            return false;
        }

        return preg_match('/^[' . preg_quote(self::CHARS, '/') . ']+$/', $token) === 1;
    }

    private static function random(): string
    {
        $max   = strlen(self::CHARS) - 1;
        $token = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $token .= self::CHARS[random_int(0, $max)];
        }

        return $token;
    }
}
