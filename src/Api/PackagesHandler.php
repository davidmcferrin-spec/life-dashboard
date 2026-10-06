<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\DB\Database;
use Dashboard\Package\PackageWatchService;

final class PackagesHandler
{
    private PackageWatchService $packages;

    public function __construct(Database $db)
    {
        $this->packages = PackageWatchService::fromDatabase($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        $all = isset($_GET['all']) && $_GET['all'] === '1';
        $list = $all
            ? $this->packages->listAll((int) $user['id'])
            : $this->packages->listForDashboard((int) $user['id']);

        jsonResponse(['packages' => $list]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function create(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();

        try {
            $package = $this->packages->create(
                (int) $user['id'],
                (string) ($body['tracking_number'] ?? ''),
                (string) ($body['carrier'] ?? 'auto'),
                isset($body['label']) ? (string) $body['label'] : null,
                isset($body['external_url']) ? (string) $body['external_url'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['package' => $package], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function update(array $user, array $params): void
    {
        validateCsrf();
        $id   = (int) ($params['id'] ?? 0);
        $body = $this->jsonBody();

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid package id.'], 400);
        }

        try {
            $package = $this->packages->update($id, (int) $user['id'], $body);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if ($package === null) {
            jsonResponse(['error' => 'Package not found.'], 404);
        }

        jsonResponse(['package' => $package]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function delete(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0 || !$this->packages->delete($id, (int) $user['id'])) {
            jsonResponse(['error' => 'Package not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
