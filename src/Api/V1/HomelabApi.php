<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Homelab\Catalog;
use Dashboard\Homelab\HomelabService;
use Dashboard\Homelab\Qbit;
use Dashboard\Homelab\Servarr;
use Dashboard\Homelab\Unraid;
use Dashboard\Router\Router;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

/**
 * Per-service routes. The path contains the scope name (sonarr, radarr, prowlarr, qbittorrent)
 * so a token is checked against that service and not a shared homelab scope.
 */
final class HomelabApi
{
    public function __construct(
        private readonly ApiRunner $runner,
        private readonly Services $svc,
    ) {}

    public function register(Router $r): void
    {
        foreach (Catalog::ids() as $service) {
            $this->settings($r, $service);
        }
        $this->servarr($r, 'sonarr');
        $this->servarr($r, 'radarr');
        $this->prowlarr($r);
        $this->qbit($r);
        $this->unraid($r);
        $this->jellyfin($r);
        $this->plex($r);
    }

    private function settings(Router $r, string $service): void
    {
        $r->get('/api/v1/' . $service . '/settings', fn () => $this->run(fn (int $u): array => [
            'settings' => $this->hub()->view($u)[$service],
        ]));
        $r->post('/api/v1/' . $service . '/settings', fn () => $this->run(function (int $u) use ($service): array {
            $actor = $this->runner->actor();

            return ['settings' => $this->hub()->save($u, $service, $this->runner->body(), $actor->kind, $actor->tool, $this->stamp())];
        }, 200, true));
        $r->post('/api/v1/' . $service . '/test', fn () => $this->run(
            fn (int $u): array => $this->hub()->test($u, $service),
            200,
            true
        ));
    }

    private function servarr(Router $r, string $service): void
    {
        $noun = $service === 'sonarr' ? 'series' : 'movies';
        $r->get('/api/v1/' . $service . '/status', fn () => $this->run(fn (int $u): array => $service === 'sonarr'
            ? $this->hub()->sonarrStatus($u)
            : $this->hub()->radarrStatus($u)));
        $r->get('/api/v1/' . $service . '/queue', fn () => $this->run(fn (int $u): array => $this->hub()->queue($u, $service)));
        $r->get('/api/v1/' . $service . '/calendar', fn () => $this->run(fn (int $u): array => $this->hub()->calendar(
            $u,
            $service,
            (string) ($_GET['start'] ?? ''),
            (string) ($_GET['end'] ?? '')
        )));
        $r->get('/api/v1/' . $service . '/missing', fn () => $this->run(fn (int $u): array => $this->hub()->missing($u, $service)));
        $r->get('/api/v1/' . $service . '/' . $noun, fn () => $this->run(fn (int $u): array => $this->hub()->library($u, $service)));
        $r->get('/api/v1/' . $service . '/lookup', fn () => $this->run(fn (int $u): array => $this->hub()->lookup($u, $service, (string) ($_GET['term'] ?? ''))));
        $r->get('/api/v1/' . $service . '/options', fn () => $this->run(fn (int $u): array => $this->hub()->options($u, $service)));
        $r->get('/api/v1/' . $service . '/health', fn () => $this->run(fn (int $u): array => $this->hub()->health($u, $service)));
        $r->post('/api/v1/' . $service . '/' . $noun, fn () => $this->run(function (int $u) use ($service): array {
            $actor = $this->runner->actor();

            return $this->hub()->add($u, $service, $this->runner->body(), $actor->kind, $actor->tool, $this->stamp());
        }, 200, true));
        $one = $service === 'sonarr' ? 'series' : 'movie';
        $r->post('/api/v1/' . $service . '/' . $one . '/{id}/search', fn (array $p) => $this->run(function (int $u) use ($service, $p): array {
            $actor = $this->runner->actor();

            return $this->hub()->searchItem($u, $service, self::intId($p), $actor->kind, $actor->tool, $this->stamp());
        }, 200, true));
        $r->post('/api/v1/' . $service . '/' . $one . '/{id}/delete', fn (array $p) => $this->run(function (int $u) use ($service, $p): array {
            $body = $this->runner->body();
            $actor = $this->runner->actor();

            return $this->hub()->remove(
                $u,
                $service,
                self::intId($p),
                self::confirm($body),
                Servarr::boolish($body['delete_files'] ?? false, 'delete_files'),
                $actor->kind,
                $actor->tool,
                $this->stamp()
            );
        }, 200, true));
    }

    private function prowlarr(Router $r): void
    {
        $r->get('/api/v1/prowlarr/status', fn () => $this->run(fn (int $u): array => $this->hub()->prowlarrStatus($u)));
        $r->get('/api/v1/prowlarr/indexers', fn () => $this->run(fn (int $u): array => $this->hub()->indexers($u)));
        $r->get('/api/v1/prowlarr/health', fn () => $this->run(fn (int $u): array => $this->hub()->health($u, 'prowlarr')));
        $r->get('/api/v1/prowlarr/search', fn () => $this->run(fn (int $u): array => $this->hub()->prowlarrSearch($u, (string) ($_GET['query'] ?? ''))));
        $r->get('/api/v1/prowlarr/apps', fn () => $this->run(fn (int $u): array => $this->hub()->apps($u)));
    }

    private function qbit(Router $r): void
    {
        $r->get('/api/v1/qbittorrent/status', fn () => $this->run(fn (int $u): array => $this->hub()->transfer($u)));
        $r->get('/api/v1/qbittorrent/torrents', fn () => $this->run(fn (int $u): array => $this->hub()->torrents(
            $u,
            (string) ($_GET['filter'] ?? ''),
            (string) ($_GET['category'] ?? ''),
            ''
        )));
        $r->get('/api/v1/qbittorrent/torrents/{hash}', fn (array $p) => $this->run(fn (int $u): array => $this->hub()->torrents(
            $u,
            '',
            '',
            Qbit::hashes(self::segment($p, 'hash'))
        )));
        $r->get('/api/v1/qbittorrent/categories', fn () => $this->run(fn (int $u): array => $this->hub()->categories($u)));
        foreach (['pause', 'resume', 'recheck'] as $action) {
            $r->post('/api/v1/qbittorrent/torrents/{hash}/' . $action, fn (array $p) => $this->run(function (int $u) use ($p, $action): array {
                $actor = $this->runner->actor();

                return $this->hub()->torrentVerb($u, $action, Qbit::hashes(self::segment($p, 'hash')), $actor->kind, $actor->tool, $this->stamp());
            }, 200, true));
        }
        $r->post('/api/v1/qbittorrent/torrents/add', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            $actor = $this->runner->actor();

            return $this->hub()->torrentAdd(
                $u,
                (string) ($body['urls'] ?? ''),
                (string) ($body['category'] ?? ''),
                $actor->kind,
                $actor->tool,
                $this->stamp()
            );
        }, 200, true));
        $r->post('/api/v1/qbittorrent/torrents/{hash}/delete', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();
            $actor = $this->runner->actor();

            return $this->hub()->torrentDelete(
                $u,
                Qbit::hashes(self::segment($p, 'hash')),
                self::confirm($body),
                Servarr::boolish($body['delete_files'] ?? false, 'delete_files'),
                $actor->kind,
                $actor->tool,
                $this->stamp()
            );
        }, 200, true));
    }

    private function unraid(Router $r): void
    {
        $r->get('/api/v1/unraid/status', fn () => $this->run(fn (int $u): array => $this->hub()->unraidStatus($u)));
        $r->get('/api/v1/unraid/disks', fn () => $this->run(fn (int $u): array => $this->hub()->unraidDisks($u)));
        $r->get('/api/v1/unraid/docker', fn () => $this->run(fn (int $u): array => $this->hub()->unraidDocker($u)));
        $r->get('/api/v1/unraid/vms', fn () => $this->run(fn (int $u): array => $this->hub()->unraidVms($u)));
        foreach (['start', 'stop', 'restart'] as $action) {
            $r->post('/api/v1/unraid/docker/{id}/' . $action, fn (array $p) => $this->run(function (int $u) use ($p, $action): array {
                $body = $this->runner->body();
                $actor = $this->runner->actor();

                return $this->hub()->unraidDockerAct($u, $action, Unraid::target(self::segment($p, 'id')), self::confirm($body), $actor->kind, $actor->tool, $this->stamp());
            }, 200, true));
        }
        foreach (['start', 'stop'] as $action) {
            $r->post('/api/v1/unraid/vms/{id}/' . $action, fn (array $p) => $this->run(function (int $u) use ($p, $action): array {
                $body = $this->runner->body();
                $actor = $this->runner->actor();

                return $this->hub()->unraidVmAct($u, $action, Unraid::target(self::segment($p, 'id')), self::confirm($body), $actor->kind, $actor->tool, $this->stamp());
            }, 200, true));
        }
        $r->post('/api/v1/unraid/array', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            $actor = $this->runner->actor();

            return $this->hub()->unraidArray($u, (string) ($body['state'] ?? ''), self::confirm($body), $actor->kind, $actor->tool, $this->stamp());
        }, 200, true));
    }

    private function jellyfin(Router $r): void
    {
        $r->get('/api/v1/jellyfin/status', fn () => $this->run(fn (int $u): array => $this->hub()->jellyfinStatus($u)));
        $r->get('/api/v1/jellyfin/sessions', fn () => $this->run(fn (int $u): array => $this->hub()->jellyfinSessions($u)));
        $r->get('/api/v1/jellyfin/counts', fn () => $this->run(fn (int $u): array => $this->hub()->jellyfinCounts($u)));
        $r->get('/api/v1/jellyfin/recent', fn () => $this->run(fn (int $u): array => $this->hub()->jellyfinRecent($u)));
        $r->get('/api/v1/jellyfin/search', fn () => $this->run(fn (int $u): array => $this->hub()->jellyfinSearch($u, (string) ($_GET['term'] ?? ''))));
    }

    private function plex(Router $r): void
    {
        $r->get('/api/v1/plex/status', fn () => $this->run(fn (int $u): array => $this->hub()->plexStatus($u)));
        $r->get('/api/v1/plex/sessions', fn () => $this->run(fn (int $u): array => $this->hub()->plexSessions($u)));
        $r->get('/api/v1/plex/libraries', fn () => $this->run(fn (int $u): array => $this->hub()->plexLibraries($u)));
        $r->get('/api/v1/plex/recent', fn () => $this->run(fn (int $u): array => $this->hub()->plexRecent($u)));
        $r->get('/api/v1/plex/search', fn () => $this->run(fn (int $u): array => $this->hub()->plexSearch($u, (string) ($_GET['query'] ?? ''))));
        $r->post('/api/v1/plex/libraries/{key}/refresh', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $actor = $this->runner->actor();

            return $this->hub()->plexRefresh($u, self::segment($p, 'key'), $actor->kind, $actor->tool, $this->stamp());
        }, 200, true));
    }

    private function hub(): HomelabService
    {
        return HomelabService::fromDatabase($this->svc->db);
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false): void
    {
        $this->runner->run($fn, $status, $mutating);
    }

    /**
     * @return array<string, mixed>
     */
    private function stamp(): array
    {
        $actor = $this->runner->actor();
        if ($actor->kind !== 'ai') {
            return [];
        }

        return \Dashboard\Audit\TokenStamp::detail($this->runner->tokenId(), $this->runner->tokenName(), 'write');
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function confirm(array $body): bool
    {
        if (!array_key_exists('confirm', $body)) {
            return false;
        }
        $confirm = $body['confirm'];
        if (!is_bool($confirm)) {
            throw ValidationException::withFields(['confirm' => 'confirm must be true or false.']);
        }

        return $confirm;
    }

    /**
     * @param array<string, string> $params
     */
    private static function intId(array $params): int
    {
        $id = rawurldecode((string) ($params['id'] ?? ''));
        if (preg_match('/^[1-9][0-9]{0,11}$/', $id) !== 1) {
            throw ValidationException::withFields(['id' => 'The id must be a positive number.']);
        }

        return (int) $id;
    }

    /**
     * @param array<string, string> $params
     */
    private static function segment(array $params, string $key): string
    {
        return rawurldecode((string) ($params[$key] ?? ''));
    }
}
