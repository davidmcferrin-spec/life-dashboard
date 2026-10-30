<?php

declare(strict_types=1);

namespace Dashboard\Digest;

use Dashboard\Support\ValidationException;

/** The slug is the filter key, so it is the folded label. */
final class DigestTopics
{
    public const MAX = 40;

    /**
     * @return array{slug: string, label: string}
     */
    public static function name(string $raw): array
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $length = mb_strlen($label, 'UTF-8');
        if ($label === '' || $length > self::MAX) {
            throw ValidationException::withFields(['name' => 'Topic must be 1 to 40 characters.']);
        }
        if (preg_match('/\p{L}|\p{N}/u', $label) !== 1) {
            throw ValidationException::withFields(['name' => 'Topic needs a letter or number.']);
        }

        return ['slug' => mb_strtolower($label, 'UTF-8'), 'label' => $label];
    }
}
