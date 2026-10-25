<?php

declare(strict_types=1);

namespace Dashboard\Ollama;

/**
 * Same verdicts as lifedash.ollama_plan.assess.
 * A missing install is quiet. A public bind, a dead API, a failed pull,
 * or a missing default model is not.
 */
final class OllamaReport
{
    /**
     * @param array{
     *   installed: bool,
     *   systemd: string,
     *   api_ok: bool,
     *   hosts: list<string>,
     *   models: list<string>,
     *   chat: string,
     *   embed: string,
     *   pull_state: string,
     *   pull_detail?: string
     * } $facts
     * @return array{name: string, status: string, detail: string}
     */
    public static function assess(array $facts): array
    {
        $state = strtolower(trim($facts['systemd'])) ?: 'unknown';
        $pull = strtolower(trim($facts['pull_state']));
        foreach ($facts['hosts'] as $host) {
            if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                return self::row('fail', 'Ollama is listening outside this machine.');
            }
        }
        if ($pull === 'pulling') {
            return self::row('ok', 'Pulling the default models.');
        }
        if ($pull === 'failed') {
            $detail = trim((string) ($facts['pull_detail'] ?? ''));

            return self::row('warn', $detail !== '' ? $detail : 'The model pull failed.');
        }
        if ($state === 'active' && !$facts['api_ok']) {
            return self::row('warn', 'Ollama is running but the API did not answer.');
        }
        if ($facts['installed'] && in_array($state, ['failed', 'inactive', 'deactivating'], true)) {
            return self::row('warn', 'Ollama service is not running.');
        }
        if ($facts['api_ok']) {
            $names = $facts['models'];
            $missingChat = $facts['chat'] !== '' && !self::modelPresent($names, $facts['chat']);
            $missingEmbed = $facts['embed'] !== '' && !self::modelPresent($names, $facts['embed']);
            if ($missingChat && $missingEmbed) {
                return self::row('warn', 'The default chat model and the embedding model are not installed.');
            }
            if ($missingChat) {
                return self::row('warn', 'The default chat model is not installed.');
            }
            if ($missingEmbed) {
                return self::row('warn', 'The embedding model is not installed.');
            }

            return self::row('ok', 'Ollama API answered.');
        }
        if (!$facts['installed']) {
            return self::row('ok', 'Ollama is not installed.');
        }

        return self::row('warn', 'Ollama API did not answer.');
    }

    /**
     * An untagged pull is stored as name:latest.
     *
     * @param list<string> $names
     */
    private static function modelPresent(array $names, string $wanted): bool
    {
        if (in_array($wanted, $names, true)) {
            return true;
        }
        $prefix = $wanted . ':';
        foreach ($names as $name) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private static function row(string $status, string $detail): array
    {
        return ['name' => 'ollama', 'status' => $status, 'detail' => $detail];
    }
}
