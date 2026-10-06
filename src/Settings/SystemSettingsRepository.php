<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\CloudStorage\CredentialCrypto;
use Dashboard\DB\Database;

/**
 * Encrypted key/value settings stored in SQLite (admin-managed).
 */
final class SystemSettingsRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getJson(string $key): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT value_encrypted FROM system_settings WHERE setting_key = ?',
            [$key],
        );

        if ($row === null || ($row['value_encrypted'] ?? '') === '') {
            return null;
        }

        return CredentialCrypto::decryptJson((string) $row['value_encrypted']);
    }

    /**
     * @param array<string, mixed> $value
     */
    public function setJson(string $key, array $value, ?int $updatedByUserId): void
    {
        $encrypted = CredentialCrypto::encryptJson($value);
        $existing  = $this->db->fetchOne(
            'SELECT id FROM system_settings WHERE setting_key = ?',
            [$key],
        );

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO system_settings (setting_key, value_encrypted, updated_by_user_id) VALUES (?, ?, ?)',
                [$key, $encrypted, $updatedByUserId],
            );

            return;
        }

        $this->db->execute(
            'UPDATE system_settings
             SET value_encrypted = ?, updated_at = CURRENT_TIMESTAMP, updated_by_user_id = ?
             WHERE setting_key = ?',
            [$encrypted, $updatedByUserId, $key],
        );
    }

    public function delete(string $key): void
    {
        $this->db->execute('DELETE FROM system_settings WHERE setting_key = ?', [$key]);
    }
}
