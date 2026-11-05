<?php

declare(strict_types=1);

namespace Dashboard\Agents;

use Dashboard\Hermes\Chat;
use Dashboard\Hermes\ChatMemory;
use Dashboard\Hermes\Gateway;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Status and safe drives for Hermes, OpenCode, and OpenClaw.
 * The Hermes API key stays in the gateway env file. Nothing here returns it.
 */
final class LocalAgents
{
    public const SESSION = 'hermes_mcp_session_id';

    public const RUNS = 'hermes_mcp_run_ids';

    private const MAX_PROMPT = 4000;

    private const MAX_RUNS = 20;

    public function __construct(
        private readonly Gateway $gateway,
        private readonly ChatMemory $memory,
        private readonly Command $command = new Command(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function hermesStatus(): array
    {
        $installed = Command::installed(Command::HERMES);
        $reachable = $this->gateway->configured() && $this->gateway->healthy();

        return [
            'installed' => $installed,
            'binary'    => Command::HERMES,
            'version'   => $installed ? $this->version(Command::HERMES) : null,
            'model'     => 'lifedash-coder',
            'configured' => $this->gateway->configured(),
            'gateway'   => [
                'host'      => '127.0.0.1',
                'port'      => 8642,
                'reachable' => $reachable,
                'detail'    => $this->hermesDetail($installed, $reachable),
            ],
        ];
    }

    /**
     * @return array{sessions: list<array{id: string, title: string}>}
     */
    public function hermesSessions(int $userId): array
    {
        $this->requireGateway();
        $res = $this->gateway->call('GET', '/api/sessions', null, 8);
        if ($res['status'] === 200) {
            return ['sessions' => $this->sessionsFrom($res['json'])];
        }
        $id = $this->memory->read($userId, self::SESSION);

        return ['sessions' => self::idOk($id) ? [['id' => $id, 'title' => 'MCP']] : []];
    }

    /**
     * @return array{session_id: string}
     */
    public function hermesStart(int $userId, string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            $title = 'MCP';
        }
        if (mb_strlen($title) > 80 || preg_match('/[\r\n]/', $title) === 1) {
            throw ValidationException::withFields(['title' => 'Use a short title without line breaks.']);
        }
        $this->requireGateway();
        $res = $this->gateway->call('POST', '/api/sessions', ['title' => $title], 10);
        $id = is_array($res['json']) ? (string) ($res['json']['session']['id'] ?? $res['json']['id'] ?? '') : '';
        if (($res['status'] !== 200 && $res['status'] !== 201) || !self::idOk($id)) {
            throw ValidationException::withFields(['title' => $this->gateway->redact($this->errorText($res['json'], 'Hermes did not open a session.'))]);
        }
        $this->memory->write($userId, self::SESSION, $id);

        return ['session_id' => $id];
    }

    /**
     * @return array{run_id: string, status: string, session_id: string}
     */
    public function hermesSend(int $userId, string $message, ?string $sessionId): array
    {
        $message = trim($message);
        if ($message === '') {
            throw ValidationException::withFields(['message' => 'Write a message first.']);
        }
        if (mb_strlen($message) > Chat::MAX_MESSAGE) {
            throw ValidationException::withFields(['message' => 'That message is too long.']);
        }
        $this->requireGateway();
        $session = $sessionId === null || $sessionId === ''
            ? $this->ensureSession($userId)
            : $this->ownedSession($userId, $sessionId);
        $res = $this->gateway->call('POST', '/v1/runs', [
            'input'      => $message,
            'session_id' => $session,
        ], 20);
        if ($res['status'] !== 200 && $res['status'] !== 202) {
            throw ValidationException::withFields(['message' => $this->gateway->redact($this->errorText($res['json']))]);
        }
        $run = is_array($res['json']) ? (string) ($res['json']['run_id'] ?? '') : '';
        if (!self::idOk($run)) {
            throw ValidationException::withFields(['message' => 'Hermes did not start a turn.']);
        }
        $this->rememberRun($userId, $run);
        $status = is_array($res['json']) ? (string) ($res['json']['status'] ?? 'started') : 'started';

        return ['run_id' => $run, 'status' => $status, 'session_id' => $session];
    }

    /**
     * @return array{run_id: string, status: string, output: string, error: string}
     */
    public function hermesPoll(int $userId, string $runId): array
    {
        if (!self::idOk($runId) || !in_array($runId, $this->runs($userId), true)) {
            throw new NotFoundException('That Hermes turn was not found.');
        }
        $this->requireGateway();
        $res = $this->gateway->call('GET', '/v1/runs/' . $runId, null, 10);
        if ($res['status'] === 404) {
            throw new NotFoundException('That Hermes turn was not found.');
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw ValidationException::withFields(['run_id' => 'Hermes did not report the turn.']);
        }
        $body = $res['json'];
        $output = $body['output'] ?? '';

        return [
            'run_id' => $runId,
            'status' => (string) ($body['status'] ?? ''),
            'output' => is_string($output) ? mb_substr($this->gateway->redact($output), 0, Chat::MAX_MESSAGE) : '',
            'error'  => $this->gateway->redact($this->errorText($body, '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function opencodeStatus(): array
    {
        $installed = Command::installed(Command::OPENCODE);

        return [
            'installed' => $installed,
            'binary'    => Command::OPENCODE,
            'version'   => $installed ? $this->version(Command::OPENCODE) : null,
            'model'     => 'ollama/lifedash-coder',
            'endpoint'  => 'http://127.0.0.1:11434/v1',
            'config'    => is_file(Command::OPENCODE_CONFIG) ? Command::OPENCODE_CONFIG : null,
            'detail'    => $installed
                ? 'OpenCode is installed. MCP does not run it: the CLI is a coding agent and can edit files.'
                : 'OpenCode is not installed on this machine yet.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function openclawStatus(): array
    {
        $installed = Command::installed(Command::OPENCLAW);

        return [
            'installed' => $installed,
            'binary'    => Command::OPENCLAW,
            'version'   => $installed ? $this->version(Command::OPENCLAW) : null,
            'model'     => 'ollama/lifedash-coder',
            'endpoint'  => 'http://127.0.0.1:11434',
            'gateway'   => [
                'host'    => '127.0.0.1',
                'port'    => 18789,
                'running' => false,
                'detail'  => 'The OpenClaw gateway daemon is not started. Config binds loopback only.',
            ],
            'detail' => $installed
                ? 'OpenClaw CLI is installed for a loopback Ollama model call. Channels and the gateway daemon are not enabled.'
                : 'OpenClaw is not installed on this machine yet.',
        ];
    }

    /**
     * @return array{ok: bool, output: string, detail: string}
     */
    public function openclawAsk(string $prompt): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw ValidationException::withFields(['prompt' => 'Write a prompt first.']);
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT) {
            throw ValidationException::withFields(['prompt' => 'That prompt is too long.']);
        }
        if (!$this->command->injected() && !Command::installed(Command::OPENCLAW)) {
            throw ValidationException::withFields(['prompt' => 'OpenClaw is not installed on this machine yet.']);
        }
        $ran = $this->command->run(Command::OPENCLAW, [
            'infer', 'model', 'run',
            '--model', 'ollama/lifedash-coder',
            '--prompt', $prompt,
        ], 25);
        $output = trim($ran['out']);
        $ok = $ran['code'] === 0 && $output !== '';

        return [
            'ok'     => $ok,
            'output' => mb_substr($output, 0, Chat::MAX_MESSAGE),
            'detail' => $ok ? 'OpenClaw answered.' : $this->askDetail($ran['code'], $ran['err']),
        ];
    }

    private function askDetail(int $code, string $err): string
    {
        $err = trim($err);
        if ($code === 124) {
            return 'OpenClaw did not answer in time.';
        }
        if ($err === '') {
            return 'OpenClaw did not answer.';
        }

        return mb_substr($err, 0, 300);
    }

    private function version(string $binary): ?string
    {
        $ran = $this->command->run($binary, ['--version'], 5);
        if ($ran['code'] !== 0) {
            return null;
        }
        $line = trim(strtok($ran['out'], "\r\n") ?: '');
        if ($line === '' || strlen($line) > 120 || preg_match('/[[:cntrl:]]/', $line) === 1) {
            return null;
        }

        return $line;
    }

    private function requireGateway(): void
    {
        if (!$this->gateway->configured()) {
            throw ValidationException::withFields(['message' => 'Hermes is not configured on this machine yet.']);
        }
        if (!$this->gateway->healthy()) {
            throw ValidationException::withFields(['message' => 'Hermes is not answering on 127.0.0.1:8642.']);
        }
    }

    private function hermesDetail(bool $installed, bool $reachable): string
    {
        if (!$this->gateway->configured()) {
            return 'Hermes is not configured on this machine yet.';
        }
        if (!$installed) {
            return 'The hermes command is missing.';
        }
        if (!$reachable) {
            return 'Hermes is not answering on 127.0.0.1:8642.';
        }

        return 'Hermes gateway answered.';
    }

    private function ensureSession(int $userId): string
    {
        $existing = $this->memory->read($userId, self::SESSION);
        if (self::idOk($existing)) {
            $res = $this->gateway->call('GET', '/api/sessions/' . $existing, null, 8);
            if ($res['status'] === 200) {
                return $existing;
            }
        }

        return $this->hermesStart($userId, 'MCP')['session_id'];
    }

    private function ownedSession(int $userId, string $sessionId): string
    {
        if (!self::idOk($sessionId)) {
            throw ValidationException::withFields(['session_id' => 'That session id is not valid.']);
        }
        $res = $this->gateway->call('GET', '/api/sessions/' . $sessionId, null, 8);
        if ($res['status'] !== 200) {
            throw new NotFoundException('That Hermes session was not found.');
        }
        $this->memory->write($userId, self::SESSION, $sessionId);

        return $sessionId;
    }

    private function rememberRun(int $userId, string $run): void
    {
        $runs = $this->runs($userId);
        $runs[] = $run;
        if (count($runs) > self::MAX_RUNS) {
            $runs = array_slice($runs, -self::MAX_RUNS);
        }
        $this->memory->write($userId, self::RUNS, json_encode(array_values($runs), JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function runs(int $userId): array
    {
        $raw = $this->memory->read($userId, self::RUNS);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id) {
            if (is_string($id) && self::idOk($id)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param ?array<string, mixed> $json
     * @return list<array{id: string, title: string}>
     */
    private function sessionsFrom(?array $json): array
    {
        if ($json === null) {
            return [];
        }
        if (array_is_list($json)) {
            $rows = $json;
        } elseif (isset($json['data']) && is_array($json['data'])) {
            $rows = $json['data'];
        } elseif (isset($json['sessions']) && is_array($json['sessions'])) {
            $rows = $json['sessions'];
        } else {
            return [];
        }
        if (array_is_list($rows) === false) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if (!self::idOk($id)) {
                continue;
            }
            $title = $this->gateway->redact(trim((string) ($row['title'] ?? '')));
            $out[] = ['id' => $id, 'title' => mb_substr($title, 0, 80)];
            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param ?array<string, mixed> $json
     */
    private function errorText(?array $json, string $fallback = 'Hermes rejected the turn.'): string
    {
        if ($json === null) {
            return $fallback === '' ? '' : 'Hermes did not answer.';
        }
        $error = $json['error'] ?? null;
        $text = '';
        if (is_string($error)) {
            $text = $error;
        } elseif (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            $text = $error['message'];
        }
        if ($text === '') {
            return $fallback;
        }

        return mb_substr($text, 0, 300);
    }

    private static function idOk(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) === 1;
    }
}
