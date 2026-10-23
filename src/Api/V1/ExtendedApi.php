<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Auth\OAuthClientEndpoint;
use Dashboard\Auth\OAuthService;
use Dashboard\Auth\PgOAuthStore;
use Dashboard\Deploy\DeployService;
use Dashboard\Ollama\OllamaService;
use Dashboard\Pantry\MealBuilder;
use Dashboard\Mcp\AgentPrompt;
use Dashboard\Router\Router;
use Dashboard\Support\Clock;
use Dashboard\Support\ForbiddenException;
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
            $admin = (int) $this->svc->db->fetchScalar('SELECT is_admin FROM users WHERE id = ?', [$u]) === 1;
            return ['token' => $this->svc->tokens->create($u, (string) ($b['name'] ?? 'Agent'), $scopes, isset($b['expires_at']) ? (string) $b['expires_at'] : null, $admin)];
        }, 201, true));
        $r->get('/api/v1/system/deploy/status', fn () => $this->run(function (int $u): array {
            $this->assertSystemAdmin($u);
            return $this->deploy()->status();
        }, 200, false, 'system:read'));
        $r->get('/api/v1/system/deploy/log', fn () => $this->run(function (int $u): array {
            $this->assertSystemAdmin($u);
            $job = isset($_GET['job_id']) ? (string) $_GET['job_id'] : null;
            return $this->deploy()->log($job);
        }, 200, false, 'system:read'));
        $r->post('/api/v1/system/deploy/mcp-updates', fn () => $this->run(function (int $u): array {
            if ($this->runner->grantedScopes() !== null) {
                throw new ForbiddenException('Only an admin session can change MCP updates.');
            }
            $this->assertSystemAdmin($u);
            $b = $this->runner->body();
            if (array_diff(array_keys($b), ['enabled']) !== []) {
                throw ValidationException::withFields(['body' => 'Only enabled is accepted.']);
            }
            if (!array_key_exists('enabled', $b) || !is_bool($b['enabled'])) {
                throw ValidationException::withFields(['enabled' => 'enabled must be true or false.']);
            }
            return $this->deploy()->setMcpUpdates($b['enabled'], $u);
        }, 200, true, 'system:deploy'));
        $r->post('/api/v1/system/deploy', fn () => $this->run(function (int $u): array {
            $this->assertSystemAdmin($u);
            $b = $this->runner->body();
            if (array_diff(array_keys($b), ['confirm_sha', 'dry_run']) !== []) {
                throw ValidationException::withFields(['body' => 'Only confirm_sha and dry_run are accepted.']);
            }
            $dry = false;
            if (array_key_exists('dry_run', $b)) {
                if (!is_bool($b['dry_run'])) {
                    throw ValidationException::withFields(['dry_run' => 'dry_run must be true or false.']);
                }
                $dry = $b['dry_run'];
            }
            return $this->deploy()->request(
                (string) ($b['confirm_sha'] ?? ''),
                $dry,
                $u,
                $this->runner->tokenId(),
                $this->runner->tokenName(),
            );
        }, 202, true, 'system:deploy'));
        $r->post('/api/v1/tokens/{id}/revoke', fn (array $p) => $this->run(fn (int $u) => [
            'token' => $this->svc->tokens->revoke($u, self::id($p)),
        ], 200, true));
        $r->post('/api/v1/tokens/{id}/update', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            if (array_diff(array_keys($b), ['name', 'scopes']) !== []) {
                throw ValidationException::withFields(['body' => 'Only name and scopes are accepted.']);
            }
            if (!isset($b['name']) || !is_string($b['name'])) {
                throw ValidationException::withFields(['name' => 'Name must be 1–64 characters.']);
            }
            if (!isset($b['scopes']) || !is_array($b['scopes'])) {
                throw ValidationException::withFields(['scopes' => 'scopes must be a list.']);
            }
            foreach ($b['scopes'] as $scope) {
                if (!is_string($scope)) {
                    throw ValidationException::withFields(['scopes' => 'scopes must be a list.']);
                }
            }
            return $this->svc->tokens->update($u, self::id($p), $b['name'], $b['scopes']);
        }, 200, true));
        $r->post('/api/v1/tokens/{id}/delete', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            if ($b !== []) {
                throw ValidationException::withFields(['body' => 'Delete takes no fields.']);
            }
            return $this->svc->tokens->delete($u, self::id($p));
        }, 200, true));
        $r->post('/api/v1/oauth/clients/{id}/update', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $admin = (int) $this->svc->db->fetchScalar('SELECT is_admin FROM users WHERE id = ?', [$u]) === 1;
            $endpoint = new OAuthClientEndpoint(new OAuthService(new PgOAuthStore($this->svc->db)));
            $endpoint->authorize(
                $_SERVER,
                ['id' => $u, 'is_admin' => $admin ? 1 : 0],
                function_exists('requestCsrfToken') ? requestCsrfToken() : '',
                (string) ($_SESSION['csrf_token'] ?? '')
            );
            return $endpoint->update($u, $admin, self::id($p), $this->runner->body());
        }, 200, true));

        $r->get('/api/v1/audit/presence', fn () => $this->run(function (int $u): array {
            $after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT);
            if ($after === false || $after < 0) {
                $after = 0;
            }
            return ['events' => $this->svc->audit->presence($u, $after, (int) ($_GET['limit'] ?? 20))];
        }));
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
        $r->get('/api/v1/pages/{id}/revisions/{version}', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $v = filter_var($p['version'] ?? null, FILTER_VALIDATE_INT);
            if ($v === false || $v < 1) {
                throw ValidationException::withFields(['version' => 'version must be a positive integer.']);
            }
            return $this->svc->pages->revisionDiff($u, self::id($p), $v);
        }));
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

        $r->get('/api/v1/shopping/lists', fn () => $this->run(fn (int $u) => [
            'lists' => $this->svc->shopping->lists($u),
        ]));
        $r->post('/api/v1/shopping/lists', fn () => $this->run(fn (int $u) => [
            'list' => $this->svc->shopping->createList($u, (string) ($this->runner->body()['name'] ?? '')),
        ], 201, true));
        $r->post('/api/v1/shopping/lists/{id}/clear-bought', fn (array $p) => $this->run(fn (int $u) => [
            'cleared' => $this->svc->shopping->clearBought($u, self::id($p)),
            'list'    => ['id' => self::id($p)],
        ], 200, true));
        $r->post('/api/v1/shopping/clear-bought', fn () => $this->run(fn (int $u) => [
            'cleared' => $this->svc->shopping->clearBought($u, null),
        ], 200, true));
        $r->get('/api/v1/shopping/items', fn () => $this->run(function (int $u): array {
            $listId = isset($_GET['list_id']) && $_GET['list_id'] !== '' ? (int) $_GET['list_id'] : null;
            $include = !isset($_GET['include_bought']) || filter_var($_GET['include_bought'], FILTER_VALIDATE_BOOLEAN);
            $hours = isset($_GET['clear_hours']) ? (int) $_GET['clear_hours'] : 24;
            return ['items' => $this->svc->shopping->items($u, $listId, $include, $hours)];
        }));
        $r->post('/api/v1/shopping/items', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            $listId = (int) ($b['list_id'] ?? 0);
            $raw = $b['items'] ?? null;
            if ($raw === null && (isset($b['text']) || isset($b['name']))) {
                $raw = [$b];
            }
            if (!is_array($raw)) {
                throw ValidationException::withFields(['items' => 'Add at least one item.']);
            }
            $items = $this->svc->shopping->addItems($u, $listId, array_values($raw), $this->addedBy());
            return [
                'items'   => $items,
                'item'    => $items[0] ?? null,
                'list_id' => $listId,
            ];
        }, 201, true));
        $r->post('/api/v1/shopping/items/{id}/check', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            $bought = !array_key_exists('bought', $b) || filter_var($b['bought'], FILTER_VALIDATE_BOOLEAN);
            return ['item' => $this->svc->shopping->checkItem($u, self::id($p), $bought)];
        }, 200, true));
        $r->post('/api/v1/shopping/items/{id}/delete', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $id = self::id($p);
            $this->svc->shopping->removeItem($u, $id);
            return ['id' => $id, 'removed' => true];
        }, 200, true));
        $r->post('/api/v1/shopping/items/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'item' => $this->svc->shopping->updateItem($u, self::id($p), $this->runner->body()),
        ], 200, true));

        // /query, not /search: Scope::forPath would otherwise see the search area.
        $r->get('/api/v1/calls/stats', fn () => $this->run(fn (int $u) => [
            'stats' => $this->svc->calls->stats($u),
        ]));
        $r->get('/api/v1/calls/query', fn () => $this->run(fn (int $u) => [
            'calls' => $this->svc->calls->search($u, (string) ($_GET['q'] ?? ''), (int) ($_GET['limit'] ?? 30)),
        ]));
        $r->get('/api/v1/calls/lookup', fn () => $this->run(fn (int $u) => [
            'lookup' => $this->svc->calls->lookup($u, (string) ($_GET['phone'] ?? '')),
        ]));
        $r->get('/api/v1/calls/contacts', fn () => $this->run(fn (int $u) => [
            'contacts' => $this->svc->calls->contacts($u),
        ]));
        $r->post('/api/v1/calls/contacts', fn () => $this->run(fn (int $u) => [
            'contact' => $this->svc->calls->upsertContact($u, $this->runner->body()),
        ], 200, true));
        $r->get('/api/v1/calls/agencies', fn () => $this->run(fn (int $u) => [
            'agencies' => $this->svc->calls->agencies($u),
        ]));
        $r->post('/api/v1/calls/agencies', fn () => $this->run(fn (int $u) => [
            'agency' => $this->svc->calls->upsertAgency($u, $this->runner->body()),
        ], 200, true));
        $r->get('/api/v1/calls', fn () => $this->run(function (int $u): array {
            $filters = [
                'status'        => self::strParam('status'),
                'category'      => self::strParam('category'),
                'urgency'       => self::strParam('urgency'),
                'handled_by'    => self::strParam('handled_by'),
                'from'          => self::strParam('from'),
                'to'            => self::strParam('to'),
                'phone'         => self::strParam('phone'),
                'limit'         => (int) ($_GET['limit'] ?? 50),
            ];
            if (array_key_exists('return_needed', $_GET) && $_GET['return_needed'] !== '') {
                $filters['return_needed'] = filter_var($_GET['return_needed'], FILTER_VALIDATE_BOOLEAN);
            }
            return ['calls' => $this->svc->calls->listCalls($u, $filters)];
        }));
        $r->post('/api/v1/calls', fn () => $this->run(function (int $u): array {
            $source = $this->runner->actor()->kind === 'ai' ? 'ai' : 'manual';
            return ['call' => $this->svc->calls->log($u, $this->runner->body(), $source)];
        }, 201, true));
        $r->post('/api/v1/calls/{id}/status', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            $return = array_key_exists('return_needed', $b) ? filter_var($b['return_needed'], FILTER_VALIDATE_BOOLEAN) : null;
            return ['call' => $this->svc->calls->setStatus($u, self::id($p), (string) ($b['status'] ?? ''), $return)];
        }, 200, true));
        $r->post('/api/v1/calls/{id}/todo', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->runner->body();
            $todo = $b['todo_id'] ?? $b['linked_todo_id'] ?? null;
            $id = ($todo === null || $todo === '' || $todo === 0 || $todo === '0') ? null : (int) $todo;
            return ['call' => $this->svc->calls->linkTodo($u, self::id($p), $id)];
        }, 200, true));
        $r->get('/api/v1/calls/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'call' => $this->svc->calls->get($u, self::id($p)),
        ]));
        $r->post('/api/v1/calls/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'call' => $this->svc->calls->update($u, self::id($p), $this->runner->body()),
        ], 200, true));

        $this->registerPantry($r);
        $this->registerOllama($r);
        (new DigestApi($this->runner, $this->svc))->register($r);
    }

    private function registerOllama(Router $r): void
    {
        $ollama = fn (): OllamaService => new OllamaService($this->svc->db);
        $r->get('/api/v1/ollama/status', fn () => $this->run(function (int $u) use ($ollama): array {
            $this->assertOllamaAdmin($u);

            return $ollama()->view();
        }));
        $r->get('/api/v1/ollama/models', fn () => $this->run(function (int $u) use ($ollama): array {
            $this->assertOllamaAdmin($u);

            return $ollama()->modelList();
        }));
        $r->post('/api/v1/ollama/settings', fn () => $this->run(function (int $u) use ($ollama): array {
            $this->assertOllamaAdmin($u);
            $this->assertOllamaWrite();

            return ['settings' => $ollama()->save($this->runner->body())];
        }, 200, true));
        $r->post('/api/v1/ollama/test', fn () => $this->run(function (int $u) use ($ollama): array {
            $this->assertOllamaAdmin($u);

            return $ollama()->test();
        }, 200, true, 'ollama:read'));
        $r->post('/api/v1/ollama/generate', fn () => $this->run(function (int $u) use ($ollama): array {
            $this->assertOllamaAdmin($u);
            $b = $this->runner->body();
            $model = isset($b['model']) ? (string) $b['model'] : null;

            return $ollama()->generate((string) ($b['prompt'] ?? ''), $model);
        }, 200, true, 'ollama:read'));
        $r->post('/api/v1/ollama/pull', fn () => $this->run(function (int $u) use ($ollama): array {
            $this->assertOllamaAdmin($u);
            $this->assertOllamaWrite();

            return $ollama()->queue('ollama-pull', (string) ($this->runner->body()['model'] ?? ''));
        }, 202, true));
        $r->post('/api/v1/ollama/remove', fn () => $this->run(function (int $u) use ($ollama): array {
            $this->assertOllamaAdmin($u);
            $this->assertOllamaWrite();

            return $ollama()->queue('ollama-remove', (string) ($this->runner->body()['model'] ?? ''));
        }, 202, true));
    }

    private function registerPantry(Router $r): void
    {
        $r->get('/api/v1/pantry/meals/components', fn () => $this->run(function (int $u): array {
            $category = isset($_GET['category']) ? (string) $_GET['category'] : null;
            $onHand = null;
            if (array_key_exists('on_hand', $_GET) && $_GET['on_hand'] !== '') {
                $onHand = filter_var($_GET['on_hand'], FILTER_VALIDATE_BOOLEAN);
            }
            return $this->svc->meals->listComponents($u, $category, $onHand);
        }));
        $r->post('/api/v1/pantry/meals/components', fn () => $this->run(function (int $u): array {
            $this->assertMealWrite();
            return $this->svc->meals->addComponent($u, $this->runner->body());
        }, 201, true));
        $r->post('/api/v1/pantry/meals/components/{id}/remove', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $this->assertMealWrite();
            return $this->svc->meals->removeComponent($u, self::id($p));
        }, 200, true));
        $r->get('/api/v1/pantry/meals/components/{id}/pairings', fn (array $p) => $this->run(
            fn (int $u) => $this->svc->meals->listPairings($u, self::id($p))
        ));
        $r->post('/api/v1/pantry/meals/components/{id}', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $this->assertMealWrite();
            return $this->svc->meals->updateComponent($u, self::id($p), $this->runner->body());
        }, 200, true));
        $r->post('/api/v1/pantry/meals/pairings/remove', fn () => $this->run(function (int $u): array {
            $this->assertMealWrite();
            return $this->svc->meals->removePairing($u, $this->runner->body());
        }, 200, true));
        $r->post('/api/v1/pantry/meals/pairings', fn () => $this->run(function (int $u): array {
            $this->assertMealWrite();
            return $this->svc->meals->addPairing($u, $this->runner->body());
        }, 200, true));
        $r->post('/api/v1/pantry/meals/view', fn () => $this->run(function (int $u): array {
            $this->assertMealWrite();
            $body = $this->runner->body();
            if (!array_key_exists('on_hand_only', $body) || !is_bool($body['on_hand_only'])) {
                throw ValidationException::withFields(['on_hand_only' => 'on_hand_only must be true or false.']);
            }
            return $this->svc->meals->suggest($u, [], $body['on_hand_only'], true);
        }, 200, true));
        $r->post('/api/v1/pantry/meals/recipe', fn () => $this->run(function (int $u): array {
            $this->assertMealWrite();
            $body = $this->runner->body();
            $picks = $body['picks'] ?? [];
            if (!is_array($picks)) {
                throw ValidationException::withFields(['picks' => 'picks must be a list of component ids.']);
            }
            return $this->svc->meals->saveRecipe($u, (string) ($body['name'] ?? ''), $picks);
        }, 201, true));
        $r->post('/api/v1/pantry/meals/groceries', fn () => $this->run(function (int $u): array {
            $this->assertMealWrite();
            $body = $this->runner->body();
            $picks = $body['picks'] ?? [];
            if (!is_array($picks)) {
                throw ValidationException::withFields(['picks' => 'picks must be a list of component ids.']);
            }
            return $this->svc->meals->addMissing($u, $picks, $this->addedBy());
        }, 200, true));
        $r->get('/api/v1/pantry/meals/prep', fn () => $this->run(
            fn (int $u) => $this->svc->meals->planPrep($u, $this->prepInput(false))
        ));
        $r->post('/api/v1/pantry/meals/prep', fn () => $this->run(function (int $u): array {
            $this->assertMealWrite();
            return $this->svc->meals->logPrep($u, $this->prepInput(true), $this->actorLabel());
        }, 201, true));
        $r->get('/api/v1/pantry/meals', fn () => $this->run(function (int $u): array {
            $picks = self::csv($_GET['picks'] ?? null) ?? [];
            $only = null;
            if (array_key_exists('on_hand_only', $_GET) && $_GET['on_hand_only'] !== '') {
                $only = filter_var($_GET['on_hand_only'], FILTER_VALIDATE_BOOLEAN);
            }
            return $this->svc->meals->suggest($u, $picks, $only, false);
        }));
        $r->get('/api/v1/pantry/lots', fn () => $this->run(fn (int $u) => $this->svc->pantry->listLots($u, $_GET)));
        $r->get('/api/v1/pantry/units', fn () => $this->run(fn (int $u) => $this->svc->pantry->units()));
        $r->get('/api/v1/pantry/summary', fn () => $this->run(function (int $u): array {
            $days = isset($_GET['soon_days']) ? (int) $_GET['soon_days'] : 3;
            return $this->svc->pantry->summary($u, $days);
        }));
        $r->get('/api/v1/pantry/expiring', fn () => $this->run(fn (int $u) => $this->svc->pantry->expiring($u, (int) ($_GET['days'] ?? 3))));
        $r->post('/api/v1/pantry/purge', fn () => $this->run(function (int $u): array {
            $ids = $this->runner->body()['lot_ids'] ?? [];
            if (!is_array($ids)) {
                throw ValidationException::withFields(['lot_ids' => 'lot_ids must be a list.']);
            }
            return $this->svc->pantry->toss($u, $ids, $this->actorLabel());
        }, 200, true));
        $r->get('/api/v1/pantry/low-stock', fn () => $this->run(fn (int $u) => $this->svc->pantry->lowStock($u, false, null, $this->actorLabel())));
        $r->post('/api/v1/pantry/low-stock', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            $listId = isset($body['list_id']) && $body['list_id'] !== '' ? (int) $body['list_id'] : 1;
            return $this->svc->pantry->lowStock($u, true, $listId, $this->actorLabel());
        }, 200, true));
        $r->get('/api/v1/pantry/stats', fn () => $this->run(fn (int $u) => $this->svc->pantry->stats(
            $u,
            isset($_GET['from']) ? (string) $_GET['from'] : null,
            isset($_GET['to']) ? (string) $_GET['to'] : null
        )));
        $r->get('/api/v1/pantry/products', fn () => $this->run(fn (int $u) => [
            'products' => $this->svc->pantry->listProducts($u, isset($_GET['search']) ? (string) $_GET['search'] : null),
        ]));
        $r->post('/api/v1/pantry/products', fn () => $this->run(fn (int $u) => [
            'product' => $this->svc->pantry->upsertProduct($u, $this->runner->body()),
        ], 200, true));
        $r->post('/api/v1/pantry/products/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'product' => $this->svc->pantry->updateProductShelf($u, self::id($p), $this->runner->body()),
        ], 200, true));
        $r->get('/api/v1/pantry/products/upc/{upc}', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $external = filter_var($_GET['external'] ?? '0', FILTER_VALIDATE_BOOLEAN);
            return $this->svc->pantry->productByUpc($u, (string) ($p['upc'] ?? ''), $external);
        }));
        $r->get('/api/v1/pantry/lookup', fn () => $this->run(function (int $u): array {
            $external = !isset($_GET['external']) || filter_var($_GET['external'], FILTER_VALIDATE_BOOLEAN);
            return $this->svc->pantry->productByUpc($u, (string) ($_GET['upc'] ?? ''), $external);
        }));
        $r->post('/api/v1/pantry/lots', fn () => $this->run(function (int $u): array {
            $items = $this->runner->body()['items'] ?? null;
            if (!is_array($items)) {
                throw ValidationException::withFields(['items' => 'items must be a list.']);
            }
            return $this->svc->pantry->addItems($u, $items, $this->actorLabel());
        }, 201, true));
        $r->post('/api/v1/pantry/consume', fn () => $this->run(fn (int $u) => $this->svc->pantry->consume($u, $this->runner->body(), $this->actorLabel()), 200, true));
        $r->post('/api/v1/pantry/lots/{id}', fn (array $p) => $this->run(fn (int $u) => $this->svc->pantry->adjust($u, self::id($p), $this->runner->body(), $this->actorLabel()), 200, true));
        $r->post('/api/v1/pantry/lots/{id}/split', fn (array $p) => $this->run(fn (int $u) => $this->svc->pantry->splitOrMove($u, self::id($p), $this->runner->body(), $this->actorLabel()), 200, true));
        $r->post('/api/v1/pantry/lots/{id}/open', fn (array $p) => $this->run(fn (int $u) => $this->svc->pantry->openLot($u, self::id($p), $this->actorLabel()), 200, true));
        $r->post('/api/v1/pantry/lots/{id}/toss', fn (array $p) => $this->run(fn (int $u) => $this->svc->pantry->toss($u, [self::id($p)], $this->actorLabel()), 200, true));
        $r->post('/api/v1/pantry/import-order', fn () => $this->run(fn (int $u) => $this->svc->pantry->importOrder($u, $this->runner->body(), $this->actorLabel()), 200, true));
        $r->get('/api/v1/pantry/categories', fn () => $this->run(fn (int $u) => ['categories' => $this->svc->pantry->categories($u)]));
        $r->post('/api/v1/pantry/categories', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            return ['category' => $this->svc->pantry->updateCategory($u, (string) ($body['category'] ?? ''), $body)];
        }, 200, true));
        $r->get('/api/v1/pantry/recipes', fn () => $this->run(function (int $u): array {
            $prep = null;
            if (array_key_exists('meal_prep', $_GET) && $_GET['meal_prep'] !== '') {
                $parsed = filter_var($_GET['meal_prep'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed === null) {
                    throw ValidationException::withFields(['meal_prep' => 'meal_prep must be true or false.']);
                }
                $prep = $parsed;
            }
            return ['recipes' => $this->svc->pantry->listRecipes($u, $prep)];
        }));
        $r->post('/api/v1/pantry/recipes', fn () => $this->run(fn (int $u) => [
            'recipe' => $this->svc->pantry->upsertRecipe($u, $this->runner->body()),
        ], 200, true));
        $r->post('/api/v1/pantry/recipes/{id}', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();
            $body['recipe_id'] = self::id($p);
            return ['recipe' => $this->svc->pantry->upsertRecipe($u, $body)];
        }, 200, true));
        $r->get('/api/v1/pantry/what-can-i-make', fn () => $this->run(fn (int $u) => $this->svc->pantry->whatCanIMake($u)));
    }

    private function actorLabel(): string
    {
        if ($this->runner->actor()->kind === 'ai') {
            $name = $this->runner->tokenName();
            return $name !== null && $name !== '' ? $name : 'agent';
        }
        $name = $this->svc->db->fetchScalar('SELECT username FROM users WHERE id = ?', [$this->runner->actor()->userId]);
        return is_string($name) && $name !== '' ? $name : 'david';
    }

    private function weeklyReview(): void
    {
        $body = $this->runner->body();
        $write = !array_key_exists('write_page', $body) || filter_var($body['write_page'], FILTER_VALIDATE_BOOLEAN);
        $this->runner->run(function (int $u) use ($write): array {
            return ['page' => $this->svc->money->weeklyReview($u, $write)];
        }, $write ? 201 : 200, true);
    }

    private function addedBy(): string
    {
        return $this->runner->actor()->kind === 'ai' ? 'agent' : 'user';
    }

    /**
     * @return array{servings: mixed, recipe_id: mixed, picks: list<mixed>, name: mixed}
     */
    private function prepInput(bool $fromBody): array
    {
        if ($fromBody) {
            $body = $this->runner->body();
            $picks = $body['picks'] ?? [];
            if (!is_array($picks)) {
                throw ValidationException::withFields(['picks' => 'picks must be a list of component ids.']);
            }
            return [
                'servings' => $body['servings'] ?? null,
                'recipe_id' => $body['recipe_id'] ?? null,
                'picks' => array_values($picks),
                'name' => $body['name'] ?? null,
            ];
        }
        return [
            'servings' => $_GET['servings'] ?? null,
            'recipe_id' => $_GET['recipe_id'] ?? null,
            'picks' => self::csv($_GET['picks'] ?? null) ?? [],
            'name' => isset($_GET['name']) ? (string) $_GET['name'] : null,
        ];
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
    /**
     * @param list<string>|string|null $scope
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false, array|string|null $scope = null): void
    {
        $this->runner->run($fn, $status, $mutating, $scope);
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

    private function deploy(): DeployService
    {
        return new DeployService($this->svc->db);
    }

    private function assertMealWrite(): void
    {
        if (!MealBuilder::mcpWriteAllowed($this->deploy()->mcpUpdatesEnabled(), $this->runner->tokenId())) {
            throw new ForbiddenException(DeployService::MCP_DISABLED);
        }
    }

    private function assertOllamaAdmin(int $userId): void
    {
        if ($this->runner->grantedScopes() !== null) {
            return;
        }
        $flag = $this->svc->db->fetchScalar('SELECT is_admin FROM users WHERE id = ?', [$userId]);
        if ((int) $flag !== 1) {
            throw new ForbiddenException('Local AI settings require an admin session.');
        }
    }

    private function assertOllamaWrite(): void
    {
        OllamaService::assertChange($this->runner->tokenId(), $this->deploy()->mcpUpdatesEnabled());
    }

    private function assertSystemAdmin(int $userId): void
    {
        if ($this->runner->grantedScopes() !== null) {
            return;
        }
        $flag = $this->svc->db->fetchScalar('SELECT is_admin FROM users WHERE id = ?', [$userId]);
        if ((int) $flag !== 1) {
            throw new ForbiddenException('System tools require an admin session.');
        }
    }
}
