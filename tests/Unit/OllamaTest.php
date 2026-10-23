<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\Database;
use Dashboard\Ollama\OllamaClient;
use Dashboard\Ollama\OllamaEndpoint;
use Dashboard\Ollama\OllamaReport;
use Dashboard\Ollama\OllamaService;
use Dashboard\Ollama\OllamaSettings;
use Dashboard\Settings\ServiceControl;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class OllamaTest extends TestCase
{
    public function testUrlAndModelNamesStayOnThisMachine(): void
    {
        self::assertSame('http://127.0.0.1:11434', OllamaEndpoint::baseUrl(''));
        self::assertSame('http://127.0.0.1:11434', OllamaEndpoint::baseUrl('http://127.0.0.1:11434/'));
        self::assertSame('http://localhost:11435', OllamaEndpoint::baseUrl('http://localhost:11435'));
        self::assertSame('http://[::1]:11434', OllamaEndpoint::baseUrl('http://[::1]:11434'));
        self::assertSame('llama3.2:3b', OllamaEndpoint::model(' llama3.2:3b ', 'chat_model', false));
        self::assertSame('', OllamaEndpoint::model('  ', 'chat_model', false));

        foreach (['http://10.1.1.1:11434', 'http://0.0.0.0:11434', 'https://127.0.0.1:11434', 'http://127.0.0.1:11434/api'] as $bad) {
            try {
                OllamaEndpoint::baseUrl($bad);
                self::fail($bad);
            } catch (ValidationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        try {
            OllamaEndpoint::model('../x', 'model', true);
            self::fail('path');
        } catch (ValidationException) {
        }
    }

    public function testReportMatchesTheHealthRules(): void
    {
        $quiet = OllamaReport::assess([
            'installed' => false, 'systemd' => 'inactive', 'api_ok' => false,
            'hosts' => [], 'models' => [], 'chat' => '', 'embed' => '', 'pull_state' => '',
        ]);
        self::assertSame('ok', $quiet['status']);
        self::assertSame('Ollama is not installed.', $quiet['detail']);

        $pulling = OllamaReport::assess([
            'installed' => true, 'systemd' => 'active', 'api_ok' => false,
            'hosts' => [], 'models' => [], 'chat' => 'llama3.2:3b', 'embed' => 'nomic-embed-text',
            'pull_state' => 'pulling',
        ]);
        self::assertSame('ok', $pulling['status']);
        self::assertSame('Pulling the default models.', $pulling['detail']);

        $public = OllamaReport::assess([
            'installed' => true, 'systemd' => 'active', 'api_ok' => true,
            'hosts' => ['0.0.0.0'], 'models' => ['qwen2.5:7b'], 'chat' => 'qwen2.5:7b',
            'embed' => 'nomic-embed-text', 'pull_state' => 'ready',
        ]);
        self::assertSame('fail', $public['status']);
        self::assertSame('Ollama is listening outside this machine.', $public['detail']);

        $missing = OllamaReport::assess([
            'installed' => true, 'systemd' => 'active', 'api_ok' => true,
            'hosts' => ['127.0.0.1'], 'models' => ['nomic-embed-text'], 'chat' => 'qwen2.5:7b',
            'embed' => 'nomic-embed-text', 'pull_state' => 'ready',
        ]);
        self::assertSame('warn', $missing['status']);
        self::assertSame('The default chat model is not installed.', $missing['detail']);
    }

    public function testEffectiveChatUsesThePlanWhenUnset(): void
    {
        $row = ['base_url' => 'http://127.0.0.1:11434', 'chat_model' => '', 'embed_model' => 'nomic-embed-text'];
        $effective = OllamaSettings::effective($row, ['chat_model' => 'qwen2.5:7b']);
        self::assertSame('qwen2.5:7b', $effective['chat_model']);
        $saved = OllamaSettings::effective(['base_url' => 'http://127.0.0.1:11434', 'chat_model' => 'llama3.2:1b', 'embed_model' => 'nomic-embed-text'], ['chat_model' => 'qwen2.5:7b']);
        self::assertSame('llama3.2:1b', $saved['chat_model']);
    }

    public function testPullIsQueuedThroughTheServiceSpool(): void
    {
        $spool = sys_get_temp_dir() . '/ld-ollama-' . bin2hex(random_bytes(4));
        mkdir($spool . '/incoming', 0777, true);
        try {
            $service = new OllamaService(new OllamaMemoryDatabase(), new ServiceControl($spool));
            $queued = $service->queue('ollama-pull', 'llama3.2:3b');
            self::assertTrue($queued['queued']);
            $raw = file_get_contents($spool . '/incoming/' . $queued['id'] . '.json');
            $doc = json_decode((string) $raw, true);
            self::assertSame('ollama-pull', $doc['action']);
            self::assertSame('llama3.2:3b', $doc['model']);
        } finally {
            array_map('unlink', glob($spool . '/incoming/*') ?: []);
            @rmdir($spool . '/incoming');
            @rmdir($spool);
        }
    }

    public function testViewReadsModelsAndAFailedPull(): void
    {
        $dir = sys_get_temp_dir() . '/ld-ollama-state-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/plan.json', json_encode([
            'accelerator' => 'cpu', 'ram_mib' => 8192, 'vram_mib' => 0,
            'chat_model' => 'llama3.2:3b', 'embed_model' => 'nomic-embed-text',
        ]));
        file_put_contents($dir . '/status.json', json_encode([
            'state' => 'failed', 'detail' => 'The model pull failed.', 'listen' => ['127.0.0.1'],
        ]));
        $client = new class extends OllamaClient {
            public function models(string $base): ?array
            {
                return [['name' => 'nomic-embed-text', 'size' => 10, 'modified_at' => '']];
            }

            public function version(string $base): ?string
            {
                return '0.9.0';
            }
        };
        try {
            $db = new OllamaMemoryDatabase();
            $view = (new OllamaService($db, null, $client, $dir))->view();
            self::assertSame('cpu', $view['hardware']['accelerator']);
            self::assertSame('llama3.2:3b', $view['effective']['chat_model']);
            self::assertSame('failed', $view['job']['state']);
            self::assertSame('warn', $view['health']['status']);
            self::assertSame('The model pull failed.', $view['health']['detail']);
            self::assertSame('0.9.0', $view['version']);
        } finally {
            @unlink($dir . '/plan.json');
            @unlink($dir . '/status.json');
            @rmdir($dir);
        }
    }
}

final class OllamaMemoryDatabase extends Database
{
    public function __construct()
    {
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return [
            'base_url'    => 'http://127.0.0.1:11434',
            'chat_model'  => '',
            'embed_model' => 'nomic-embed-text',
        ];
    }

    public function execute(string $sql, array $params = []): int
    {
        return 1;
    }
}
