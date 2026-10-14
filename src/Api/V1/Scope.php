<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Support\ValidationException;

/**
 * Bearer scopes are "{area}:read" or "{area}:write".
 * The required scope is inferred from the request path and verb.
 * "read" / "write" are shorthand for every area. "*" is both.
 */
final class Scope
{
    public const AREAS = ['metrics', 'widgets', 'life', 'pages', 'money', 'invest', 'search', 'reddit'];

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
        foreach ($requested as $raw) {
            $scope = strtolower(trim((string) $raw));
            if ($scope === '*' || $scope === 'all') {
                return ['*'];
            }
            if ($scope === 'read' || $scope === 'write') {
                foreach (self::AREAS as $area) {
                    $out[] = $area . ':' . $scope;
                }
                continue;
            }
            if (!preg_match('/^([a-z]+):(read|write)$/', $scope, $m) || !in_array($m[1], self::AREAS, true)) {
                throw ValidationException::withFields(['scopes' => "Unknown scope '{$scope}'."]);
            }
            $out[] = $scope;
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
        if (in_array('*', $granted, true)) {
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
}
