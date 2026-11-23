<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Agents\AgentName;
use Dashboard\Home\HomeStatus;
use Dashboard\Router\Router;
use Dashboard\Tracking\Services;

/** Morning brief, link graph, health, and home status. */
final class PlatformApi
{
    public function __construct(
        private readonly ApiRunner $runner,
        private readonly Services $svc,
    ) {}

    public function register(Router $r): void
    {
        $r->post('/api/v1/brief', fn () => $this->run(fn (int $u) => $this->compose($u), 201, true));
        $r->get('/api/v1/brief/history', fn () => $this->run(fn (int $u) => $this->svc->briefs->history($u)));
        $r->get('/api/v1/brief/schedule', fn () => $this->run(fn (int $u) => $this->svc->briefs->schedule($u)));
        $r->post('/api/v1/brief/schedule', fn () => $this->run(function (int $u): array {
            return $this->svc->briefs->saveSchedule($u, $this->runner->body());
        }, 200, true));

        $r->get('/api/v1/graph', fn () => $this->run(function (int $u): array {
            $limit = (int) ($_GET['limit'] ?? 40);

            return $this->svc->graph->query($u, (string) ($_GET['q'] ?? ''), $limit === 0 ? 40 : $limit, []);
        }));
        $r->get('/api/v1/graph/neighbors', fn () => $this->run(function (int $u): array {
            $kind = (string) ($_GET['kind'] ?? '');
            $ref = (int) ($_GET['ref'] ?? 0);

            return $this->svc->graph->neighbors($u, $kind, $ref, []);
        }));
        $r->post('/api/v1/graph/sync', fn () => $this->run(fn (int $u) => $this->svc->graph->sync($u), 200, true));
        $r->post('/api/v1/graph/edges', fn () => $this->run(function (int $u): array {
            $b = $this->runner->body();

            return $this->svc->graph->link(
                $u,
                (string) ($b['from_kind'] ?? ''),
                (int) ($b['from_ref'] ?? 0),
                (string) ($b['to_kind'] ?? ''),
                (int) ($b['to_ref'] ?? 0)
            );
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
        $r->post('/api/v1/health/meal-log', fn () => $this->run(fn (int $u) => $this->svc->healthLog->logMeal($u, $this->runner->body(), $this->runner->actor()->source()), 201, true));
        $r->get('/api/v1/health/meal-log', fn () => $this->run(fn (int $u) => $this->svc->healthLog->listMeals($u, self::str('from'), self::str('to'))));
        $r->post('/api/v1/health/blood-pressure', fn () => $this->run(fn (int $u) => $this->svc->healthLog->logBloodPressure($u, $this->runner->body()), 201, true));
        $r->get('/api/v1/health/blood-pressure', fn () => $this->run(fn (int $u) => $this->svc->healthLog->listBloodPressure($u, self::str('from'), self::str('to'))));
        $r->post('/api/v1/health/medications', fn () => $this->run(fn (int $u) => $this->svc->healthLog->addMedication($u, $this->runner->body()), 201, true));
        $r->post('/api/v1/health/medications/{id}/update', fn (array $p) => $this->run(fn (int $u) => $this->svc->healthLog->updateMedication($u, self::id($p), $this->runner->body()), 200, true));
        $r->get('/api/v1/health/medications', fn () => $this->run(function (int $u): array {
            $active = self::str('active');
            $flag = $active === null ? null : $active === '1' || $active === 'true';

            return $this->svc->healthLog->listMedications($u, $flag);
        }));
        $r->post('/api/v1/health/medications/{id}/doses', fn (array $p) => $this->run(fn (int $u) => $this->svc->healthLog->logDose($u, self::id($p), $this->runner->body()), 201, true));
        $r->post('/api/v1/health/appointments', fn () => $this->run(fn (int $u) => $this->svc->healthLog->addAppointment($u, $this->runner->body()), 201, true));
        $r->post('/api/v1/health/appointments/{id}/update', fn (array $p) => $this->run(fn (int $u) => $this->svc->healthLog->updateAppointment($u, self::id($p), $this->runner->body()), 200, true));
        $r->get('/api/v1/health/appointments', fn () => $this->run(function (int $u): array {
            $upcoming = self::str('upcoming');

            return $this->svc->healthLog->listAppointments(
                $u,
                $upcoming === '1' || $upcoming === 'true',
                self::str('from'),
                self::str('to')
            );
        }));

        $r->get('/api/v1/home/status', fn () => $this->run(fn () => (new HomeStatus($this->svc->db))->status()));
        $r->get('/api/v1/mail/status', fn () => $this->run(fn () => [
            'connected' => false,
            'detail'    => 'Mail is not connected on this server.',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function compose(int $userId): array
    {
        $out = $this->svc->briefs->compose($userId);
        $agent = $this->briefAgent();
        if ($agent !== null) {
            $out['open_handoffs'] = $this->svc->handoffs->openForAgent($userId, $agent);
        }

        return $out;
    }

    /** Open handoffs on a morning brief belong to the calling key, and only when that key can read them. */
    private function briefAgent(): ?string
    {
        if ($this->runner->actor()->kind !== 'ai') {
            return null;
        }
        $granted = $this->runner->grantedScopes();
        if (is_array($granted) && !Scope::allows($granted, 'handoffs:read')) {
            return null;
        }

        return AgentName::identity(true, $this->runner->tokenName(), '');
    }

    private static function str(string $name): ?string
    {
        $value = $_GET[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, string> $params */
    private static function id(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new \Dashboard\Support\NotFoundException('Not found.');
        }

        return $id;
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false): void
    {
        $this->runner->run($fn, $status, $mutating);
    }
}
