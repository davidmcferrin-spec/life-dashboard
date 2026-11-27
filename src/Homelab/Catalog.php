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
            'where' => 'qBittorrent → Tools → Options → Web UI. Use the WebUI username and password, and the URL including the port (often 8080). The dashboard logs in with that pair and keeps the cookie for the request. There is no API key.',
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
