<?php

declare(strict_types=1);

namespace Dashboard\Ollama;

use Dashboard\Support\ValidationException;

/** Loopback HTTP only. The service is not reachable from the tunnel. */
final class OllamaEndpoint
{
    public const DEFAULT_URL = 'http://127.0.0.1:11434';

    public const DEFAULT_EMBED = 'nomic-embed-text';

    public const DEFAULT_CHAT = 'llama3.2:3b';

    public static function baseUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::DEFAULT_URL;
        }
        $parts = parse_url($raw);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http') {
            throw ValidationException::withFields(['base_url' => 'The local model URL must be http on this machine.']);
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withFields(['base_url' => 'The local model URL must be http on this machine.']);
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            throw ValidationException::withFields(['base_url' => 'The local model URL must be http on this machine.']);
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw ValidationException::withFields(['base_url' => 'The local model URL must stay on this machine.']);
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 11434;
        if ($port < 1 || $port > 65535) {
            throw ValidationException::withFields(['base_url' => 'The local model URL must be http on this machine.']);
        }
        $shown = $host === '::1' ? '[::1]' : $host;

        return 'http://' . $shown . ':' . $port;
    }

    public static function model(string $raw, string $field, bool $required): string
    {
        $raw = trim($raw);
        if ($raw === '' && !$required) {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/', $raw) !== 1) {
            throw ValidationException::withFields([$field => 'Use a model name such as llama3.2:3b.']);
        }

        return $raw;
    }
}
