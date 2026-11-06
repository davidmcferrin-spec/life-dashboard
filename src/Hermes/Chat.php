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

    /** Legacy MCP-only session. Shown beside the shared room until New chat. */
    public const LEGACY = 'hermes_mcp_session_id';

    public const PRIOR = 'hermes_prior_session_id';

    public const PRIOR_CLOSED = 'hermes_room_closed';

    public const READY = 'hermes_room_ready';

    /** Shared session whose unlabeled user turns came from MCP before this room existed. */
    public const AGENT_SESSION = 'hermes_agent_session_id';

    public const LEDGER = 'hermes_sender_ledger';

    public const WATCH = 'hermes_watch_run';

    public const MAX_MESSAGE = 8000;

    /** Newest messages painted on first load. Older rows arrive on scroll-up. */
    public const PAGE = 40;

    private const PAGE_MAX = 100;

    public function __construct(
        private readonly Gateway $gateway,
        private readonly ChatMemory $memory,
    ) {
    }

    /**
     * Newest page of the gateway session. Pass the oldest id already on screen
     * as $beforeId to read the previous page. The session store is the history;
     * this does not keep a second copy.
     *
     * @return array{reachable: bool, detail: string, run_id: string, messages: list<array{id: string, role: string, content: string, ts: string, sender: string, origin: string}>, has_more: bool, next_before_id: string}
     */
    public function thread(int $userId, string $beforeId = '', int $limit = self::PAGE): array
    {
        $beforeId = $this->cursor($beforeId);
        $limit = $this->pageSize($limit);
        $run = $this->activeRun($userId);
        if (!$this->gateway->configured()) {
            return $this->quiet('Hermes is not configured on this machine yet.', $run);
        }
        if (!$this->gateway->healthy()) {
            return $this->quiet('Hermes is not answering on 127.0.0.1:8642.', $run);
        }
        $this->prepare($userId);
        $session = $this->liveSession($userId);
        $prior = $this->priorSession($userId, $session);
        if ($session === '' && $prior !== '') {
            $session = $prior;
            $prior = '';
        }
        if ($prior === '') {
            $history = $session === ''
                ? ['messages' => [], 'has_more' => false, 'next_before_id' => '']
                : $this->history($session, $limit, $beforeId);
            if ($session !== '') {
                $history['messages'] = $this->attribute($userId, $session, $history['messages'], $this->fallback($userId, $session));
            }

            return [
                'reachable' => true,
                'detail'    => 'Hermes gateway answered.',
                'run_id'    => $run,
            ] + $history;
        }

        return [
            'reachable' => true,
            'detail'    => 'Hermes gateway answered.',
            'run_id'    => $run,
        ] + $this->merged($userId, $session, $prior, $limit, $beforeId);
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
        $this->notePrompt($userId, $session, $message, 'you', 'You');
        $status = is_array($res['json']) ? (string) ($res['json']['status'] ?? 'started') : 'started';

        return ['run_id' => $run, 'status' => $status];
    }

    /**
     * @return array{run_id: string, status: string, output: string, error: string}
     */
    public function poll(int $userId, string $runId): array
    {
        $browserRun = $this->memory->read($userId, self::RUN);
        $watch = $this->memory->read($userId, self::WATCH);
        if (self::idOk($runId) !== true || ($browserRun !== $runId && $watch !== $runId)) {
            throw new NotFoundException('That Hermes turn was not found.');
        }
        $this->requireGateway();
        $res = $this->gateway->call('GET', '/v1/runs/' . $runId, null, 10);
        if ($res['status'] === 404) {
            $this->forgetRun($userId, $runId);
            throw new NotFoundException('That Hermes turn was not found.');
        }
        if ($res['status'] !== 200 || !is_array($res['json'])) {
            throw ValidationException::withFields(['message' => 'Hermes did not report the turn.']);
        }
        $body = $res['json'];
        $session = (string) ($body['session_id'] ?? '');
        $allowed = $this->openSessions($userId);
        if ($session !== '' && !in_array($session, $allowed, true)) {
            throw new NotFoundException('That Hermes turn was not found.');
        }
        $status = (string) ($body['status'] ?? '');
        if (in_array($status, ['completed', 'failed', 'cancelled', 'interrupted', 'unknown'], true)) {
            $this->forgetRun($userId, $runId);
        }
        $output = $body['output'] ?? '';

        return [
            'run_id' => $runId,
            'status' => $status,
            'output' => is_string($output) ? mb_substr($output, 0, self::MAX_MESSAGE) : '',
            'error'  => $this->errorText($body, ''),
        ];
    }

    /** @return array{reachable: bool, detail: string, run_id: string, messages: list<array{id: string, role: string, content: string, ts: string, sender: string, origin: string}>, has_more: bool, next_before_id: string} */
    public function reset(int $userId): array
    {
        $this->memory->write($userId, self::SESSION, '');
        $this->memory->write($userId, self::RUN, '');
        $this->memory->write($userId, self::PRIOR, '');
        $this->memory->write($userId, self::AGENT_SESSION, '');
        $this->memory->write($userId, self::LEDGER, '');
        $this->memory->write($userId, self::WATCH, '');
        $this->memory->write($userId, self::LEGACY, '');
        $this->memory->write($userId, self::PRIOR_CLOSED, '1');
        $this->memory->write($userId, self::READY, '1');

        return $this->thread($userId);
    }

    /**
     * Remember which transcript to fold in. Runs once, then New chat keeps it shut.
     */
    public function prepare(int $userId): void
    {
        if ($this->memory->read($userId, self::PRIOR_CLOSED) === '1') {
            return;
        }
        if ($this->memory->read($userId, self::READY) === '1') {
            return;
        }
        $legacy = $this->memory->read($userId, self::LEGACY);
        $dash = $this->memory->read($userId, self::SESSION);
        $legacyOk = self::idOk($legacy);
        $dashOk = self::idOk($dash);
        if ($legacyOk && !$dashOk) {
            $this->memory->write($userId, self::SESSION, $legacy);
            $this->memory->write($userId, self::AGENT_SESSION, $legacy);
        } elseif ($legacyOk && $dashOk && $legacy === $dash) {
            $this->memory->write($userId, self::AGENT_SESSION, $legacy);
        } elseif ($legacyOk && $dashOk) {
            $this->memory->write($userId, self::PRIOR, $legacy);
        }
        $this->memory->write($userId, self::READY, '1');
    }

    public function sharedSession(int $userId): string
    {
        return $this->ensureSession($userId);
    }

    public function notePrompt(int $userId, string $sessionId, string $message, string $origin, string $label): void
    {
        if (self::idOk($sessionId) !== true) {
            return;
        }
        $message = trim($message);
        if ($message === '') {
            return;
        }
        $origin = $origin === 'mcp' ? 'mcp' : 'you';
        $rows = $this->ledger($userId);
        $rows[] = [
            'session' => $sessionId,
            'hash'    => self::digest($message),
            'origin'  => $origin,
            'label'   => $this->cleanLabel($label, $origin),
            'at'      => time(),
        ];
        if (count($rows) > 200) {
            $rows = array_slice($rows, -200);
        }
        $this->memory->write($userId, self::LEDGER, json_encode(array_values($rows), JSON_THROW_ON_ERROR));
    }

    public function watch(int $userId, string $runId): void
    {
        if (self::idOk($runId)) {
            $this->memory->write($userId, self::WATCH, $runId);
        }
    }

    public function clearWatch(int $userId, string $runId): void
    {
        if ($this->memory->read($userId, self::WATCH) === $runId) {
            $this->memory->write($userId, self::WATCH, '');
        }
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
        $this->prepare($userId);
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

    private function cursor(string $beforeId): string
    {
        $beforeId = trim($beforeId);
        if ($beforeId === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $beforeId) !== 1) {
            throw ValidationException::withFields(['before_id' => 'That history cursor is not valid.']);
        }

        return $beforeId;
    }

    private function pageSize(int $limit): int
    {
        if ($limit < 1) {
            return self::PAGE;
        }

        return min($limit, self::PAGE_MAX);
    }

    /**
     * Ask the gateway for the newest page (`order=latest` plus `before_id`).
     * A gateway that still returns the whole transcript is windowed here.
     * A gateway that pages with `offset` and ignores `before_id` is walked
     * backward from the cursor. An empty page means there is nothing older.
     *
     * @return array{messages: list<array{id: string, role: string, content: string, ts: string}>, has_more: bool, next_before_id: string}
     */
    private function history(string $session, int $limit, string $beforeId): array
    {
        $query = ['limit' => $limit, 'order' => 'latest'];
        if ($beforeId !== '') {
            $query['before_id'] = $beforeId;
        }
        $loaded = $this->load($session, $query);
        if ($loaded === null) {
            return ['messages' => [], 'has_more' => false, 'next_before_id' => ''];
        }
        $visible = $this->visible($loaded['rows']);
        if ($beforeId === '' && $this->pageOrder($loaded['json']) === 'oldest' && count($visible) <= $limit) {
            $again = $this->load($session, []);
            if ($again !== null) {
                $loaded = $again;
                $visible = $this->visible($loaded['rows']);
            }
        }

        $paged = $this->window($visible, $limit, $beforeId, $loaded['json']);
        if ($beforeId !== '' && $paged['messages'] === [] && count($visible) === $limit) {
            $walked = $this->olderByOffset($session, $limit, $beforeId);
            if ($walked !== null) {
                return $walked;
            }
        }

        return $paged;
    }

    /**
     * Latest-page gateways that ignore before_id still honor offset.
     * Walk back until the page holds rows older than the cursor.
     *
     * @return ?array{messages: list<array{id: string, role: string, content: string, ts: string}>, has_more: bool, next_before_id: string}
     */
    private function olderByOffset(string $session, int $limit, string $beforeId): ?array
    {
        if (preg_match('/^\d+$/', $beforeId) !== 1) {
            return null;
        }
        $offset = $limit;
        $seen = '';
        for ($guard = 0; $guard < 25; $guard++) {
            $loaded = $this->load($session, ['limit' => $limit, 'order' => 'latest', 'offset' => $offset]);
            if ($loaded === null) {
                return ['messages' => [], 'has_more' => false, 'next_before_id' => ''];
            }
            $visible = $this->visible($loaded['rows']);
            if ($visible === []) {
                return $this->pack([], false);
            }
            $signature = $visible[0]['id'] . ':' . $visible[count($visible) - 1]['id'];
            if ($signature === $seen) {
                return $this->pack([], false);
            }
            $seen = $signature;
            $older = $this->rowsOlderThan($visible, $beforeId);
            if ($older === null) {
                return null;
            }
            if ($older !== []) {
                if (count($older) > $limit) {
                    $older = array_values(array_slice($older, -$limit));
                }

                return $this->pack($older, count($visible) >= $limit);
            }
            if (count($visible) < $limit) {
                return $this->pack([], false);
            }
            $offset += $limit;
        }

        return $this->pack([], false);
    }

    /**
     * @param array<string, int|string> $query
     * @return ?array{rows: list<mixed>, json: array<string, mixed>}
     */
    private function load(string $session, array $query): ?array
    {
        $path = '/api/sessions/' . $session . '/messages';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }
        $res = $this->gateway->call('GET', $path, null, 15);
        $json = is_array($res['json']) ? $res['json'] : null;
        $rows = $this->rows($json);
        if ($res['status'] !== 200 || $rows === null) {
            return null;
        }

        return ['rows' => $rows, 'json' => $json];
    }

    /**
     * @param ?array<string, mixed> $json
     * @return ?list<mixed>
     */
    private function rows(?array $json): ?array
    {
        if ($json === null) {
            return null;
        }
        foreach (['data', 'messages'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return array_values($json[$key]);
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array{id: string, role: string, content: string, ts: string}>
     */
    private function visible(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $text = $this->visibleText($row);
            if ($text === '') {
                continue;
            }
            $out[] = [
                'id'      => $this->messageId($row),
                'role'    => $role,
                'content' => mb_substr($text, 0, self::MAX_MESSAGE),
                'ts'      => $this->stamp($row),
            ];
        }
        $out = $this->chronological($out);
        foreach ($out as $i => $row) {
            if ($row['id'] === '') {
                $out[$i]['id'] = 'i' . $i;
            }
        }

        return $out;
    }

    /**
     * @param list<array{id: string, role: string, content: string, ts: string}> $clean
     * @param array<string, mixed> $json
     * @return array{messages: list<array{id: string, role: string, content: string, ts: string}>, has_more: bool, next_before_id: string}
     */
    private function window(array $clean, int $limit, string $beforeId, array $json): array
    {
        $at = $beforeId === '' ? null : $this->indexOf($clean, $beforeId);
        if (count($clean) > $limit) {
            if ($at !== null) {
                $start = max(0, $at - $limit);

                return $this->pack(array_values(array_slice($clean, $start, $at - $start)), $start > 0);
            }
            if ($beforeId !== '') {
                return $this->pack([], false);
            }
            $start = count($clean) - $limit;

            return $this->pack(array_values(array_slice($clean, $start)), true);
        }

        if ($beforeId !== '' && $at === null) {
            $older = $this->rowsOlderThan($clean, $beforeId);
            if ($older !== null) {
                if ($older === []) {
                    return $this->pack([], false);
                }
                if (count($older) > $limit) {
                    $older = array_values(array_slice($older, -$limit));
                }

                return $this->pack($older, count($clean) >= $limit);
            }
        }
        $page = $at === null ? $clean : array_values(array_slice($clean, 0, $at));
        if ($page === []) {
            return $this->pack([], false);
        }
        $filled = count($clean) >= $limit;

        return $this->pack($page, $this->metaHasMore($json, $filled));
    }

    /**
     * Rows strictly older than a numeric cursor. Null when an id is not numeric.
     *
     * @param list<array{id: string, role: string, content: string, ts: string}> $clean
     * @return ?list<array{id: string, role: string, content: string, ts: string}>
     */
    private function rowsOlderThan(array $clean, string $beforeId): ?array
    {
        if (preg_match('/^\d+$/', $beforeId) !== 1) {
            return null;
        }
        $cursor = (int) $beforeId;
        $older = [];
        foreach ($clean as $row) {
            if (preg_match('/^\d+$/', $row['id']) !== 1) {
                return null;
            }
            if ((int) $row['id'] < $cursor) {
                $older[] = $row;
            }
        }

        return $older;
    }

    /**
     * @param list<array{id: string, role: string, content: string, ts: string}> $slice
     * @return array{messages: list<array{id: string, role: string, content: string, ts: string}>, has_more: bool, next_before_id: string}
     */
    private function pack(array $slice, bool $hasMore): array
    {
        return [
            'messages'       => $slice,
            'has_more'       => $hasMore,
            'next_before_id' => $hasMore && $slice !== [] ? $slice[0]['id'] : '',
        ];
    }

    /**
     * @param list<array{id: string, role: string, content: string, ts: string}> $clean
     */
    private function indexOf(array $clean, string $id): ?int
    {
        foreach ($clean as $i => $row) {
            if ($row['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Newest-first numeric ids are flipped so the page stays oldest-first.
     *
     * @param list<array{id: string, role: string, content: string, ts: string}> $rows
     * @return list<array{id: string, role: string, content: string, ts: string}>
     */
    private function chronological(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (preg_match('/^\d+$/', $row['id']) !== 1) {
                return $rows;
            }
            $ids[] = (int) $row['id'];
        }
        if (count($ids) >= 2 && $ids[0] > $ids[count($ids) - 1]) {
            return array_values(array_reverse($rows));
        }

        return $rows;
    }

    /** @param array<string, mixed> $json */
    private function pageOrder(array $json): string
    {
        $page = $json['pagination'] ?? null;
        if (!is_array($page)) {
            return '';
        }

        return (string) ($page['order'] ?? '');
    }

    /** @param array<string, mixed> $json */
    private function metaHasMore(array $json, bool $fallback): bool
    {
        if (array_key_exists('has_more', $json)) {
            return (bool) $json['has_more'];
        }
        $page = $json['pagination'] ?? null;
        if (is_array($page) && array_key_exists('has_more', $page)) {
            return (bool) $page['has_more'];
        }
        $cursor = '';
        if (isset($json['next_before_id']) && is_scalar($json['next_before_id'])) {
            $cursor = (string) $json['next_before_id'];
        } elseif (is_array($page) && isset($page['next_before_id']) && is_scalar($page['next_before_id'])) {
            $cursor = (string) $page['next_before_id'];
        }
        if ($cursor !== '') {
            return true;
        }
        if (is_array($page) && isset($page['returned'], $page['limit']) && is_numeric($page['returned']) && is_numeric($page['limit'])) {
            return (int) $page['returned'] > 0 && (int) $page['returned'] >= (int) $page['limit'];
        }

        return $fallback;
    }

    /** @param array<string, mixed> $row */
    private function messageId(array $row): string
    {
        if (!array_key_exists('id', $row)) {
            return '';
        }
        $id = $row['id'];
        if (is_int($id)) {
            return (string) $id;
        }
        if (is_string($id) && preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $id) === 1) {
            return $id;
        }

        return '';
    }

    /** @param array<string, mixed> $row */
    private function visibleText(array $row): string
    {
        if (isset($row['display_content']) && is_string($row['display_content']) && trim($row['display_content']) !== '') {
            return trim($row['display_content']);
        }

        return $this->text($row['content'] ?? '');
    }

    /** @param array<string, mixed> $row */
    private function stamp(array $row): string
    {
        $value = $row['timestamp'] ?? $row['created_at'] ?? $row['ts'] ?? null;
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{10,13}$/', $value) === 1)) {
            $n = (int) $value;
            if ($n > 9999999999) {
                $n = (int) floor($n / 1000);
            }
            if ($n < 1) {
                return '';
            }

            return gmdate('c', $n);
        }
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        $parsed = strtotime(trim($value));
        if ($parsed === false) {
            return '';
        }

        return gmdate('c', $parsed);
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
     * @return array{reachable: bool, detail: string, run_id: string, messages: list<array{id: string, role: string, content: string, ts: string}>, has_more: bool, next_before_id: string}
     */
    private function quiet(string $detail, string $run): array
    {
        return [
            'reachable'      => false,
            'detail'         => $detail,
            'run_id'         => $run,
            'messages'       => [],
            'has_more'       => false,
            'next_before_id' => '',
        ];
    }

    private function activeRun(int $userId): string
    {
        $run = $this->memory->read($userId, self::RUN);
        if ($run !== '') {
            return $run;
        }

        return $this->memory->read($userId, self::WATCH);
    }

    private function forgetRun(int $userId, string $runId): void
    {
        if ($this->memory->read($userId, self::RUN) === $runId) {
            $this->memory->write($userId, self::RUN, '');
        }
        $this->clearWatch($userId, $runId);
    }

    /** @return list<string> */
    private function openSessions(int $userId): array
    {
        $out = [];
        foreach ([self::SESSION, self::PRIOR] as $key) {
            $id = $this->memory->read($userId, $key);
            if ($id !== '') {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function priorSession(int $userId, string $session): string
    {
        if ($this->memory->read($userId, self::PRIOR_CLOSED) === '1') {
            return '';
        }
        $prior = $this->memory->read($userId, self::PRIOR);
        if (self::idOk($prior) !== true || $prior === $session) {
            return '';
        }
        $res = $this->gateway->call('GET', '/api/sessions/' . $prior, null, 8);
        if ($res['status'] === 200) {
            return $prior;
        }
        if ($res['status'] === 404) {
            $this->memory->write($userId, self::PRIOR, '');
        }

        return '';
    }

    private function fallback(int $userId, string $session): string
    {
        return $this->memory->read($userId, self::AGENT_SESSION) === $session ? 'mcp' : 'you';
    }

    /**
     * Newest page across the shared session and the legacy MCP session.
     * Ids carry the slot and timestamp so a later page can step backward.
     *
     * @return array{messages: list<array{id: string, role: string, content: string, ts: string, sender: string, origin: string}>, has_more: bool, next_before_id: string}
     */
    private function merged(int $userId, string $primary, string $prior, int $limit, string $beforeId): array
    {
        $cursor = $beforeId === '' ? null : $this->cursorMark($beforeId);
        if ($beforeId !== '' && $cursor === null) {
            return $this->pack([], false);
        }
        $left = $this->pull($userId, $primary, 'd', $this->fallback($userId, $primary), $limit, $cursor);
        $right = $this->pull($userId, $prior, 'm', 'mcp', $limit, $cursor);
        $rows = array_merge($left['rows'], $right['rows']);
        usort($rows, [$this, 'byTime']);
        $more = $left['has_more'] || $right['has_more'] || count($rows) > $limit;
        if (count($rows) > $limit) {
            $rows = array_values(array_slice($rows, -$limit));
        }
        $messages = [];
        foreach ($rows as $row) {
            $messages[] = $this->publish($row);
        }

        return $this->pack($messages, $more);
    }

    /**
     * @param ?array{slot: string, sort_ts: int, raw: string} $cursor
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    private function pull(int $userId, string $session, string $slot, string $fallback, int $limit, ?array $cursor): array
    {
        $gatewayBefore = ($cursor !== null && $cursor['slot'] === $slot) ? $cursor['raw'] : '';
        $pool = [];
        $gatewayMore = false;
        for ($guard = 0; $guard < 8; $guard++) {
            $page = $this->history($session, $limit, $gatewayBefore);
            if ($page['messages'] === []) {
                $gatewayMore = false;
                break;
            }
            $batch = [];
            foreach ($this->attribute($userId, $session, $page['messages'], $fallback) as $message) {
                $batch[] = $this->marked($message, $slot);
            }
            $oldest = $batch[0]['raw'];
            foreach ($batch as $row) {
                if ($cursor === null || $this->byTime($row, $cursor) < 0) {
                    $pool[$row['id']] = $row;
                }
            }
            $gatewayMore = $page['has_more'];
            if (!$gatewayMore || count($pool) >= $limit) {
                break;
            }
            if ($oldest === '' || $oldest === $gatewayBefore) {
                break;
            }
            $gatewayBefore = $oldest;
        }
        $rows = array_values($pool);
        usort($rows, [$this, 'byTime']);
        $more = $gatewayMore || count($rows) > $limit;
        if (count($rows) > $limit) {
            $rows = array_values(array_slice($rows, -$limit));
        }

        return ['rows' => $rows, 'has_more' => $more];
    }

    /**
     * @param array{id: string, role: string, content: string, ts: string, sender?: string, origin?: string} $message
     * @return array<string, mixed>
     */
    private function marked(array $message, string $slot): array
    {
        $raw = (string) $message['id'];
        $ts = 0;
        if ($message['ts'] !== '') {
            $parsed = strtotime($message['ts']);
            if ($parsed !== false && $parsed > 0) {
                $ts = $parsed;
            }
        }
        $message['raw'] = $raw;
        $message['slot'] = $slot;
        $message['sort_ts'] = $ts;
        $message['id'] = $slot . '.' . $ts . '.' . $raw;

        return $message;
    }

    /**
     * @return ?array{slot: string, sort_ts: int, raw: string}
     */
    private function cursorMark(string $beforeId): ?array
    {
        $parts = explode('.', $beforeId, 3);
        if (count($parts) !== 3) {
            return null;
        }
        if (($parts[0] !== 'd' && $parts[0] !== 'm') || preg_match('/^\d{1,16}$/', $parts[1]) !== 1) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $parts[2]) !== 1) {
            return null;
        }

        return ['slot' => $parts[0], 'sort_ts' => (int) $parts[1], 'raw' => $parts[2]];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function byTime(array $a, array $b): int
    {
        $ts = ((int) $a['sort_ts']) <=> ((int) $b['sort_ts']);
        if ($ts !== 0) {
            return $ts;
        }
        $slot = (($a['slot'] ?? '') === 'd' ? 0 : 1) <=> (($b['slot'] ?? '') === 'd' ? 0 : 1);
        if ($slot !== 0) {
            return $slot;
        }
        $left = (string) ($a['raw'] ?? '');
        $right = (string) ($b['raw'] ?? '');
        if (preg_match('/^\d+$/', $left) === 1 && preg_match('/^\d+$/', $right) === 1) {
            return ((int) $left) <=> ((int) $right);
        }

        return strcmp($left, $right);
    }

    /**
     * @param list<array{id: string, role: string, content: string, ts: string}> $rows
     * @return list<array{id: string, role: string, content: string, ts: string, sender: string, origin: string}>
     */
    private function attribute(int $userId, string $session, array $rows, string $fallback): array
    {
        $ledger = [];
        foreach ($this->ledger($userId) as $entry) {
            if (($entry['session'] ?? '') === $session) {
                $ledger[] = $entry;
            }
        }
        $used = [];
        $out = [];
        foreach ($rows as $row) {
            $sender = 'Hermes';
            $origin = 'hermes';
            if ($row['role'] === 'user') {
                $match = $this->matchLedger($row, $ledger, $used);
                if ($match !== null) {
                    $sender = $match['label'];
                    $origin = $match['origin'];
                } elseif ($fallback === 'mcp') {
                    $sender = 'AI Agent (MCP)';
                    $origin = 'mcp';
                } else {
                    $sender = 'You';
                    $origin = 'you';
                }
            }
            $out[] = [
                'id'      => $row['id'],
                'role'    => $row['role'],
                'content' => $row['content'],
                'ts'      => $row['ts'],
                'sender'  => $sender,
                'origin'  => $origin,
            ];
        }

        return $out;
    }

    /**
     * @param array{id: string, role: string, content: string, ts: string} $message
     * @param list<array{session: string, hash: string, origin: string, label: string, at: int}> $ledger
     * @param array<int, true> $used
     * @return ?array{session: string, hash: string, origin: string, label: string, at: int}
     */
    private function matchLedger(array $message, array $ledger, array &$used): ?array
    {
        if ($message['ts'] === '') {
            return null;
        }
        $parsed = strtotime($message['ts']);
        if ($parsed === false || $parsed <= 0) {
            return null;
        }
        $hash = self::digest($message['content']);
        $best = null;
        $bestDelta = 181;
        foreach ($ledger as $i => $entry) {
            if (isset($used[$i]) || $entry['hash'] !== $hash) {
                continue;
            }
            $delta = abs($parsed - $entry['at']);
            if ($delta > 180 || $delta >= $bestDelta) {
                continue;
            }
            $best = $i;
            $bestDelta = $delta;
        }
        if ($best === null) {
            return null;
        }
        $used[$best] = true;

        return $ledger[$best];
    }

    /**
     * @return list<array{session: string, hash: string, origin: string, label: string, at: int}>
     */
    private function ledger(int $userId): array
    {
        $raw = $this->memory->read($userId, self::LEDGER);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $session = (string) ($row['session'] ?? '');
            $hash = (string) ($row['hash'] ?? '');
            $origin = (string) ($row['origin'] ?? '');
            $label = (string) ($row['label'] ?? '');
            $at = $row['at'] ?? 0;
            if (self::idOk($session) !== true || preg_match('/^[a-f0-9]{16}$/', $hash) !== 1) {
                continue;
            }
            if (($origin !== 'you' && $origin !== 'mcp') || $label === '' || !is_numeric($at)) {
                continue;
            }
            $out[] = [
                'session' => $session,
                'hash'    => $hash,
                'origin'  => $origin,
                'label'   => $label,
                'at'      => (int) $at,
            ];
        }

        return $out;
    }

    private function cleanLabel(string $label, string $origin): string
    {
        $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label) ?? '');
        if ($label === '') {
            return $origin === 'mcp' ? 'AI Agent (MCP)' : 'You';
        }

        return mb_substr($label, 0, 80);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, role: string, content: string, ts: string, sender: string, origin: string}
     */
    private function publish(array $row): array
    {
        return [
            'id'      => (string) $row['id'],
            'role'    => (string) $row['role'],
            'content' => (string) $row['content'],
            'ts'      => (string) $row['ts'],
            'sender'  => (string) ($row['sender'] ?? ''),
            'origin'  => (string) ($row['origin'] ?? ''),
        ];
    }

    private static function digest(string $text): string
    {
        return substr(hash('sha256', $text), 0, 16);
    }

    private static function idOk(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) === 1;
    }
}
