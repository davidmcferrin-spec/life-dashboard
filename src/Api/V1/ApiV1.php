<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Auth\Auth;
use Dashboard\Config;
use Dashboard\Router\Router;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\RealtimeToken;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use Dashboard\Widgets\WidgetTypeRegistry;

/**
 * /api/v1 — JSON API for metrics, datapoints, habits, widgets and layout.
 *
 * Conventions
 *   - Session auth (CSRF on POST) or a scoped bearer token (no CSRF).
 *   - Errors: {"error": "...", "fields": {"config.metric_id": "..."}} with
 *     422 (validation), 404 (not found / not yours), 400 (bad request), 403 (scope).
 *   - Session writes are source "widget"/"api". Bearer tokens write source "ai".
 */
final class ApiV1
{
    private readonly ApiRunner $runner;

    public function __construct(
        private readonly Auth $auth,
        private readonly Services $svc,
    ) {
        $this->runner = new ApiRunner($auth, $svc->tokens, $svc->audit);
    }

    public function register(Router $r): void
    {
        // ---- metrics -------------------------------------------------
        $r->get('/api/v1/metrics', fn () => $this->run(fn (int $u) => [
            'metrics' => $this->svc->metrics->list($u, self::kindParam(), ($_GET['archived'] ?? '') === '1'),
        ]));
        $r->post('/api/v1/metrics', fn () => $this->run(fn (int $u) => [
            'metric' => $this->svc->metrics->create($u, $this->body()),
        ], 201, true));
        $r->post('/api/v1/metrics/reorder', fn () => $this->run(function (int $u): array {
            $ids = $this->body()['ids'] ?? null;
            if (!is_array($ids) || !array_is_list($ids)) {
                throw ValidationException::withFields(['ids' => 'ids must be a list of metric ids.']);
            }
            $this->svc->metrics->reorder($u, array_map('intval', $ids));
            return ['ok' => true];
        }, 200, true));
        $r->get('/api/v1/metrics/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'metric' => $this->svc->metrics->get($u, self::id($p)),
        ]));
        $r->post('/api/v1/metrics/{id}/update', fn (array $p) => $this->run(fn (int $u) => [
            'metric' => $this->svc->metrics->update($u, self::id($p), $this->body()),
        ], 200, true));
        $r->post('/api/v1/metrics/{id}/archive', fn (array $p) => $this->run(fn (int $u) => [
            'metric' => $this->svc->metrics->setArchived($u, self::id($p), (bool) ($this->body()['archived'] ?? true)),
        ], 200, true));

        // ---- datapoints ----------------------------------------------
        $r->get('/api/v1/metrics/{id}/datapoints', fn (array $p) => $this->run(fn (int $u) => [
            'datapoints' => $this->svc->points->list(
                $u,
                self::id($p),
                self::strParam('from'),
                self::strParam('to'),
                (int) ($_GET['limit'] ?? 100)
            ),
        ]));
        $r->get('/api/v1/metrics/{id}/series', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $today = $this->svc->clock->today();
            return ['series' => $this->svc->points->series(
                $u,
                self::id($p),
                (string) ($_GET['bucket'] ?? 'day'),
                (string) ($_GET['agg'] ?? 'avg'),
                self::strParam('from') ?? \Dashboard\Support\Clock::shiftDate($today, 29),
                self::strParam('to') ?? $today
            )];
        }));
        $r->post('/api/v1/metrics/{id}/datapoints', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->body();
            if ($this->runner->actor()->kind === 'ai') {
                $b['source'] = 'ai';
            } else {
                $b['source'] = in_array($b['source'] ?? null, ['widget', 'api'], true) ? $b['source'] : 'api';
            }
            return ['datapoint' => $this->svc->points->record($u, self::id($p), $b)];
        }, 201, true));
        $r->post('/api/v1/datapoints/{id}/delete', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $this->svc->points->delete($u, self::id($p));
            return ['ok' => true];
        }, 200, true));

        // ---- habits --------------------------------------------------
        $r->get('/api/v1/habits', fn () => $this->run(fn (int $u) => $this->svc->habits->summary(
            $u,
            null,
            (int) ($_GET['days'] ?? 7)
        )));
        $r->post('/api/v1/habits/{id}/toggle', fn (array $p) => $this->run(fn (int $u) => $this->svc->habits->toggle(
            $u,
            self::id($p),
            isset($this->body()['date']) ? (string) $this->body()['date'] : null,
            $this->runner->actor()->source()
        ), 200, true));

        // ---- widgets -------------------------------------------------
        $r->get('/api/v1/widget-types', fn () => $this->run(fn (int $u) => [
            'types' => WidgetTypeRegistry::catalogue(),
        ]));
        $r->get('/api/v1/widgets', fn () => $this->run(fn (int $u) => [
            'widgets' => $this->svc->widgets->list($u),
        ]));
        $r->post('/api/v1/widgets', fn () => $this->run(function (int $u): array {
            $b = $this->body();
            $pos = array_intersect_key($b, array_flip(['x', 'y', 'w', 'h']));
            return ['widget' => $this->svc->widgets->create(
                $u,
                (string) ($b['type'] ?? ''),
                isset($b['title']) ? (string) $b['title'] : null,
                is_array($b['config'] ?? null) ? $b['config'] : [],
                $pos === [] ? null : $pos
            )];
        }, 201, true));
        $r->get('/api/v1/widgets/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'widget' => $this->svc->widgets->get($u, self::id($p)),
        ]));
        $r->get('/api/v1/widgets/{id}/data', fn (array $p) => $this->run(fn (int $u) => [
            'data' => $this->svc->widgetData->forWidget($u, self::id($p)),
        ]));
        $r->post('/api/v1/widgets/{id}/update', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $b = $this->body();
            return ['widget' => $this->svc->widgets->update(
                $u,
                self::id($p),
                array_intersect_key($b, array_flip(['title', 'config']))
            )];
        }, 200, true));
        $r->post('/api/v1/widgets/{id}/delete', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $this->svc->widgets->delete($u, self::id($p));
            return ['ok' => true];
        }, 200, true));
        $r->get('/api/v1/widgets/{id}/revisions', fn (array $p) => $this->run(fn (int $u) => [
            'revisions' => $this->svc->widgets->revisions($u, self::id($p)),
        ]));
        $r->post('/api/v1/widgets/{id}/rollback', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $v = filter_var($this->body()['version'] ?? null, FILTER_VALIDATE_INT);
            if ($v === false || $v < 1) {
                throw ValidationException::withFields(['version' => 'version must be a positive integer.']);
            }
            return ['widget' => $this->svc->widgets->rollback($u, self::id($p), $v)];
        }, 200, true));
        $r->post('/api/v1/layout', fn () => $this->run(function (int $u): array {
            $items = $this->body()['items'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw ValidationException::withFields(['items' => 'items must be a list.']);
            }
            return ['updated' => $this->svc->widgets->saveLayout($u, $items)];
        }, 200, true));

        // ---- realtime ------------------------------------------------
        $r->get('/api/v1/realtime/token', fn () => $this->run(fn (int $u) => [
            'token' => RealtimeToken::issue($u, Config::get('APP_SECRET')),
            'url'   => '/ws',
            'ttl'   => RealtimeToken::TTL_SECONDS,
        ]));

        (new ExtendedApi($this->runner, $this->svc))->register($r);
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false, ?string $scope = null): void
    {
        $this->runner->run($fn, $status, $mutating, $scope);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return $this->runner->body();
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

    private static function strParam(string $name): ?string
    {
        $v = $_GET[$name] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function kindParam(): ?string
    {
        $k = self::strParam('kind');
        if ($k !== null && !in_array($k, \Dashboard\Tracking\MetricService::KINDS, true)) {
            throw ValidationException::withFields(['kind' => 'Unknown kind.']);
        }
        return $k;
    }
}
