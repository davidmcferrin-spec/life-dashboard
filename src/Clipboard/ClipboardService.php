<?php

declare(strict_types=1);

namespace Dashboard\Clipboard;

use Dashboard\Settings\PreferenceService;

/**
 * Business logic for cloud clipboards (v1: plain text).
 */
final class ClipboardService
{
    private const PREVIEW_LENGTH = 120;

    public function __construct(
        private readonly ClipboardRepository $repo,
        private readonly ClipboardAccessService $access,
        private readonly PreferenceService $prefs,
    ) {}

    public static function fromDatabase(\Dashboard\DB\Database $db): self
    {
        return new self(
            new ClipboardRepository($db),
            new ClipboardAccessService($db),
            new PreferenceService($db),
        );
    }

    /**
     * Seed Main + Scratch on first use.
     */
    public function ensureDefaults(int $userId): void
    {
        if ($this->prefs->hasWidgetDefaultsSeeded($userId, 'clipboards')) {
            return;
        }

        $defaults = [
            [
                'name'              => 'Main',
                'slug'              => 'main',
                'content'           => '',
                'pin_to_dashboard'  => 1,
                'auto_save'         => 1,
                'sort_order'        => 0,
            ],
            [
                'name'              => 'Scratch',
                'slug'              => 'scratch',
                'content'           => '',
                'pin_to_dashboard'  => 0,
                'auto_save'         => 1,
                'sort_order'        => 1,
            ],
        ];

        foreach ($defaults as $board) {
            $this->repo->insert([
                'user_id'           => $userId,
                'name'              => $board['name'],
                'slug'              => $board['slug'],
                'content'           => $board['content'],
                'pin_to_dashboard'  => $board['pin_to_dashboard'],
                'auto_save'         => $board['auto_save'],
                'sort_order'        => $board['sort_order'],
            ]);
        }

        $this->prefs->markWidgetDefaultsSeeded($userId, 'clipboards');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSummaries(int $userId, bool $pinnedOnly = false): array
    {
        $this->ensureDefaults($userId);

        $out = [];

        foreach ($this->repo->listForUser($userId, $pinnedOnly) as $row) {
            $summary = $this->summarize($row);
            $summary['access'] = 'owner';
            $summary['permission'] = ClipboardAccess::PERM_WRITE;
            $summary['is_owner'] = true;
            $out[] = $summary;
        }

        if ($pinnedOnly) {
            return $out;
        }

        $shares = $this->access->shareRepository();
        if ($shares->tablesReady()) {
            foreach ($shares->listClipboardsSharedWithUser($userId) as $row) {
                $summary = $this->summarize($row);
                $summary['access'] = 'shared';
                $summary['permission'] = (string) $row['share_permission'];
                $summary['is_owner'] = false;
                $summary['owner_username'] = (string) $row['owner_username'];
                $summary['share_id'] = (int) $row['share_id'];
                $out[] = $summary;
            }
        }

        return $out;
    }

    public function resolveAccess(int $boardId, int $userId): ?ClipboardAccess
    {
        $this->ensureDefaults($userId);

        return $this->access->resolve($boardId, $userId);
    }

    public function resolveLinkAccess(string $token): ?ClipboardAccess
    {
        return $this->access->resolveByLink($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBoardForAccess(ClipboardAccess $access, ?int $sinceVersion = null): ?array
    {
        $row = $access->board;
        $version = (int) $row['content_version'];

        if ($sinceVersion !== null && $sinceVersion === $version) {
            return [
                'unchanged'       => true,
                'id'              => (int) $row['id'],
                'content_version' => $version,
                'updated_at'      => (string) $row['updated_at'],
            ];
        }

        return $this->formatBoard($row, $access);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBoard(int $id, int $userId, ?int $sinceVersion = null): ?array
    {
        $access = $this->resolveAccess($id, $userId);

        return $access === null ? null : $this->getBoardForAccess($access, $sinceVersion);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBoardBySlug(string $slug, int $userId): ?array
    {
        $this->ensureDefaults($userId);

        $row = $this->repo->findBySlugForUser($slug, $userId);

        if ($row === null) {
            return null;
        }

        return $this->getBoard((int) $row['id'], $userId);
    }

    /**
     * @return array{board: array<string, mixed>}|array{error: string, board?: array<string, mixed>}
     */
    public function saveContentForAccess(ClipboardAccess $access, string $content, int $expectedVersion): array
    {
        if (!$access->canWrite()) {
            return ['error' => 'forbidden', 'board' => []];
        }

        $id = $access->boardId();

        if ($access->isOwner) {
            $updated = $this->repo->updateContent($id, $access->ownerId(), $content, $expectedVersion);
        } else {
            $updated = $this->repo->updateContentById($id, $content, $expectedVersion);
        }

        if ($updated === null) {
            $current = $this->repo->findById($id);

            return [
                'error' => 'conflict',
                'board' => $current !== null ? $this->formatBoard($current, $access) : [],
            ];
        }

        return ['board' => $this->formatBoard($updated, $access)];
    }

    /**
     * @return array{board: array<string, mixed>}|array{error: string, board?: array<string, mixed>}
     */
    public function saveContent(int $id, int $userId, string $content, int $expectedVersion): array
    {
        $access = $this->resolveAccess($id, $userId);

        if ($access === null) {
            return ['error' => 'not_found', 'board' => []];
        }

        return $this->saveContentForAccess($access, $content, $expectedVersion);
    }

    /**
     * @param array{name?: string, pin_to_dashboard?: bool, auto_save?: bool} $settings
     * @return array<string, mixed>|null
     */
    public function updateSettings(int $id, int $userId, array $settings): ?array
    {
        if ($this->access->requireOwner($id, $userId) === null) {
            return null;
        }

        $row = $this->repo->findByIdForUser($id, $userId);

        if ($row === null) {
            return null;
        }

        $fields = [];

        if (isset($settings['name'])) {
            $name = trim((string) $settings['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('Name cannot be empty.');
            }
            if (mb_strlen($name) > 128) {
                throw new \InvalidArgumentException('Name is too long.');
            }
            $fields['name'] = $name;
            $fields['slug'] = $this->uniqueSlug($userId, $name, $id);
        }

        if (array_key_exists('pin_to_dashboard', $settings)) {
            $fields['pin_to_dashboard'] = $settings['pin_to_dashboard'] ? 1 : 0;
        }

        if (array_key_exists('auto_save', $settings)) {
            $fields['auto_save'] = $settings['auto_save'] ? 1 : 0;
        }

        if ($fields === []) {
            return $this->formatBoard($row, new ClipboardAccess($row, ClipboardAccess::PERM_WRITE, true));
        }

        $this->repo->updateSettings($id, $userId, $fields);

        $fresh = $this->repo->findByIdForUser($id, $userId);

        return $fresh !== null
            ? $this->formatBoard($fresh, new ClipboardAccess($fresh, ClipboardAccess::PERM_WRITE, true))
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, string $name, bool $pinToDashboard = false): array
    {
        $this->ensureDefaults($userId);

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name cannot be empty.');
        }

        $id = $this->repo->insert([
            'user_id'          => $userId,
            'name'             => $name,
            'slug'             => $this->uniqueSlug($userId, $name, null),
            'content'          => '',
            'pin_to_dashboard' => $pinToDashboard ? 1 : 0,
            'auto_save'        => 1,
            'sort_order'       => $this->repo->nextSortOrder($userId),
        ]);

        $row = $this->repo->findByIdForUser($id, $userId);

        if ($row === null) {
            throw new \RuntimeException('Failed to create clipboard.');
        }

        return $this->formatBoard($row, new ClipboardAccess($row, ClipboardAccess::PERM_WRITE, true));
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->repo->delete($id, $userId);
    }

    private function uniqueSlug(int $userId, string $name, ?int $excludeId): string
    {
        $base = $this->slugify($name);
        $slug = $base;
        $n    = 2;

        while ($this->repo->slugExists($userId, $slug, $excludeId)) {
            $slug = $base . '-' . $n;
            $n++;
        }

        return $slug;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

        if ($slug === '') {
            $slug = 'board';
        }

        return mb_substr($slug, 0, 64);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summarize(array $row): array
    {
        $content = (string) $row['content'];
        $preview = mb_substr(str_replace(["\r\n", "\r", "\n"], ' ', $content), 0, self::PREVIEW_LENGTH);

        if (mb_strlen($content) > self::PREVIEW_LENGTH) {
            $preview .= '…';
        }

        return [
            'id'                => (int) $row['id'],
            'name'              => (string) $row['name'],
            'slug'              => (string) $row['slug'],
            'pin_to_dashboard'  => (bool) $row['pin_to_dashboard'],
            'auto_save'         => (bool) $row['auto_save'],
            'content_version'   => (int) $row['content_version'],
            'updated_at'        => (string) $row['updated_at'],
            'preview'           => $preview,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatBoard(array $row, ClipboardAccess $access): array
    {
        return [
            'id'                => (int) $row['id'],
            'name'              => (string) $row['name'],
            'slug'              => (string) $row['slug'],
            'content'           => (string) $row['content'],
            'content_version'   => (int) $row['content_version'],
            'pin_to_dashboard'  => (bool) $row['pin_to_dashboard'],
            'auto_save'         => (bool) $row['auto_save'],
            'updated_at'        => (string) $row['updated_at'],
            'permission'        => $access->permission,
            'is_owner'          => $access->isOwner,
            'access'            => $access->isOwner ? 'owner' : ($access->via ?? 'shared'),
            'can_write'         => $access->canWrite(),
        ];
    }
}
