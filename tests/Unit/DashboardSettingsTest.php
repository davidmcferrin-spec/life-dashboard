<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\CloudStorage\CredentialCrypto;
use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Settings\BrowserOnly;
use Dashboard\Settings\SecretMask;
use Dashboard\Settings\ServiceControl;
use Dashboard\Settings\SetupConfig;
use Dashboard\Settings\TunnelSettings;
use Dashboard\Spotify\TokenKey;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class DashboardSettingsTest extends TestCase
{
    /** @var list<string> */
    private array $overlays = [];

    private ?string $tokenKeyPath = null;

    protected function tearDown(): void
    {
        foreach ($this->overlays as $key) {
            Config::overlay($key, null);
        }
        TokenKey::usePath(null);
        if ($this->tokenKeyPath !== null && is_file($this->tokenKeyPath)) {
            unlink($this->tokenKeyPath);
        }
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        putenv('LIFEDASH_TEST_CAPTURE');
    }

    public function testSecretHintIsLastFourOrSet(): void
    {
        self::assertSame('', SecretMask::hint(null));
        self::assertSame('', SecretMask::hint(''));
        self::assertSame('set', SecretMask::hint('short'));
        self::assertSame('et99', SecretMask::hint('supersecret99'));
    }

    public function testSavedSecretIsMaskedAndAClearRemovesIt(): void
    {
        $this->overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new MemorySettingsDatabase();
        $setup = new SetupConfig($db);
        $setup->save('spotify', [
            'SPOTIFY_CLIENT_ID'     => 'clientid1234',
            'SPOTIFY_CLIENT_SECRET' => 'clientsecret5678',
            'SPOTIFY_REDIRECT_URI'  => 'https://dash.example.com/settings/spotify/callback',
            'SPOTIFY_SYNC_MINUTES'  => '15',
        ], 1);

        $blob = $db->rows['setup'];
        self::assertStringNotContainsString('clientsecret5678', $blob);
        $stored = CredentialCrypto::decryptJson($blob);
        self::assertSame('clientsecret5678', $stored['SPOTIFY_CLIENT_SECRET']);

        $secret = $this->field($setup->view(), 'spotify', 'SPOTIFY_CLIENT_SECRET');
        self::assertSame('', $secret['value']);
        self::assertSame('5678', $secret['hint']);
        self::assertArrayNotHasKey('SPOTIFY_TOKEN_KEY', $stored);

        $setup->save('spotify', ['clear' => ['SPOTIFY_CLIENT_SECRET']], 1);
        $after = CredentialCrypto::decryptJson($db->rows['setup']);
        self::assertArrayNotHasKey('SPOTIFY_CLIENT_SECRET', $after);
        self::assertSame('clientid1234', $after['SPOTIFY_CLIENT_ID']);
    }

    public function testDatabaseValueBeatsTheEnvironment(): void
    {
        self::assertSame('from-db', SetupConfig::prefer(' from-db ', 'from-env'));
        self::assertSame('from-env', SetupConfig::prefer('  ', 'from-env'));
        self::assertNull(SetupConfig::prefer('', ''));

        $this->overlay('PUBLIC_BASE_URL', 'https://from-env.example');
        $group = SetupConfig::catalog()['tunnel'];
        $stored = SetupConfig::mergeGroup($group, [], [
            'PUBLIC_BASE_URL' => 'https://dash.example.com',
            'MCP_PUBLIC_URL'  => 'https://mcp.example.com/mcp',
            'MCP_BIND'        => 'loopback',
        ]);
        self::assertSame('https://dash.example.com', SetupConfig::prefer($stored['PUBLIC_BASE_URL'], 'https://from-env.example'));
        self::assertSame('loopback', $stored['MCP_BIND']);
    }

    public function testRedirectSuggestionUsesTheDashboardUrl(): void
    {
        self::assertSame('', SetupConfig::redirectSuggestion());
        $this->overlay('PUBLIC_BASE_URL', 'https://dash.example.com/');
        self::assertSame(
            'https://dash.example.com/settings/spotify/callback',
            SetupConfig::redirectSuggestion(),
        );
    }

    public function testSpotifyAndBindFieldsAcceptRealValues(): void
    {
        $catalog = SetupConfig::catalog();
        $keys = [];
        foreach ($catalog as $group) {
            foreach ($group['fields'] as $field) {
                $keys[] = $field['key'];
            }
        }
        self::assertNotContains('SPOTIFY_TOKEN_KEY', $keys);
        self::assertSame('restart-mcp', $catalog['tunnel']['actions'][0]['id']);

        $spotify = SetupConfig::mergeGroup($catalog['spotify'], [], [
            'SPOTIFY_REDIRECT_URI' => 'https://dash.example.com/settings/spotify/callback',
            'SPOTIFY_SYNC_MINUTES' => '10',
        ]);
        self::assertSame('https://dash.example.com/settings/spotify/callback', $spotify['SPOTIFY_REDIRECT_URI']);
        self::assertSame('10', $spotify['SPOTIFY_SYNC_MINUTES']);
    }

    public function testMinutesAndBindRejectBadValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SetupConfig::mergeGroup(SetupConfig::catalog()['spotify'], [], [
            'SPOTIFY_SYNC_MINUTES' => '0',
        ]);
    }

    public function testBindRejectsAFreeTextValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SetupConfig::mergeGroup(SetupConfig::catalog()['tunnel'], [], [
            'MCP_BIND' => '0.0.0.0',
        ]);
    }

    public function testBearerCannotChangeTheseSettings(): void
    {
        self::assertFalse(BrowserOnly::blocked([], false));
        self::assertTrue(BrowserOnly::blocked(['HTTP_AUTHORIZATION' => 'Bearer abc.def'], false));
        self::assertTrue(BrowserOnly::blocked(['REDIRECT_HTTP_AUTHORIZATION' => 'bearer abc'], false));
        self::assertTrue(BrowserOnly::blocked([], true));

        putenv('LIFEDASH_TEST_CAPTURE=1');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc.def';
        try {
            BrowserOnly::reject();
            self::fail('A bearer must be rejected.');
        } catch (CapturedResponse $response) {
            self::assertSame(403, $response->status);
            self::assertSame(BrowserOnly::DENIED, $response->payload['error']);
        }
    }

    public function testTokenKeyFileIsCreatedOutsideTheWebRootAndReused(): void
    {
        $dir = sys_get_temp_dir() . '/ld-token-key-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->tokenKeyPath = $dir . '/spotify-token.key';
        TokenKey::usePath($this->tokenKeyPath);
        $this->overlay('SPOTIFY_TOKEN_KEY', 'explicit-token-key-value');

        $first = TokenKey::secret();
        self::assertSame('explicit-token-key-value', $first);
        self::assertStringStartsWith(sys_get_temp_dir(), TokenKey::path());
        self::assertStringNotContainsString('/public/', TokenKey::path());

        $this->overlay('SPOTIFY_TOKEN_KEY', 'a-different-key');
        TokenKey::reset();
        self::assertSame('explicit-token-key-value', TokenKey::secret());
    }

    public function testTunnelTokenIsEncryptedAndRoutesAreCopyable(): void
    {
        $this->overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new MemorySettingsDatabase();
        $spool = sys_get_temp_dir() . '/ld-tunnel-' . bin2hex(random_bytes(4));
        mkdir($spool . '/incoming', 0777, true);
        $services = new ServiceControl($spool);
        $tunnel = new TunnelSettings($db, $services);
        $tunnel->save([
            'dash_host' => 'Dash.Example.com',
            'mcp_host'  => 'mcp.example.com',
            'token'     => 'super-secret-token',
        ], 4);

        $blob = $db->rows['tunnel'];
        self::assertStringNotContainsString('super-secret-token', $blob);
        $stored = CredentialCrypto::decryptJson($blob);
        self::assertSame('super-secret-token', $stored['token']);
        self::assertSame('dash.example.com', $stored['dash_host']);

        $view = TunnelSettings::present($stored, ['connected' => false, 'detail' => 'Not checked yet', 'checked_at' => null]);
        self::assertArrayNotHasKey('token', $view);
        self::assertTrue($view['token_set']);
        self::assertSame('oken', $view['token_hint']);
        self::assertSame('dash.example.com -> http://localhost:80', $view['routes'][0]['copy']);
        self::assertSame('mcp.example.com -> http://localhost:8765', $view['routes'][1]['copy']);

        $queued = $tunnel->act('tunnel-start');
        $payload = json_decode((string) file_get_contents($spool . '/incoming/' . $queued['id'] . '.json'), true);
        self::assertSame('tunnel-start', $payload['action']);
        self::assertSame('super-secret-token', $payload['token']);
    }

    public function testTunnelHostnamesMustDiffer(): void
    {
        $db = new MemorySettingsDatabase();
        $tunnel = new TunnelSettings($db, new ServiceControl(sys_get_temp_dir() . '/unused-spool'));
        $this->expectException(\InvalidArgumentException::class);
        $tunnel->save(['dash_host' => 'same.example.com', 'mcp_host' => 'same.example.com'], 1);
    }

    public function testServiceRequestOmitsTheTokenUnlessTheActionNeedsIt(): void
    {
        $spool = sys_get_temp_dir() . '/ld-svc-' . bin2hex(random_bytes(4));
        mkdir($spool . '/incoming', 0777, true);
        $control = new ServiceControl($spool);

        $restart = $control->request('restart-mcp');
        $restartBody = json_decode((string) file_get_contents($spool . '/incoming/' . $restart['id'] . '.json'), true);
        self::assertSame('restart-mcp', $restartBody['action']);
        self::assertArrayNotHasKey('token', $restartBody);

        $status = ['connected' => true, 'detail' => 'Connected', 'checked_at' => gmdate('c')];
        mkdir($spool . '/state', 0777, true);
        file_put_contents($spool . '/state/status.json', json_encode($status));
        self::assertTrue($control->status()['connected']);
        self::assertFalse($control->stale(30));

        $missing = new ServiceControl($spool . '-missing');
        $this->expectException(ValidationException::class);
        $missing->request('restart-mcp');
    }

    private function overlay(string $key, string $value): void
    {
        Config::overlay($key, $value);
        $this->overlays[] = $key;
    }

    /**
     * @param array{groups: list<array<string, mixed>>} $view
     * @return array<string, mixed>
     */
    private function field(array $view, string $groupId, string $key): array
    {
        foreach ($view['groups'] as $group) {
            if ($group['id'] !== $groupId) {
                continue;
            }
            foreach ($group['fields'] as $field) {
                if ($field['key'] === $key) {
                    return $field;
                }
            }
        }
        self::fail('Missing field ' . $key);
    }
}

/**
 * In-memory system_settings rows. Avoids a database connection in unit tests.
 */
final class MemorySettingsDatabase extends Database
{
    public function __construct()
    {
    }

    /** @var array<string, string> */
    public array $rows = [];

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $key = (string) ($params[0] ?? '');
        if (!isset($this->rows[$key])) {
            return null;
        }
        if (str_contains($sql, 'value_encrypted')) {
            return ['value_encrypted' => $this->rows[$key]];
        }

        return ['id' => 1];
    }

    public function execute(string $sql, array $params = []): int
    {
        if (str_starts_with(ltrim($sql), 'INSERT')) {
            $this->rows[(string) $params[0]] = (string) $params[1];

            return 1;
        }
        if (str_starts_with(ltrim($sql), 'UPDATE')) {
            $this->rows[(string) $params[2]] = (string) $params[0];

            return 1;
        }

        return 0;
    }
}
