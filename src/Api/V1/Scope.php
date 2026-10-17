<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;

/**
 * Bearer scopes are "{area}:read" or "{area}:write".
 * The required scope is inferred from the request path and verb.
 * "read" / "write" are shorthand for every area. "*" is both.
 * system:read and system:deploy are explicit. They are not areas, and
 * read, write, and "*" do not grant them.
 */
final class Scope
{
    /** Areas that a full read or full write token already covered before bookmarks and the rest were added. */
    public const CORE = ['metrics', 'widgets', 'life', 'pages', 'money', 'invest', 'search'];

    public const AREAS = [
        'metrics', 'widgets', 'life', 'pages', 'money', 'invest', 'search', 'reddit',
        'bookmarks', 'todos', 'flights', 'packages', 'stocks', 'clipboards', 'audit', 'shopping', 'calls',
    ];

    public const MONEY_ENTITIES = ['debt', 'account', 'transaction', 'budget', 'sinking_fund'];

    public const TODO_ENTITIES = ['todo', 'todo_category'];

    public const METRIC_ENTITIES = ['habit'];

    public const SHOPPING_ENTITIES = ['shopping_list', 'shopping_item'];

    public const CALL_ENTITIES = ['call', 'contact', 'vetted_agency'];

    /** Privileged scopes. Present only when an admin put them on the token. */
    public const EXPLICIT = ['system:read', 'system:deploy'];

    public const SYSTEM_ENTITIES = ['deploy'];

    public const AUDIT_ENTITIES = [
        'debt', 'account', 'transaction', 'budget', 'sinking_fund', 'todo', 'todo_category', 'habit',
        'shopping_list', 'shopping_item',
        'call', 'contact', 'vetted_agency',
        'deploy',
    ];

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    public static function normalize(array $requested): array
    {
        if ($requested === []) {
            throw ValidationException::withFields(['scopes' => 'Choose at least one scope.']);
        }
        $out = [];
        $star = false;
        foreach ($requested as $raw) {
            $scope = strtolower(trim((string) $raw));
            if ($scope === '*' || $scope === 'all') {
                $star = true;
                continue;
            }
            if ($scope === 'read' || $scope === 'write') {
                foreach (self::AREAS as $area) {
                    $out[] = $area . ':' . $scope;
                }
                continue;
            }
            if (in_array($scope, self::EXPLICIT, true)) {
                $out[] = $scope;
                continue;
            }
            if (!preg_match('/^([a-z]+):(read|write)$/', $scope, $m) || !in_array($m[1], self::AREAS, true)) {
                throw ValidationException::withFields(['scopes' => "Unknown scope '{$scope}'."]);
            }
            $out[] = $scope;
        }
        if ($star) {
            $kept = array_values(array_filter(
                $out,
                static fn (string $scope): bool => in_array($scope, self::EXPLICIT, true)
            ));
            return array_values(array_unique(array_merge(['*'], $kept)));
        }
        if ($out === []) {
            throw ValidationException::withFields(['scopes' => 'Choose at least one scope.']);
        }
        return array_values(array_unique($out));
    }

    public static function forPath(string $path, bool $write): string
    {
        $area = 'metrics';
        foreach (self::AREAS as $candidate) {
            if (str_contains($path, '/' . $candidate)) {
                $area = $candidate;
                break;
            }
        }
        if (str_contains($path, '/datapoints') || str_contains($path, '/habits')) {
            $area = 'metrics';
        }
        if (str_contains($path, '/widget') || str_contains($path, '/layout') || str_contains($path, '/realtime')) {
            $area = 'widgets';
        }
        return $area . ($write ? ':write' : ':read');
    }

    /** Token minting and revocation stay on the browser session. */
    public static function sessionOnly(string $path): bool
    {
        return str_contains($path, '/api/v1/tokens');
    }

    /**
     * @param list<string> $granted
     */
    public static function allows(array $granted, string $required): bool
    {
        if (in_array($required, self::EXPLICIT, true)) {
            if (in_array($required, $granted, true)) {
                return true;
            }
            // Deploy can read status and the log. Read cannot deploy.
            return $required === 'system:read' && in_array('system:deploy', $granted, true);
        }
        if (in_array('*', $granted, true) || self::hasCore($granted, 'write')) {
            return true;
        }
        if (str_ends_with($required, ':read') && self::hasCore($granted, 'read')) {
            return true;
        }
        if (in_array($required, $granted, true)) {
            return true;
        }
        // write implies read for the same area
        if (str_ends_with($required, ':read')) {
            $write = substr($required, 0, -5) . ':write';
            return in_array($write, $granted, true);
        }
        return false;
    }

    /**
     * Null scopes are a browser session and can read every entity.
     * audit:read can too. A narrower token is limited to the entities its areas cover.
     * Returns null when the caller is unrestricted, otherwise the entity names they may see.
     *
     * @param list<string>|null $granted
     * @return list<string>|null
     */
    public static function auditEntityAllow(?array $granted, ?string $entity): ?array
    {
        if ($entity !== null && !in_array($entity, self::AUDIT_ENTITIES, true)) {
            throw ValidationException::withFields(['entity' => 'Unknown entity type.']);
        }
        if ($granted === null || self::allows($granted, 'audit:read')) {
            return null;
        }
        $allow = [];
        if (self::allows($granted, 'money:read')) {
            $allow = array_merge($allow, self::MONEY_ENTITIES);
        }
        if (self::allows($granted, 'todos:read')) {
            $allow = array_merge($allow, self::TODO_ENTITIES);
        }
        if (self::allows($granted, 'metrics:read')) {
            $allow = array_merge($allow, self::METRIC_ENTITIES);
        }
        if (self::allows($granted, 'shopping:read')) {
            $allow = array_merge($allow, self::SHOPPING_ENTITIES);
        }
        if (self::allows($granted, 'calls:read')) {
            $allow = array_merge($allow, self::CALL_ENTITIES);
        }
        if (self::allows($granted, 'system:read')) {
            $allow = array_merge($allow, self::SYSTEM_ENTITIES);
        }
        if ($entity !== null && !in_array($entity, $allow, true)) {
            throw new ForbiddenException('This token cannot read that audit entity.');
        }
        return $allow;
    }

    /**
     * A token issued as full read or full write lists every core area.
     * Those tokens keep that reach when a new area is added.
     *
     * @param list<string> $granted
     */
    private static function hasCore(array $granted, string $verb): bool
    {
        foreach (self::CORE as $area) {
            if (!in_array($area . ':' . $verb, $granted, true)) {
                return false;
            }
        }
        return true;
    }
}
