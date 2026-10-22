<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Router\Router;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

/** Grok Digest feed, sources, and the queued check. */
final class DigestApi
{
    public function __construct(
        private readonly ApiRunner $runner,
        private readonly Services $svc,
    ) {}

    public function register(Router $r): void
    {
        $r->get('/api/v1/digest/items', fn () => $this->run(fn (int $u) => $this->svc->digest->items($u, [
            'product' => $_GET['product'] ?? null,
            'since'   => $_GET['since'] ?? null,
            'unread'  => ($_GET['unread'] ?? '') === '1',
            'q'       => $_GET['q'] ?? null,
            'limit'   => $_GET['limit'] ?? 50,
        ])));
        $r->get('/api/v1/digest/sources', fn () => $this->run(fn (int $u) => $this->svc->digest->sources($u)));
        $r->post('/api/v1/digest/sources', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            return $this->svc->digest->add(
                $u,
                (string) ($body['product'] ?? ''),
                (string) ($body['label'] ?? ''),
                (string) ($body['url'] ?? ''),
            );
        }, 201, true));
        $r->post('/api/v1/digest/sources/{id}/disable', fn (array $p) => $this->run(function (int $u) use ($p): array {
            return $this->svc->digest->setEnabled($u, $this->id($p), false);
        }, 200, true));
        $r->post('/api/v1/digest/sources/{id}/enable', fn (array $p) => $this->run(function (int $u) use ($p): array {
            return $this->svc->digest->setEnabled($u, $this->id($p), true);
        }, 200, true));
        $r->post('/api/v1/digest/sources/{id}/remove', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $this->svc->digest->remove($u, $this->id($p));
            return ['ok' => true];
        }, 200, true));
        $r->post('/api/v1/digest/read', fn () => $this->run(fn (int $u) => $this->svc->digest->markRead($u), 200, true));
        $r->post('/api/v1/digest/check', fn () => $this->run(fn (int $u) => $this->svc->digest->queueCheck($u), 202, true));
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false): void
    {
        $this->runner->run(function (int $userId) use ($fn, $mutating): array {
            if ($mutating) {
                $this->svc->digest->assertAgentWrite($this->runner->tokenId());
            }
            return $fn($userId);
        }, $status, $mutating);
    }

    /**
     * @param array<string, string> $params
     */
    private function id(array $params): int
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw ValidationException::withFields(['id' => 'Source id is required.']);
        }
        return (int) $id;
    }
}
