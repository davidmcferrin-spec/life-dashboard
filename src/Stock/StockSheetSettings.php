<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use Dashboard\CloudStorage\CredentialCrypto;
use Dashboard\DB\Database;
use Dashboard\Settings\SecretMask;
use Dashboard\Support\ValidationException;

/**
 * One user's spreadsheet id and service-account key.
 * Both stay encrypted. The key is never returned.
 */
final class StockSheetSettings implements StockSheetConfig
{
    public const PREF_KEY = 'stock_sheet';

    public const DEFAULT_SPREADSHEET_ID = '1Z00dgU3HUvIuXq4UPJeF0QPzoE8iWdx9AVWM7O92V9o';

    public function __construct(private readonly Database $db) {}

    public function save(int $userId, array $input): array
    {
        $current = $this->read($userId);
        $spreadsheetId = array_key_exists('spreadsheet_id', $input)
            ? self::spreadsheetId((string) $input['spreadsheet_id'])
            : (string) ($current['spreadsheet_id'] ?? self::DEFAULT_SPREADSHEET_ID);
        if ($spreadsheetId === '') {
            $spreadsheetId = self::DEFAULT_SPREADSHEET_ID;
        }
        $json = array_key_exists('service_account_json', $input) ? trim((string) $input['service_account_json']) : '';
        if ($json === '') {
            $account = $this->accountFrom($current);
        } else {
            $account = self::parseAccount($json);
        }
        $this->write($userId, [
            'spreadsheet_id' => $spreadsheetId,
            'client_email'   => (string) ($account['client_email'] ?? ''),
            'private_key'    => (string) ($account['private_key'] ?? ''),
        ]);

        return $this->view($userId);
    }

    public function view(int $userId): array
    {
        $stored = $this->read($userId);
        $id = trim((string) ($stored['spreadsheet_id'] ?? ''));
        $email = trim((string) ($stored['client_email'] ?? ''));
        // The domain tail is the same on every service account, so the hint uses the mailbox.
        $mailbox = strstr($email, '@', true);

        return [
            'spreadsheet_id' => $id !== '' ? $id : self::DEFAULT_SPREADSHEET_ID,
            'key_hint'       => $email !== '' ? SecretMask::hint(is_string($mailbox) && $mailbox !== '' ? $mailbox : $email) : '',
            'configured'     => $this->configured($stored),
        ];
    }

    public function credentials(int $userId): ?array
    {
        $stored = $this->read($userId);
        if (!$this->configured($stored)) {
            return null;
        }
        $id = trim((string) ($stored['spreadsheet_id'] ?? ''));

        return [
            'spreadsheet_id' => $id !== '' ? $id : self::DEFAULT_SPREADSHEET_ID,
            'account'        => [
                'client_email' => (string) $stored['client_email'],
                'private_key'  => (string) $stored['private_key'],
            ],
        ];
    }

    public function userIds(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT user_id FROM user_preferences WHERE pref_key = ? ORDER BY user_id ASC',
            [self::PREF_KEY]
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['user_id'];
        }

        return $ids;
    }

    public static function spreadsheetId(string $raw): string
    {
        $id = trim($raw);
        if ($id === '') {
            return self::DEFAULT_SPREADSHEET_ID;
        }
        if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $id, $match) === 1) {
            $id = $match[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{20,80}$/', $id) !== 1) {
            throw ValidationException::withFields(['spreadsheet_id' => 'Enter the spreadsheet id from the sheet URL.']);
        }

        return $id;
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    public static function parseAccount(string $json): array
    {
        $json = trim($json);
        if ($json === '' || strlen($json) > 20000) {
            throw ValidationException::withFields(['service_account_json' => 'Paste the service account JSON key.']);
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withFields(['service_account_json' => 'That is not a JSON key.']);
        }
        if (!is_array($data)) {
            throw ValidationException::withFields(['service_account_json' => 'That is not a JSON key.']);
        }
        $email = trim((string) ($data['client_email'] ?? ''));
        $key = (string) ($data['private_key'] ?? '');
        if ($email === '' || !str_contains($email, '@') || strlen($email) > 200) {
            throw ValidationException::withFields(['service_account_json' => 'The key needs a client_email.']);
        }
        if (!str_contains($key, 'PRIVATE KEY') || strlen($key) > 16000) {
            throw ValidationException::withFields(['service_account_json' => 'The key needs a private_key.']);
        }

        return [
            'client_email' => $email,
            'private_key'  => $key,
        ];
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function configured(array $stored): bool
    {
        return trim((string) ($stored['client_email'] ?? '')) !== ''
            && str_contains((string) ($stored['private_key'] ?? ''), 'PRIVATE KEY');
    }

    /**
     * @param array<string, mixed> $stored
     * @return array{client_email: string, private_key: string}
     */
    private function accountFrom(array $stored): array
    {
        if (!$this->configured($stored)) {
            return ['client_email' => '', 'private_key' => ''];
        }

        return [
            'client_email' => (string) $stored['client_email'],
            'private_key'  => (string) $stored['private_key'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::PREF_KEY]
        );
        if ($row === null || trim((string) $row['pref_value']) === '') {
            return [];
        }
        try {
            $decoded = CredentialCrypto::decryptJson((string) $row['pref_value']);
        } catch (\RuntimeException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $value
     */
    private function write(int $userId, array $value): void
    {
        $encrypted = CredentialCrypto::encryptJson($value);
        $existing = $this->db->fetchOne(
            'SELECT id FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::PREF_KEY]
        );
        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, ?, ?)',
                [$userId, self::PREF_KEY, $encrypted]
            );
            return;
        }
        $this->db->execute(
            'UPDATE user_preferences SET pref_value = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND pref_key = ?',
            [$encrypted, $userId, self::PREF_KEY]
        );
    }
}
