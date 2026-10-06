<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\Settings\CloudStorageConfigService;

final class AdminCloudStorageHandler
{
    private CloudStorageConfigService $config;

    public function __construct()
    {
        $this->config = CloudStorageConfigService::instance();
    }

    /**
     * @param array<string, mixed> $user
     */
    public function status(array $user): void
    {
        jsonResponse($this->config->adminView());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function save(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();

        try {
            $this->config->save($body, (int) $user['id']);
            jsonResponse([
                'ok'      => true,
                'message' => 'Cloud storage settings saved.',
                'config'  => $this->config->adminView(),
            ]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
