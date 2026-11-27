<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Audit\AuditLog;
use Dashboard\DB\Database;

/**
 * Dashboard-facing homelab calls. Writes are audited. Destructive ones stop unless confirm is true.
 */
final class HomelabService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditLog $audit,
        private readonly Settings $settings,
        private readonly mixed $transport = null,
    ) {}

    public static function fromDatabase(Database $db, mixed $transport = null): self
    {
        return new self($db, new AuditLog($db), new Settings($db), $transport);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function view(int $userId): array
    {
        return $this->settings->view($userId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(int $userId, string $service, array $input, string $actor, ?string $tool, array $stamp): array
    {
        try {
            $view = $this->settings->save($userId, $service, $input);
        } catch (HomelabException $e) {
            $this->record($userId, $actor, $tool, $service, 'settings', false, $stamp, $e->getMessage(), $service);
            throw $e;
        }
        $this->record($userId, $actor, $tool, $service, 'settings', true, $stamp, 'saved', $service);

        return $view;
    }

    /**
     * @return array{ok: true, service: string, label: string, version: string, reachable: true, enabled: bool}
     */
    public function test(int $userId, string $service): array
    {
        $meta = Catalog::get($service);
        $http = $this->open($userId, $service, false);
        $version = match ($service) {
            'sonarr', 'radarr' => (new Servarr($service, $http))->status()['version'],
            'prowlarr' => (new Prowlarr($http))->status()['version'],
            'qbittorrent' => $this->qbit($userId, false)->status()['version'],
            default => '',
        };

        return [
            'ok' => true,
            'service' => $service,
            'label' => $meta['label'],
            'version' => $version,
            'reachable' => true,
            'enabled' => (bool) $this->settings->viewOne($userId, $service)['enabled'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sonarrStatus(int $userId): array
    {
        return (new Servarr('sonarr', $this->open($userId, 'sonarr', true)))->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function radarrStatus(int $userId): array
    {
        return (new Servarr('radarr', $this->open($userId, 'radarr', true)))->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function prowlarrStatus(int $userId): array
    {
        return (new Prowlarr($this->open($userId, 'prowlarr', true)))->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function queue(int $userId, string $service): array
    {
        return (new Servarr($service, $this->open($userId, $service, true)))->queue();
    }

    /**
     * @return array<string, mixed>
     */
    public function calendar(int $userId, string $service, string $start, string $end): array
    {
        if ($start === '' || $end === '') {
            $start = gmdate('Y-m-d');
            $end = gmdate('Y-m-d', time() + 14 * 86400);
        }

        return (new Servarr($service, $this->open($userId, $service, true)))->calendar($start, $end);
    }

    /**
     * @return array<string, mixed>
     */
    public function missing(int $userId, string $service): array
    {
        return (new Servarr($service, $this->open($userId, $service, true)))->missing();
    }

    /**
     * @return array<string, mixed>
     */
    public function library(int $userId, string $service): array
    {
        return (new Servarr($service, $this->open($userId, $service, true)))->library();
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(int $userId, string $service, string $term): array
    {
        return (new Servarr($service, $this->open($userId, $service, true)))->lookup($term);
    }

    /**
     * @return array<string, mixed>
     */
    public function options(int $userId, string $service): array
    {
        return (new Servarr($service, $this->open($userId, $service, true)))->options();
    }

    /**
     * @return array<string, mixed>
     */
    public function health(int $userId, string $service): array
    {
        if ($service === 'prowlarr') {
            return (new Prowlarr($this->open($userId, 'prowlarr', true)))->health();
        }

        return (new Servarr($service, $this->open($userId, $service, true)))->health();
    }

    /**
     * @return array<string, mixed>
     */
    public function indexers(int $userId): array
    {
        return (new Prowlarr($this->open($userId, 'prowlarr', true)))->indexers();
    }

    /**
     * @return array<string, mixed>
     */
    public function prowlarrSearch(int $userId, string $query): array
    {
        return (new Prowlarr($this->open($userId, 'prowlarr', true)))->search($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function apps(int $userId): array
    {
        return (new Prowlarr($this->open($userId, 'prowlarr', true)))->apps();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function add(int $userId, string $service, array $input, string $actor, ?string $tool, array $stamp): array
    {
        return $this->write($userId, $actor, $tool, $service, 'add', $service, false, false, '', $stamp, function (Http $http) use ($service, $input): array {
            return (new Servarr($service, $http))->add($input);
        });
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function searchItem(int $userId, string $service, int $id, string $actor, ?string $tool, array $stamp): array
    {
        return $this->write($userId, $actor, $tool, $service, 'search', (string) $id, false, false, '', $stamp, function (Http $http) use ($service, $id): array {
            return (new Servarr($service, $http))->search($id);
        });
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function remove(int $userId, string $service, int $id, bool $confirm, bool $deleteFiles, string $actor, ?string $tool, array $stamp): array
    {
        $noun = $service === 'sonarr' ? 'series' : 'movie';
        $because = $deleteFiles
            ? 'Deleting this ' . $noun . ' and its files needs confirm set to true.'
            : 'Deleting this ' . $noun . ' needs confirm set to true.';

        return $this->write($userId, $actor, $tool, $service, 'delete', (string) $id, true, $confirm, $because, $stamp, function (Http $http) use ($service, $id, $deleteFiles): array {
            return (new Servarr($service, $http))->delete($id, $deleteFiles);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function transfer(int $userId): array
    {
        return $this->qbit($userId, true)->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function torrents(int $userId, string $filter, string $category, string $hashes): array
    {
        return $this->qbit($userId, true)->torrents($filter, $category, $hashes);
    }

    /**
     * @return array<string, mixed>
     */
    public function categories(int $userId): array
    {
        return $this->qbit($userId, true)->categories();
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function torrentVerb(int $userId, string $action, string $hashes, string $actor, ?string $tool, array $stamp): array
    {
        return $this->write($userId, $actor, $tool, 'qbittorrent', $action, $hashes, false, false, '', $stamp, function (Http $http) use ($userId, $action, $hashes): array {
            $client = $this->qbitFrom($userId, $http);
            if ($action === 'pause') {
                return $client->pause($hashes);
            }
            if ($action === 'resume') {
                return $client->resume($hashes);
            }

            return $client->recheck($hashes);
        });
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function torrentDelete(int $userId, string $hashes, bool $confirm, bool $deleteFiles, string $actor, ?string $tool, array $stamp): array
    {
        $because = $deleteFiles
            ? 'Deleting the torrent and its files needs confirm set to true.'
            : 'Deleting the torrent needs confirm set to true.';

        return $this->write($userId, $actor, $tool, 'qbittorrent', 'delete', $hashes, true, $confirm, $because, $stamp, function (Http $http) use ($userId, $hashes, $deleteFiles): array {
            return $this->qbitFrom($userId, $http)->delete($hashes, $deleteFiles);
        });
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function torrentAdd(int $userId, string $urls, string $category, string $actor, ?string $tool, array $stamp): array
    {
        return $this->write($userId, $actor, $tool, 'qbittorrent', 'add', 'add', false, false, '', $stamp, function (Http $http) use ($userId, $urls, $category): array {
            return $this->qbitFrom($userId, $http)->add($urls, $category);
        });
    }

    private function qbit(int $userId, bool $requireEnabled): Qbit
    {
        return $this->qbitFrom($userId, $this->open($userId, 'qbittorrent', $requireEnabled));
    }

    private function qbitFrom(int $userId, Http $http): Qbit
    {
        $stored = $this->settings->stored($userId, 'qbittorrent') ?? [];

        return new Qbit($http, (string) ($stored['username'] ?? ''), (string) ($stored['password'] ?? ''));
    }

    private function open(int $userId, string $service, bool $requireEnabled): Http
    {
        $meta = Catalog::get($service);
        $stored = $this->settings->stored($userId, $service);
        $secret = trim((string) ($stored[$meta['secret']] ?? ''));
        $url = trim((string) ($stored['base_url'] ?? ''));
        $usernameOk = !$meta['username'] || trim((string) ($stored['username'] ?? '')) !== '';
        if ($stored === null || $url === '' || $secret === '' || !$usernameOk) {
            throw new HomelabException(
                $meta['label'] . ' is not configured. Save it under Settings → Homelab.',
                'homelab_unconfigured',
                409
            );
        }
        if ($requireEnabled && empty($stored['enabled'])) {
            throw new HomelabException(
                $meta['label'] . ' is turned off. Enable it under Settings → Homelab.',
                'homelab_disabled',
                409
            );
        }
        $headers = ['Accept' => 'application/json'];
        $redact = [];
        if ($meta['secret'] === 'api_key') {
            $headers['X-Api-Key'] = $secret;
            $redact[] = $secret;
        } else {
            $redact[] = $secret;
        }

        return new Http($url, $this->transport, $headers, $redact);
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    private function write(int $userId, string $actor, ?string $tool, string $service, string $action, string $resource, bool $needsConfirm, bool $confirm, string $because, array $stamp, callable $call): array
    {
        if ($needsConfirm && $confirm !== true) {
            $this->record($userId, $actor, $tool, $service, $action, false, $stamp, 'confirmation_required', $resource);
            throw new HomelabException($because, 'confirmation_required', 409, [
                'action' => $action,
                'service' => $service,
            ]);
        }
        try {
            $result = $call($this->open($userId, $service, true));
        } catch (HomelabException $e) {
            $this->record($userId, $actor, $tool, $service, $action, false, $stamp, $e->getMessage(), $resource);
            throw $e;
        }
        if (!is_array($result)) {
            $result = [];
        }
        $this->record($userId, $actor, $tool, $service, $action, true, $stamp, '', $resource);

        return $result;
    }

    /**
     * @param array<string, mixed> $stamp
     */
    private function record(int $userId, string $actor, ?string $tool, string $service, string $action, bool $ok, array $stamp, string $detail, string $resource): void
    {
        $extra = $stamp;
        unset($extra['verb']);
        $extra['entity'] = $service;
        $extra['action'] = $action;
        $extra['ok'] = $ok;
        $note = trim($detail);
        if ($note !== '') {
            $extra['detail'] = mb_substr($note, 0, 160);
        }
        $this->audit->record($userId, $actor, $tool, $service . '.' . $action, $service . ':' . $resource, $extra);
    }
}
