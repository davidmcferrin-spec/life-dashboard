<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Agents\AgentName;
use Dashboard\Router\Router;
use Dashboard\Support\NotFoundException;
use Dashboard\Tracking\Services;

/** Handoff board. Writes are audited by ApiRunner. The sender is the key label. */
final class HandoffApi
{
    public function __construct(
        private readonly ApiRunner $runner,
        private readonly Services $svc,
    ) {}

    public function register(Router $r): void
    {
        $r->get('/api/v1/handoffs', fn () => $this->run(fn (int $u) => $this->list($u)));
        $r->post('/api/v1/handoffs', fn () => $this->run(fn (int $u) => $this->create($u, $this->runner->body()), 201, true));
        $r->get('/api/v1/handoffs/{id}', fn (array $p) => $this->run(fn (int $u) => $this->svc->handoffs->get($u, self::id($p))));
        $r->post('/api/v1/handoffs/{id}/claim', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();

            return $this->svc->handoffs->claim($u, self::id($p), $this->actor(), (string) ($body['note'] ?? ''));
        }, 200, true));
        $r->post('/api/v1/handoffs/{id}/status', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();

            return $this->svc->handoffs->updateStatus(
                $u,
                self::id($p),
                (string) ($body['status'] ?? ''),
                $this->actor(),
                (string) ($body['note'] ?? '')
            );
        }, 200, true));
        $r->post('/api/v1/handoffs/{id}/comment', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();

            return $this->svc->handoffs->comment($u, self::id($p), $this->actor(), (string) ($body['body'] ?? ''));
        }, 200, true));
        $r->post('/api/v1/handoffs/{id}/close', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();

            return $this->svc->handoffs->close(
                $u,
                self::id($p),
                $this->actor(),
                (string) ($body['status'] ?? 'done'),
                (string) ($body['note'] ?? '')
            );
        }, 200, true));
        $r->post('/api/v1/handoffs/{id}/reassign', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();

            return $this->svc->handoffs->reassign(
                $u,
                self::id($p),
                $this->actor(),
                $body['to_agent'] ?? null,
                (string) ($body['note'] ?? '')
            );
        }, 200, true));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{handoff: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function create(int $userId, array $input): array
    {
        return $this->svc->handoffs->create($userId, $input, $this->actor());
    }

    /**
     * @return array{handoffs: list<array<string, mixed>>}
     */
    public function list(int $userId): array
    {
        $mine = self::flag('mine');
        $filters = [
            'to_agent'      => self::str('to_agent'),
            'from_agent'    => self::str('from_agent'),
            'status'        => self::str('status'),
            'tag'           => self::str('tag'),
            'mine'          => $mine,
            'open_only'     => self::flag('open_only'),
            'updated_since' => self::str('updated_since'),
        ];
        if ($mine) {
            $filters['actor'] = $this->actor();
        }

        return $this->svc->handoffs->list($userId, $filters);
    }

    private function actor(): string
    {
        return AgentName::identity(
            $this->runner->actor()->kind === 'ai',
            $this->runner->tokenName(),
            $this->runner->editorName()
        );
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false): void
    {
        $this->runner->run($fn, $status, $mutating);
    }

    private static function str(string $name): ?string
    {
        $value = $_GET[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function flag(string $name): bool
    {
        $value = $_GET[$name] ?? null;

        return $value === '1' || $value === 'true' || $value === 'yes';
    }

    /** @param array<string, string> $params */
    private static function id(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new NotFoundException('That handoff was not found.');
        }

        return $id;
    }
}
