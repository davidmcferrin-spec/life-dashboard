<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\DB\Database;
use Dashboard\Todo\TodoAccess;
use Dashboard\Todo\TodoService;

final class TodosHandler
{
    private TodoService $todos;

    public function __construct(Database $db)
    {
        $this->todos = TodoService::fromDatabase($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        jsonResponse(['lists' => $this->todos->listSummaries((int) $user['id'])]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function widget(array $user): void
    {
        $userId = (int) $user['id'];
        $listId = isset($_GET['list_id']) ? (int) $_GET['list_id'] : null;

        jsonResponse($this->todos->widgetPayload($userId, $listId));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function assigned(array $user): void
    {
        $status = isset($_GET['status']) ? (string) $_GET['status'] : 'open';

        jsonResponse([
            'items' => $this->todos->listAssigned((int) $user['id'], $status),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function listItems(array $user, array $params): void
    {
        $userId = (int) $user['id'];
        $listId = (int) ($params['id'] ?? 0);
        $status = isset($_GET['status']) ? (string) $_GET['status'] : 'open';

        if ($listId <= 0) {
            jsonResponse(['error' => 'Invalid list id.'], 400);
        }

        $access = $this->todos->resolveAccess($listId, $userId);
        if ($access === null) {
            jsonResponse(['error' => 'List not found.'], 404);
        }

        $items = $this->todos->listItemsForAccess($access, $userId, $status, null, true);

        jsonResponse([
            'list'  => $this->findListSummary($userId, $listId),
            'items' => $items,
            'can_write'        => $access->canWrite(),
            'can_manage_list'  => $access->canManageList(),
            'assignable_users' => $access->canWrite()
                ? $this->todos->assignableUsers($access)
                : [],
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function createList(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();

        try {
            $list = $this->todos->createList((int) $user['id'], (string) ($body['name'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['list' => $list], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function updateList(array $user, array $params): void
    {
        validateCsrf();
        $listId = (int) ($params['id'] ?? 0);
        if ($listId <= 0) {
            jsonResponse(['error' => 'Invalid list id.'], 400);
        }

        try {
            $list = $this->todos->updateList($listId, (int) $user['id'], $this->jsonBody());
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if ($list === null) {
            jsonResponse(['error' => 'List not found.'], 404);
        }

        jsonResponse(['list' => $list]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function deleteList(array $user, array $params): void
    {
        validateCsrf();
        $listId = (int) ($params['id'] ?? 0);

        if ($listId <= 0 || !$this->todos->deleteList($listId, (int) $user['id'])) {
            jsonResponse(['error' => 'List not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function createItem(array $user, array $params): void
    {
        validateCsrf();
        $listId = (int) ($params['id'] ?? 0);
        if ($listId <= 0) {
            jsonResponse(['error' => 'Invalid list id.'], 400);
        }

        $access = $this->todos->resolveAccess($listId, (int) $user['id']);
        if ($access === null) {
            jsonResponse(['error' => 'List not found.'], 404);
        }

        try {
            $item = $this->todos->createItem($access, (int) $user['id'], $this->jsonBody());
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['item' => $item], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function updateItem(array $user, array $params): void
    {
        validateCsrf();
        $itemId = (int) ($params['id'] ?? 0);
        if ($itemId <= 0) {
            jsonResponse(['error' => 'Invalid item id.'], 400);
        }

        try {
            $item = $this->todos->updateItem($itemId, (int) $user['id'], $this->jsonBody());
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if ($item === null) {
            jsonResponse(['error' => 'Task not found.'], 404);
        }

        jsonResponse(['item' => $item]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function deleteItem(array $user, array $params): void
    {
        validateCsrf();
        $itemId = (int) ($params['id'] ?? 0);

        if ($itemId <= 0 || !$this->todos->deleteItem($itemId, (int) $user['id'])) {
            jsonResponse(['error' => 'Task not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function archiveCompleted(array $user, array $params): void
    {
        validateCsrf();
        $listId = (int) ($params['id'] ?? 0);
        if ($listId <= 0) {
            jsonResponse(['error' => 'Invalid list id.'], 400);
        }

        try {
            $count = $this->todos->archiveCompleted($listId, (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['archived' => $count]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function listShares(array $user, array $params): void
    {
        $listId = (int) ($params['id'] ?? 0);
        if ($listId <= 0) {
            jsonResponse(['error' => 'Invalid list id.'], 400);
        }

        try {
            $shares = $this->todos->listShares($listId, (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 404);
        }

        jsonResponse($shares);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function shareWithUser(array $user, array $params): void
    {
        validateCsrf();
        $listId = (int) ($params['id'] ?? 0);
        if ($listId <= 0) {
            jsonResponse(['error' => 'Invalid list id.'], 400);
        }

        $body = $this->jsonBody();

        try {
            $share = $this->todos->shareWithUser(
                $listId,
                (int) $user['id'],
                (string) ($body['identifier'] ?? ''),
                (string) ($body['permission'] ?? TodoAccess::PERM_READ),
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['share' => $share]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function removeUserShare(array $user, array $params): void
    {
        validateCsrf();
        $listId   = (int) ($params['id'] ?? 0);
        $shareId  = (int) ($params['shareId'] ?? 0);

        if ($listId <= 0 || $shareId <= 0 || !$this->todos->removeUserShare($listId, (int) $user['id'], $shareId)) {
            jsonResponse(['error' => 'Share not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findListSummary(int $userId, int $listId): ?array
    {
        foreach ($this->todos->listSummaries($userId) as $list) {
            if ((int) $list['id'] === $listId) {
                return $list;
            }
        }

        return null;
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
