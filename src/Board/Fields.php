<?php

declare(strict_types=1);

namespace Dashboard\Board;

use Dashboard\Handoff\HandoffInput;
use Dashboard\Support\ValidationException;

/** Shared field checks for the bot-batch rows. Status lists stay with each service. */
final class Fields
{
    public static function text(mixed $value, string $field, int $max, bool $required): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw ValidationException::withFields([$field => self::label($field) . ' is required.']);
            }

            return null;
        }
        if (!is_string($value)) {
            throw ValidationException::withFields([$field => self::label($field) . ' must be text.']);
        }
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            if ($required) {
                throw ValidationException::withFields([$field => self::label($field) . ' is required.']);
            }

            return null;
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withFields([$field => self::label($field) . " must be {$max} characters or less."]);
        }

        return $text;
    }

    /**
     * @param list<string> $allowed
     */
    public static function status(mixed $value, array $allowed, ?string $fallback = null): string
    {
        if ($value === null || $value === '') {
            if ($fallback !== null) {
                return $fallback;
            }
            throw ValidationException::withFields(['status' => 'Status must be ' . self::list($allowed) . '.']);
        }
        if (!is_string($value)) {
            throw ValidationException::withFields(['status' => 'Status must be ' . self::list($allowed) . '.']);
        }
        $status = strtolower(trim($value));
        if (!in_array($status, $allowed, true)) {
            throw ValidationException::withFields(['status' => 'Status must be ' . self::list($allowed) . '.']);
        }

        return $status;
    }

    public static function id(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (is_bool($value) || !is_int($value) || $value < 1) {
            throw ValidationException::withFields([$field => self::label($field) . ' must be a positive id.']);
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    public static function ids(mixed $value, string $field, int $max): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            throw ValidationException::withFields([$field => self::label($field) . ' must be a list of ids.']);
        }
        $out = [];
        foreach ($value as $item) {
            $id = self::id($item, $field);
            if ($id === null) {
                continue;
            }
            $out[$id] = $id;
            if (count($out) > $max) {
                throw ValidationException::withFields([$field => 'Use ' . $max . ' ids or fewer.']);
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    public static function headlines(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw ValidationException::withFields(['items' => 'Add at least one headline.']);
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['text'] ?? $item['headline'] ?? $item['title'] ?? null;
            }
            $line = self::text($item, 'items', 240, false);
            if ($line === null) {
                continue;
            }
            $out[] = $line;
            if (count($out) > 30) {
                throw ValidationException::withFields(['items' => 'Use 30 headlines or fewer.']);
            }
        }
        if ($out === []) {
            throw ValidationException::withFields(['items' => 'Add at least one headline.']);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function tags(mixed $value): array
    {
        return HandoffInput::tags($value);
    }

    /**
     * @param list<string> $allowed
     */
    private static function list(array $allowed): string
    {
        return implode(', ', $allowed);
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
