<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\Clock\AlarmSoundService;
use Dashboard\Clock\AlarmSoundStorage;
use Dashboard\DB\Database;

final class ClockAlarmHandler
{
    private readonly AlarmSoundService $sounds;

    public function __construct(Database $db)
    {
        $this->sounds = new AlarmSoundService($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        jsonResponse([
            'sounds'    => $this->sounds->listOptions((int) $user['id']),
            'has_mine'  => $this->sounds->hasPersonal((int) $user['id']),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function stream(array $user, string $soundKey = 'mine'): void
    {
        $userId = (int) $user['id'];

        if ($soundKey === 'mine' || $soundKey === 'custom') {
            $meta = $this->sounds->streamMetaForSound('mine', $userId);
        } elseif (AlarmSoundStorage::isValidSharedId($soundKey)) {
            $meta = $this->sounds->streamMetaForSharedId($soundKey);
        } else {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Alarm sound not found.';
            exit;
        }

        if ($meta === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Alarm sound not found.';
            exit;
        }

        header('Content-Type: ' . $meta['mime']);
        header('Content-Length: ' . (string) filesize($meta['path']));
        header('Cache-Control: private, max-age=3600');
        readfile($meta['path']);
        exit;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function upload(array $user): void
    {
        validateCsrf();

        $file = $_FILES['alarm_sound'] ?? null;
        if (!is_array($file)) {
            jsonResponse(['error' => 'No file uploaded.'], 422);
        }

        $scope = (string) ($_POST['scope'] ?? 'shared');
        $label = isset($_POST['label']) ? (string) $_POST['label'] : null;
        $userId = (int) $user['id'];

        try {
            if ($scope === 'mine') {
                $this->sounds->uploadPersonal($userId, $file);
                jsonResponse([
                    'ok'     => true,
                    'scope'  => 'mine',
                    'id'     => 'mine',
                    'label'  => 'My personal upload',
                ]);
            }

            $created = $this->sounds->uploadShared($userId, $file, $label);
            jsonResponse([
                'ok'    => true,
                'scope' => 'shared',
                'id'    => $created['id'],
                'label' => $created['label'],
            ]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function delete(array $user, bool $isAdmin): void
    {
        validateCsrf();

        $userId = (int) $user['id'];
        $scope = (string) ($_POST['scope'] ?? '');
        $soundId = (string) ($_POST['sound_id'] ?? '');

        try {
            if ($scope === 'mine' || $soundId === 'mine') {
                $this->sounds->deletePersonal($userId);
                jsonResponse(['ok' => true, 'has_mine' => false]);
            }

            if ($soundId === '') {
                jsonResponse(['error' => 'Missing sound id.'], 422);
            }

            $this->sounds->deleteShared($soundId, $userId, $isAdmin);
            jsonResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }
}
