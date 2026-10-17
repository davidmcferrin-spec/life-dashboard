<?php

declare(strict_types=1);

namespace Dashboard\Calls;

use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

/**
 * Enums, phone display, and the rule that full account numbers and SSNs
 * never land in a column. A reference is stored as its last four digits.
 */
final class CallGuard
{
    public const DIRECTIONS = ['inbound', 'outbound'];

    public const CATEGORIES = ['work', 'personal', 'vip', 'debt_collector', 'spam', 'urgent'];

    public const URGENCIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = [
        'new', 'needs_return', 'returned', 'waiting', 'resolved', 'spam', 'ignored', 'escalated_to_penny',
    ];

    public const SOURCES = ['ai', 'manual', 'bland'];

    public const HANDLERS = ['claire', 'cos', 'david'];

    public const VERDICTS = ['unverified', 'likely_legit', 'unclear', 'suspicious', 'known_scam'];

    /** Statuses that still count as an open return when return_needed is set. */
    public const OPEN_RETURN = ['new', 'needs_return', 'waiting', 'escalated_to_penny'];

    public const CLOSED = ['returned', 'resolved', 'spam', 'ignored'];

    /**
     * @param list<string> $allowed
     */
    public static function enum(mixed $value, array $allowed, string $field, bool $required = true): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withFields([$field => 'Choose a ' . str_replace('_', ' ', $field) . '.']);
            }
            return null;
        }
        $text = strtolower(trim((string) $value));
        if (!in_array($text, $allowed, true)) {
            throw ValidationException::withFields([
                $field => ucfirst(str_replace('_', ' ', $field)) . ' must be one of: ' . implode(', ', $allowed) . '.',
            ]);
        }
        return $text;
    }

    public static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return (int) $value === 1;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Last 4 of a reference. A 9-digit value or a ###-##-#### shape is refused
     * rather than stored. Longer account numbers are reduced to the last 4.
     */
    public static function referenceLast4(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (self::isSsnShape($text)) {
            throw ValidationException::withFields([
                'reference_last4' => 'SSN-like values are not stored. Keep the last 4 digits of a reference number.',
            ]);
        }
        if (preg_match('/^[*xX•·.\-\s]*(\d{4})$/u', $text, $m) === 1) {
            return $m[1];
        }
        $digits = preg_replace('/\D/', '', $text) ?? '';
        if (strlen($digits) === 9) {
            throw ValidationException::withFields([
                'reference_last4' => 'SSN-like values are not stored. Keep the last 4 digits of a reference number.',
            ]);
        }
        if (strlen($digits) < 4) {
            throw ValidationException::withFields([
                'reference_last4' => 'Reference numbers are stored as the last 4 digits.',
            ]);
        }
        return substr($digits, -4);
    }

    public static function assertTextSafe(string $value, string $field): void
    {
        if (self::containsSsn($value)) {
            throw ValidationException::withFields([
                $field => 'SSN-like values are not stored.',
            ]);
        }
        $stripped = self::stripPhonesDatesAndMoney($value);
        if (preg_match('/\d{8,}/', $stripped) === 1) {
            throw ValidationException::withFields([
                $field => 'Full account numbers are not stored. Use the last 4 digits only.',
            ]);
        }
    }

    public static function text(mixed $value, string $field, int $max, bool $scan = true): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withFields([$field => "Keep {$field} under {$max} characters."]);
        }
        if ($scan) {
            self::assertTextSafe($text, $field);
        }
        return $text;
    }

    public static function phone(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (self::isSsnShape($text) || self::containsSsn($text)) {
            throw ValidationException::withFields([$field => 'SSN-like values are not stored.']);
        }
        $digits = preg_replace('/\D/', '', $text) ?? '';
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            throw ValidationException::withFields([$field => 'Use a phone number, 7 to 15 digits.']);
        }
        return (str_starts_with($text, '+') ? '+' : '') . $digits;
    }

    public static function formatPhone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '';
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $national = $digits;
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $national = substr($digits, 1);
        }
        if (strlen($national) === 10) {
            return sprintf('(%s) %s-%s', substr($national, 0, 3), substr($national, 3, 3), substr($national, 6));
        }
        return $phone;
    }

    public static function telHref(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 10) {
            return 'tel:+1' . $digits;
        }
        return 'tel:+' . $digits;
    }

    /** Digits used to match a repeat caller. Prefers the last 10 of a US number. */
    public static function matchDigits(mixed $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }
        return $digits;
    }

    public static function url(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 500 || preg_match('#\Ahttps?://#i', $text) !== 1) {
            throw ValidationException::withFields([$field => 'Use an http or https URL.']);
        }
        return $text;
    }

    public static function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Clock::normalizeDate(trim((string) $value));
        } catch (\InvalidArgumentException) {
            throw ValidationException::withFields([$field => 'Use a date (YYYY-MM-DD).']);
        }
    }

    public static function timestamp(mixed $value, string $field, Clock $clock): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw ValidationException::withFields([$field => 'Use a date or timestamp.']);
        }
        try {
            return $clock->utcString($clock->parse($text));
        } catch (\InvalidArgumentException) {
            throw ValidationException::withFields([$field => 'Use a date or timestamp.']);
        }
    }

    /**
     * @return list<string>
     */
    public static function phones(mixed $value, string $field): array
    {
        return self::stringList($value, $field, 12, 20, true);
    }

    /**
     * @return list<string>
     */
    public static function urls(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw ValidationException::withFields([$field => $field . ' must be a list.']);
        }
        $out = [];
        foreach (array_values($value) as $i => $item) {
            $url = self::url($item, $field);
            if ($url !== null) {
                $out[] = $url;
            }
            if ($i > 19) {
                throw ValidationException::withFields([$field => 'Keep the list to 20 URLs.']);
            }
        }
        return array_values(array_unique($out));
    }

    public static function isSsnShape(string $value): bool
    {
        $text = trim($value);
        return preg_match('/^\d{3}[-\s]\d{2}[-\s]\d{4}$/', $text) === 1
            || preg_match('/^\d{9}$/', $text) === 1;
    }

    public static function containsSsn(string $value): bool
    {
        return preg_match('/\b\d{3}-\d{2}-\d{4}\b/', $value) === 1
            || preg_match('/\b\d{3}\s\d{2}\s\d{4}\b/', $value) === 1;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function callColumns(array $current, array $body, Clock $clock, bool $creating, string $defaultSource): array
    {
        $row = $current;
        $set = static function (string $key, mixed $value) use (&$row, $body): void {
            if (array_key_exists($key, $body)) {
                $row[$key] = $value;
            }
        };

        if (array_key_exists('call_at', $body)) {
            $row['call_at'] = self::timestamp($body['call_at'], 'call_at', $clock);
        }
        $set('direction', self::enum($body['direction'] ?? $row['direction'] ?? null, self::DIRECTIONS, 'direction', $creating || array_key_exists('direction', $body)));
        if (!array_key_exists('direction', $body) && !$creating) {
            $row['direction'] = $current['direction'];
        }
        $set('phone', self::phone($body['phone'] ?? null, 'phone'));
        $set('callback_number', self::phone($body['callback_number'] ?? null, 'callback_number'));
        $set('name', self::text($body['name'] ?? null, 'name', 160));
        $set('company', self::text($body['company'] ?? null, 'company', 160));
        if (array_key_exists('category', $body) || $creating) {
            $row['category'] = self::enum($body['category'] ?? $row['category'] ?? 'personal', self::CATEGORIES, 'category');
        }
        $set('reason', self::text($body['reason'] ?? null, 'reason', 240));
        $set('summary', self::text($body['summary'] ?? null, 'summary', 4000));
        $set('notes', self::text($body['notes'] ?? null, 'notes', 8000));
        if (array_key_exists('urgency', $body) || $creating) {
            $row['urgency'] = self::enum($body['urgency'] ?? $row['urgency'] ?? 'normal', self::URGENCIES, 'urgency');
        }
        $set('deadline', self::date($body['deadline'] ?? null, 'deadline'));
        if (array_key_exists('return_needed', $body)) {
            $row['return_needed'] = self::flag($body['return_needed']) ? 1 : 0;
        }
        if (array_key_exists('status', $body) || $creating) {
            $row['status'] = self::enum($body['status'] ?? $row['status'] ?? 'new', self::STATUSES, 'status');
        }
        if (array_key_exists('duration_sec', $body)) {
            $row['duration_sec'] = self::duration($body['duration_sec']);
        }
        $set('transcript_url', self::url($body['transcript_url'] ?? null, 'transcript_url'));
        $set('recording_url', self::url($body['recording_url'] ?? null, 'recording_url'));
        $set('transcript_text', self::text($body['transcript_text'] ?? null, 'transcript_text', 20000));
        if (array_key_exists('has_recording', $body)) {
            $row['has_recording'] = self::flag($body['has_recording']) ? 1 : 0;
        } elseif ($creating && !empty($row['recording_url'])) {
            $row['has_recording'] = 1;
        }
        if (array_key_exists('source', $body) || $creating) {
            $row['source'] = self::enum($body['source'] ?? $defaultSource, self::SOURCES, 'source');
        }
        if (array_key_exists('source_call_id', $body)) {
            $row['source_call_id'] = self::sourceCallId($body['source_call_id']);
        }
        if (array_key_exists('linked_todo_id', $body)) {
            $row['linked_todo_id'] = self::optionalId($body['linked_todo_id'], 'linked_todo_id');
        }
        if (array_key_exists('contact_id', $body)) {
            $row['contact_id'] = self::optionalId($body['contact_id'], 'contact_id');
        }
        if (array_key_exists('handled_by', $body)) {
            $row['handled_by'] = self::enum($body['handled_by'], self::HANDLERS, 'handled_by', false);
        }
        if (array_key_exists('notified_david', $body)) {
            $row['notified_david'] = self::flag($body['notified_david']) ? 1 : 0;
        }
        if (array_key_exists('notified_at', $body)) {
            $row['notified_at'] = ($body['notified_at'] === null || $body['notified_at'] === '')
                ? null
                : self::timestamp($body['notified_at'], 'notified_at', $clock);
        }
        if ((int) ($row['notified_david'] ?? 0) === 1 && empty($row['notified_at'])) {
            $row['notified_at'] = $clock->utcString($clock->now());
        }
        if ((int) ($row['notified_david'] ?? 0) === 0 && array_key_exists('notified_david', $body) && !array_key_exists('notified_at', $body)) {
            $row['notified_at'] = null;
        }
        if ($creating && !array_key_exists('status', $body) && (int) $row['return_needed'] === 1) {
            $row['status'] = 'needs_return';
        }
        if (
            array_key_exists('status', $body)
            && in_array($row['status'], self::CLOSED, true)
            && !array_key_exists('return_needed', $body)
        ) {
            $row['return_needed'] = 0;
        }
        if ($row['direction'] === null || $row['direction'] === '') {
            throw ValidationException::withFields(['direction' => 'Direction must be inbound or outbound.']);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function debtColumns(array $current, array $body, Clock $clock): array
    {
        $row = $current;
        $set = static function (string $key, mixed $value) use (&$row, $body): void {
            if (array_key_exists($key, $body)) {
                $row[$key] = $value;
            }
        };
        $set('collector_name', self::text($body['collector_name'] ?? null, 'collector_name', 160));
        $set('agency', self::text($body['agency'] ?? null, 'agency', 160));
        $set('agency_address', self::text($body['agency_address'] ?? null, 'agency_address', 300));
        $set('agency_phone', self::phone($body['agency_phone'] ?? null, 'agency_phone'));
        $set('website', self::url($body['website'] ?? null, 'website'));
        $set('original_creditor', self::text($body['original_creditor'] ?? null, 'original_creditor', 160));
        if (array_key_exists('reference_last4', $body)) {
            $row['reference_last4'] = self::referenceLast4($body['reference_last4']);
        }
        if (array_key_exists('amount_claimed', $body)) {
            $row['amount_claimed'] = self::amount($body['amount_claimed']);
        }
        $set('debt_date', self::date($body['debt_date'] ?? null, 'debt_date'));
        if (array_key_exists('validation_requested', $body)) {
            $row['validation_requested'] = self::flag($body['validation_requested']) ? 1 : 0;
        }
        if (array_key_exists('verdict', $body)) {
            $row['verdict'] = self::enum($body['verdict'] ?? 'unverified', self::VERDICTS, 'verdict');
        }
        $set('scam_reasons', self::text($body['scam_reasons'] ?? null, 'scam_reasons', 4000));
        $set('verification_notes', self::text($body['verification_notes'] ?? null, 'verification_notes', 4000));
        $set('cfpb_complaints', self::text($body['cfpb_complaints'] ?? null, 'cfpb_complaints', 2000));
        $set('state_license', self::text($body['state_license'] ?? null, 'state_license', 80));
        $set('ag_ftc_warnings', self::text($body['ag_ftc_warnings'] ?? null, 'ag_ftc_warnings', 2000));
        if (array_key_exists('evidence_urls', $body)) {
            $row['evidence_urls'] = self::urls($body['evidence_urls'], 'evidence_urls');
        }
        if (array_key_exists('vetted_at', $body)) {
            $row['vetted_at'] = ($body['vetted_at'] === null || $body['vetted_at'] === '')
                ? null
                : self::timestamp($body['vetted_at'], 'vetted_at', $clock);
        }
        if (array_key_exists('referred_to_penny', $body)) {
            $row['referred_to_penny'] = self::flag($body['referred_to_penny']) ? 1 : 0;
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function blankDebt(): array
    {
        return [
            'collector_name'       => null,
            'agency'                => null,
            'agency_address'        => null,
            'agency_phone'          => null,
            'website'               => null,
            'original_creditor'     => null,
            'reference_last4'       => null,
            'amount_claimed'        => null,
            'debt_date'             => null,
            'validation_requested'  => 0,
            'verdict'               => 'unverified',
            'scam_reasons'          => null,
            'verification_notes'    => null,
            'cfpb_complaints'       => null,
            'state_license'         => null,
            'ag_ftc_warnings'       => null,
            'evidence_urls'         => [],
            'vetted_at'             => null,
            'referred_to_penny'     => 0,
        ];
    }

    private static function duration(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 86400) {
            throw ValidationException::withFields(['duration_sec' => 'Duration is seconds from 0 to 86400.']);
        }
        return (int) $value;
    }

    private static function amount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 9999999999) {
            throw ValidationException::withFields(['amount_claimed' => 'Amount claimed must be a positive number.']);
        }
        return number_format((float) $value, 2, '.', '');
    }

    private static function sourceCallId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 128 || preg_match('/\s/', $text) === 1) {
            throw ValidationException::withFields(['source_call_id' => 'source_call_id is a single id up to 128 characters.']);
        }
        return $text;
    }

    private static function optionalId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw ValidationException::withFields([$field => 'Use a positive id, or empty to clear it.']);
        }
        return $id;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $field, int $itemMax, int $maxItems, bool $asPhone): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw ValidationException::withFields([$field => $field . ' must be a list.']);
        }
        if (count($value) > $maxItems) {
            throw ValidationException::withFields([$field => "Keep the list to {$maxItems} entries."]);
        }
        $out = [];
        foreach ($value as $item) {
            $text = $asPhone
                ? self::phone($item, $field)
                : self::text($item, $field, $itemMax);
            if ($text !== null) {
                $out[] = $text;
            }
        }
        return array_values(array_unique($out));
    }

    private static function stripPhonesDatesAndMoney(string $value): string
    {
        $value = preg_replace('/(?<!\d)\+?1?[\s.\-]?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}\b(?!\d)/', ' ', $value) ?? $value;
        $value = preg_replace('/(?<!\d)\d{10,11}(?!\d)/', ' ', $value) ?? $value;
        $value = preg_replace('/\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?/', ' ', $value) ?? $value;
        $value = preg_replace('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[$]?\d{1,3}(?:,\d{3})+(?:\.\d+)?/', ' ', $value) ?? $value;
        $value = preg_replace('/[$]?\d+\.\d+/', ' ', $value) ?? $value;
        $value = preg_replace('/[*xX•·]{2,}\d{4}/u', ' ', $value) ?? $value;
        return $value;
    }
}
