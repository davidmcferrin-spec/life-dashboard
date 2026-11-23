<?php

declare(strict_types=1);

namespace Dashboard\Stock;

/** A sheet read failed. The message is safe to store and show. */
final class SheetException extends \RuntimeException
{
    public static function make(string $message): self
    {
        return new self(self::safe($message));
    }

    public static function safe(string $message): string
    {
        $text = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[redacted]', $message) ?? $message;
        $text = preg_replace('/ya29\.[A-Za-z0-9._\-]+/', '[redacted]', $text) ?? $text;
        $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            $text = 'The sheet sync failed.';
        }

        return mb_substr($text, 0, 300);
    }
}
