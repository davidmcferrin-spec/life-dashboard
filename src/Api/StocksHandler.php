<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\DB\Database;
use Dashboard\Stock\StockService;

final class StocksHandler
{
    private StockService $stocks;

    public function __construct(Database $db)
    {
        $this->stocks = StockService::fromDatabase($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        $userId = (int) $user['id'];

        jsonResponse([
            'symbols'             => $this->stocks->listForUser($userId),
            'widget_symbols'      => $this->stocks->widgetForUser($userId),
            'max_symbols'         => StockService::maxSymbols(),
            'max_widget_symbols'  => StockService::widgetMaxSymbols(),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function create(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();

        try {
            $symbol = $this->stocks->addSymbol(
                (int) $user['id'],
                (string) ($body['symbol'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['symbol' => $symbol], 201);
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
            jsonResponse(['error' => 'Symbol not found.'], 404);
        }

        try {
            $symbol = $this->stocks->updateSymbol($id, (int) $user['id'], $body);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if ($symbol === null) {
            jsonResponse(['error' => 'Symbol not found.'], 404);
        }

        jsonResponse(['symbol' => $symbol]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function delete(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0 || !$this->stocks->delete($id, (int) $user['id'])) {
            jsonResponse(['error' => 'Symbol not found.'], 404);
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
