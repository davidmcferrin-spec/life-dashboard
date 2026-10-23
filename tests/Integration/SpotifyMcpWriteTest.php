<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiV1;
use Dashboard\Auth\Auth;
use Dashboard\Deploy\DeployService;
use Dashboard\Router\Router;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class SpotifyMcpWriteTest extends PgsqlTestCase
{
    private Services $svc;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['david', 'david@x', 'h', 1]
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->svc = new Services($this->db, new Clock('America/Chicago', new DateTimeImmutable('2026-10-22 18:00:00', new DateTimeZone('UTC'))));
    }

    public function testBearerPlaylistWriteReadsTheSameFlagAsDeployStatus(): void
    {
        $created = $this->svc->tokens->create($this->user, 'Grok Bot v3', ['spotify:write'], null, true);
        $deploy = new DeployService($this->db);
        $deploy->setMcpUpdates(true, $this->user);
        self::assertTrue($deploy->status()['mcp_updates']);

        $on = $this->dispatch((string) $created['token']);
        self::assertSame(404, $on->status);
        self::assertSame('Playlist not found.', $on->payload['error'] ?? null);

        $deploy->setMcpUpdates(false, $this->user);
        self::assertFalse((new DeployService($this->db))->status()['mcp_updates']);
        $off = $this->dispatch((string) $created['token']);
        self::assertSame(403, $off->status);
        self::assertSame(DeployService::MCP_DISABLED, $off->payload['error']);
    }

    private function dispatch(string $token): CapturedResponse
    {
        $saved = [
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
            'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'HTTP_X_LIFEDASH_TOOL' => $_SERVER['HTTP_X_LIFEDASH_TOOL'] ?? null,
        ];
        $capture = getenv('LIFEDASH_TEST_CAPTURE');
        putenv('LIFEDASH_TEST_CAPTURE=1');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v1/spotify/playlists/52/tracks';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'add_playlist_tracks';
        try {
            $router = new Router();
            (new ApiV1(new Auth($this->db), $this->svc))->register($router);
            $router->dispatch();
            self::fail('The playlist write did not answer.');
        } catch (CapturedResponse $response) {
            return $response;
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
            if ($capture === false) {
                putenv('LIFEDASH_TEST_CAPTURE');
            } else {
                putenv('LIFEDASH_TEST_CAPTURE=' . $capture);
            }
        }
    }
}
