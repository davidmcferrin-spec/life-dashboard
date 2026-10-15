<?php

declare(strict_types=1);

namespace Dashboard\Todo;

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

final class TodoService
{
    private const MAX_LISTS        = 10;
    private const MAX_OPEN_ITEMS   = 100;
    private const MAX_SHARES       = 10;
    private const WIDGET_MAX_ITEMS = 8;
    private const WIDGET_MAX_TABS  = 4;
    private const TITLE_MAX        = 255;
    private const NOTES_MAX        = 4000;

    public function __construct(
        private readonly TodoRepository $repo,
        private readonly PreferenceService $prefs,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self(new TodoRepository($db), new PreferenceService($db));
    }

    public static function maxLists(): int
    {
        return max(1, Config::int('TODO_MAX_LISTS_PER_USER', self::MAX_LISTS));
    }

    public static function widgetMaxItems(): int
    {
        return max(1, Config::int('TODO_WIDGET_MAX_ITEMS', self::WIDGET_MAX_ITEMS));
    }

    public static function widgetMaxTabs(): int
    {
        return max(1, Config::int('TODO_WIDGET_MAX_TABS', self::WIDGET_MAX_TABS));
    }

    public function ensureDefaults(int $userId): void
    {
        if (!$this->repo->tablesReady()) {
            return;
        }

        if ($this->prefs->hasWidgetDefaultsSeeded($userId, 'todos')) {
            return;
        }

        $defaults = [
            ['name' => 'Work',     'slug' => 'work',     'sort' => 0, 'pin' => true],
            ['name' => 'Personal', 'slug' => 'personal', 'sort' => 1, 'pin' => true],
            ['name' => 'Home',     'slug' => 'home',     'sort' => 2, 'pin' => true],
        ];

        foreach ($defaults as $row) {
            $this->repo->insertList($userId, $row['name'], $row['slug'], $row['sort'], $row['pin']);
        }

        $this->prefs->markWidgetDefaultsSeeded($userId, 'todos');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSummaries(int $userId): array
    {
        $this->ensureDefaults($userId);

        $out = [];

        foreach ($this->repo->listOwnedForUser($userId) as $row) {
            $out[] = $this->formatListSummary($row, true, TodoAccess::PERM_WRITE);
        }

        foreach ($this->repo->listSharedWithUser($userId) as $row) {
            $out[] = $this->formatListSummary(
                $row,
                false,
                (string) ($row['share_permission'] ?? TodoAccess::PERM_READ),
                (string) ($row['owner_username'] ?? ''),
            );
        }

        return $out;
    }

    /**
     * @return array{lists: list<array<string, mixed>>, tab_lists: list<array<string, mixed>>, list_id: int|null, items: list<array<string, mixed>>, can_write: bool}
     */
    public function widgetPayload(int $userId, ?int $requestedListId = null): array
    {
        $lists = $this->listSummaries($userId);

        if ($lists === []) {
            return [
                'lists'     => [],
                'tab_lists' => [],
                'list_id'   => null,
                'items'     => [],
                'can_write' => false,
            ];
        }

        $tabLists = $this->widgetTabLists($lists);
        $tabIds   = array_flip(array_map(static fn (array $l): int => (int) $l['id'], $tabLists));

        $listId = $this->resolveWidgetListId($userId, $lists, $requestedListId);
        if (!isset($tabIds[$listId])) {
            $listId = (int) $tabLists[0]['id'];
        }

        $access = $this->resolveAccess($listId, $userId);

        if ($access === null) {
            $listId = (int) $tabLists[0]['id'];
            $access = $this->resolveAccess($listId, $userId);
        }

        if ($access !== null && $requestedListId !== null && $requestedListId === $listId) {
            $this->prefs->set($userId, PreferenceService::KEY_TODO_WIDGET_LIST_ID, (string) $listId);
        }

        $items = $access !== null
            ? $this->listItemsForAccess($access, $userId, 'open', self::widgetMaxItems(), true)
            : [];

        return [
            'lists'            => $lists,
            'tab_lists'        => $tabLists,
            'list_id'          => $access?->listId(),
            'items'            => $items,
            'can_write'        => $access?->canWrite() ?? false,
            'assignable_users' => ($access !== null && $access->canWrite())
                ? $this->assignableUsers($access)
                : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listItemsForAccess(
        TodoAccess $access,
        int $userId,
        string $status = 'open',
        ?int $limit = null,
        bool $includeDetails = false,
    ): array {
        $items = $this->repo->listItems($access->listId(), $status, $limit);

        return array_values(array_map(
            function (array $row) use ($access, $userId, $includeDetails): array {
                return array_merge(
                    $this->formatItem($row, $includeDetails),
                    ['can_edit' => $this->canModifyItem($access, $userId, $row)],
                );
            },
            array_filter(
                $items,
                fn (array $row): bool => $this->canViewItem($access, $userId, $row),
            ),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAssigned(int $userId, string $status = 'open'): array
    {
        $this->ensureDefaults($userId);

        return array_map(
            fn (array $row): array => $this->formatItem($row, includeDetails: true),
            $this->repo->listAssignedToUser($userId, $status),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createList(int $userId, string $name): array
    {
        $this->ensureDefaults($userId);

        if ($this->repo->countOwnedLists($userId) >= self::maxLists()) {
            throw new \InvalidArgumentException('Maximum of ' . self::maxLists() . ' todo lists allowed.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('List name is required.');
        }

        $name = mb_substr($name, 0, 128);
        $slug = $this->uniqueSlug($userId, $this->slugify($name));
        $id   = $this->repo->insertList($userId, $name, $slug, $this->repo->nextListSortOrder($userId), false);
        $row  = $this->repo->findListById($id);

        if ($row === null) {
            throw new \RuntimeException('Failed to create todo list.');
        }

        return $this->formatListSummary($row, true, TodoAccess::PERM_WRITE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function agentCategories(int $userId): array
    {
        $this->ensureDefaults($userId);
        $out = [];
        foreach ($this->repo->listOwnedForUser($userId) as $row) {
            $out[] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function agentCreateCategory(int $userId, string $name): array
    {
        return $this->createList($userId, $name);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function agentList(int $userId, array $filters): array
    {
        $this->ensureDefaults($userId);
        $status = strtolower(trim((string) ($filters['status'] ?? 'open')));
        if (!in_array($status, ['open', 'done', 'all'], true)) {
            throw new \InvalidArgumentException('Status must be open, done, or all.');
        }
        $category = isset($filters['category']) ? trim((string) $filters['category']) : '';
        if ($category !== '') {
            $this->requireOwnedCategory($userId, $category);
        }
        $priority = null;
        if (isset($filters['priority']) && $filters['priority'] !== null && $filters['priority'] !== '') {
            $priority = $this->normalizePriority($filters['priority']);
        }
        $dueBefore = $this->optionalDue($filters['due_before'] ?? null, 'due_before');
        $dueAfter = $this->optionalDue($filters['due_after'] ?? null, 'due_after');
        $limit = (int) ($filters['limit'] ?? 50);
        if ($limit < 1) {
            $limit = 50;
        }
        $rows = $this->repo->searchOwned($userId, [
            'category'   => $category,
            'status'     => $status === 'done' ? 'completed' : $status,
            'due_before' => $dueBefore,
            'due_after'  => $dueAfter,
            'priority'   => $priority,
            'limit'      => min(200, $limit),
        ]);
        return array_map(function (array $row): array {
            $item = $this->formatItem($row, true);
            $item['category'] = (string) ($row['list_name'] ?? '');
            return $item;
        }, $rows);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function agentCreate(int $userId, array $body): array
    {
        $this->ensureDefaults($userId);
        $category = trim((string) ($body['category'] ?? ''));
        if ($category === '') {
            $category = 'Personal';
        }
        $list = $this->requireOwnedCategory($userId, $category);
        $access = $this->resolveAccess((int) $list['id'], $userId);
        if ($access === null) {
            throw new \InvalidArgumentException('Unknown category. Create it with create_todo_category.');
        }
        $item = $this->createItem($access, $userId, $body);
        $item['category'] = (string) $list['name'];
        return $item;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function agentUpdate(int $userId, int $itemId, array $body): array
    {
        $this->ownedItem($userId, $itemId);
        if (array_key_exists('category', $body) && trim((string) $body['category']) !== '') {
            $list = $this->requireOwnedCategory($userId, (string) $body['category']);
            $this->repo->updateItem($itemId, ['list_id' => (int) $list['id']]);
        }
        $updated = $this->updateItem($itemId, $userId, $body);
        if ($updated === null) {
            throw new \InvalidArgumentException('Task not found.');
        }
        $fresh = $this->repo->findItemById($itemId);
        $list = $fresh !== null ? $this->repo->findListById((int) $fresh['list_id']) : null;
        $updated['category'] = $list !== null ? (string) $list['name'] : '';
        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function agentComplete(int $userId, int $itemId, bool $reopen): array
    {
        $this->ownedItem($userId, $itemId);
        $updated = $this->updateItem($itemId, $userId, ['status' => $reopen ? 'open' : 'completed']);
        if ($updated === null) {
            throw new \InvalidArgumentException('Task not found.');
        }
        $fresh = $this->repo->findItemById($itemId);
        $list = $fresh !== null ? $this->repo->findListById((int) $fresh['list_id']) : null;
        $updated['category'] = $list !== null ? (string) $list['name'] : '';
        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwnedCategory(int $userId, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Category is required.');
        }
        foreach ($this->repo->listOwnedForUser($userId) as $row) {
            if (strcasecmp((string) $row['name'], $name) === 0) {
                return $row;
            }
        }
        throw new \InvalidArgumentException('Unknown category. Create it with create_todo_category.');
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedItem(int $userId, int $itemId): array
    {
        $row = $this->repo->findItemById($itemId);
        if ($row === null || (int) ($row['list_owner_id'] ?? 0) !== $userId) {
            throw new \InvalidArgumentException('Task not found.');
        }
        return $row;
    }

    private function optionalDue(mixed $raw, string $field): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return $this->normalizeDueDate($raw);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException($field . ' must be YYYY-MM-DD.');
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null
     */
    public function updateList(int $listId, int $userId, array $fields): ?array
    {
        $access = $this->requireOwner($listId, $userId);
        $update = [];

        if (isset($fields['name'])) {
            $name = trim((string) $fields['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('List name is required.');
            }
            $update['name'] = mb_substr($name, 0, 128);
            $update['slug'] = $this->uniqueSlug($userId, $this->slugify($name), $listId);
        }

        if (array_key_exists('pin_to_dashboard', $fields)) {
            $wantsPin = !empty($fields['pin_to_dashboard']);
            if ($wantsPin) {
                $row = $this->repo->findListByIdForOwner($listId, $userId);
                $alreadyPinned = $row !== null && !empty($row['pin_to_dashboard']);
                if (!$alreadyPinned && $this->repo->countPinnedForUser($userId) >= self::widgetMaxTabs()) {
                    throw new \InvalidArgumentException(
                        'Maximum of ' . self::widgetMaxTabs() . ' lists can appear on the dashboard.',
                    );
                }
            }
            $update['pin_to_dashboard'] = $wantsPin ? 1 : 0;
        }

        if ($update !== []) {
            $this->repo->updateList($listId, $userId, $update);
        }

        $row = $this->repo->findListByIdForOwner($listId, $userId);

        return $row !== null ? $this->formatListSummary($row, true, TodoAccess::PERM_WRITE) : null;
    }

    public function deleteList(int $listId, int $userId): bool
    {
        $this->requireOwner($listId, $userId);

        return $this->repo->deleteList($listId, $userId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createItem(TodoAccess $access, int $userId, array $body): array
    {
        if (!$access->canWrite()) {
            throw new \InvalidArgumentException('You do not have permission to add tasks to this list.');
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Task title is required.');
        }

        if ($this->repo->countOpenItems($access->listId()) >= self::MAX_OPEN_ITEMS) {
            throw new \InvalidArgumentException('Maximum of ' . self::MAX_OPEN_ITEMS . ' open tasks per list.');
        }

        $title = mb_substr($title, 0, self::TITLE_MAX);
        $notes = $this->normalizeNotes($body['notes'] ?? null);
        $links = $this->normalizeLinks($body['links'] ?? null);
        $linksJson = $links !== [] ? json_encode($links, JSON_THROW_ON_ERROR) : null;

        $assigneeId = $this->resolveAssigneeId($access, $userId, $body['assigned_to_user_id'] ?? null);
        $assignedBy = $assigneeId !== null ? $userId : null;
        $dueDate    = $this->normalizeDueDate($body['due_date'] ?? null);
        $priority   = array_key_exists('priority', $body) ? $this->normalizePriority($body['priority']) : null;

        $id = $this->repo->insertItem(
            $access->listId(),
            $title,
            $notes,
            $linksJson,
            $userId,
            $assigneeId,
            $assignedBy,
            $this->repo->nextItemSortOrder($access->listId()),
            $dueDate,
            $priority,
        );

        $row = $this->repo->findItemById($id);
        if ($row === null) {
            throw new \RuntimeException('Failed to create task.');
        }

        return $this->formatItem($row, includeDetails: true);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function updateItem(int $itemId, int $userId, array $body): ?array
    {
        $row = $this->repo->findItemById($itemId);
        if ($row === null) {
            return null;
        }

        $access = $this->resolveAccess((int) $row['list_id'], $userId);
        if ($access === null || !$this->canModifyItem($access, $userId, $row)) {
            throw new \InvalidArgumentException('Task not found or permission denied.');
        }

        $update = [];

        if (isset($body['title'])) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('Task title is required.');
            }
            $update['title'] = mb_substr($title, 0, self::TITLE_MAX);
        }

        if (array_key_exists('notes', $body)) {
            $update['notes'] = $this->normalizeNotes($body['notes']);
        }

        if (array_key_exists('links', $body)) {
            $links = $this->normalizeLinks($body['links']);
            $update['links_json'] = $links !== [] ? json_encode($links, JSON_THROW_ON_ERROR) : null;
        }

        if (array_key_exists('status', $body)) {
            $status = (string) $body['status'];
            if (!in_array($status, ['open', 'completed', 'archived'], true)) {
                throw new \InvalidArgumentException('Invalid status.');
            }
            if (!$this->canChangeStatus($access, $userId, $row, $status)) {
                throw new \InvalidArgumentException('You do not have permission to change this task status.');
            }
            $update['status'] = $status;
            if ($status === 'completed') {
                $update['completed_at'] = date('Y-m-d H:i:s');
                $update['archived_at']  = null;
            } elseif ($status === 'archived') {
                $update['archived_at'] = date('Y-m-d H:i:s');
            } else {
                $update['completed_at'] = null;
                $update['archived_at']  = null;
            }
        }

        if (array_key_exists('assigned_to_user_id', $body)) {
            if (!$access->canWrite()) {
                throw new \InvalidArgumentException('You do not have permission to assign tasks.');
            }
            $assigneeId = $this->resolveAssigneeId($access, $userId, $body['assigned_to_user_id']);
            $update['assigned_to_user_id'] = $assigneeId;
            $update['assigned_by_user_id'] = $assigneeId !== null ? $userId : null;
        }

        if (array_key_exists('due_date', $body)) {
            if (!$access->canWrite()) {
                throw new \InvalidArgumentException('You do not have permission to set a due date.');
            }
            $update['due_date'] = $this->normalizeDueDate($body['due_date']);
        }

        if (array_key_exists('priority', $body)) {
            if (!$access->canWrite()) {
                throw new \InvalidArgumentException('You do not have permission to set a priority.');
            }
            $update['priority'] = $this->normalizePriority($body['priority']);
        }

        if ($update !== []) {
            $this->repo->updateItem($itemId, $update);
        }

        $fresh = $this->repo->findItemById($itemId);

        return $fresh !== null ? $this->formatItem($fresh, includeDetails: true) : null;
    }

    public function deleteItem(int $itemId, int $userId): bool
    {
        $row = $this->repo->findItemById($itemId);
        if ($row === null) {
            return false;
        }

        $access = $this->resolveAccess((int) $row['list_id'], $userId);
        if ($access === null || !$access->canWrite()) {
            return false;
        }

        return $this->repo->deleteItem($itemId);
    }

    public function archiveCompleted(int $listId, int $userId): int
    {
        $access = $this->resolveAccess($listId, $userId);
        if ($access === null || !$access->canWrite()) {
            throw new \InvalidArgumentException('List not found or permission denied.');
        }

        return $this->repo->archiveCompletedForList($listId);
    }

    /**
     * @return array{users: list<array<string, mixed>>}
     */
    public function listShares(int $listId, int $userId): array
    {
        $this->requireOwner($listId, $userId);

        $users = array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'user_id'    => (int) $row['shared_with_user_id'],
            'username'   => (string) $row['username'],
            'email'      => (string) $row['email'],
            'permission' => (string) $row['permission'],
            'created_at' => (string) $row['created_at'],
        ], $this->repo->listUserSharesForList($listId));

        return ['users' => $users];
    }

    /**
     * @return array<string, mixed>
     */
    public function shareWithUser(int $listId, int $ownerId, string $identifier, string $permission): array
    {
        $this->requireOwner($listId, $ownerId);

        $shares = $this->repo->listUserSharesForList($listId);
        if (count($shares) >= self::MAX_SHARES) {
            throw new \InvalidArgumentException('Maximum of ' . self::MAX_SHARES . ' shares per list.');
        }

        $target = $this->repo->findUserByIdentifier($identifier);
        if ($target === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $targetId = (int) $target['id'];
        if ($targetId === $ownerId) {
            throw new \InvalidArgumentException('Cannot share with yourself.');
        }

        $permission = $this->normalizeSharePermission($permission);

        $existing = $this->repo->findUserShare($listId, $targetId);
        if ($existing !== null) {
            $this->repo->updateUserSharePermission((int) $existing['id'], $listId, $permission);

            return [
                'id'         => (int) $existing['id'],
                'user_id'    => $targetId,
                'username'   => (string) $target['username'],
                'email'      => (string) $target['email'],
                'permission' => $permission,
                'updated'    => true,
            ];
        }

        $id = $this->repo->insertUserShare($listId, $ownerId, $targetId, $permission);

        return [
            'id'         => $id,
            'user_id'    => $targetId,
            'username'   => (string) $target['username'],
            'email'      => (string) $target['email'],
            'permission' => $permission,
            'updated'    => false,
        ];
    }

    public function removeUserShare(int $listId, int $ownerId, int $shareId): bool
    {
        $this->requireOwner($listId, $ownerId);

        return $this->repo->deleteUserShare($shareId, $listId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignableUsers(TodoAccess $access): array
    {
        $users = [];

        $ownerData = $this->repo->findUserById($access->ownerId());
        if ($ownerData !== null) {
            $users[(int) $ownerData['id']] = [
                'id'       => (int) $ownerData['id'],
                'username' => (string) $ownerData['username'],
                'email'    => (string) $ownerData['email'],
            ];
        }

        foreach ($this->repo->listShareRecipientIds($access->listId()) as $uid) {
            $u = $this->repo->findUserById($uid);
            if ($u !== null) {
                $users[(int) $u['id']] = [
                    'id'       => (int) $u['id'],
                    'username' => (string) $u['username'],
                    'email'    => (string) $u['email'],
                ];
            }
        }

        return array_values($users);
    }

    public function resolveAccess(int $listId, int $userId): ?TodoAccess
    {
        $list = $this->repo->findListById($listId);
        if ($list === null) {
            return null;
        }

        if ((int) $list['user_id'] === $userId) {
            return new TodoAccess($list, TodoAccess::PERM_WRITE, true, TodoAccess::ROLE_OWNER);
        }

        $share = $this->repo->findUserShare($listId, $userId);
        if ($share === null) {
            return null;
        }

        return new TodoAccess(
            $list,
            (string) $share['permission'],
            false,
            TodoAccess::ROLE_SHARED,
        );
    }

    private function requireOwner(int $listId, int $userId): TodoAccess
    {
        $access = $this->resolveAccess($listId, $userId);
        if ($access === null || !$access->canManageList()) {
            throw new \InvalidArgumentException('Todo list not found.');
        }

        return $access;
    }

    /**
     * @param list<array<string, mixed>> $lists
     */
    private function resolveWidgetListId(int $userId, array $lists, ?int $requestedListId): int
    {
        $allowed = array_flip(array_map(static fn (array $l): int => (int) $l['id'], $lists));

        if ($requestedListId !== null && isset($allowed[$requestedListId])) {
            return $requestedListId;
        }

        $pref = $this->prefs->get($userId, PreferenceService::KEY_TODO_WIDGET_LIST_ID);
        if ($pref !== null && isset($allowed[(int) $pref])) {
            return (int) $pref;
        }

        foreach ($lists as $list) {
            if (!empty($list['pin_to_dashboard'])) {
                return (int) $list['id'];
            }
        }

        return (int) $lists[0]['id'];
    }

    /**
     * @param list<array<string, mixed>> $lists
     * @return list<array<string, mixed>>
     */
    private function widgetTabLists(array $lists): array
    {
        $pinned = array_values(array_filter(
            $lists,
            static fn (array $list): bool => !empty($list['pin_to_dashboard']),
        ));

        if ($pinned !== []) {
            return array_slice($pinned, 0, self::widgetMaxTabs());
        }

        return array_slice($lists, 0, self::widgetMaxTabs());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function canViewItem(TodoAccess $access, int $userId, array $row): bool
    {
        if ($access->canWrite()) {
            return true;
        }

        if ($access->permission === TodoAccess::PERM_READ) {
            $assignee = $row['assigned_to_user_id'] ?? null;

            return $assignee !== null && (int) $assignee === $userId;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function canModifyItem(TodoAccess $access, int $userId, array $row): bool
    {
        if ($access->canWrite()) {
            return true;
        }

        if ($access->permission === TodoAccess::PERM_READ) {
            $assignee = $row['assigned_to_user_id'] ?? null;

            return $assignee !== null && (int) $assignee === $userId;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function canChangeStatus(TodoAccess $access, int $userId, array $row, string $newStatus): bool
    {
        if ($access->canWrite()) {
            return true;
        }

        if ($access->permission !== TodoAccess::PERM_READ) {
            return false;
        }

        $assignee = $row['assigned_to_user_id'] ?? null;
        if ($assignee === null || (int) $assignee !== $userId) {
            return false;
        }

        return in_array($newStatus, ['open', 'completed'], true);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatListSummary(
        array $row,
        bool $isOwner,
        string $permission,
        string $ownerUsername = '',
    ): array {
        $listId = (int) $row['id'];
        $counts = $this->repo->countItemsByStatus($listId);

        return [
            'id'               => $listId,
            'name'             => (string) $row['name'],
            'slug'             => (string) $row['slug'],
            'pin_to_dashboard' => (bool) ($row['pin_to_dashboard'] ?? false),
            'is_owner'         => $isOwner,
            'permission'       => $permission,
            'owner_username'   => $ownerUsername,
            'counts'           => $counts,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatItem(array $row, bool $includeDetails = false): array
    {
        $links = $this->decodeLinks($row['links_json'] ?? null);
        $notes = isset($row['notes']) ? (string) $row['notes'] : '';
        $hasDetails = $notes !== '' || $links !== [];

        $out = [
            'id'                 => (int) $row['id'],
            'list_id'            => (int) $row['list_id'],
            'title'              => (string) $row['title'],
            'status'             => (string) $row['status'],
            'has_details'        => $hasDetails,
            'assigned_to_user_id' => $row['assigned_to_user_id'] !== null ? (int) $row['assigned_to_user_id'] : null,
            'assignee_username'  => $row['assignee_username'] ?? null,
            'due_date'           => isset($row['due_date']) && $row['due_date'] !== null && $row['due_date'] !== ''
                ? (string) $row['due_date']
                : null,
            'priority'           => isset($row['priority']) && $row['priority'] !== null && $row['priority'] !== ''
                ? (int) $row['priority']
                : null,
            'due_urgency'        => $this->computeDueUrgency(
                isset($row['due_date']) && $row['due_date'] !== null && $row['due_date'] !== ''
                    ? (string) $row['due_date']
                    : null,
                (string) $row['status'],
            ),
            'completed_at'       => $row['completed_at'] ?? null,
            'archived_at'        => $row['archived_at'] ?? null,
            'created_at'         => (string) $row['created_at'],
        ];

        if (isset($row['list_name'])) {
            $out['list_name']      = (string) $row['list_name'];
            $out['owner_username'] = $row['owner_username'] ?? null;
        }

        if ($includeDetails) {
            $out['notes'] = $notes !== '' ? $notes : null;
            $out['links'] = $links;
        }

        return $out;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function decodeLinks(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $link) {
            if (!is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '' || !$this->isValidUrl($url)) {
                continue;
            }
            $label = trim((string) ($link['label'] ?? ''));
            if ($label === '') {
                $label = parse_url($url, PHP_URL_HOST) ?: $url;
            }
            $out[] = ['label' => mb_substr($label, 0, 128), 'url' => $url];
        }

        return $out;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function normalizeLinks(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Links must be an array.');
        }

        $out = [];
        foreach ($raw as $link) {
            if (!is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (!str_contains($url, '://')) {
                $url = 'https://' . $url;
            }
            if (!$this->isValidUrl($url)) {
                throw new \InvalidArgumentException('Invalid link URL.');
            }
            $label = trim((string) ($link['label'] ?? ''));
            if ($label === '') {
                $label = parse_url($url, PHP_URL_HOST) ?: $url;
            }
            $out[] = ['label' => mb_substr($label, 0, 128), 'url' => mb_substr($url, 0, 512)];
            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    private function normalizeNotes(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $notes = trim((string) $raw);

        return $notes === '' ? null : mb_substr($notes, 0, self::NOTES_MAX);
    }

    private function normalizePriority(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (int) $raw != (float) $raw) {
            throw new \InvalidArgumentException('Priority must be 1–5.');
        }
        $priority = (int) $raw;
        if ($priority < 1 || $priority > 5) {
            throw new \InvalidArgumentException('Priority must be 1–5.');
        }
        return $priority;
    }

    private function normalizeDueDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid due date. Use YYYY-MM-DD.');
        }

        return $value;
    }

    private function computeDueUrgency(?string $dueDate, string $status): ?string
    {
        if ($dueDate === null || $status !== 'open') {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        $due   = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        if ($due === false) {
            return null;
        }

        $diff = (int) $today->diff($due)->format('%r%a');
        if ($diff < 0) {
            return 'overdue';
        }
        if ($diff === 0) {
            return 'today';
        }
        if ($diff <= 2) {
            return 'soon';
        }

        return null;
    }

    private function resolveAssigneeId(TodoAccess $access, int $userId, mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === 0) {
            return null;
        }

        if (!$access->canWrite()) {
            throw new \InvalidArgumentException('You do not have permission to assign tasks.');
        }

        $assigneeId = (int) $raw;
        $allowed    = array_column($this->assignableUsers($access), 'id');

        if (!in_array($assigneeId, $allowed, true)) {
            throw new \InvalidArgumentException('Assignee must be the list owner or a user this list is shared with.');
        }

        return $assigneeId;
    }

    private function normalizeSharePermission(string $permission): string
    {
        $permission = strtolower(trim($permission));

        return in_array($permission, [TodoAccess::PERM_READ, TodoAccess::PERM_WRITE], true)
            ? $permission
            : TodoAccess::PERM_READ;
    }

    private function uniqueSlug(int $userId, string $base, ?int $excludeId = null): string
    {
        $slug = $base !== '' ? $base : 'list';
        $n    = 2;

        while ($this->repo->slugExists($userId, $slug, $excludeId)) {
            $slug = $base . '-' . $n;
            $n++;
        }

        return $slug;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? mb_substr($slug, 0, 64) : 'list';
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }
}
