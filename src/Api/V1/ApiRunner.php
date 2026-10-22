<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Audit\AuditLog;
use Dashboard\Audit\TokenStamp;
use Dashboard\Auth\ApiTokenService;
use Dashboard\Auth\Auth;
use Dashboard\Auth\BearerTokens;
use Dashboard\Auth\OAuthRules;
use Dashboard\Auth\OAuthService;
use Dashboard\Support\PublicUrl;
use Dashboard\Money\AccountNumberGuard;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\UnauthorizedException;
use Dashboard\Support\ValidationException;

/**
 * Session (CSRF on writes) or Authorization: Bearer. Bearer writes are actor "ai".
 */
final class ApiRunner
{
    private ?ApiActor $actor = null;

    private ?int $tokenId = null;

    private ?string $tokenName = null;

    /** @var array<string, mixed>|null */
    private ?array $jsonBody = null;

    /** @var list<string>|null null means a browser session, which is unrestricted */
    private ?array $grantedScopes = null;

    public function __construct(
        private readonly Auth $auth,
        private readonly ApiTokenService $tokens,
        private readonly AuditLog $audit,
        private readonly ?OAuthService $oauth = null,
    ) {}

    public function actor(): ApiActor
    {
        if ($this->actor === null) {
            throw new \LogicException('actor() before authentication.');
        }
        return $this->actor;
    }

    /**
     * @return list<string>|null
     */
    public function grantedScopes(): ?array
    {
        return $this->grantedScopes;
    }

    public function tokenId(): ?int
    {
        return $this->tokenId;
    }

    public function tokenName(): ?string
    {
        return $this->tokenName;
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     * @param list<string>|string|null $scope one required scope, or any of a list
     */
    public function run(callable $fn, int $status = 200, bool $mutating = false, array|string|null $scope = null): void
    {
        try {
            $userId = $this->authenticate($mutating, $scope);
            $payload = $fn($userId);
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $statusOut = $status;
            if (isset($payload['http_status']) && is_int($payload['http_status'])) {
                $statusOut = $payload['http_status'];
                unset($payload['http_status']);
            }
            $deployRead = $method === 'GET' && str_contains(self::safePath(), '/system/deploy');
            if ($mutating) {
                $this->auditCall($userId, $payload);
            } elseif (($this->actor()->kind === 'ai' && $method === 'GET' && !self::isMetaRead(self::safePath())) || $deployRead) {
                $this->auditRead($userId);
            }
            jsonResponse($payload, $statusOut);
        } catch (UnauthorizedException $e) {
            header('WWW-Authenticate: ' . OAuthRules::wwwAuthenticate(PublicUrl::mcp()));
            header('Cache-Control: no-store');
            jsonResponse(['error' => $e->getMessage()], 401);
        } catch (ForbiddenException $e) {
            $this->auditDeployFailure($e->getMessage());
            jsonResponse(['error' => $e->getMessage()], 403);
        } catch (NotFoundException $e) {
            $this->auditDeployFailure($e->getMessage());
            jsonResponse(['error' => $e->getMessage()], 404);
        } catch (ValidationException $e) {
            $this->auditDeployFailure($e->getMessage());
            jsonResponse(['error' => $e->getMessage(), 'fields' => $e->fields()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw ValidationException::withFields(['body' => 'Body must be a JSON object.']);
        }
        $this->jsonBody = $data;
        return $data;
    }

    /**
     * @param list<string>|string|null $scope
     */
    private function authenticate(bool $mutating, array|string|null $scope): int
    {
        $this->grantedScopes = null;
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = is_string($path) ? $path : '/';
        $bearer = self::bearerToken();
        $tool = self::toolHeader();

        if ($bearer !== null) {
            $oauth = $this->oauth;
            $row = BearerTokens::resolve(
                $bearer,
                fn (string $token): ?array => $this->tokens->authenticate($token),
                fn (string $token): ?array => $oauth?->authenticate($token),
            );
            if ($row === null) {
                throw new UnauthorizedException('Invalid or expired token.');
            }
            if (Scope::sessionOnly($path)) {
                throw new ForbiddenException('Creating, editing, revoking, and deleting tokens requires a signed-in browser session.');
            }
            $this->grantedScopes = $row['scopes'];
            $this->actor = new ApiActor('ai', $row['user_id'], $tool);
            $this->tokenId = (int) $row['id'];
            $this->tokenName = (string) $row['name'];
            $legacyAudit = str_contains($path, '/audit') && !str_contains($path, '/audit/log');
            if (!str_contains($path, '/agent-prompt') && !$legacyAudit) {
                $required = $scope ?? Scope::forPath($path, $mutating);
                $allowed = is_array($required)
                    ? self::allowsAny($row['scopes'], $required)
                    : Scope::allows($row['scopes'], $required);
                if (!$allowed) {
                    $label = is_array($required) ? implode(' or ', $required) : $required;
                    throw new ForbiddenException('Token is missing the ' . $label . ' scope.');
                }
            }
            return $row['user_id'];
        }

        $user = $this->auth->requireApiUser();
        if ($mutating) {
            validateCsrf();
        }
        $this->actor = new ApiActor('user', (int) $user['id'], $tool);
        return (int) $user['id'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function auditCall(int $userId, array $payload): void
    {
        $actor = $this->actor();
        $this->audit->record(
            $userId,
            $actor->kind,
            $actor->tool,
            'POST',
            self::safePath(),
            $this->auditDetail('write', $payload)
        );
    }

    private function auditRead(int $userId): void
    {
        $actor = $this->actor();
        $this->audit->record(
            $userId,
            $actor->kind,
            $actor->tool,
            'GET',
            self::safePath(),
            $this->auditDetail('read')
        );
    }

    /**
     * The brain map and the audit feed are not agent work. Recording them
     * would draw a trace every time the map refreshes.
     */
    public static function isMetaRead(string $path): bool
    {
        foreach (['/audit', '/agent-prompt', '/realtime/token', '/pages/graph'] as $part) {
            if (str_contains($path, $part)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Entity type and id only. Notes, titles, and payees stay out of the log.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function auditDetail(string $verb, array $payload = []): array
    {
        $extra = [];
        $path = self::safePath();
        if (str_contains($path, '/system/deploy')) {
            $extra['entity'] = 'deploy';
            foreach (['job_id', 'confirm_sha', 'dry_run', 'state', 'mcp_updates', 'changed', 'previous'] as $key) {
                if (!array_key_exists($key, $payload)) {
                    continue;
                }
                $value = $payload[$key];
                if (is_bool($value) || (is_string($value) && preg_match('/^[A-Za-z0-9_:-]{1,80}$/', $value) === 1)) {
                    $extra[$key] = $value;
                }
            }
        }
        if (preg_match('#/api/v1/tokens/\d+#', $path) === 1) {
            $extra = array_merge($extra, self::tokenChangeDetail($payload));
        }
        if ($verb === 'read' && str_contains($path, '/api/v1/money/')) {
            $filter = self::moneyFilter($_GET);
            if ($filter !== '') {
                $extra['filter'] = $filter;
            }
        }
        $entity = self::entityFromPath($path);
        if ($entity !== null) {
            $extra['entity'] = $entity;
            $id = self::entityIdFromPayload($payload) ?? self::entityIdFromPath($path);
            if ($id !== null) {
                $extra['entity_id'] = $id;
            }
        }
        if ($this->actor()->kind !== 'ai') {
            return $extra;
        }
        return TokenStamp::detail($this->tokenId, $this->tokenName, $verb, $extra);
    }

    /**
     * Name and scopes from an edit, revoke, or delete. The plaintext secret is not copied.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function tokenChangeDetail(array $payload): array
    {
        $row = isset($payload['token']) && is_array($payload['token']) ? $payload['token'] : [];
        $out = [];
        if (isset($row['id']) && is_numeric($row['id']) && (int) $row['id'] > 0) {
            $out['token_id'] = (int) $row['id'];
        }
        $name = self::tokenLabel($row['name'] ?? null);
        if ($name !== null) {
            $out['token_name'] = $name;
        }
        $scopes = self::tokenScopeList($row['scopes'] ?? null);
        if ($scopes !== []) {
            $out['scopes'] = $scopes;
        }
        $previous = self::tokenLabel($payload['previous_name'] ?? null);
        if ($previous !== null) {
            $out['previous_name'] = $previous;
        }
        $previousScopes = self::tokenScopeList($payload['previous_scopes'] ?? null);
        if ($previousScopes !== []) {
            $out['previous_scopes'] = $previousScopes;
        }
        if (($payload['deleted'] ?? false) === true) {
            $out['deleted'] = true;
        }
        return $out;
    }

    private static function tokenLabel(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $name = trim($value);
        if ($name === '' || preg_match('/[\x00-\x1F]/', $name) === 1) {
            return null;
        }
        return mb_substr($name, 0, 64);
    }

    /**
     * @return list<string>
     */
    private static function tokenScopeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $scopes = [];
        foreach ($value as $scope) {
            if (!is_string($scope) || preg_match('/^[a-z0-9_*:.-]{1,40}$/', $scope) !== 1) {
                continue;
            }
            $scopes[] = $scope;
        }
        return $scopes;
    }

    public static function entityFromPath(string $path): ?string
    {
        $map = [
            '/money/category-caps'  => 'budget',
            '/money/sinking-funds'  => 'sinking_fund',
            '/money/transactions'   => 'transaction',
            '/money/accounts'       => 'account',
            '/money/debts'          => 'debt',
            '/money/budget'         => 'budget',
            '/todos/categories'     => 'todo_category',
            '/todos/items'          => 'todo',
            '/habits'               => 'habit',
            '/shopping/clear-bought' => 'shopping_item',
            '/shopping/items'       => 'shopping_item',
            '/shopping/lists'       => 'shopping_list',
            '/calls/contacts'       => 'contact',
            '/calls/agencies'       => 'vetted_agency',
            '/calls'                => 'call',
            '/pantry/products'      => 'product',
            '/pantry/recipes'       => 'recipe',
            '/pantry/meals/recipe'  => 'recipe',
            '/pantry/categories'    => 'pantry_category',
            '/pantry/meals'         => 'meal_component',
            '/pantry'               => 'pantry_lot',
            '/spotify'              => 'spotify',
            '/system/deploy'        => 'deploy',
        ];
        foreach ($map as $fragment => $entity) {
            if (str_contains($path, $fragment)) {
                return $entity;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function entityIdFromPayload(array $payload): ?int
    {
        foreach (['debt', 'account', 'sinking_fund', 'todo', 'category', 'transaction', 'habit', 'item', 'list', 'call', 'contact', 'agency', 'product', 'lot', 'component', 'pairing', 'recipe', 'playlist', 'prompt'] as $key) {
            $row = $payload[$key] ?? null;
            if (is_array($row) && isset($row['id']) && is_numeric($row['id']) && (int) $row['id'] > 0) {
                return (int) $row['id'];
            }
        }
        foreach (['debt_id', 'account_id', 'sinking_fund_id', 'todo_id', 'entity_id', 'id'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key]) && (int) $payload[$key] > 0) {
                return (int) $payload[$key];
            }
        }
        return null;
    }

    public static function entityIdFromPath(string $path): ?int
    {
        if (preg_match('#/(?:debts|accounts|transactions|sinking-funds|items|categories|habits|lists|calls|lots|products|recipes|components|playlists|prompts)/(\d+)\b#', $path, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * @param list<string> $granted
     * @param list<string> $required
     */
    private static function allowsAny(array $granted, array $required): bool
    {
        foreach ($required as $scope) {
            if (Scope::allows($granted, $scope)) {
                return true;
            }
        }
        return false;
    }

    public static function safePath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = is_string($path) ? $path : '/';
        return mb_substr(AccountNumberGuard::redact($path) ?? $path, 0, 160);
    }

    /**
     * Tool name and filter only. Values that look like account numbers are removed.
     *
     * @param array<string, mixed> $query
     */
    public static function moneyFilter(array $query): string
    {
        $keep = [];
        foreach (['from', 'to', 'year', 'month', 'category', 'account', 'limit'] as $key) {
            if (!isset($query[$key]) || $query[$key] === '') {
                continue;
            }
            $value = AccountNumberGuard::redact(trim((string) $query[$key])) ?? '';
            if ($value === '' || str_contains($value, '[redacted]')) {
                continue;
            }
            $keep[$key] = mb_substr($value, 0, 80);
        }
        return http_build_query($keep);
    }

    private static function bearerToken(): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $value = $_SERVER[$key] ?? '';
            if (is_string($value) && preg_match('/^Bearer\s+(\S+)/i', $value, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function auditDeployFailure(string $message): void
    {
        if ($this->actor === null) {
            return;
        }
        $path = self::safePath();
        if (!str_contains($path, '/system/deploy')) {
            return;
        }
        $extra = [
            'entity' => 'deploy',
            'error'  => mb_substr($message, 0, 200),
        ];
        $detail = $this->actor->kind === 'ai'
            ? TokenStamp::detail($this->tokenId, $this->tokenName, 'write', $extra)
            : $extra;
        $this->audit->record(
            $this->actor->userId,
            $this->actor->kind,
            $this->actor->tool,
            is_string($_SERVER['REQUEST_METHOD'] ?? null) ? (string) $_SERVER['REQUEST_METHOD'] : 'POST',
            $path,
            $detail
        );
    }

    private static function toolHeader(): ?string
    {
        $value = $_SERVER['HTTP_X_LIFEDASH_TOOL'] ?? '';
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 64);
    }
}
