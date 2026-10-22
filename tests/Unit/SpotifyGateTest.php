<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\Database;
use Dashboard\Deploy\DeployService;
use Dashboard\Spotify\SpotifyAccess;
use Dashboard\Spotify\SpotifyAccount;
use Dashboard\Spotify\SpotifyLibrary;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\MetricService;
use Dashboard\Widgets\WidgetService;
use Dashboard\Widgets\WidgetTypeRegistry;
use PHPUnit\Framework\TestCase;

final class SpotifyGateTest extends TestCase
{
    public function testBrowserWritesStayAllowedWhenMcpUpdatesAreOff(): void
    {
        SpotifyAccess::assertChange(null, false);
        SpotifyAccess::assertChange(0, false);
        self::assertTrue(DeployService::mcpRequestAllowed(false, null));
        self::assertFalse(DeployService::mcpRequestAllowed(false, 4));
        self::assertTrue(DeployService::mcpRequestAllowed(true, 4));
    }

    public function testTokenWritesAndPlaybackAreBlockedWhenMcpUpdatesAreOff(): void
    {
        try {
            SpotifyAccess::assertChange(4, false);
            self::fail('A bearer write must honor Allow MCP updates.');
        } catch (ForbiddenException $e) {
            self::assertSame(DeployService::MCP_DISABLED, $e->getMessage());
        }
        SpotifyAccess::assertChange(4, true);
    }

    public function testFloatingPlayerIsNotInsertedOnTheGrid(): void
    {
        $player = WidgetTypeRegistry::types()['spotify_player'];
        $card = WidgetTypeRegistry::types()['spotify'];
        self::assertTrue($player['floating']);
        self::assertFalse($player['singleton']);
        self::assertArrayNotHasKey('floating', $card);
        self::assertFalse($card['singleton']);

        $db = $this->createMock(Database::class);
        $svc = new WidgetService($db, new MetricService($db));
        try {
            $svc->create(1, 'spotify_player', null, []);
            self::fail('The floating player must not become a grid widget.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('floats above the page', $e->getMessage());
        }
    }

    public function testRedirectMustBeHttpsOrLoopback(): void
    {
        SpotifyAccount::assertRedirect('https://dash.example.com/settings/spotify/callback');
        SpotifyAccount::assertRedirect('http://127.0.0.1:8443/settings/spotify/callback');
        SpotifyAccount::assertRedirect('http://localhost/settings/spotify/callback');
        try {
            SpotifyAccount::assertRedirect('http://192.168.1.20/settings/spotify/callback');
            self::fail('A bare IP is not a Spotify redirect.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('127.0.0.1', $e->getMessage());
        }
    }

    public function testPreviewAppliesStagedEditsWithoutTouchingTheStoredList(): void
    {
        $tracks = [
            ['uri' => 'spotify:track:aaa', 'name' => 'Alpha'],
            ['uri' => 'spotify:track:bbb', 'name' => 'Beta'],
        ];
        $preview = SpotifyLibrary::preview($tracks, [
            ['op' => 'add', 'status' => 'pending', 'payload' => [
                'uris' => ['spotify:track:ccc'],
                'position' => 1,
                'tracks' => [['uri' => 'spotify:track:ccc', 'name' => 'Gamma', 'artists' => ['North']]],
            ]],
            ['op' => 'remove', 'status' => 'pending', 'payload' => ['uris' => ['spotify:track:aaa']]],
            ['op' => 'reorder', 'status' => 'discarded', 'payload' => ['range_start' => 0, 'insert_before' => 2, 'length' => 1]],
        ]);
        self::assertSame(['spotify:track:ccc', 'spotify:track:bbb'], array_column($preview, 'uri'));
        self::assertSame('Gamma', $preview[0]['name']);
        self::assertSame(['North'], $preview[0]['artists']);
        self::assertSame(0, $preview[0]['position']);
    }

    public function testActorLabelSeparatesTheBrowserFromMcpAndAi(): void
    {
        self::assertSame('david', SpotifyLibrary::actorLabel('user', null));
        self::assertSame('mcp', SpotifyLibrary::actorLabel('ai', null));
        self::assertSame('ai', SpotifyLibrary::actorLabel('ai', 'create_spotify_playlist'));
    }
}
