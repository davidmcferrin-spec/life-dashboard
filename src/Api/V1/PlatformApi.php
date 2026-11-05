<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Grants\SectorMap;
use Dashboard\Home\HomeStatus;
use Dashboard\Router\Router;
use Dashboard\Support\ForbiddenException;
use Dashboard\Tracking\Services;

/** Permissions, morning brief, link graph, health, and home status. */
final class PlatformApi
{
    public function __construct(
        private readonly ApiRunner $runner,
        private readonly Services $svc,
    ) {}

    public function register(Router $r): void
    {
        $r->get('/api/v1/grants/mine', fn () => $this->run(function (int $u): array {
            $kind = $this->runner->principalKind();
            $id = $this->runner->principalId();
            if ($kind === null || $id === null) {
                throw new ForbiddenException('A browser session uses the permissions page.');
            }

            return [
                'principal_kind' => $kind,
                'principal_id'   => $id,
                'grants'         => $this->svc->grants->mine($u, $kind, $id),
            ];
        }));
        $r->get('/api/v1/grants', fn () => $this->run(fn (int $u) => $this->svc->grants->board($u)));
        $r->get('/api/v1/grants/audit', fn () => $this->run(fn (int $u) => [
            'events' => $this->svc->grants->recentAudits($u),
        ]));
        $r->post('/api/v1/grants', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();

            return ['grant' => $this->svc->grants->save(
                $u,
                (string) ($b['principal_kind'] ?? ''),
                (int) ($b['principal_id'] ?? 0),
                (string) ($b['sector'] ?? ''),
                (string) ($b['mode'] ?? ''),
            )];
        }, 200, true));

        $r->post('/api/v1/brief', fn () => $this->run(function (int $u): array {
            return $this->svc->briefs->compose($u, $this->kind(), $this->id());
        }, 201, true));
        $r->get('/api/v1/brief/history', fn () => $this->run(fn (int $u) => $this->svc->briefs->history($u)));
        $r->get('/api/v1/brief/schedule', fn () => $this->run(fn (int $u) => $this->svc->briefs->schedule($u)));
        $r->post('/api/v1/brief/schedule', fn () => $this->run(function (int $u): array {
            return $this->svc->briefs->saveSchedule($u, $this->runner->body());
        }, 200, true));

        $r->get('/api/v1/graph', fn () => $this->run(function (int $u): array {
            $hidden = $this->hidden($u);
            $limit = (int) ($_GET['limit'] ?? 40);
            $result = $this->svc->graph->query($u, (string) ($_GET['q'] ?? ''), $limit === 0 ? 40 : $limit, $hidden);
            $kinds = array_column($result['nodes'], 'kind');
            if (array_intersect($kinds, ['bill', 'envelope']) !== []) {
                $this->svc->grants->take($u, $this->kind(), $this->id(), 'money', 'graph_query');
            }
            if (in_array('health', $kinds, true)) {
                $this->svc->grants->take($u, $this->kind(), $this->id(), 'health', 'graph_query');
            }

            return $result;
        }));
        $r->get('/api/v1/graph/neighbors', fn () => $this->run(function (int $u): array {
            $kind = (string) ($_GET['kind'] ?? '');
            $ref = (int) ($_GET['ref'] ?? 0);
            $this->assertVisible($u, $kind);

            return $this->svc->graph->neighbors($u, $kind, $ref, $this->hidden($u));
        }));
        $r->post('/api/v1/graph/sync', fn () => $this->run(fn (int $u) => $this->svc->graph->sync($u), 200, true));
        $r->post('/api/v1/graph/edges', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();
            $from = (string) ($b['from_kind'] ?? '');
            $to = (string) ($b['to_kind'] ?? '');
            $this->assertVisible($u, $from);
            $this->assertVisible($u, $to);

            return $this->svc->graph->link($u, $from, (int) ($b['from_ref'] ?? 0), $to, (int) ($b['to_ref'] ?? 0));
        }, 201, true));

        $r->get('/api/v1/health/status', fn () => $this->run(fn (int $u) => $this->svc->health->status($u)));
        $r->get('/api/v1/health/readiness', fn () => $this->run(fn (int $u) => $this->svc->health->readiness($u, self::str('day'))));
        $r->get('/api/v1/health/sleep', fn () => $this->run(fn (int $u) => $this->svc->health->sleep($u, self::str('from') ?? '', self::str('to') ?? '')));
        $r->get('/api/v1/health/workouts', fn () => $this->run(fn (int $u) => $this->svc->health->workouts($u, self::str('from') ?? '', self::str('to') ?? '')));
        $r->get('/api/v1/health/metrics', fn () => $this->run(fn (int $u) => $this->svc->health->metrics(
            $u,
            self::str('metric') ?? '',
            self::str('from') ?? '',
            self::str('to') ?? ''
        )));
        $r->get('/api/v1/health/brief', fn () => $this->run(fn (int $u) => $this->svc->health->brief($u)));
        $r->post('/api/v1/health/sync', fn () => $this->run(fn (int $u) => $this->svc->health->sync($u), 200, true));
        $r->post('/api/v1/health/workouts', fn () => $this->run(fn (int $u) => $this->svc->health->logWorkout($u, $this->runner->body()), 201, true));
        $r->post('/api/v1/health/meals', fn () => $this->run(fn (int $u) => $this->svc->health->logMeal($u, $this->runner->body()), 201, true));
        $r->post('/api/v1/health/body', fn () => $this->run(fn (int $u) => $this->svc->health->logBody($u, $this->runner->body()), 201, true));

        $r->get('/api/v1/home/status', fn () => $this->run(fn () => (new HomeStatus($this->svc->db))->status()));
        $r->get('/api/v1/mail/status', fn () => $this->run(fn () => [
            'connected' => false,
            'detail'    => 'Mail is not connected on this server.',
        ]));
    }

    private function kind(): ?string
    {
        return $this->runner->grantedScopes() === null ? null : $this->runner->principalKind();
    }

    private function id(): ?int
    {
        return $this->runner->grantedScopes() === null ? null : $this->runner->principalId();
    }

    /**
     * @return list<string>
     */
    private function hidden(int $userId): array
    {
        $kind = $this->kind();
        $id = $this->id();
        if ($kind === null || $id === null) {
            return [];
        }
        $hidden = [];
        if ($this->svc->grants->mode($userId, $kind, $id, 'money') === 'deny') {
            $hidden[] = 'bill';
            $hidden[] = 'envelope';
        }
        if ($this->svc->grants->mode($userId, $kind, $id, 'health') === 'deny') {
            $hidden[] = 'health';
        }

        return $hidden;
    }

    private function assertVisible(int $userId, string $kind): void
    {
        $sector = match ($kind) {
            'bill', 'envelope' => 'money',
            'health' => 'health',
            default => null,
        };
        if ($sector === null) {
            return;
        }
        $principal = $this->kind();
        $id = $this->id();
        if ($principal === null || $id === null) {
            return;
        }
        if ($this->svc->grants->mode($userId, $principal, $id, $sector) === 'deny') {
            throw new ForbiddenException(SectorMap::needsGrant($sector, true));
        }
        $this->svc->grants->take($userId, $principal, $id, $sector, 'graph');
    }

    private static function str(string $name): ?string
    {
        $value = $_GET[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false): void
    {
        $this->runner->run($fn, $status, $mutating);
    }
}
