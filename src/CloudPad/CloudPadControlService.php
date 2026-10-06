<?php

declare(strict_types=1);

namespace Dashboard\CloudPad;

use Dashboard\Config;
use Dashboard\DB\Database;

final class CloudPadControlService
{
    private const DEFAULT_LOCK_SECONDS = 90;

    public function __construct(
        private readonly CloudPadRepository $repo,
        private readonly CloudPadAccessService $access,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        $access = new CloudPadAccessService($db);

        return new self($access->canvasRepository(), $access);
    }

    public static function lockSeconds(): int
    {
        return max(30, Config::int('CLOUDPAD_CONTROL_LOCK_SECONDS', self::DEFAULT_LOCK_SECONDS));
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(int $canvasId, int $userId): ?array
    {
        $access = $this->access->resolve($canvasId, $userId);
        if ($access === null) {
            return null;
        }

        $this->expireStale($canvasId);
        $canvas = $this->repo->findById($canvasId);
        if ($canvas === null) {
            return null;
        }

        return $this->formatState($canvas, $userId, $access);
    }

    /**
     * @return array{control: array<string, mixed>}|array{error: string}
     */
    public function take(int $canvasId, int $userId, bool $force = false): array
    {
        $access = $this->access->resolve($canvasId, $userId);
        if ($access === null) {
            return ['error' => 'not_found'];
        }

        if (!$access->canWrite() && !$access->isOwner) {
            return ['error' => 'forbidden'];
        }

        $this->expireStale($canvasId);
        $canvas = $this->repo->findById($canvasId);
        if ($canvas === null) {
            return ['error' => 'not_found'];
        }

        $holderId = $canvas['control_user_id'] !== null ? (int) $canvas['control_user_id'] : null;

        if ($holderId !== null && $holderId !== $userId) {
            if (!$force || !$access->isOwner) {
                return ['error' => 'locked'];
            }
        }

        $now     = $this->now();
        $expires = $this->expiresAt();

        $this->repo->updateControlFields($canvasId, [
            'control_user_id'        => $userId,
            'control_granted_at'     => $now,
            'control_expires_at'     => $expires,
            'control_requested_by'   => null,
            'control_requested_at'   => null,
        ]);

        $fresh = $this->repo->findById($canvasId);

        return ['control' => $this->formatState($fresh ?? $canvas, $userId, $access)];
    }

    /**
     * @return array{control: array<string, mixed>}|array{error: string}
     */
    public function request(int $canvasId, int $userId): array
    {
        $access = $this->access->resolve($canvasId, $userId);
        if ($access === null || !$access->canWrite()) {
            return ['error' => 'forbidden'];
        }

        $this->expireStale($canvasId);
        $canvas = $this->repo->findById($canvasId);
        if ($canvas === null) {
            return ['error' => 'not_found'];
        }

        $holderId = $canvas['control_user_id'] !== null ? (int) $canvas['control_user_id'] : null;

        if ($holderId === null || $holderId === $userId) {
            return $this->take($canvasId, $userId);
        }

        $this->repo->updateControlFields($canvasId, [
            'control_requested_by' => $userId,
            'control_requested_at' => $this->now(),
        ]);

        $fresh = $this->repo->findById($canvasId);

        return ['control' => $this->formatState($fresh ?? $canvas, $userId, $access)];
    }

    /**
     * @return array{control: array<string, mixed>}|array{error: string}
     */
    public function grant(int $canvasId, int $userId): array
    {
        $access = $this->access->resolve($canvasId, $userId);
        if ($access === null) {
            return ['error' => 'not_found'];
        }

        $this->expireStale($canvasId);
        $canvas = $this->repo->findById($canvasId);
        if ($canvas === null) {
            return ['error' => 'not_found'];
        }

        $holderId = $canvas['control_user_id'] !== null ? (int) $canvas['control_user_id'] : null;
        $requesterId = $canvas['control_requested_by'] !== null ? (int) $canvas['control_requested_by'] : null;

        if ($requesterId === null) {
            return ['error' => 'no_request'];
        }

        if ($holderId !== $userId && !$access->isOwner) {
            return ['error' => 'forbidden'];
        }

        $now     = $this->now();
        $expires = $this->expiresAt();

        $this->repo->updateControlFields($canvasId, [
            'control_user_id'        => $requesterId,
            'control_granted_at'     => $now,
            'control_expires_at'     => $expires,
            'control_requested_by'   => null,
            'control_requested_at'   => null,
        ]);

        $fresh = $this->repo->findById($canvasId);

        return ['control' => $this->formatState($fresh ?? $canvas, $userId, $access)];
    }

    /**
     * @return array{control: array<string, mixed>}|array{error: string}
     */
    public function deny(int $canvasId, int $userId): array
    {
        $access = $this->access->resolve($canvasId, $userId);
        if ($access === null) {
            return ['error' => 'not_found'];
        }

        $canvas = $this->repo->findById($canvasId);
        if ($canvas === null) {
            return ['error' => 'not_found'];
        }

        $holderId = $canvas['control_user_id'] !== null ? (int) $canvas['control_user_id'] : null;

        if ($holderId !== $userId && !$access->isOwner) {
            return ['error' => 'forbidden'];
        }

        $this->repo->updateControlFields($canvasId, [
            'control_requested_by' => null,
            'control_requested_at' => null,
        ]);

        $fresh = $this->repo->findById($canvasId);

        return ['control' => $this->formatState($fresh ?? $canvas, $userId, $access)];
    }

    /**
     * @return array{control: array<string, mixed>}|array{error: string}
     */
    public function release(int $canvasId, int $userId): array
    {
        $access = $this->access->resolve($canvasId, $userId);
        if ($access === null) {
            return ['error' => 'not_found'];
        }

        $canvas = $this->repo->findById($canvasId);
        if ($canvas === null) {
            return ['error' => 'not_found'];
        }

        $holderId = $canvas['control_user_id'] !== null ? (int) $canvas['control_user_id'] : null;

        if ($holderId !== $userId && !$access->isOwner) {
            return ['error' => 'forbidden'];
        }

        $this->repo->updateControlFields($canvasId, [
            'control_user_id'        => null,
            'control_granted_at'     => null,
            'control_expires_at'     => null,
            'control_requested_by'   => null,
            'control_requested_at'   => null,
        ]);

        $fresh = $this->repo->findById($canvasId);

        return ['control' => $this->formatState($fresh ?? $canvas, $userId, $access)];
    }

    /**
     * @return array{control: array<string, mixed>}|array{error: string}
     */
    public function heartbeat(int $canvasId, int $userId): array
    {
        $access = $this->access->resolve($canvasId, $userId);
        if ($access === null) {
            return ['error' => 'not_found'];
        }

        $this->expireStale($canvasId);
        $canvas = $this->repo->findById($canvasId);
        if ($canvas === null) {
            return ['error' => 'not_found'];
        }

        $holderId = $canvas['control_user_id'] !== null ? (int) $canvas['control_user_id'] : null;

        if ($holderId !== $userId) {
            return ['error' => 'not_holder'];
        }

        $this->repo->updateControlFields($canvasId, [
            'control_expires_at' => $this->expiresAt(),
        ]);

        $fresh = $this->repo->findById($canvasId);

        return ['control' => $this->formatState($fresh ?? $canvas, $userId, $access)];
    }

    public function userHasControl(array $canvas, int $userId): bool
    {
        $this->expireStale((int) $canvas['id']);
        $fresh = $this->repo->findById((int) $canvas['id']);
        if ($fresh === null) {
            return false;
        }

        $holderId = $fresh['control_user_id'] !== null ? (int) $fresh['control_user_id'] : null;

        return $holderId === $userId;
    }

    private function expireStale(int $canvasId): void
    {
        $this->repo->clearExpiredControl($canvasId, $this->now());
    }

    /**
     * @param array<string, mixed> $canvas
     * @return array<string, mixed>
     */
    private function formatState(array $canvas, int $viewerId, CloudPadAccess $access): array
    {
        $holderId    = $canvas['control_user_id'] !== null ? (int) $canvas['control_user_id'] : null;
        $requesterId = $canvas['control_requested_by'] !== null ? (int) $canvas['control_requested_by'] : null;

        $holder = $holderId !== null ? $this->repo->findUserById($holderId) : null;
        $requester = $requesterId !== null ? $this->repo->findUserById($requesterId) : null;

        return [
            'holder_id'           => $holderId,
            'holder_username'     => $holder !== null ? (string) $holder['username'] : null,
            'expires_at'          => $canvas['control_expires_at'],
            'requested_by'        => $requesterId,
            'requester_username'  => $requester !== null ? (string) $requester['username'] : null,
            'requested_at'        => $canvas['control_requested_at'],
            'viewer_has_control'  => $holderId === $viewerId,
            'viewer_can_edit'     => $holderId === $viewerId && $access->canWrite(),
            'viewer_is_owner'     => $access->isOwner,
            'pending_request'     => $requesterId !== null,
            'pending_for_viewer'  => $requesterId === $viewerId,
        ];
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function expiresAt(): string
    {
        return gmdate('Y-m-d H:i:s', time() + self::lockSeconds());
    }
}
