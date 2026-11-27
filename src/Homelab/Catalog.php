<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Support\ValidationException;

/**
 * Services the homelab page, settings form, and scopes share.
 * A new app is one row here plus a client. Secrets are named so the form and the store stay in step.
 */
final class Catalog
{
    /**
     * @var array<string, array{label: string, icon: string, secret: string, secret_label: string, username: bool, where: string}>
     */
    public const SERVICES = [
        'sonarr' => [
            'label' => 'Sonarr',
            'icon' => 'monitor',
            'secret' => 'api_key',
            'secret_label' => 'API key',
            'username' => false,
            'where' => 'Sonarr → Settings → General → Security → API Key. The base URL is the one Sonarr shows under Settings → General, including the port (often 8989) and any URL base.',
        ],
        'radarr' => [
            'label' => 'Radarr',
            'icon' => 'disc-3',
            'secret' => 'api_key',
            'secret_label' => 'API key',
            'username' => false,
            'where' => 'Radarr → Settings → General → Security → API Key. The base URL includes the port (often 7878) and any URL base.',
        ],
        'prowlarr' => [
            'label' => 'Prowlarr',
            'icon' => 'target',
            'secret' => 'api_key',
            'secret_label' => 'API key',
            'username' => false,
            'where' => 'Prowlarr → Settings → General → Security → API Key. The base URL includes the port (often 9696) and any URL base.',
        ],
        'qbittorrent' => [
            'label' => 'qBittorrent',
            'icon' => 'arrow-down-right',
            'secret' => 'password',
            'secret_label' => 'Password',
            'username' => true,
            'where' => 'qBittorrent → Tools → Options → Web UI. Auth is none when this server is in the bypass whitelist (no login), password for the WebUI username and password, or token for the API key (qBittorrent 5.2.0 or newer, sent as Authorization: Bearer). The URL includes the port, often 8080.',
        ],
        'unraid' => [
            'label' => 'Unraid',
            'icon' => 'server',
            'secret' => 'api_key',
            'secret_label' => 'API key',
            'username' => false,
            'where' => 'Unraid 7 → Settings → Management Access → API Keys. Create a key. The base URL is the server origin, often http://tower. The dashboard calls the official GraphQL API at /graphql with the x-api-key header. Reboot and shutdown are not in that API.',
        ],
        'jellyfin' => [
            'label' => 'Jellyfin',
            'icon' => 'play',
            'secret' => 'api_key',
            'secret_label' => 'API key',
            'username' => false,
            'where' => 'Jellyfin → Dashboard → API Keys. The base URL is the server, often http://tower:8096, not the /web page. If Dashboard → Networking has a Base URL, add that path, for example http://tower:8096/jellyfin. A trailing /web is removed on save. Jellyfin user is optional: a name from the user list, or blank to use the first administrator. Jellyfin 10.11 wants Authorization: MediaBrowser Token. Older servers still accept X-Emby-Token.',
        ],
        'plex' => [
            'label' => 'Plex',
            'icon' => 'play-square',
            'secret' => 'api_key',
            'secret_label' => 'API token',
            'username' => false,
            'where' => 'Plex Web → Settings → Account, then open an XML page for this server (or watch a request in the browser network panel). Copy the X-Plex-Token value. The base URL is the server, often http://tower:32400.',
        ],
    ];

    /**
     * @return array{label: string, icon: string, secret: string, secret_label: string, username: bool, where: string}
     */
    public static function get(string $service): array
    {
        if (!isset(self::SERVICES[$service])) {
            throw ValidationException::withFields(['service' => 'Unknown homelab service.']);
        }

        return self::SERVICES[$service];
    }

    public static function label(string $service): string
    {
        return self::get($service)['label'];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::SERVICES);
    }
}
