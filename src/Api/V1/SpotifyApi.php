<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\DB\Database;
use Dashboard\Deploy\DeployService;
use Dashboard\Router\Router;
use Dashboard\Spotify\MusicLibrary;
use Dashboard\Spotify\SpotifyAccess;
use Dashboard\Spotify\SpotifyAccount;
use Dashboard\Spotify\SpotifyException;
use Dashboard\Spotify\SpotifyHttp;
use Dashboard\Spotify\SpotifyLibrary;
use Dashboard\Spotify\SpotifyService;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;

final class SpotifyApi
{
    public function __construct(
        private readonly ApiRunner $runner,
        private readonly ?SpotifyService $spotify = null,
    ) {}

    public function register(Router $r): void
    {
        $r->get('/api/v1/spotify/status', fn () => $this->run(fn (int $u) => $this->svc()->settings($u)));
        $r->get('/api/v1/spotify/playlists', fn () => $this->run(fn (int $u) => [
            'playlists' => $this->svc()->library()->playlists($u),
        ]));
        $r->get('/api/v1/spotify/playlists/facets', fn () => $this->run(fn (int $u) => $this->svc()->library()->playlistFacets($u)));
        $r->get('/api/v1/spotify/tracks/catalog', fn () => $this->run(fn (int $u) => [
            'stale' => $this->svc()->library()->catalogStale($u)['stale'],
        ]));
        $r->get('/api/v1/spotify/playlists/{id}', fn (array $p) => $this->run(fn (int $u) => $this->svc()->library()->playlist($u, $this->id($p))));
        $r->get('/api/v1/spotify/playlists/{id}/changes', fn (array $p) => $this->run(fn (int $u) => [
            'changes' => $this->svc()->library()->changes($u, $this->id($p)),
        ]));
        $r->get('/api/v1/spotify/tracks/meta', fn () => $this->run(fn (int $u) => $this->svc()->library()->mixtape()->get(
            $u,
            self::metaUris($_GET['uris'] ?? ''),
        )));
        $r->get('/api/v1/spotify/library/notes', fn () => $this->run(fn (int $u) => $this->catalog()->notes($u, self::metaUris($_GET['uris'] ?? ''))));
        $r->get('/api/v1/spotify/library/drawer', fn () => $this->run(fn (int $u) => $this->catalog()->drawer($u, (string) ($_GET['uri'] ?? ''))));
        $r->get('/api/v1/spotify/library/activity', fn () => $this->run(fn (int $u) => $this->catalog()->activity($u)));
        $r->get('/api/v1/spotify/seasons', fn () => $this->run(fn (int $u) => $this->svc()->library()->mixtape()->seasons($u)));
        $r->get('/api/v1/spotify/seasons/active', fn () => $this->run(fn (int $u) => $this->svc()->library()->mixtape()->active($u, $this->svc()->library()->clock())));
        $r->get('/api/v1/spotify/search', fn () => $this->run(fn (int $u) => $this->svc()->playback()->search(
            $u,
            (string) ($_GET['q'] ?? ''),
            (string) ($_GET['type'] ?? 'track'),
        )));
        $r->get('/api/v1/spotify/tracks/{id}', fn (array $p) => $this->run(fn (int $u) => $this->svc()->playback()->track($u, (string) ($p['id'] ?? ''))));
        $r->get('/api/v1/spotify/history', fn () => $this->run(fn (int $u) => [
            'plays' => $this->svc()->library()->history(
                $u,
                $this->query('from'),
                $this->query('to'),
                (int) ($_GET['limit'] ?? 50),
                $this->svc()->clock(),
            ),
        ]));
        $r->get('/api/v1/spotify/saved', fn () => $this->run(fn (int $u) => [
            'saved' => $this->svc()->library()->saved($u, (int) ($_GET['limit'] ?? 50)),
        ]));
        $r->get('/api/v1/spotify/top', fn () => $this->run(fn (int $u) => [
            'items' => $this->svc()->library()->top($u, $this->query('kind'), $this->query('time_range')),
        ]));
        $r->get('/api/v1/spotify/player', fn () => $this->run(fn (int $u) => $this->svc()->playback()->now($u)));
        $r->get('/api/v1/spotify/devices', fn () => $this->run(fn (int $u) => $this->svc()->playback()->devices($u)));
        $r->get('/api/v1/spotify/queue', fn () => $this->run(fn (int $u) => $this->svc()->playback()->queue($u)));
        $r->get('/api/v1/spotify/taste', fn () => $this->run(fn (int $u) => $this->svc()->library()->taste($u)));
        $r->get('/api/v1/spotify/prompts', fn () => $this->run(fn (int $u) => [
            'prompts' => $this->svc()->library()->prompts($u),
        ]));
        $r->get('/api/v1/spotify/prompts/{id}', fn (array $p) => $this->run(fn (int $u) => [
            'prompt' => $this->svc()->library()->prompt($u, $this->id($p)),
        ]));
        $r->get('/api/v1/spotify/player-config', fn () => $this->run(function (int $u): array {
            $status = $this->svc()->account()->status($u);
            return [
                'enabled' => $this->svc()->playerEnabled($u),
                'connected' => !empty($status['connected']),
                'display_name' => $status['display_name'],
                'missing_playback_scopes' => $status['missing_playback_scopes'] ?? [],
            ];
        }));
        $r->get('/api/v1/spotify/playback/token', fn () => $this->run(function (int $u): array {
            if ($this->runner->tokenId() !== null) {
                throw new ForbiddenException('The playback token is only available to a signed-in browser.');
            }
            $token = $this->svc()->playback()->sdkToken($u);
            header('Cache-Control: no-store');
            return $token;
        }, 200, false, 'spotify:playback'));

        $r->post('/api/v1/spotify/playlists', fn () => $this->run(fn (int $u) => $this->svc()->library()->createPlaylist($u, $this->runner->body(), $this->by()), 201, true));
        $r->post('/api/v1/spotify/playlists/{id}/update', fn (array $p) => $this->run(fn (int $u) => $this->svc()->library()->updatePlaylist($u, $this->id($p), $this->runner->body(), $this->by()), 200, true));
        $r->post('/api/v1/spotify/playlists/{id}/tracks', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();
            return $this->svc()->library()->addTracks($u, $this->id($p), $body['uris'] ?? null, $body['position'] ?? null, $this->by(), $this->trackMeta($body));
        }, 200, true));
        $r->post('/api/v1/spotify/playlists/{id}/tracks/remove', fn (array $p) => $this->run(fn (int $u) => $this->svc()->library()->removeTracks($u, $this->id($p), $this->runner->body()['uris'] ?? null, $this->by()), 200, true));
        $r->post('/api/v1/spotify/playlists/{id}/tracks/reorder', fn (array $p) => $this->run(fn (int $u) => $this->svc()->library()->reorderTracks($u, $this->id($p), $this->runner->body(), $this->by()), 200, true));
        $r->post('/api/v1/spotify/playlists/{id}/tracks/replace', fn (array $p) => $this->run(fn (int $u) => $this->svc()->library()->replaceTracks($u, $this->id($p), $this->runner->body()['uris'] ?? null, $this->by()), 200, true));
        $r->post('/api/v1/spotify/discard', fn () => $this->run(fn (int $u) => [
            'discarded' => $this->svc()->library()->discard($u, null),
        ], 200, true));
        $r->post('/api/v1/spotify/playlists/{id}/discard', fn (array $p) => $this->run(fn (int $u) => [
            'discarded' => $this->svc()->library()->discard($u, $this->id($p)),
        ], 200, true));
        $r->post('/api/v1/spotify/playlists/{id}/delete', fn (array $p) => $this->run(fn (int $u) => $this->svc()->library()->deletePlaylist($u, $this->id($p), $this->by()), 200, true));
        $r->post('/api/v1/spotify/saved/remove', fn () => $this->run(function (int $u): array {
            $changed = $this->svc()->playback()->library($u, $this->trackList(), false);
            $this->svc()->library()->forgetSaved($u, $changed['ids']);
            return ['removed' => $changed['ids']];
        }, 200, true));
        $r->post('/api/v1/spotify/saved', fn () => $this->run(function (int $u): array {
            $changed = $this->svc()->playback()->library($u, $this->trackList(), true);
            $this->svc()->library()->rememberSaved($u, $changed['tracks']);
            return ['saved' => $changed['ids']];
        }, 200, true));
        $r->post('/api/v1/spotify/push', fn () => $this->run(function (int $u): array {
            $playlistId = $this->playlistId();
            if ($playlistId !== null) {
                $this->svc()->library()->assertEditable($u, $playlistId);
            }
            $this->svc()->library()->mixtape()->assertPending($u, $playlistId, $this->svc()->library()->clock());
            return $this->svc()->sync()->push($u, $playlistId);
        }, 200, true));
        $r->post('/api/v1/spotify/library/genres', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            return $this->catalog()->addGenre($u, (string) ($body['uri'] ?? ''), $body['genre'] ?? '');
        }, 200, true));
        $r->post('/api/v1/spotify/library/season', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            return $this->catalog()->setSeason($u, $this->trackList(), $body['season'] ?? '');
        }, 200, true));
        $r->post('/api/v1/spotify/library/playlist', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            $playlistId = $body['playlist_id'] ?? 0;
            if (!is_numeric($playlistId)) {
                throw ValidationException::withFields(['playlist_id' => 'Choose a playlist.']);
            }
            $tracks = is_array($body['tracks'] ?? null) ? $body['tracks'] : [];
            return $this->catalog()->addToPlaylist($u, $this->trackList(), (int) $playlistId, $this->by(), $tracks);
        }, 200, true));
        $r->post('/api/v1/spotify/library/reanalyze', fn () => $this->run(fn (int $u) => $this->catalog()->reanalyze($u, $this->trackList()), 200, true));
        $r->post('/api/v1/spotify/library/undo', fn () => $this->run(function (int $u): array {
            return $this->catalog()->undo($u, (int) ($this->runner->body()['id'] ?? 0));
        }, 200, true));
        $r->post('/api/v1/spotify/tracks/catalog', fn () => $this->run(function (int $u): array {
            $library = $this->svc()->library();
            $queue = $library->catalogStale($u);
            $fetched = $this->svc()->playback()->catalog($u, $queue['ids']);
            $applied = $library->applyCatalog($u, $fetched['tracks']);
            $applied['requested'] = count($queue['ids']);
            $applied['stale'] = $library->catalogStale($u)['stale'];
            return $applied;
        }, 200, true));
        $r->post('/api/v1/spotify/tracks/meta', fn () => $this->run(fn (int $u) => $this->svc()->library()->mixtape()->saveMany(
            $u,
            is_array($this->runner->body()['tracks'] ?? null) ? $this->runner->body()['tracks'] : [],
        ), 200, true));
        $r->post('/api/v1/spotify/seasons', fn () => $this->run(fn (int $u) => [
            'season' => $this->svc()->library()->mixtape()->saveSeason($u, $this->runner->body()),
        ], 200, true));
        $r->post('/api/v1/spotify/mixtape/plan', fn () => $this->run(function (int $u): array {
            $body = $this->runner->body();
            $prompt = isset($body['prompt_id']) && $body['prompt_id'] !== '' ? (int) $body['prompt_id'] : null;
            $arc = isset($body['arc']) && is_array($body['arc']) ? $body['arc'] : null;
            $options = [];
            if (isset($body['locks']) && is_array($body['locks'])) {
                $options['locks'] = $body['locks'];
            }
            if (array_key_exists('key_compat', $body)) {
                $options['key'] = (bool) $body['key_compat'];
            }
            if (isset($body['target_ms']) && is_numeric($body['target_ms'])) {
                $options['target_ms'] = (int) $body['target_ms'];
            }
            if (isset($body['durations']) && is_array($body['durations'])) {
                $options['durations'] = $body['durations'];
            }
            if (isset($body['avoid']) && is_array($body['avoid'])) {
                $options['avoid'] = $body['avoid'];
            }
            if (isset($body['slots']) && is_numeric($body['slots'])) {
                $options['slots'] = (int) $body['slots'];
            }
            return $this->svc()->library()->mixtape()->plan($u, $body['uris'] ?? [], $prompt, $arc, $options);
        }, 200, false));
        $r->get('/api/v1/spotify/mixtape/locks', fn () => $this->run(fn (int $u) => $this->svc()->library()->mixtape()->locks($u)));
        $r->post('/api/v1/spotify/mixtape/locks', fn () => $this->run(function (int $u): array {
            $locks = $this->runner->body()['locks'] ?? [];
            return $this->svc()->library()->mixtape()->saveLocks($u, is_array($locks) ? $locks : []);
        }, 200, true));
        $r->post('/api/v1/spotify/sync', fn () => $this->run(fn (int $u) => $this->svc()->sync()->pull($u, $this->playlistId()), 200, true));
        $r->post('/api/v1/spotify/taste', fn () => $this->run(fn (int $u) => $this->svc()->library()->saveTaste($u, $this->runner->body()['profile'] ?? null), 200, true));
        $r->post('/api/v1/spotify/prompts', fn () => $this->run(fn (int $u) => [
            'prompt' => $this->svc()->library()->savePrompt($u, null, $this->runner->body()),
        ], 201, true));
        $r->post('/api/v1/spotify/prompts/{id}/update', fn (array $p) => $this->run(fn (int $u) => [
            'prompt' => $this->svc()->library()->savePrompt($u, $this->id($p), $this->runner->body()),
        ], 200, true));
        $r->post('/api/v1/spotify/prompts/{id}/remove', fn (array $p) => $this->run(fn (int $u) => $this->svc()->library()->removePrompt($u, $this->id($p)), 200, true));
        $r->post('/api/v1/spotify/prompts/{id}/run', fn (array $p) => $this->run(fn (int $u) => [
            'prompt' => $this->svc()->library()->markPromptRun($u, $this->id($p)),
        ], 200, true));
        $r->post('/api/v1/spotify/player', fn () => $this->run(function (int $u): array {
            $enabled = $this->runner->body()['enabled'] ?? true;
            $on = $enabled === true || $enabled === 1 || $enabled === '1';
            $this->svc()->setPlayerEnabled($u, $on);
            return ['enabled' => $on];
        }, 200, true));

        foreach (['play', 'pause', 'next', 'previous', 'seek', 'volume', 'shuffle', 'repeat', 'queue', 'transfer'] as $action) {
            $r->post('/api/v1/spotify/playback/' . $action, fn () => $this->run(function (int $u) use ($action): array {
                $body = $this->runner->body();
                if ($action === 'play' && !empty($body['playlist_id'])) {
                    $detail = $this->svc()->library()->playlist($u, (int) $body['playlist_id']);
                    $spotifyId = $detail['playlist']['spotify_id'] ?? null;
                    if (!is_string($spotifyId) || $spotifyId === '') {
                        throw ValidationException::withFields(['playlist_id' => 'That playlist is not on Spotify yet. Push it first.']);
                    }
                    $body['context_uri'] = 'spotify:playlist:' . $spotifyId;
                    unset($body['uris']);
                }
                return $this->svc()->playback()->command($u, $action, $body);
            }, 200, true));
        }
    }

    private function catalog(): MusicLibrary
    {
        $db = Database::getInstance();
        $http = new SpotifyHttp();
        return new MusicLibrary($db, new SpotifyAccount($db, $http), $http, $this->svc()->library());
    }

    private function svc(): SpotifyService
    {
        return $this->spotify ?? SpotifyService::fromDatabase();
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     * @param list<string>|string|null $scope
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false, array|string|null $scope = null): void
    {
        $this->runner->run(function (int $userId) use ($fn, $mutating): array {
            if ($mutating) {
                // A DeployService with no database reports the switch as off.
                // deploy_status reads server_flags through the app database.
                $enabled = (new DeployService(Database::getInstance()))->mcpUpdatesEnabled();
                SpotifyAccess::assertChange($this->runner->tokenId(), $enabled);
            }
            try {
                return $fn($userId);
            } catch (SpotifyException $e) {
                return ['http_status' => $e->httpStatus, 'error' => $e->getMessage()];
            }
        }, $status, $mutating, $scope);
    }

    private function by(): string
    {
        $actor = $this->runner->actor();
        return SpotifyLibrary::actorLabel($actor->kind, $actor->tool);
    }

    /**
     * @param array<string, string> $params
     */
    private function id(array $params): int
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) {
            throw ValidationException::withFields(['id' => 'Expected an id.']);
        }
        return (int) $id;
    }

    /**
     * @return list<string>
     */
    private static function metaUris(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $text)), static fn (string $uri): bool => $uri !== ''));
    }

    private function query(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function trackList(): array
    {
        $body = $this->runner->body();
        $raw = $body['uris'] ?? $body['ids'] ?? null;
        return is_array($raw) ? array_values($raw) : [];
    }

    private function playlistId(): ?int
    {
        $body = $this->runner->body();
        if (!isset($body['playlist_id']) || $body['playlist_id'] === null || $body['playlist_id'] === '') {
            return null;
        }
        if (!is_numeric($body['playlist_id']) || (int) $body['playlist_id'] < 1) {
            throw ValidationException::withFields(['playlist_id' => 'Expected a playlist id.']);
        }
        return (int) $body['playlist_id'];
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function trackMeta(array $body): array
    {
        $tracks = $body['tracks'] ?? [];
        if (!is_array($tracks)) {
            return [];
        }
        $out = [];
        foreach ($tracks as $track) {
            if (!is_array($track) || !isset($track['uri'])) {
                continue;
            }
            $out[] = [
                'uri' => (string) $track['uri'],
                'name' => (string) ($track['name'] ?? ''),
                'artists' => is_array($track['artists'] ?? null) ? $track['artists'] : [],
                'album' => isset($track['album']) ? (string) $track['album'] : null,
                'duration_ms' => (int) ($track['duration_ms'] ?? 0),
                'explicit' => !empty($track['explicit']),
                'image_url' => isset($track['image_url']) ? (string) $track['image_url'] : null,
            ];
        }
        return $out;
    }
}
