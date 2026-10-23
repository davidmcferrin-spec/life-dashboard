<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\CloudStorage\CredentialCrypto;
use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Settings\GrokConnection;
use Dashboard\Settings\SecretMask;
use PHPUnit\Framework\TestCase;

final class GrokConnectionTest extends TestCase
{
    /** @var list<string> */
    private array $overlays = [];

    protected function tearDown(): void
    {
        foreach ($this->overlays as $key) {
            Config::overlay($key, null);
        }
    }

    public function testSecretIsStoredEncryptedAndAbsentFromThePageAndLogs(): void
    {
        $secret = 'grok-live-key-99ab';
        $this->overlay('APP_SECRET', 'unit-test-app-secret-value');
        $log = tempnam(sys_get_temp_dir(), 'grok-log');
        self::assertNotFalse($log);
        $previous = ini_set('error_log', $log);
        $db = new GrokSettingsDatabase();
        $grok = new GrokConnection($db);

        try {
            $grok->save([
                'api_url'    => 'https://api.x.ai/v1',
                'api_secret' => $secret,
            ], 1);
            $view = $grok->view();
            $html = $this->renderPage($view);
            try {
                $grok->save([
                    'api_url'    => 'not a url',
                    'api_secret' => $secret . '-rejected',
                ], 1);
                self::fail('An invalid URL should not be saved.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $blob = $db->rows[GrokConnection::KEY];
        self::assertStringNotContainsString($secret, $blob);
        $stored = CredentialCrypto::decryptJson($blob);
        self::assertSame($secret, $stored['api_secret']);
        self::assertSame('https://api.x.ai/v1', $stored['api_url']);

        self::assertSame('99ab', $view['secret_hint']);
        self::assertSame(SecretMask::hint($secret), $view['secret_hint']);
        self::assertArrayNotHasKey('api_secret', $view);
        self::assertStringNotContainsString($secret, json_encode($view, JSON_THROW_ON_ERROR));

        self::assertStringNotContainsString($secret, $html);
        self::assertStringNotContainsString($secret . '-rejected', $html);
        self::assertStringContainsString('placeholder="•••••••• 99ab"', $html);
        self::assertStringContainsString('value="https://api.x.ai/v1"', $html);
        self::assertStringNotContainsString('value="' . $secret, $html);
        self::assertStringContainsString('Grok API URL', $html);
        self::assertStringContainsString('API secret', $html);
        self::assertStringContainsString('Connect your digital world.', $html);
        self::assertStringContainsString('secret-field', $html);
        self::assertStringContainsString('Encrypted', $html);

        $logged = is_file($log) ? (string) file_get_contents($log) : '';
        self::assertStringNotContainsString($secret, $logged);
        self::assertStringNotContainsString($secret . '-rejected', $logged);
        unlink($log);

        foreach (array_merge($_ENV, $_SERVER) as $value) {
            if (is_string($value)) {
                self::assertStringNotContainsString($secret, $value);
            }
        }
    }

    public function testShortSecretShowsSetAndABlankKeepsTheStoredKey(): void
    {
        $secret = 'x9k2';
        $this->overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new GrokSettingsDatabase();
        $grok = new GrokConnection($db);
        $grok->save([
            'api_url'    => 'https://api.x.ai/v1',
            'api_secret' => $secret,
        ], 1);

        $view = $grok->view();
        self::assertSame('set', $view['secret_hint']);
        $html = $this->renderPage($view);
        self::assertStringNotContainsString($secret, $html);
        self::assertStringContainsString('placeholder="••••••••"', $html);
        self::assertStringNotContainsString('value="' . $secret, $html);

        $grok->save([
            'api_url'    => 'https://api.x.ai/v1',
            'api_secret' => '   ',
        ], 1);
        $again = CredentialCrypto::decryptJson($db->rows[GrokConnection::KEY]);
        self::assertSame($secret, $again['api_secret']);
        self::assertStringNotContainsString($secret, json_encode($grok->view(), JSON_THROW_ON_ERROR));
    }

    public function testConnectionsStepListsGrokForAnAdminOnly(): void
    {
        require_once VIEWS_DIR . '/settings/_nav.php';
        $admin = \settings_nav_groups(true);
        $member = \settings_nav_groups(false);
        self::assertSame(['Grok', '/settings/grok'], $admin['Connections']['grok']);
        self::assertArrayNotHasKey('grok', $member['Connections']);
    }

    private function overlay(string $key, string $value): void
    {
        Config::overlay($key, $value);
        $this->overlays[] = $key;
    }

    /**
     * @param array{api_url: string, secret_hint: string} $view
     */
    private function renderPage(array $view): string
    {
        $runner = sys_get_temp_dir() . '/grok-render-' . bin2hex(random_bytes(4)) . '.php';
        $viewFile = sys_get_temp_dir() . '/grok-view-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($viewFile, json_encode($view, JSON_THROW_ON_ERROR));
        $root = ROOT_DIR;
        $code = <<<PHP
<?php
declare(strict_types=1);
require '{$root}/tests/bootstrap.php';
\$view = json_decode((string) file_get_contents('{$viewFile}'), true);
if (!is_array(\$view)) {
    fwrite(STDERR, "view json\\n");
    exit(1);
}
\$_SERVER['REQUEST_URI'] = '/settings/grok';
\$_SESSION = [];
\$user = ['id' => 1, 'username' => 'ada', 'is_admin' => 1];
\$grok = \$view;
\$themePref = 'jarvis';
\$starfieldPref = false;
\$clockFormat = '12h';
\$jarvisAccent = '#1aa6a6';
\$themeLocalOnly = true;
\$grokCsrf = 'test-csrf';
require '{$root}/templates/settings/grok.php';
PHP;
        file_put_contents($runner, $code);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes);
        self::assertIsResource($process);
        $html = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unlink($runner);
        unlink($viewFile);
        self::assertSame(0, $status, (string) $errors);
        self::assertStringNotContainsString((string) ($view['secret_hint'] === 'set' ? 'x9k2' : 'grok-live-key-99ab'), (string) $errors);

        return (string) $html;
    }
}

/**
 * In-memory system_settings rows. Avoids a database connection in unit tests.
 */
final class GrokSettingsDatabase extends Database
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
