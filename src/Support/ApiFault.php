<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * API failures stay JSON. The HTML error page would be shown to an MCP caller as the tool result.
 */
final class ApiFault
{
    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $query = strpos($uri, '?');
        if ($query !== false) {
            $uri = substr($uri, 0, $query);
        }
        // parse_url() rejects a segment that looks like a scheme, such as amt:2.
        if (!str_contains($uri, ':')) {
            $parsed = parse_url($uri, PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '') {
                $uri = $parsed;
            }
        }
        $path = '/' . trim($uri, '/');

        return $path === '/' ? '/' : $path;
    }

    public static function isApi(): bool
    {
        return str_starts_with(self::path(), '/api/');
    }

    public static function json(int $status, string $error): void
    {
        if (getenv('LIFEDASH_TEST_CAPTURE') === '1') {
            throw new CapturedResponse(['error' => $error], $status);
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code($status);
        echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
    }
}
