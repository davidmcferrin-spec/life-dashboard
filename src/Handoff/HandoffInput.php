<?php

declare(strict_types=1);

namespace Dashboard\Handoff;

use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

/**
 * Field checks for a handoff. The stored sender is not taken from this input.
 */
final class HandoffInput
{
    /** @var list<string> */
    public const STATUSES = ['open', 'claimed', 'in_progress', 'blocked', 'done', 'cancelled'];

    /** @var list<string> */
    public const OPEN = ['open', 'claimed', 'in_progress', 'blocked'];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'normal', 'high'];

    /** @var list<string> */
    public const CLOSE = ['done', 'cancelled'];

    public static function title(mixed $value): string
    {
        if (!is_string($value)) {
            throw ValidationException::withFields(['title' => 'Title is required.']);
        }
        $title = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        if ($title === '') {
            throw ValidationException::withFields(['title' => 'Title is required.']);
        }
        if (mb_strlen($title) > 200) {
            throw ValidationException::withFields(['title' => 'Title must be 200 characters or less.']);
        }

        return $title;
    }

    public static function note(mixed $value, int $max, string $field, bool $required): string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withFields([$field => 'Write a note.']);
            }

            return '';
        }
        if (!is_string($value)) {
            throw ValidationException::withFields([$field => 'That note must be text.']);
        }
        $text = str_replace("\0", '', $value);
        if (mb_strlen($text) > $max) {
            throw ValidationException::withFields([$field => 'That note is too long.']);
        }

        return $text;
    }

    public static function status(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::STATUSES, true)) {
            throw ValidationException::withFields(['status' => 'Status must be open, claimed, in_progress, blocked, done, or cancelled.']);
        }

        return $value;
    }

    public static function closeStatus(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'done';
        }
        if (!is_string($value) || !in_array($value, self::CLOSE, true)) {
            throw ValidationException::withFields(['status' => 'Close as done or cancelled.']);
        }

        return $value;
    }

    public static function priority(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'normal';
        }
        if (!is_string($value) || !in_array($value, self::PRIORITIES, true)) {
            throw ValidationException::withFields(['priority' => 'Priority must be low, normal, or high.']);
        }

        return $value;
    }

    public static function agent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw ValidationException::withFields(['to_agent' => 'Agent name must be text.']);
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return null;
        }
        if (mb_strlen($name) > 60) {
            throw ValidationException::withFields(['to_agent' => 'Agent name must be 60 characters or less.']);
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    public static function tags(mixed $value): array
    {
        $items = self::list($value, 'tags');
        $out = [];
        foreach ($items as $tag) {
            if (!is_string($tag)) {
                throw ValidationException::withFields(['tags' => 'Each tag must be text.']);
            }
            $tag = strtolower(trim($tag));
            if ($tag === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $tag) !== 1) {
                throw ValidationException::withFields(['tags' => 'Tags use letters, numbers, hyphens, and underscores.']);
            }
            $out[$tag] = $tag;
            if (count($out) > 12) {
                throw ValidationException::withFields(['tags' => 'Use 12 tags or fewer.']);
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    public static function urls(mixed $value): array
    {
        $items = self::list($value, 'linked_urls');
        $out = [];
        foreach ($items as $url) {
            if (!is_string($url)) {
                throw ValidationException::withFields(['linked_urls' => 'Each link must be text.']);
            }
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withFields(['linked_urls' => 'Links must be http or https.']);
            }
            if (strlen($url) > 500) {
                throw ValidationException::withFields(['linked_urls' => 'A link must be 500 characters or less.']);
            }
            $out[$url] = $url;
            if (count($out) > 20) {
                throw ValidationException::withFields(['linked_urls' => 'Use 20 links or fewer.']);
            }
        }

        return array_values($out);
    }

    /**
     * @return list<int>
     */
    public static function pageIds(mixed $value): array
    {
        $items = self::list($value, 'linked_page_ids');
        $out = [];
        foreach ($items as $id) {
            if (is_string($id) && preg_match('/^\d+$/', $id) === 1) {
                $id = (int) $id;
            }
            if (is_bool($id) || !is_int($id) || $id < 1) {
                throw ValidationException::withFields(['linked_page_ids' => 'Page ids must be positive numbers.']);
            }
            $out[$id] = $id;
            if (count($out) > 20) {
                throw ValidationException::withFields(['linked_page_ids' => 'Use 20 pages or fewer.']);
            }
        }

        return array_values($out);
    }

    /** A date alone is the end of that local day, so it is not overdue until the day is over. */
    public static function due(Clock $clock, mixed $value): ?string
    {
        return self::moment($clock, $value, 'due_at', true);
    }

    public static function since(Clock $clock, mixed $value): ?string
    {
        return self::moment($clock, $value, 'updated_since', false);
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $field): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_string($value)) {
            $split = preg_split('/\s*,\s*/', $value);

            return is_array($split) ? $split : [];
        }
        if (!is_array($value)) {
            throw ValidationException::withFields([$field => 'That field must be a list.']);
        }

        return $value;
    }

    private static function moment(Clock $clock, mixed $value, string $field, bool $endOfDay): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw ValidationException::withFields([$field => 'Use a date or a timestamp.']);
        }
        $raw = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $raw .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        }
        try {
            $parsed = $clock->parse($raw);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withFields([$field => 'Use a date or a timestamp.']);
        }

        return $clock->utcString($parsed);
    }
}
