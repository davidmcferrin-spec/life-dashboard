<?php

declare(strict_types=1);

namespace Dashboard\CloudPad;

use Dashboard\Config;
use Dashboard\DB\Database;

final class CloudPadService
{
    private const MAX_CANVASES       = 10;
    private const MAX_CONTENT_BYTES  = 3145728;

    public function __construct(
        private readonly CloudPadRepository $repo,
        private readonly CloudPadAccessService $access,
        private readonly CloudPadControlService $control,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        $access = new CloudPadAccessService($db);

        return new self(
            $access->canvasRepository(),
            $access,
            CloudPadControlService::fromDatabase($db),
        );
    }

    public static function maxCanvases(): int
    {
        return max(1, Config::int('CLOUDPAD_MAX_CANVASES', self::MAX_CANVASES));
    }

    public static function maxContentBytes(): int
    {
        return max(65536, Config::int('CLOUDPAD_MAX_CONTENT_BYTES', self::MAX_CONTENT_BYTES));
    }

    public static function emptyScene(): string
    {
        return json_encode([
            'type'     => 'excalidraw',
            'version'  => 2,
            'source'   => 'CloudPad',
            'elements' => [],
            'appState' => [
                'viewBackgroundColor' => '#1e1e1e',
                'gridSize'            => null,
            ],
            'files'    => new \stdClass(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSummaries(int $userId): array
    {
        $out = [];

        foreach ($this->repo->listForUser($userId) as $row) {
            $summary = $this->summarize($row);
            $summary['access'] = 'owner';
            $summary['permission'] = CloudPadAccess::PERM_WRITE;
            $summary['is_owner'] = true;
            $out[] = $summary;
        }

        $shares = $this->access->shareRepository();
        if ($shares->tablesReady()) {
            foreach ($shares->listCanvasesSharedWithUser($userId) as $row) {
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

    public function resolveAccess(int $canvasId, int $userId): ?CloudPadAccess
    {
        return $this->access->resolve($canvasId, $userId);
    }

    public function resolveLinkAccess(string $token): ?CloudPadAccess
    {
        return $this->access->resolveByLink($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCanvasForAccess(CloudPadAccess $access, int $viewerId, ?int $sinceVersion = null): ?array
    {
        $row = $access->canvas;
        $version = (int) $row['content_version'];

        if ($sinceVersion !== null && $sinceVersion === $version) {
            return [
                'unchanged'       => true,
                'id'              => (int) $row['id'],
                'content_version' => $version,
                'updated_at'      => (string) $row['updated_at'],
            ];
        }

        return $this->formatCanvas($row, $access, $viewerId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCanvas(int $id, int $userId, ?int $sinceVersion = null): ?array
    {
        $access = $this->resolveAccess($id, $userId);

        return $access === null ? null : $this->getCanvasForAccess($access, $userId, $sinceVersion);
    }

    /**
     * @return array{canvas: array<string, mixed>}|array{error: string, canvas?: array<string, mixed>}
     */
    public function saveContentForAccess(CloudPadAccess $access, int $userId, string $content, int $expectedVersion): array
    {
        if (!$access->canWrite()) {
            return ['error' => 'forbidden', 'canvas' => []];
        }

        if (
            !$access->isOwner
            && !$this->control->userHasControl($access->canvas, $userId)
        ) {
            return ['error' => 'no_control', 'canvas' => []];
        }

        $this->validateContent($content);

        $id = $access->canvasId();
        $updated = $this->repo->updateContent($id, $content, $expectedVersion);

        if ($updated === null) {
            $current = $this->repo->findById($id);

            return [
                'error'  => 'conflict',
                'canvas' => $current !== null ? $this->formatCanvas($current, $access, $userId) : [],
            ];
        }

        return ['canvas' => $this->formatCanvas($updated, $access, $userId)];
    }

    /**
     * @return array{canvas: array<string, mixed>}|array{error: string, canvas?: array<string, mixed>}
     */
    public function saveContent(int $id, int $userId, string $content, int $expectedVersion): array
    {
        $access = $this->resolveAccess($id, $userId);

        if ($access === null) {
            return ['error' => 'not_found', 'canvas' => []];
        }

        return $this->saveContentForAccess($access, $userId, $content, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name cannot be empty.');
        }

        if ($this->repo->countForUser($userId) >= self::maxCanvases()) {
            throw new \InvalidArgumentException('Maximum of ' . self::maxCanvases() . ' canvases allowed.');
        }

        $id = $this->repo->insert(
            $userId,
            $name,
            $this->uniqueSlug($userId, $name, null),
            self::emptyScene(),
        );

        $row = $this->repo->findByIdForUser($id, $userId);
        if ($row === null) {
            throw new \RuntimeException('Failed to create canvas.');
        }

        $access = new CloudPadAccess($row, CloudPadAccess::PERM_WRITE, true, 'owner');
        $this->control->take($id, $userId);

        return $this->formatCanvas($this->repo->findById($id) ?? $row, $access, $userId);
    }

    public function updateName(int $id, int $userId, string $name): ?array
    {
        if ($this->access->requireOwner($id, $userId) === null) {
            return null;
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Name cannot be empty.');
        }

        $slug = $this->uniqueSlug($userId, $name, $id);
        $this->repo->updateName($id, $userId, $name, $slug);

        $row = $this->repo->findById($id);
        if ($row === null) {
            return null;
        }

        $access = new CloudPadAccess($row, CloudPadAccess::PERM_WRITE, true, 'owner');

        return $this->formatCanvas($row, $access, $userId);
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->repo->delete($id, $userId);
    }

    public function control(): CloudPadControlService
    {
        return $this->control;
    }

    private function validateContent(string $content): void
    {
        if (strlen($content) > self::maxContentBytes()) {
            throw new \InvalidArgumentException('Canvas data is too large.');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Canvas data must be valid JSON.');
        }
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
            $slug = 'canvas';
        }

        return mb_substr($slug, 0, 64);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summarize(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'name'            => (string) $row['name'],
            'slug'            => (string) $row['slug'],
            'content_version' => (int) $row['content_version'],
            'updated_at'      => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatCanvas(array $row, CloudPadAccess $access, int $viewerId): array
    {
        $control = $viewerId > 0
            ? $this->control->getState((int) $row['id'], $viewerId)
            : null;

        return [
            'id'              => (int) $row['id'],
            'name'            => (string) $row['name'],
            'slug'            => (string) $row['slug'],
            'content'         => (string) $row['content'],
            'content_version' => (int) $row['content_version'],
            'updated_at'      => (string) $row['updated_at'],
            'permission'      => $access->permission,
            'is_owner'        => $access->isOwner,
            'access'          => $access->isOwner ? 'owner' : ($access->via ?? 'shared'),
            'can_write'       => $access->canWrite(),
            'control'         => $control,
        ];
    }
}
