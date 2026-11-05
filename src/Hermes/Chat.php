<?php

declare(strict_types=1);

namespace Dashboard\Hermes;

use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * One dashboard thread per user, stored as a Hermes API session.
 * Turns start a run and return immediately so PHP is not held for the model.
 */
final class Chat
{
    public const SESSION = 'hermes_session_id';

    public const RUN = 'hermes_run_id';

    public const MAX_MESSAGE = 8000;

    private const HISTORY = 40;

    public function __construct(
        private readonly Gateway $gateway,
        private readonly ChatMemory $memory,
    ) {
    }

    /**
     * @return array{reachable: bool, detail: string, run_id: string, messages: list<array{role: string, content: string}>}
     */
    public function thread(int $userId): array
    {
        $run = $this->memory->read($userId, self::RUN);
        if (!$this->gateway->configured()) {
            return $this->quiet('Hermes is not configured on this machine yet.', $run);
        }
        if (!$this->gateway->healthy()) {
            return $this->quiet('Hermes is not answering on 127.0.0.1:8642.', $run);
        }
        $session = $this->liveSession($userId);

        return [
            'reachable' => true,
            'detail'    => 'Hermes gateway answered.',
            'run_id'    => $run,
            'messages'  => $session === '' ? [] : $this->messages($session),
        ];
    }

    /**
     * @return array{run_id: string, status: string}
     */
    public function send(int $userId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            throw ValidationException::withFields(['message' => 'Write a message first.']);
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            throw ValidationException::withFields(['message' => 'That message is too long.']);
        }
        $this->requireGateway();
        $session = $this->ensureSession($userId);
        $res = $this->gateway->call('POST', '/v1/runs', [
            'input'      => $message,
            'session_id' => $session,
        ], 20);
        if ($res['status'] !== 200 && $res['status'] !== 202) {
            throw ValidationException::withFields(['message' => $this->errorText($res['json'])]);
        }
        $run = is_array($res['json']) ? (string) ($res['json']['run_id'] ?? '') : '';
        if (self::idOk($run) !== true) {
            throw ValidationException::withFields(['message' => 'Hermes did not start a turn.']);
        }
        $this->memory->write($userId, self::RUN, $run);
        $status = is_array($res['json']) ? (string) ($res['json']['status'] ?? 'started') : 'started';

        return ['run_id' => $run, 'status' => $status];
    }

    /**
     * @return array{run_id: string, status: string, output: string, error: string}
     */
    public function poll(int $userId, string $runId): array
    {
        if (self::idOk($runId) !== true || $this->memory->read($userId, self::RUN) !== $runId) {
            throw new NotFoundException('That Hermes turn was not found.');
        }
        $this->requireGateway();
        $res = $this->gateway->call('GET', '/v1/runs/' . $runId, null, 10);
        if ($res['status'] === 404) {
            $this->memory->write($userId, self::RUN, '');
            throw new NotFoundException('That Hermes turn was not found.');
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw ValidationException::withFields(['message' => 'Hermes did not report the turn.']);
        }
        $body = $res['json'];
        $session = (string) ($body['session_id'] ?? '');
        $ours = $this->memory->read($userId, self::SESSION);
        if ($session !== '' && $session !== $ours) {
            throw new NotFoundException('That Hermes turn was not found.');
        }
        $status = (string) ($body['status'] ?? '');
        if (in_array($status, ['completed', 'failed', 'cancelled', 'interrupted', 'unknown'], true)) {
            $this->memory->write($userId, self::RUN, '');
        }
        $output = $body['output'] ?? '';

        return [
            'run_id' => $runId,
            'status' => $status,
            'output' => is_string($output) ? mb_substr($output, 0, self::MAX_MESSAGE) : '',
            'error'  => $this->errorText($body, ''),
        ];
    }

    /** @return array{reachable: bool, detail: string, run_id: string, messages: list<array{role: string, content: string}>} */
    public function reset(int $userId): array
    {
        $this->memory->write($userId, self::SESSION, '');
        $this->memory->write($userId, self::RUN, '');

        return $this->thread($userId);
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

    private function liveSession(int $userId): string
    {
        $session = $this->memory->read($userId, self::SESSION);
        if ($session === '') {
            return '';
        }
        if (self::idOk($session) !== true) {
            $this->memory->write($userId, self::SESSION, '');

            return '';
        }
        $res = $this->gateway->call('GET', '/api/sessions/' . $session, null, 8);
        if ($res['status'] === 200) {
            return $session;
        }
        $this->memory->write($userId, self::SESSION, '');
        $this->memory->write($userId, self::RUN, '');

        return '';
    }

    private function ensureSession(int $userId): string
    {
        $session = $this->liveSession($userId);
        if ($session !== '') {
            return $session;
        }
        $res = $this->gateway->call('POST', '/api/sessions', ['title' => 'Dashboard'], 10);
        $id = is_array($res['json']) ? (string) ($res['json']['session']['id'] ?? '') : '';
        if (($res['status'] !== 200 && $res['status'] !== 201) || self::idOk($id) !== true) {
            throw ValidationException::withFields(['message' => 'Hermes did not open a session.']);
        }
        $this->memory->write($userId, self::SESSION, $id);

        return $id;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function messages(string $session): array
    {
        $res = $this->gateway->call('GET', '/api/sessions/' . $session . '/messages', null, 15);
        $rows = is_array($res['json']) ? ($res['json']['data'] ?? null) : null;
        if ($res['status'] !== 200 || !is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $text = $this->text($row['content'] ?? '');
            if ($text === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => mb_substr($text, 0, self::MAX_MESSAGE)];
        }
        if (count($out) > self::HISTORY) {
            $out = array_slice($out, -self::HISTORY);
        }

        return array_values($out);
    }

    private function text(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode('', $parts));
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

    /**
     * @return array{reachable: bool, detail: string, run_id: string, messages: list<array{role: string, content: string}>}
     */
    private function quiet(string $detail, string $run): array
    {
        return [
            'reachable' => false,
            'detail'    => $detail,
            'run_id'    => $run,
            'messages'  => [],
        ];
    }

    private static function idOk(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) === 1;
    }
}
