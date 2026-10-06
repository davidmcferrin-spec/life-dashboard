<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\Config;
use Dashboard\DB\Database;

/**
 * System-wide cloud file settings (root folder name, quota) — admin-managed.
 */
final class CloudStorageConfigService
{
    public const SETTING_KEY = 'cloud_storage';

    /** @var array<string, string|null>|null */
    private static ?array $cache = null;

    /** @var array<string, string> */
    private const ENV_MAP = [
        'CLOUD_ROOT_FOLDER_NAME'  => 'cloud_root_folder_name',
        'CLOUD_FILES_QUOTA_BYTES' => 'cloud_files_quota_bytes',
    ];

    private SystemSettingsRepository $settings;

    public function __construct(private readonly Database $db)
    {
        $this->settings = new SystemSettingsRepository($db);
    }

    public static function instance(): self
    {
        return new self(Database::getInstance());
    }

    public function maybe(string $envKey): ?string
    {
        $value = $this->merged()[$envKey] ?? null;

        return ($value !== null && $value !== '') ? $value : null;
    }

    public function get(string $envKey, ?string $default = null): string
    {
        $value = $this->maybe($envKey);

        if ($value !== null) {
            return $value;
        }

        if ($default !== null) {
            return $default;
        }

        return Config::get($envKey);
    }

    public function int(string $envKey, int $default): int
    {
        $value = $this->maybe($envKey);

        if ($value !== null && is_numeric($value)) {
            return (int) $value;
        }

        return Config::int($envKey, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminView(): array
    {
        return [
            'cloud_root_folder_name'  => $this->get('CLOUD_ROOT_FOLDER_NAME', 'PersonalDashboard'),
            'cloud_files_quota_bytes' => $this->int('CLOUD_FILES_QUOTA_BYTES', 5_368_709_120),
            'source'                  => $this->hasDbSettings() ? 'database' : 'env',
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input, int $adminUserId): void
    {
        $current = $this->loadDbSettings() ?? [];

        $rootFolder = trim((string) ($input['cloud_root_folder_name'] ?? $current['cloud_root_folder_name'] ?? 'PersonalDashboard'));
        if ($rootFolder === '') {
            throw new \InvalidArgumentException('Cloud root folder name is required.');
        }

        $quotaRaw = trim((string) ($input['cloud_files_quota_bytes'] ?? $current['cloud_files_quota_bytes'] ?? ''));
        if ($quotaRaw === '' || !ctype_digit($quotaRaw)) {
            throw new \InvalidArgumentException('Cloud files quota must be a positive number of bytes.');
        }

        $quota = (int) $quotaRaw;
        if ($quota < 1) {
            throw new \InvalidArgumentException('Cloud files quota must be at least 1 byte.');
        }

        $this->settings->setJson(self::SETTING_KEY, [
            'cloud_root_folder_name'  => $rootFolder,
            'cloud_files_quota_bytes' => (string) $quota,
        ], $adminUserId);
        self::$cache = null;
    }

    /**
     * @return array<string, string|null>
     */
    private function merged(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $merged = [];
        foreach (self::ENV_MAP as $envKey => $storageKey) {
            $merged[$envKey] = Config::maybe($envKey);
        }

        $db = $this->loadDbSettings();
        if ($db !== null) {
            foreach (self::ENV_MAP as $envKey => $storageKey) {
                if (array_key_exists($storageKey, $db)) {
                    $value = $db[$storageKey];
                    $merged[$envKey] = ($value !== null && $value !== '') ? (string) $value : null;
                }
            }
        }

        self::$cache = $merged;

        return $merged;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadDbSettings(): ?array
    {
        return $this->settings->getJson(self::SETTING_KEY);
    }

    private function hasDbSettings(): bool
    {
        return $this->loadDbSettings() !== null;
    }
}
