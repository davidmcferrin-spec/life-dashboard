<?php

declare(strict_types=1);

namespace Dashboard\Auth;

use Dashboard\Api\V1\Scope;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Authorization code + PKCE for the MCP connector. Tokens are hashed before
 * they are stored. A refresh token rotates on every use, and presenting the
 * previous one revokes that grant.
 */
final class OAuthService
{
    /** @param null|\Closure(): int $clock */
    public function __construct(
        private readonly OAuthStore $store,
        private readonly ?\Closure $clock = null,
        private readonly string $resource = '',
    ) {}

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function register(array $body, string $ip): array
    {
        $this->limit('register', $ip);
        $name = trim((string) ($body['client_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new OAuthException('invalid_client_metadata', 'client_name must be 1 to 120 characters.');
        }
        $uris = $body['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === []) {
            throw new OAuthException('invalid_client_metadata', 'redirect_uris must list at least one URI.');
        }
        if (count($uris) > 8) {
            throw new OAuthException('invalid_client_metadata', 'redirect_uris accepts at most 8 URIs.');
        }
        $clean = [];
        foreach ($uris as $uri) {
            if (!is_string($uri)) {
                throw new OAuthException('invalid_client_metadata', 'Each redirect URI must be a string.');
            }
            $error = OAuthRules::redirectError($uri);
            if ($error !== null) {
                throw new OAuthException('invalid_redirect_uri', $error);
            }
            $clean[] = $uri;
        }
        $authMethod = strtolower(trim((string) ($body['token_endpoint_auth_method'] ?? 'none')));
        if ($authMethod !== 'none') {
            throw new OAuthException('invalid_client_metadata', 'This server only registers public clients (token_endpoint_auth_method none).');
        }
        $this->rejectList($body['grant_types'] ?? null, ['authorization_code', 'refresh_token'], 'grant_types');
        $this->rejectList($body['response_types'] ?? null, ['code'], 'response_types');
        $scopes = OAuthRules::parseScope((string) ($body['scope'] ?? ''));
        $known = OAuthRules::known(true);
        foreach ($scopes as $scope) {
            if (!in_array($scope, $known, true)) {
                throw new OAuthException('invalid_client_metadata', "Unknown scope '{$scope}'.");
            }
        }
        $now = $this->now();
        $publicId = 'ldoc_' . bin2hex(random_bytes(16));
        $this->store->insertClient([
            'user_id' => null,
            'client_id' => $publicId,
            'client_name' => $name,
            'redirect_uris' => $clean,
            'registered_scopes' => $scopes,
            'granted_scopes' => [],
            'allowed_scopes' => null,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
        ]);
        return [
            'client_id' => $publicId,
            'client_name' => $name,
            'redirect_uris' => $clean,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => OAuthRules::scopeString($scopes),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{client_name: string, redirect_uri: string, offered: list<string>, request: array<string, mixed>}
     */
    public function prepare(array $query, string $ip, bool $admin): array
    {
        $this->limit('authorize', $ip);
        $client = $this->requireClient((string) ($query['client_id'] ?? ''));
        $redirect = (string) ($query['redirect_uri'] ?? '');
        if (!in_array($redirect, $client['redirect_uris'], true)) {
            throw new OAuthException('invalid_request', 'redirect_uri is not registered for this client.');
        }
        if ((string) ($query['response_type'] ?? '') !== 'code') {
            throw new OAuthException('unsupported_response_type', 'response_type must be code.', toClient: true);
        }
        $method = (string) ($query['code_challenge_method'] ?? '');
        $challenge = (string) ($query['code_challenge'] ?? '');
        $challengeError = OAuthRules::challengeError($method, $challenge);
        if ($challengeError !== null) {
            throw new OAuthException('invalid_request', $challengeError, toClient: true);
        }
        $requested = OAuthRules::parseScope((string) ($query['scope'] ?? ''));
        if ($requested === [] && $client['registered_scopes'] !== []) {
            $requested = $client['registered_scopes'];
        }
        if (!$admin) {
            $requested = array_values(array_filter(
                $requested,
                static fn (string $scope): bool => !in_array($scope, Scope::EXPLICIT, true)
            ));
        }
        $allowed = self::allowedOf($client);
        $requested = OAuthRules::capRequest($requested, $allowed);
        if ($allowed !== null && $requested === []) {
            throw new OAuthException('invalid_scope', 'This client is not allowed to request that scope.', toClient: true);
        }
        try {
            $offered = OAuthRules::grantScopes($client['registered_scopes'], $requested, $requested, $admin);
        } catch (OAuthException $e) {
            throw new OAuthException($e->error, $e->getMessage(), toClient: true);
        }
        $resource = trim((string) ($query['resource'] ?? ''));
        $this->checkResource($resource, true);
        $state = (string) ($query['state'] ?? '');
        if (strlen($state) > 512) {
            throw new OAuthException('invalid_request', 'state is too long.', toClient: true);
        }
        return [
            'client_name' => (string) $client['client_name'],
            'redirect_uri' => $redirect,
            'offered' => $offered,
            'request' => [
                'client_row_id' => (int) $client['id'],
                'client_id' => (string) $client['client_id'],
                'redirect_uri' => $redirect,
                'code_challenge' => rtrim(trim($challenge), '='),
                'code_challenge_method' => 'S256',
                'requested' => $requested,
                'state' => $state,
                'resource' => $resource,
                'client_name' => (string) $client['client_name'],
                'created' => $this->now(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $request session payload from prepare()
     * @param list<string> $selected
     */
    public function grant(int $userId, bool $admin, array $request, array $selected): string
    {
        $created = (int) ($request['created'] ?? 0);
        if ($this->now() - $created > OAuthRules::CODE_TTL) {
            throw new OAuthException('invalid_request', 'The consent request expired. Start the connection again.');
        }
        $client = $this->store->findClient((string) ($request['client_id'] ?? ''));
        if ($client === null || (int) $client['id'] !== (int) ($request['client_row_id'] ?? 0)) {
            throw new OAuthException('invalid_request', 'The OAuth client no longer exists.');
        }
        if ($client['revoked_at'] !== null) {
            throw new OAuthException('invalid_client', 'This client has been revoked.', toClient: true);
        }
        $owner = $client['user_id'];
        if ($owner !== null && (int) $owner !== $userId) {
            throw new OAuthException('invalid_request', 'This client is already linked to another account.');
        }
        $requested = is_array($request['requested'] ?? null) ? $request['requested'] : [];
        $allowed = self::allowedOf($client);
        $requested = OAuthRules::capRequest($requested, $allowed);
        $selected = OAuthRules::capRequest($selected, $allowed);
        $scopes = OAuthRules::grantScopes($client['registered_scopes'], $requested, $selected, $admin);
        $now = $this->now();
        $this->store->bindClient((int) $client['id'], $userId, $scopes, $now);
        $plain = 'ldocd_' . bin2hex(random_bytes(32));
        $resource = (string) ($request['resource'] ?? '');
        $this->store->insertCode([
            'user_id' => $userId,
            'client_row_id' => (int) $client['id'],
            'code_hash' => hash('sha256', $plain),
            'redirect_uri' => (string) $request['redirect_uri'],
            'code_challenge' => (string) $request['code_challenge'],
            'code_challenge_method' => 'S256',
            'scopes' => $scopes,
            'resource' => $resource !== '' ? $resource : null,
            'expires_at' => $now + OAuthRules::CODE_TTL,
            'created_at' => $now,
        ]);
        return OAuthRules::withQuery((string) $request['redirect_uri'], [
            'code' => $plain,
            'state' => (string) ($request['state'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function token(array $form, string $ip): array
    {
        $this->limit('token', $ip);
        $grant = (string) ($form['grant_type'] ?? '');
        if ($grant === 'authorization_code') {
            return $this->exchange($form);
        }
        if ($grant === 'refresh_token') {
            return $this->refresh($form);
        }
        throw new OAuthException('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
    }

    /**
     * @param array<string, mixed> $form
     */
    public function revokePresented(array $form, string $ip): void
    {
        $this->limit('token', $ip);
        $plain = trim((string) ($form['token'] ?? ''));
        if ($plain === '') {
            return;
        }
        $hash = hash('sha256', $plain);
        $row = $this->store->findAccess($hash) ?? $this->store->findRefresh($hash);
        if ($row === null) {
            return;
        }
        $clientId = trim((string) ($form['client_id'] ?? ''));
        if ($clientId !== '' && !hash_equals((string) $row['client_public_id'], $clientId)) {
            return;
        }
        $this->store->revokeToken((int) $row['id'], $this->now());
    }

    /**
     * @return array{user_id: int, scopes: list<string>, id: int, name: string}|null
     */
    public function authenticate(string $plain): ?array
    {
        if (!str_starts_with($plain, 'ldoa_')) {
            return null;
        }
        $row = $this->store->findAccess(hash('sha256', $plain));
        if ($row === null || !$this->tokenLive($row, false)) {
            return null;
        }
        $now = $this->now();
        $this->store->touchToken((int) $row['id'], $now);
        $this->store->touchClient(
            (int) $row['client_row_id'],
            $now,
            \Dashboard\Mcp\PlainHttp::inbound($_SERVER)
        );
        // Intersect on every call so a narrower cap applies before the access token expires.
        $scopes = OAuthRules::effectiveScopes($row['scopes'], self::allowedOf($row));
        return [
            'user_id' => (int) $row['user_id'],
            'scopes' => $scopes,
            'id' => (int) $row['id'],
            'name' => (string) $row['client_name'],
        ];
    }

    /**
     * @param list<string> $scopes
     * @return array{token: array<string, mixed>, previous_name: string, previous_scopes: list<string>}
     */
    public function updateClient(int $userId, int $id, string $name, array $scopes, bool $admin): array
    {
        if (!$admin) {
            throw new ForbiddenException('Only an admin can edit an OAuth client.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withFields(['name' => 'Name must be 1–120 characters.']);
        }
        $scopes = Scope::normalize($scopes);
        ApiTokenService::assertAssignable($scopes, true);
        $client = $this->store->findClientById($id);
        if ($client === null || (int) ($client['user_id'] ?? 0) !== $userId) {
            throw new NotFoundException('OAuth client not found.');
        }
        if ($client['revoked_at'] !== null) {
            throw new ValidationException('A revoked OAuth client cannot be edited.');
        }
        $previousName = (string) $client['client_name'];
        $previous = self::allowedOf($client);
        $previousScopes = $previous ?? (is_array($client['granted_scopes'] ?? null) ? $client['granted_scopes'] : []);
        if (!$this->store->saveClient($id, $userId, $name, $scopes)) {
            throw new ValidationException('A revoked OAuth client cannot be edited.');
        }
        foreach ($this->store->liveTokenScopes($id) as $token) {
            $this->store->setTokenScopes((int) $token['id'], OAuthRules::effectiveScopes($token['scopes'], $scopes));
        }
        return [
            'token' => [
                'id' => $id,
                'name' => $name,
                'scopes' => $scopes,
            ],
            'previous_name' => $previousName,
            'previous_scopes' => $previousScopes,
        ];
    }

    public function revokeClient(int $userId, int $id): bool
    {
        return $this->store->revokeClient($id, $userId, $this->now());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listClients(int $userId): array
    {
        return $this->store->listClients($userId);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function exchange(array $form): array
    {
        $client = $this->requireClient((string) ($form['client_id'] ?? ''));
        $plain = trim((string) ($form['code'] ?? ''));
        $redirect = (string) ($form['redirect_uri'] ?? '');
        $verifier = (string) ($form['code_verifier'] ?? '');
        if ($plain === '' || $redirect === '' || $verifier === '') {
            throw new OAuthException('invalid_request', 'code, redirect_uri, client_id, and code_verifier are required.');
        }
        $code = $this->store->takeCode(hash('sha256', $plain), $this->now());
        if ($code === null || (int) $code['client_row_id'] !== (int) $client['id']) {
            throw new OAuthException('invalid_grant', 'Authorization code is invalid or expired.');
        }
        if (!hash_equals((string) $code['redirect_uri'], $redirect)) {
            throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        $pkce = OAuthRules::pkceError((string) $code['code_challenge_method'], (string) $code['code_challenge'], $verifier);
        if ($pkce !== null) {
            throw new OAuthException('invalid_grant', $pkce);
        }
        $resource = trim((string) ($form['resource'] ?? ''));
        $expected = (string) ($code['resource'] ?? '');
        if ($resource !== '' && $expected !== '' && !hash_equals($expected, $resource)) {
            throw new OAuthException('invalid_target', 'resource does not match the authorization request.');
        }
        $scopes = OAuthRules::effectiveScopes($code['scopes'], self::allowedOf($client));
        if ($scopes === []) {
            throw new OAuthException('invalid_scope', 'This client is not allowed any of the granted scopes.');
        }
        return $this->issue((int) $code['user_id'], $client, $scopes);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function refresh(array $form): array
    {
        $client = $this->requireClient((string) ($form['client_id'] ?? ''));
        $plain = trim((string) ($form['refresh_token'] ?? ''));
        if ($plain === '') {
            throw new OAuthException('invalid_request', 'refresh_token is required.');
        }
        $hash = hash('sha256', $plain);
        $row = $this->store->findRefresh($hash);
        if ($row === null) {
            $previous = $this->store->findPreviousRefresh($hash);
            if ($previous !== null && (string) $previous['client_public_id'] === (string) $client['client_id']) {
                $this->store->revokeToken((int) $previous['id'], $this->now());
            }
            throw new OAuthException('invalid_grant', 'Refresh token is invalid, expired, or already used.');
        }
        if ((string) $row['client_public_id'] !== (string) $client['client_id'] || !$this->tokenLive($row, true)) {
            throw new OAuthException('invalid_grant', 'Refresh token is invalid, expired, or already used.');
        }
        $scopes = OAuthRules::refreshScopes($row['scopes'], self::allowedOf($client), OAuthRules::parseScope((string) ($form['scope'] ?? '')));
        if ($scopes === []) {
            throw new OAuthException('invalid_scope', 'This client is not allowed any of the granted scopes.');
        }
        $issued = $this->mint((int) $row['user_id'], $client, $scopes);
        $this->store->rotateToken(
            (int) $row['id'],
            hash('sha256', $issued['access_token']),
            hash('sha256', $issued['refresh_token']),
            $hash,
            $scopes,
            $this->now() + OAuthRules::ACCESS_TTL,
            (int) $row['refresh_expires_at'],
            $this->now()
        );
        $this->store->touchClient((int) $client['id'], $this->now());
        return $issued;
    }

    /**
     * @param list<string> $scopes
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function issue(int $userId, array $client, array $scopes): array
    {
        $issued = $this->mint($userId, $client, $scopes);
        $now = $this->now();
        $this->store->insertToken([
            'user_id' => $userId,
            'client_row_id' => (int) $client['id'],
            'token_hash' => hash('sha256', $issued['access_token']),
            'refresh_hash' => hash('sha256', $issued['refresh_token']),
            'scopes' => $scopes,
            'access_expires_at' => $now + OAuthRules::ACCESS_TTL,
            'refresh_expires_at' => $now + OAuthRules::REFRESH_TTL,
            'created_at' => $now,
        ]);
        $this->store->touchClient((int) $client['id'], $now);
        return $issued;
    }

    /**
     * @param list<string> $scopes
     * @param array<string, mixed> $client
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    private function mint(int $userId, array $client, array $scopes): array
    {
        unset($userId, $client);
        return [
            'access_token' => 'ldoa_' . bin2hex(random_bytes(32)),
            'token_type' => 'Bearer',
            'expires_in' => OAuthRules::ACCESS_TTL,
            'refresh_token' => 'ldor_' . bin2hex(random_bytes(32)),
            'scope' => OAuthRules::scopeString($scopes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireClient(string $publicId): array
    {
        $publicId = trim($publicId);
        if ($publicId === '' || strlen($publicId) > 64) {
            throw new OAuthException('invalid_client', 'client_id is missing.');
        }
        $client = $this->store->findClient($publicId);
        if ($client === null || $client['revoked_at'] !== null) {
            throw new OAuthException('invalid_client', 'OAuth client is unknown or revoked.', 401);
        }
        return $client;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function tokenLive(array $row, bool $refresh): bool
    {
        if ($row['revoked_at'] !== null || $row['client_revoked_at'] !== null) {
            return false;
        }
        $deadline = $refresh ? (int) $row['refresh_expires_at'] : (int) $row['access_expires_at'];
        return $deadline > $this->now();
    }

    private function checkResource(string $resource, bool $toClient): void
    {
        if ($resource === '' && $this->resource === '') {
            return;
        }
        if ($this->resource !== '' && $resource !== '' && !hash_equals($this->resource, $resource)) {
            throw new OAuthException('invalid_target', 'resource does not match this server.', toClient: $toClient);
        }
        if ($resource === '') {
            return;
        }
        $error = OAuthRules::redirectError($resource);
        if ($error !== null) {
            throw new OAuthException('invalid_target', 'resource must be an https URL or a loopback URL.', toClient: $toClient);
        }
    }

    /**
     * @param mixed $given
     * @param list<string> $allowed
     */
    private function rejectList(mixed $given, array $allowed, string $field): void
    {
        if ($given === null || $given === '') {
            return;
        }
        if (!is_array($given)) {
            throw new OAuthException('invalid_client_metadata', $field . ' must be a list.');
        }
        foreach ($given as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true)) {
                throw new OAuthException('invalid_client_metadata', $field . ' contains an unsupported value.');
            }
        }
    }

    private function limit(string $kind, string $ip): void
    {
        if (!preg_match('/^[A-Za-z0-9:.]{1,64}$/', $ip)) {
            $ip = 'unknown';
        }
        [$limit, $window] = match ($kind) {
            'register' => [OAuthRules::REGISTER_LIMIT, OAuthRules::REGISTER_WINDOW],
            'authorize' => [OAuthRules::AUTHORIZE_LIMIT, OAuthRules::AUTHORIZE_WINDOW],
            'token' => [OAuthRules::TOKEN_LIMIT, OAuthRules::TOKEN_WINDOW],
            default => throw new \LogicException('Unknown OAuth rate bucket.'),
        };
        if (!$this->store->allowRate($kind . ':' . $ip, $limit, $window, $this->now())) {
            throw new OAuthException('slow_down', 'Too many requests. Wait and try again.', 429, $window);
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>|null
     */
    private static function allowedOf(array $row): ?array
    {
        $allowed = $row['allowed_scopes'] ?? null;
        return is_array($allowed) ? $allowed : null;
    }
}
