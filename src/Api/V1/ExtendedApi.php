<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Mcp\AgentPrompt;
use Dashboard\Router\Router;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\UnauthorizedException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

/**
 * L2–L7 routes on top of the L1 metrics/widgets API.
 */
final class ExtendedApi
{
    public function __construct(
        private readonly ApiRunner $runner,
        private readonly Services $svc,
    ) {}

    public function register(Router $r): void
    {
        $r->get('/api/v1/tokens', fn () => $this->run(fn (int $u) => ['tokens' => $this->svc->tokens->list($u)]));
        $r->post('/api/v1/tokens', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            $scopes = $b['scopes'] ?? ['read', 'write'];
            if (!is_array($scopes)) {
                throw ValidationException::withFields(['scopes' => 'scopes must be a list.']);
            }
            return ['token' => $this->svc->tokens->create($u, (string) ($b['name'] ?? 'Agent'), $scopes, isset($b['expires_at']) ? (string) $b['expires_at'] : null)];
        }, 201, true));
        $r->post('/api/v1/tokens/{id}/revoke', fn (array $p) => $this->run(fn (int $u) => [
            'token' => $this->svc->tokens->revoke($u, self::id($p)),
        ], 200, true));

        $r->get('/api/v1/audit', fn () => $this->run(fn (int $u) => [
            'events' => $this->svc->audit->recent($u, (int) ($_GET['limit'] ?? 40)),
        ]));
        $r->get('/api/v1/search', fn () => $this->run(fn (int $u) => $this->svc->search->search(
            $u,
            (string) ($_GET['q'] ?? ''),
            self::csv($_GET['include'] ?? null),
            self::csv($_GET['exclude'] ?? null),
        )));
        $r->get('/api/v1/agent-prompt', fn () => $this->run(fn (int $u) => [
            'prompt'  => AgentPrompt::text(),
            'scopes'  => Scope::AREAS,
        ]));

        $r->get('/api/v1/life/today', fn () => $this->run(fn (int $u) => [
            'today' => $this->svc->clock->today(),
            'rows'  => $this->svc->life->today($u),
        ]));
        $r->post('/api/v1/life/ingest', fn () => $this->run(function (int $u): array {
            $actor = $this->runner->actor();
            return $this->svc->life->ingest($u, $this->runner->body(), $actor->source(), $actor->kind, $actor->tool);
        }, 201, true));
        $r->get('/api/v1/life/hooks', fn () => $this->run(fn (int $u) => ['hooks' => $this->svc->life->hooks($u)]));
        $r->post('/api/v1/life/hooks', fn () => $this->run(fn (int $u) => [
            'hook' => $this->svc->life->createHook($u, (string) ($this->runner->body()['name'] ?? 'Home Assistant')),
        ], 201, true));
        $r->post('/api/v1/life/hooks/{id}/revoke', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $this->svc->life->revokeHook($u, self::id($p));
            return ['ok' => true];
        }, 200, true));
        $r->post('/api/v1/life/hook/{token}', function (array $p): void {
            $this->hook($p['token'] ?? '');
        });

        $r->get('/api/v1/pages', fn () => $this->run(fn (int $u) => [
            'pages' => $this->svc->pages->list($u, self::strParam('q'), (int) ($_GET['limit'] ?? 100)),
        ]));
        $r->post('/api/v1/pages', fn () => $this->run(function (int $u): array {
            $actor = $this->runner->actor();
            return ['page' => $this->svc->pages->create($u, $this->runner->body(), $actor->kind, $actor->tool)];
        }, 201, true));
        $r->get('/api/v1/pages/graph', fn () => $this->run(fn (int $u) => $this->svc->pages->graph($u)));
        $r->get('/api/v1/pages/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'page' => $this->svc->pages->get($u, self::id($p)),
        ]));
        $r->post('/api/v1/pages/{id}/update', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $actor = $this->runner->actor();
            return ['page' => $this->svc->pages->update($u, self::id($p), $this->runner->body(), $actor->kind, $actor->tool)];
        }, 200, true));
        $r->post('/api/v1/pages/{id}/restore', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $v = filter_var($this->runner->body()['version'] ?? null, FILTER_VALIDATE_INT);
            if ($v === false || $v < 1) {
                throw ValidationException::withFields(['version' => 'version must be a positive integer.']);
            }
            $actor = $this->runner->actor();
            return ['page' => $this->svc->pages->restore($u, self::id($p), $v, $actor->kind, $actor->tool)];
        }, 200, true));
        $r->post('/api/v1/pages/{id}/archive', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $this->svc->pages->archive($u, self::id($p), $this->runner->actor()->kind);
            return ['ok' => true];
        }, 200, true));
        $r->post('/api/v1/pages/{id}/embedding', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            $vector = $b['embedding'] ?? $b['vector'] ?? null;
            if (!is_array($vector) || !array_is_list($vector)) {
                throw ValidationException::withFields(['embedding' => 'embedding must be a list of numbers.']);
            }
            $this->svc->pages->setEmbedding($u, self::id($p), $vector, isset($b['model']) ? (string) $b['model'] : null);
            return ['ok' => true];
        }, 200, true));

        $r->get('/api/v1/money/accounts', fn () => $this->run(fn (int $u) => ['accounts' => $this->svc->money->accounts($u)]));
        $r->post('/api/v1/money/accounts', fn () => $this->run(fn (int $u) => [
            'account' => $this->svc->money->createAccount($u, $this->runner->body()),
        ], 201, true));
        $r->post('/api/v1/money/accounts/{id}/update', fn (array $p) => $this->run(fn (int $u) => [
            'account' => $this->svc->money->updateAccount($u, self::id($p), $this->runner->body()),
        ], 200, true));
        $r->post('/api/v1/money/accounts/{id}/archive', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $this->svc->money->archiveAccount($u, self::id($p));
            return ['ok' => true];
        }, 200, true));
        $r->get('/api/v1/money/transactions', fn () => $this->run(fn (int $u) => [
            'transactions' => $this->svc->money->transactions($u, self::strParam('from'), self::strParam('to'), (int) ($_GET['limit'] ?? 50)),
        ]));
        $r->post('/api/v1/money/transactions', fn () => $this->run(function (int $u): array {
            $source = $this->runner->actor()->kind === 'ai' ? 'ai' : 'manual';
            return ['transaction' => $this->svc->money->addTransaction($u, $this->runner->body(), $source)];
        }, 201, true));
        $r->post('/api/v1/money/import', fn () => $this->run(function (int $u): array {
            $csv = (string) ($this->runner->body()['csv'] ?? '');
            if (trim($csv) === '') {
                throw ValidationException::withFields(['csv' => 'Paste or upload a CSV.']);
            }
            return $this->svc->money->importCsv($u, $csv);
        }, 200, true));
        $r->get('/api/v1/money/summary', fn () => $this->run(function (int $u): array {
            $today = $this->svc->clock->today();
            $from = self::strParam('from') ?? Clock::shiftDate($today, 29);
            $to = self::strParam('to') ?? $today;
            return ['summary' => $this->svc->money->summary($u, $from, $to)];
        }));
        $r->post('/api/v1/money/weekly-review', fn () => $this->run(fn (int $u) => [
            'page' => $this->svc->money->weeklyReview($u),
        ], 201, true));

        $r->get('/api/v1/invest/scorecard', fn () => $this->run(fn (int $u) => [
            'scorecard' => $this->svc->invest->scorecard($u),
        ]));
        $r->post('/api/v1/invest/import', fn () => $this->run(function (int $u): array {
            $csv = (string) ($this->runner->body()['csv'] ?? '');
            if (trim($csv) === '') {
                throw ValidationException::withFields(['csv' => 'Paste a Robinhood activity CSV.']);
            }
            return $this->svc->invest->importRobinhood($u, $csv);
        }, 200, true));
        $r->post('/api/v1/invest/wallet', fn () => $this->run(fn (int $u) => [
            'wallet' => $this->svc->invest->saveWallet($u, (string) ($this->runner->body()['address'] ?? '')),
        ], 200, true));
        $r->post('/api/v1/invest/polymarket/refresh', fn () => $this->run(fn (int $u) => $this->svc->invest->refreshPolymarket($u), 200, true));

        $r->get('/api/v1/reddit/subs', fn () => $this->run(fn (int $u) => [
            'subs' => $this->svc->reddit->list($u),
        ]));
        $r->post('/api/v1/reddit/subs', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            return ['sub' => $this->svc->reddit->add(
                $u,
                (string) ($b['name'] ?? ''),
                (string) ($b['sort'] ?? 'hot'),
                (string) ($b['include'] ?? ''),
                (string) ($b['exclude'] ?? ''),
            )];
        }, 200, true));
        $r->post('/api/v1/reddit/subs/remove', fn () => $this->run(function (int $u): array {
            $this->svc->reddit->remove($u, (string) ($this->runner->body()['name'] ?? ''));
            return ['ok' => true];
        }, 200, true));
        $r->get('/api/v1/reddit/posts', fn () => $this->run(fn (int $u) => [
            'posts' => $this->svc->reddit->posts($u, [
                'subreddit' => self::strParam('subreddit') ?? '',
                'since'     => self::strParam('since') ?? '',
                'min_score' => (int) ($_GET['min_score'] ?? 0),
                'keyword'   => self::strParam('keyword') ?? '',
                'limit'     => (int) ($_GET['limit'] ?? 25),
            ]),
        ]));
        $r->get('/api/v1/reddit/posts/{post}/comments', fn (array $p) => $this->run(fn (int $u) => $this->svc->reddit->comments(
            $u,
            (string) ($p['post'] ?? ''),
            null,
            (int) ($_GET['limit'] ?? 20),
        )));
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false): void
    {
        $this->runner->run($fn, $status, $mutating);
    }

    private function hook(string $token): void
    {
        try {
            $userId = $this->svc->life->userForHook($token);
            $result = $this->svc->life->ingest($userId, $this->runner->body(), 'integration', 'integration', 'home-assistant');
            jsonResponse($result, 201);
        } catch (NotFoundException $e) {
            jsonResponse(['error' => $e->getMessage()], 404);
        } catch (ValidationException $e) {
            jsonResponse(['error' => $e->getMessage(), 'fields' => $e->fields()], 422);
        } catch (UnauthorizedException $e) {
            jsonResponse(['error' => $e->getMessage()], 401);
        }
    }

    /** @param array<string, string> $params */
    private static function id(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new NotFoundException('Not found.');
        }
        return $id;
    }

    /** @return list<string>|null */
    private static function csv(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return array_values(array_map(static fn (mixed $part): string => (string) $part, $value));
        }
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $value)),
            static fn (string $part): bool => $part !== ''
        ));
    }

    private static function strParam(string $name): ?string
    {
        $v = $_GET[$name] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }
}
