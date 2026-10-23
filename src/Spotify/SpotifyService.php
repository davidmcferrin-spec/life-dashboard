<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;
use Dashboard\Support\Clock;

final class SpotifyService
{
    public const PLAYER_PREF = 'spotify_player';

    public function __construct(
        private readonly Database $db,
        private readonly SpotifyAccount $account,
        private readonly SpotifyLibrary $library,
        private readonly SpotifyPlayback $playback,
        private readonly SyncBridge $sync,
        private readonly Clock $clock,
        private readonly PreferenceService $prefs,
    ) {}

    public static function fromDatabase(?Database $db = null, ?Clock $clock = null): self
    {
        $db ??= Database::getInstance();
        $clock ??= Clock::fromConfig();
        $http = new SpotifyHttp();
        $account = new SpotifyAccount($db, $http);
        return new self(
            $db,
            $account,
            new SpotifyLibrary($db, $clock),
            new SpotifyPlayback($account, $http),
            new SyncBridge(),
            $clock,
            new PreferenceService($db),
        );
    }

    public function account(): SpotifyAccount
    {
        return $this->account;
    }

    public function library(): SpotifyLibrary
    {
        return $this->library;
    }

    public function playback(): SpotifyPlayback
    {
        return $this->playback;
    }

    public function sync(): SyncBridge
    {
        return $this->sync;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(int $userId): array
    {
        $status = $this->account->status($userId);
        $status['player_enabled'] = $this->playerEnabled($userId);
        $status['redirect_uri'] = SpotifyAccount::redirectUri();
        return $status;
    }

    public function playerEnabled(int $userId): bool
    {
        return $this->prefs->get($userId, self::PLAYER_PREF, '0') === '1';
    }

    public function setPlayerEnabled(int $userId, bool $enabled): void
    {
        $this->prefs->set($userId, self::PLAYER_PREF, $enabled ? '1' : '0');
    }

    /**
     * @return array<string, mixed>
     */
    public function card(int $userId): array
    {
        $now = null;
        $status = $this->account->status($userId);
        if (!empty($status['connected'])) {
            $now = $this->playback->cachedNow($userId, false);
            if ($now === null) {
                $now = ['unavailable' => true];
            }
        }
        $card = $this->library->card($userId, $this->clock, is_array($now) && empty($now['unavailable']) ? $now : null);
        if (!empty($status['connected']) && ($now === null || !empty($now['unavailable']))) {
            $card['now_error'] = 'Now playing unavailable';
        }
        return $card;
    }
}
