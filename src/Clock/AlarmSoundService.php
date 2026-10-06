<?php

declare(strict_types=1);

namespace Dashboard\Clock;

use Dashboard\DB\Database;

final class AlarmSoundService
{
    public function __construct(private readonly Database $db) {}

    public function hasPersonal(int $userId): bool
    {
        return AlarmSoundStorage::hasUserSound($userId);
    }

    public function sharedExists(string $id): bool
    {
        if (!AlarmSoundStorage::isValidSharedId($id)) {
            return false;
        }

        return $this->db->fetchOne(
            'SELECT id FROM alarm_sounds WHERE id = ?',
            [strtolower($id)],
        ) !== null;
    }

    /**
     * @return list<array{id: string, label: string, scope: string, uploaded_by?: string}>
     */
    public function listOptions(int $userId): array
    {
        $options = [
            ['id' => 'builtin', 'label' => 'Built-in tone', 'scope' => 'builtin'],
        ];

        $rows = $this->db->fetchAll(
            'SELECT s.id, s.label, u.username
             FROM alarm_sounds s
             INNER JOIN users u ON u.id = s.uploaded_by
             ORDER BY s.created_at DESC',
        );

        foreach ($rows as $row) {
            $options[] = [
                'id'           => 'library:' . (string) $row['id'],
                'label'        => (string) $row['label'],
                'scope'        => 'shared',
                'uploaded_by'  => (string) $row['username'],
            ];
        }

        if ($this->hasPersonal($userId)) {
            $options[] = [
                'id'    => 'mine',
                'label' => 'My personal upload',
                'scope' => 'mine',
            ];
        }

        return $options;
    }

    public function normalizeSoundKey(string $sound, int $userId): string
    {
        if ($sound === 'custom') {
            $sound = 'mine';
        }

        if ($sound === 'builtin') {
            return 'builtin';
        }

        if ($sound === 'mine') {
            return $this->hasPersonal($userId) ? 'mine' : 'builtin';
        }

        if (str_starts_with($sound, 'library:')) {
            $id = substr($sound, 8);

            return $this->sharedExists($id) ? ('library:' . strtolower($id)) : 'builtin';
        }

        return 'builtin';
    }

    public function streamUrlForSound(string $sound): ?string
    {
        if ($sound === 'builtin') {
            return null;
        }

        if ($sound === 'custom' || $sound === 'mine') {
            return '/api/clock/alarm-sound/mine';
        }

        if (str_starts_with($sound, 'library:')) {
            $id = substr($sound, 8);
            if (!AlarmSoundStorage::isValidSharedId($id)) {
                return null;
            }

            return '/api/clock/alarm-sound/' . strtolower($id);
        }

        return null;
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public function streamMetaForSound(string $sound, int $userId): ?array
    {
        if ($sound === 'custom' || $sound === 'mine') {
            $path = AlarmSoundStorage::userPathForUser($userId);

            return $path !== null ? AlarmSoundStorage::metaForPath($path) : null;
        }

        if (str_starts_with($sound, 'library:')) {
            return $this->streamMetaForSharedId(substr($sound, 8));
        }

        return null;
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public function streamMetaForSharedId(string $id): ?array
    {
        if (!AlarmSoundStorage::isValidSharedId($id)) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT file_ext FROM alarm_sounds WHERE id = ?',
            [strtolower($id)],
        );

        if ($row === null) {
            return null;
        }

        $path = AlarmSoundStorage::sharedPath(strtolower($id), (string) $row['file_ext']);

        return AlarmSoundStorage::metaForPath($path);
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array{id: string, label: string}
     */
    public function uploadShared(int $userId, array $file, ?string $label = null): array
    {
        $id = AlarmSoundStorage::generateId();
        $ext = AlarmSoundStorage::storeSharedUpload($id, $file);
        $label = $label !== null && trim($label) !== ''
            ? mb_substr(trim($label), 0, 128)
            : AlarmSoundStorage::labelFromFilename((string) ($file['name'] ?? 'Alarm sound'));

        $this->db->execute(
            'INSERT INTO alarm_sounds (id, label, file_ext, uploaded_by) VALUES (?, ?, ?, ?)',
            [$id, $label, $ext, $userId],
        );

        return ['id' => 'library:' . $id, 'label' => $label];
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     */
    public function uploadPersonal(int $userId, array $file): void
    {
        AlarmSoundStorage::storeUserUpload($userId, $file);
    }

    public function deletePersonal(int $userId): void
    {
        AlarmSoundStorage::deleteUserFiles($userId);
    }

    public function deleteShared(string $soundId, int $userId, bool $isAdmin): void
    {
        $id = str_starts_with($soundId, 'library:') ? substr($soundId, 8) : $soundId;
        if (!AlarmSoundStorage::isValidSharedId($id)) {
            throw new \InvalidArgumentException('Invalid sound id.');
        }

        $id = strtolower($id);
        $row = $this->db->fetchOne(
            'SELECT uploaded_by, file_ext FROM alarm_sounds WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return;
        }

        if (!$isAdmin && (int) $row['uploaded_by'] !== $userId) {
            throw new \InvalidArgumentException('You can only delete sounds you uploaded.');
        }

        AlarmSoundStorage::deleteSharedFile($id, (string) $row['file_ext']);
        $this->db->execute('DELETE FROM alarm_sounds WHERE id = ?', [$id]);
        $this->resetPrefsUsingSharedSound('library:' . $id);
    }

    private function resetPrefsUsingSharedSound(string $soundKey): void
    {
        $rows = $this->db->fetchAll(
            "SELECT user_id, pref_value FROM user_preferences WHERE pref_key = 'focus_clock'",
        );

        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['pref_value'] ?? ''), true);
            if (!is_array($decoded) || !isset($decoded['alarm']['sound'])) {
                continue;
            }
            if ($decoded['alarm']['sound'] !== $soundKey) {
                continue;
            }
            $decoded['alarm']['sound'] = 'builtin';
            $this->db->execute(
                'UPDATE user_preferences SET pref_value = ? WHERE user_id = ? AND pref_key = ?',
                [json_encode($decoded, JSON_THROW_ON_ERROR), (int) $row['user_id'], 'focus_clock'],
            );
        }
    }

    public function migrateLegacyStorage(): void
    {
        AlarmSoundStorage::ensureDirectories();
        $base = AlarmSoundStorage::baseDirectory();

        if (!is_dir($base)) {
            return;
        }

        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'shared' || $entry === 'user') {
                continue;
            }

            if (!preg_match('/^(\d+)\.(mp3|wav|ogg|webm)$/i', $entry, $m)) {
                continue;
            }

            $userId = (int) $m[1];
            $src    = $base . '/' . $entry;
            $dest   = AlarmSoundStorage::userDirectory() . '/' . $entry;

            if (!is_file($src)) {
                continue;
            }

            if (!is_file($dest)) {
                @rename($src, $dest);
            }
        }

        $rows = $this->db->fetchAll(
            "SELECT user_id, pref_value FROM user_preferences WHERE pref_key = 'focus_clock'",
        );

        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['pref_value'] ?? ''), true);
            if (!is_array($decoded) || ($decoded['alarm']['sound'] ?? '') !== 'custom') {
                continue;
            }
            $decoded['alarm']['sound'] = 'mine';
            $this->db->execute(
                'UPDATE user_preferences SET pref_value = ? WHERE user_id = ? AND pref_key = ?',
                [json_encode($decoded, JSON_THROW_ON_ERROR), (int) $row['user_id'], 'focus_clock'],
            );
        }
    }
}
