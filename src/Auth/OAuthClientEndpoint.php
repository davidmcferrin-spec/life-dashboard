<?php

declare(strict_types=1);

namespace Dashboard\Auth;

use Dashboard\Settings\BrowserOnly;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\UnauthorizedException;
use Dashboard\Support\ValidationException;

/**
 * Browser edit of an OAuth client's name and scope cap.
 * A bearer cannot call it, and the session must send the CSRF token.
 */
final class OAuthClientEndpoint
{
    public function __construct(private readonly OAuthService $oauth) {}

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed>|null $user
     */
    public function authorize(array $server, ?array $user, string $givenCsrf, string $sessionCsrf): void
    {
        if (BrowserOnly::blocked($server, false)) {
            throw new ForbiddenException(BrowserOnly::DENIED);
        }
        if ($user === null) {
            throw new UnauthorizedException('Not authenticated.');
        }
        if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $givenCsrf)) {
            throw new ForbiddenException('Invalid CSRF token. Refresh the page and try again.');
        }
        if ((int) ($user['is_admin'] ?? 0) !== 1) {
            throw new ForbiddenException('Only an admin can edit an OAuth client.');
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(int $userId, bool $admin, int $id, array $body): array
    {
        if (array_diff(array_keys($body), ['name', 'scopes']) !== []) {
            throw ValidationException::withFields(['body' => 'Only name and scopes are accepted.']);
        }
        if (!isset($body['name']) || !is_string($body['name'])) {
            throw ValidationException::withFields(['name' => 'Name must be 1–120 characters.']);
        }
        if (!isset($body['scopes']) || !is_array($body['scopes'])) {
            throw ValidationException::withFields(['scopes' => 'scopes must be a list.']);
        }
        foreach ($body['scopes'] as $scope) {
            if (!is_string($scope)) {
                throw ValidationException::withFields(['scopes' => 'scopes must be a list.']);
            }
        }
        return $this->oauth->updateClient($userId, $id, $body['name'], $body['scopes'], $admin);
    }
}
