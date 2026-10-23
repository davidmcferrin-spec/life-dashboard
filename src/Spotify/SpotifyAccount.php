<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/** One Spotify account per dashboard user. Tokens stay in this class. */
final class SpotifyAccount
{
    /** @var list<string> */
    public const SCOPES = [
        'playlist-read-private',
        'playlist-read-collaborative',
        'playlist-modify-private',
        'playlist-modify-public',
        'user-library-read',
        'user-library-modify',
        'user-read-recently-played',
        'user-top-read',
        'user-read-playback-state',
        'user-modify-playback-state',
        'user-read-currently-playing',
        'streaming',
        'user-read-email',
        'user-read-private',
    ];

    /** Scopes the Web Playback SDK refuses to start without. */
    public const PLAYBACK_SCOPES = [
        'streaming',
        'user-read-email',
        'user-read-private',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly SpotifyHttp $http = new SpotifyHttp(),
        private readonly TokenCipher $cipher = new TokenCipher(),
    ) {}

    public static function configured(): bool
    {
        return self::clientId() !== null && self::clientSecret() !== null && self::redirectUri() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT spotify_user_id, display_name, scopes, status, status_detail, last_sync_at, last_sync_status, last_sync_error
             FROM spotify_account WHERE user_id = ?',
            [$userId]
        );
        if ($row === null) {
            return [
                'connected' => false,
                'configured' => self::configured(),
                'display_name' => null,
                'spotify_user_id' => null,
                'scopes' => [],
                'status' => 'disconnected',
                'status_detail' => null,
                'last_sync_at' => null,
                'last_sync_status' => null,
                'last_sync_error' => null,
                'missing_playback_scopes' => self::PLAYBACK_SCOPES,
            ];
        }
        $scopes = preg_split('/\s+/', trim((string) $row['scopes'])) ?: [];
        return [
            'connected' => (string) $row['status'] === 'connected',
            'configured' => self::configured(),
            'display_name' => (string) $row['display_name'],
            'spotify_user_id' => (string) $row['spotify_user_id'],
            'scopes' => array_values(array_filter($scopes, static fn (string $s): bool => $s !== '')),
            'status' => (string) $row['status'],
            'status_detail' => $row['status_detail'] !== null ? (string) $row['status_detail'] : null,
            'last_sync_at' => $row['last_sync_at'] !== null ? (string) $row['last_sync_at'] : null,
            'last_sync_status' => $row['last_sync_status'] !== null ? (string) $row['last_sync_status'] : null,
            'last_sync_error' => $row['last_sync_error'] !== null ? (string) $row['last_sync_error'] : null,
            'missing_playback_scopes' => self::missingPlaybackScopes($scopes),
        ];
    }

    /**
     * @param list<string> $granted
     * @return list<string>
     */
    public static function missingPlaybackScopes(array $granted): array
    {
        $have = [];
        foreach ($granted as $scope) {
            if ($scope !== '') {
                $have[$scope] = true;
            }
        }
        $missing = [];
        foreach (self::PLAYBACK_SCOPES as $scope) {
            if (!isset($have[$scope])) {
                $missing[] = $scope;
            }
        }
        return $missing;
    }

    /** @return array{url: string, state: string} */
    public function beginConnect(): array
    {
        $clientId = self::requireClientId();
        $redirect = self::requireRedirect();
        self::assertRedirect($redirect);
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));
        $_SESSION['spotify_oauth'] = [
            'state' => $state,
            'verifier' => $verifier,
            'created' => time(),
        ];
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'state' => $state,
            'scope' => implode(' ', self::SCOPES),
            'code_challenge_method' => 'S256',
            'code_challenge' => $challenge,
        ], '', '&', PHP_QUERY_RFC3986);
        return ['url' => 'https://accounts.spotify.com/authorize?' . $query, 'state' => $state];
    }

    public function finishConnect(int $userId, string $code, string $state): void
    {
        $pending = $_SESSION['spotify_oauth'] ?? null;
        unset($_SESSION['spotify_oauth']);
        if (!is_array($pending) || !hash_equals((string) ($pending['state'] ?? ''), $state)) {
            throw new ValidationException('Spotify returned a sign-in state this session did not start.');
        }
        if ((time() - (int) ($pending['created'] ?? 0)) > 600) {
            throw new ValidationException('The Spotify sign-in expired. Connect again.');
        }
        $verifier = (string) ($pending['verifier'] ?? '');
        $clientId = self::requireClientId();
        $secret = self::requireSecret();
        $redirect = self::requireRedirect();
        $tokens = $this->http->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
            'client_id' => $clientId,
            'code_verifier' => $verifier,
        ], $clientId, $secret);
        $access = (string) ($tokens['access_token'] ?? '');
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            throw new SpotifyException('Spotify did not return a token.', 502);
        }
        $me = $this->http->api('GET', '/me', $access);
        $this->store($userId, $me, $access, $refresh, (int) ($tokens['expires_in'] ?? 3600), (string) ($tokens['scope'] ?? implode(' ', self::SCOPES)));
    }

    public function disconnect(int $userId): void
    {
        $this->db->execute('DELETE FROM spotify_account WHERE user_id = ?', [$userId]);
    }

    /**
     * Short-lived access token for a server-side call or the Web Playback SDK.
     * The refresh token is never returned.
     */
    public function accessToken(int $userId, bool $force = false): string
    {
        return $this->db->transaction(function (Database $db) use ($userId, $force): string {
            $db->execute("SELECT pg_advisory_xact_lock(hashtext('spotify:' || ?::text)::bigint)", [(string) $userId]);
            $row = $db->fetchOne(
                'SELECT access_token_enc, refresh_token_enc, expires_at, status FROM spotify_account WHERE user_id = ?',
                [$userId]
            );
            if ($row === null) {
                throw new NotFoundException('Spotify is not connected.');
            }
            if ((string) $row['status'] === 'needs_reauth') {
                throw new SpotifyException('Spotify needs to be connected again.', 409, true);
            }
            $access = $this->cipher->decrypt((string) $row['access_token_enc']);
            $expires = $row['expires_at'] !== null ? (string) $row['expires_at'] : null;
            if (!$force && !Retry::needsRefresh($expires, time())) {
                return $access;
            }
            $refresh = $this->cipher->decrypt((string) $row['refresh_token_enc']);
            try {
                $tokens = $this->http->token([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh,
                ], self::requireClientId(), self::requireSecret());
            } catch (SpotifyException $e) {
                if ($e->reauth) {
                    $this->markReauth($userId, 'Spotify needs to be connected again.');
                }
                throw new SpotifyException('Spotify needs to be connected again.', 409, true);
            }
            $newAccess = (string) ($tokens['access_token'] ?? '');
            if ($newAccess === '') {
                $this->markReauth($userId, 'Spotify needs to be connected again.');
                throw new SpotifyException('Spotify needs to be connected again.', 409, true);
            }
            $newRefresh = (string) ($tokens['refresh_token'] ?? '');
            if ($newRefresh === '') {
                $newRefresh = $refresh;
            }
            $db->execute(
                'UPDATE spotify_account
                 SET access_token_enc = ?, refresh_token_enc = ?, expires_at = ?, status = ?, status_detail = NULL, updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE user_id = ?',
                [
                    $this->cipher->encrypt($newAccess),
                    $this->cipher->encrypt($newRefresh),
                    gmdate('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600)),
                    'connected',
                    $userId,
                ]
            );
            return $newAccess;
        });
    }

    /**
     * @template T
     * @param callable(string): T $fn
     * @return T
     */
    public function call(int $userId, callable $fn): mixed
    {
        $token = $this->accessToken($userId);
        try {
            return $fn($token);
        } catch (SpotifyException $e) {
            if (!$e->reauth) {
                throw $e;
            }
            $token = $this->accessToken($userId, true);
            return $fn($token);
        }
    }

    public function markReauth(int $userId, string $detail): void
    {
        $this->db->execute(
            'UPDATE spotify_account SET status = ?, status_detail = ?, updated_at = (now() AT TIME ZONE \'UTC\') WHERE user_id = ?',
            ['needs_reauth', $detail, $userId]
        );
    }

    /**
     * @param array<string, mixed> $me
     */
    private function store(int $userId, array $me, string $access, string $refresh, int $expiresIn, string $scopes): void
    {
        $spotifyId = (string) ($me['id'] ?? '');
        $name = trim((string) ($me['display_name'] ?? ''));
        if ($name === '') {
            $name = $spotifyId !== '' ? $spotifyId : 'Spotify';
        }
        if ($spotifyId === '') {
            throw new SpotifyException('Spotify did not return an account id.', 502);
        }
        $this->db->execute(
            'INSERT INTO spotify_account
                (user_id, spotify_user_id, display_name, access_token_enc, refresh_token_enc, expires_at, scopes, status, status_detail, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, (now() AT TIME ZONE \'UTC\'))
             ON CONFLICT (user_id) DO UPDATE SET
                spotify_user_id = EXCLUDED.spotify_user_id,
                display_name = EXCLUDED.display_name,
                access_token_enc = EXCLUDED.access_token_enc,
                refresh_token_enc = EXCLUDED.refresh_token_enc,
                expires_at = EXCLUDED.expires_at,
                scopes = EXCLUDED.scopes,
                status = EXCLUDED.status,
                status_detail = NULL,
                updated_at = (now() AT TIME ZONE \'UTC\')',
            [
                $userId,
                $spotifyId,
                $name,
                $this->cipher->encrypt($access),
                $this->cipher->encrypt($refresh),
                gmdate('Y-m-d H:i:s', time() + max(60, $expiresIn)),
                $scopes,
                'connected',
            ]
        );
    }

    public static function assertRedirect(string $uri): void
    {
        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === 'https' && $host !== '') {
            return;
        }
        if ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return;
        }
        throw new ValidationException('SPOTIFY_REDIRECT_URI must be https, or http://127.0.0.1 (or localhost) for a one-time connect.');
    }

    private static function clientId(): ?string
    {
        return self::usable(Config::maybe('SPOTIFY_CLIENT_ID'));
    }

    private static function clientSecret(): ?string
    {
        return self::usable(Config::maybe('SPOTIFY_CLIENT_SECRET'));
    }

    public static function redirectUri(): ?string
    {
        return self::usable(Config::maybe('SPOTIFY_REDIRECT_URI'));
    }

    private static function requireClientId(): string
    {
        return self::clientId() ?? throw new ValidationException('Set SPOTIFY_CLIENT_ID on the server before connecting Spotify.');
    }

    private static function requireSecret(): string
    {
        return self::clientSecret() ?? throw new ValidationException('Set SPOTIFY_CLIENT_SECRET on the server before connecting Spotify.');
    }

    private static function requireRedirect(): string
    {
        return self::redirectUri() ?? throw new ValidationException('Set SPOTIFY_REDIRECT_URI on the server before connecting Spotify.');
    }

    private static function usable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || str_starts_with($value, 'CHANGE_ME')) {
            return null;
        }
        return $value;
    }
}
