<?php

declare(strict_types=1);

namespace Dashboard\Ollama;

/** Talks only to a loopback URL and does not follow redirects. */
class OllamaClient
{
    /**
     * @return list<float>|null
     */
    public function embeddings(string $base, string $model, string $prompt, int $timeout): ?array
    {
        $body = json_encode(['model' => $model, 'prompt' => $prompt], JSON_THROW_ON_ERROR);
        $res = $this->call('POST', $base . '/api/embeddings', $body, $timeout);
        if ($res === null) {
            return null;
        }
        $vector = $res['embedding'] ?? ($res['embeddings'][0] ?? null);

        return is_array($vector) && $vector !== [] ? $vector : null;
    }

    /**
     * @return list<array{name: string, size: int, modified_at: string}>|null
     */
    public function models(string $base): ?array
    {
        $res = $this->call('GET', $base . '/api/tags', null, 3);
        if ($res === null || !isset($res['models']) || !is_array($res['models'])) {
            return null;
        }
        $out = [];
        foreach ($res['models'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = (string) ($item['name'] ?? $item['model'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name'        => $name,
                'size'        => (int) ($item['size'] ?? 0),
                'modified_at' => (string) ($item['modified_at'] ?? ''),
            ];
        }

        return $out;
    }

    public function version(string $base): ?string
    {
        $res = $this->call('GET', $base . '/api/version', null, 3);
        if ($res === null) {
            return null;
        }
        $version = $res['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function generate(string $base, string $model, string $prompt): string
    {
        $body = json_encode([
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
        ], JSON_THROW_ON_ERROR);
        $res = $this->call('POST', $base . '/api/generate', $body, 60);
        if ($res === null) {
            throw new \RuntimeException('Ollama did not answer at ' . $base . '.');
        }
        $text = $res['response'] ?? '';
        if (!is_string($text)) {
            throw new \RuntimeException('Ollama returned an empty reply.');
        }

        return mb_substr($text, 0, 8000);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $url, ?string $body, int $timeout): ?array
    {
        $header = "Accept: application/json\r\nContent-Type: application/json\r\n";
        $options = [
            'http' => [
                'method'          => $method,
                'header'          => $header,
                'timeout'         => $timeout,
                'follow_location' => 0,
                'ignore_errors'   => true,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = $body;
        }
        $context = stream_context_create($options);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            error_log('[Dashboard][ollama] Ollama did not answer at ' . $url . '.');

            return null;
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $match)) {
            $status = (int) $match[0];
        }
        if ($status !== 200) {
            error_log('[Dashboard][ollama] Ollama returned HTTP ' . $status . ' for ' . $url . '.');

            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
