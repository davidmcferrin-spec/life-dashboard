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
        $r->get('/api/v1/audit/log', fn () => $this->runner->run(function (int $u): array {
            $entity = self::strParam('entity');
            $allow = Scope::auditEntityAllow($this->runner->grantedScopes(), $entity);
            $actor = self::strParam('actor') ?? self::strParam('source');
            return ['events' => $this->svc->audit->search($u, [
                'since'     => self::strParam('since'),
                'until'     => self::strParam('until'),
                'actor'     => $actor,
                'tool'      => self::strParam('tool'),
                'entity'    => $entity,
                'entity_id' => $_GET['entity_id'] ?? null,
                'limit'     => (int) ($_GET['limit'] ?? 50),
            ], $allow)];
        }, 200, false, ['audit:read', 'money:read', 'todos:read']));
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

        $r->get('/api/v1/pages', fn () => $this->run(function (int $u): array {
            [$folderId, $folderSet] = $this->folderFilter($u);
            return ['pages' => $this->svc->pages->list($u, self::strParam('q'), (int) ($_GET['limit'] ?? 100), $folderId, $folderSet)];
        }));
        $r->post('/api/v1/pages', fn () => $this->run(function (int $u): array {
            $actor = $this->runner->actor();
            return ['page' => $this->svc->pages->create($u, $this->runner->body(), $actor->kind, $actor->tool)];
        }, 201, true));
        $r->get('/api/v1/pages/folders', fn () => $this->run(fn (int $u) => [
            'folders' => $this->svc->pages->folderTree($u),
        ]));
        $r->post('/api/v1/pages/folders', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            $actor = $this->runner->actor();
            $parent = self::nullableId($b['parent_id'] ?? null);
            return ['folder' => $this->svc->pages->createFolder($u, (string) ($b['name'] ?? ''), $parent, $actor->kind, $actor->tool)];
        }, 201, true));
        $r->post('/api/v1/pages/folders/{id}/move', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            if (!array_key_exists('parent_id', $b)) {
                throw ValidationException::withFields(['parent_id' => 'parent_id is required. Use null for the root.']);
            }
            $actor = $this->runner->actor();
            return ['folder' => $this->svc->pages->moveFolder($u, self::id($p), self::nullableId($b['parent_id']), $actor->kind, $actor->tool)];
        }, 200, true));
        $r->post('/api/v1/pages/folders/{id}/delete', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            $move = array_key_exists('move_contents', $b) && filter_var($b['move_contents'], FILTER_VALIDATE_BOOLEAN);
            $actor = $this->runner->actor();
            $this->svc->pages->deleteFolder($u, self::id($p), $move, $actor->kind, $actor->tool);
            return ['ok' => true, 'folder_id' => self::id($p)];
        }, 200, true));
        $r->post('/api/v1/pages/folders/{id}', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $actor = $this->runner->actor();
            return ['folder' => $this->svc->pages->renameFolder($u, self::id($p), (string) ($this->runner->body()['name'] ?? ''), $actor->kind, $actor->tool)];
        }, 200, true));
        $r->get('/api/v1/pages/graph', fn () => $this->run(fn (int $u) => $this->svc->pages->graph($u)));
        $r->get('/api/v1/pages/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'page' => $this->svc->pages->get($u, self::id($p)),
        ]));
        $r->post('/api/v1/pages/{id}/move', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            if (!array_key_exists('folder_id', $b) && !array_key_exists('folder', $b)) {
                throw ValidationException::withFields(['folder_id' => 'folder_id is required. Use null for the root.']);
            }
            $actor = $this->runner->actor();
            $this->svc->pages->movePage($u, self::id($p), $this->svc->pages->folderFromInput($u, $b), $actor->kind, $actor->tool);
            return ['page' => $this->svc->pages->get($u, self::id($p))];
        }, 200, true));
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
            'transactions' => $this->svc->money->transactions(
                $u,
                self::strParam('from'),
                self::strParam('to'),
                (int) ($_GET['limit'] ?? 50),
                self::strParam('category'),
                self::strParam('account'),
            ),
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
        $r->post('/api/v1/money/weekly-review', fn () => $this->weeklyReview());
        $r->post('/api/v1/money/transactions/bulk', fn () => $this->run(function (int $u): array {
            $items = $this->runner->body()['transactions'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw ValidationException::withFields(['transactions' => 'transactions must be a list.']);
            }
            $source = $this->runner->actor()->kind === 'ai' ? 'ai' : 'manual';
            return $this->svc->money->addTransactions($u, $items, $source);
        }, 200, true));
        $r->get('/api/v1/money/budget', fn () => $this->run(function (int $u): array {
            $today = $this->svc->clock->today();
            $year = (int) ($_GET['year'] ?? substr($today, 0, 4));
            $month = (int) ($_GET['month'] ?? substr($today, 5, 2));
            return ['budget' => $this->svc->plan->budget($u, $year, $month)];
        }));
        $r->post('/api/v1/money/budget', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            return ['budget' => $this->svc->plan->setBudgetLine(
                $u,
                (int) ($b['year'] ?? 0),
                (int) ($b['month'] ?? 0),
                (string) ($b['category'] ?? ''),
                (float) ($b['amount'] ?? 0),
                isset($b['due_day']) && $b['due_day'] !== '' ? (int) $b['due_day'] : null,
            )];
        }, 200, true));
        $r->post('/api/v1/money/category-caps', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            $weekly = array_key_exists('weekly_cap', $b) && $b['weekly_cap'] !== '' && $b['weekly_cap'] !== null
                ? (float) $b['weekly_cap'] : null;
            $monthly = array_key_exists('monthly_cap', $b) && $b['monthly_cap'] !== '' && $b['monthly_cap'] !== null
                ? (float) $b['monthly_cap'] : null;
            return ['cap' => $this->svc->plan->setCategoryCap(
                $u,
                (string) ($b['category'] ?? ''),
                $weekly,
                $monthly,
                (string) ($b['effective_from'] ?? ''),
            )];
        }, 200, true));
        $r->get('/api/v1/money/debts', fn () => $this->run(fn (int $u) => ['debts' => $this->svc->plan->debts($u)]));
        $r->post('/api/v1/money/debts', fn () => $this->run(fn (int $u) => [
            'debt' => $this->svc->plan->saveDebt($u, $this->runner->body()),
        ], 201, true));
        $r->post('/api/v1/money/debts/{id}/archive', fn (array $p) => $this->run(fn (int $u) => [
            'debt' => $this->svc->plan->archiveDebt($u, self::id($p)),
        ], 200, true));
        $r->post('/api/v1/money/debts/{id}/delete', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $id = self::id($p);
            $this->svc->plan->deleteDebt($u, $id);
            return ['ok' => true, 'debt_id' => $id];
        }, 200, true));
        $r->post('/api/v1/money/debts/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'debt' => $this->svc->plan->updateDebt($u, self::id($p), $this->runner->body()),
        ], 200, true));
        $r->get('/api/v1/money/sinking-funds', fn () => $this->run(fn (int $u) => [
            'sinking_funds' => $this->svc->plan->sinkingFunds($u),
        ]));
        $r->post('/api/v1/money/sinking-funds', fn () => $this->run(fn (int $u) => [
            'sinking_fund' => $this->svc->plan->saveSinkingFund($u, $this->runner->body()),
        ], 201, true));
        $r->post('/api/v1/money/sinking-funds/{id}/archive', fn (array $p) => $this->run(fn (int $u) => [
            'sinking_fund' => $this->svc->plan->archiveSinkingFund($u, self::id($p)),
        ], 200, true));
        $r->post('/api/v1/money/sinking-funds/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'sinking_fund' => $this->svc->plan->updateSinkingFund($u, self::id($p), $this->runner->body()),
        ], 200, true));
        $r->post('/api/v1/money/snapshots', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            $breakdown = $b['breakdown'] ?? null;
            if ($breakdown !== null && !is_array($breakdown)) {
                throw ValidationException::withFields(['breakdown' => 'Breakdown must be an object of label to amount.']);
            }
            return ['snapshot' => $this->svc->money->addSnapshot(
                $u,
                (string) ($b['date'] ?? $b['as_of'] ?? ''),
                isset($b['net_worth']) ? (float) $b['net_worth'] : null,
                is_array($breakdown) ? $breakdown : null,
            )];
        }, 200, true));
        $r->post('/api/v1/money/credit-scores', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            return ['credit' => $this->svc->plan->addCreditScore(
                $u,
                (string) ($b['date'] ?? $b['as_of'] ?? ''),
                (int) ($b['score'] ?? 0),
                isset($b['utilization']) && $b['utilization'] !== '' ? (float) $b['utilization'] : null,
            )];
        }, 200, true));
        $r->post('/api/v1/money/baby-step', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            return ['baby_step' => $this->svc->plan->setBabyStep($u, (int) ($b['step'] ?? 0), isset($b['note']) ? (string) $b['note'] : null)];
        }, 200, true));
        $r->get('/api/v1/money/dashboard', fn () => $this->run(fn (int $u) => [
            'dashboard' => $this->svc->plan->dashboard($u),
        ]));

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

        $r->get('/api/v1/todos/categories', fn () => $this->run(fn (int $u) => [
            'categories' => $this->todo(fn () => $this->svc->todos->agentCategories($u)),
        ]));
        $r->post('/api/v1/todos/categories', fn () => $this->run(fn (int $u) => [
            'category' => $this->todo(fn () => $this->svc->todos->agentCreateCategory($u, (string) ($this->runner->body()['name'] ?? ''))),
        ], 201, true));
        $r->get('/api/v1/todos/items', fn () => $this->run(fn (int $u) => [
            'todos' => $this->todo(fn () => $this->svc->todos->agentList($u, [
                'category'   => self::strParam('category'),
                'status'     => self::strParam('status') ?? 'open',
                'due_before' => self::strParam('due_before'),
                'due_after'  => self::strParam('due_after'),
                'priority'   => $_GET['priority'] ?? null,
                'limit'      => (int) ($_GET['limit'] ?? 50),
            ])),
        ]));
        $r->post('/api/v1/todos/items', fn () => $this->run(fn (int $u) => [
            'todo' => $this->todo(fn () => $this->svc->todos->agentCreate($u, $this->runner->body())),
        ], 201, true));
        $r->post('/api/v1/todos/items/{id}/complete', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            $reopen = array_key_exists('reopen', $b) && filter_var($b['reopen'], FILTER_VALIDATE_BOOLEAN);
            return ['todo' => $this->todo(fn () => $this->svc->todos->agentComplete($u, self::id($p), $reopen))];
        }, 200, true));
        $r->post('/api/v1/todos/items/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'todo' => $this->todo(fn () => $this->svc->todos->agentUpdate($u, self::id($p), $this->runner->body())),
        ], 200, true));
    }

    private function weeklyReview(): void
    {
        $body = $this->runner->body();
        $write = !array_key_exists('write_page', $body) || filter_var($body['write_page'], FILTER_VALIDATE_BOOLEAN);
        $this->runner->run(function (int $u) use ($write): array {
            return ['page' => $this->svc->money->weeklyReview($u, $write)];
        }, $write ? 201 : 200, true);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function todo(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withFields(['todo' => $e->getMessage()]);
        }
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

    /**
     * @return array{0: ?int, 1: bool}
     */
    private function folderFilter(int $userId): array
    {
        if (array_key_exists('folder_id', $_GET) && $_GET['folder_id'] !== '') {
            $raw = (string) $_GET['folder_id'];
            if ($raw === '0' || $raw === 'root') {
                return [null, true];
            }
            return [(int) $raw, true];
        }
        $path = self::strParam('folder');
        if ($path === null) {
            return [null, false];
        }
        if (strcasecmp($path, 'root') === 0) {
            return [null, true];
        }
        return [$this->svc->pages->folderIdByPath($userId, $path), true];
    }

    private static function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0' || $value === 'root') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 1) {
            throw ValidationException::withFields(['folder_id' => 'Use a folder id, or null for the root.']);
        }
        return (int) $value;
    }

    private static function strParam(string $name): ?string
    {
        $v = $_GET[$name] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }
}
