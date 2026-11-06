<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Agents\Command;
use Dashboard\Agents\LocalAgents;
use Dashboard\Hermes\Chat;
use Dashboard\Hermes\ChatMemory;
use Dashboard\Hermes\Gateway;
use Dashboard\Mcp\AgentPrompt;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class LocalAgentsTest extends TestCase
{
    public function testStatusOmitsTheGatewayKey(): void
    {
        $key = str_repeat('ab', 16);
        $agents = $this->agents($key, [
            ['GET', '/health', 200, ['status' => 'ok']],
        ]);
        $status = $agents->hermesStatus();
        self::assertTrue($status['configured']);
        self::assertTrue($status['gateway']['reachable']);
        self::assertSame('127.0.0.1', $status['gateway']['host']);
        self::assertSame(8642, $status['gateway']['port']);
        self::assertStringNotContainsString($key, json_encode($status, JSON_THROW_ON_ERROR));
        $open = $agents->opencodeStatus();
        self::assertFalse($open['installed']);
        self::assertSame(Command::OPENCODE, $open['binary']);
        self::assertSame('ollama/lifedash-coder', $open['model']);
        self::assertStringContainsString('not installed', $open['detail']);
        $claw = $agents->openclawStatus();
        self::assertFalse($claw['installed']);
        self::assertFalse($claw['gateway']['running']);
        self::assertSame('127.0.0.1', $claw['gateway']['host']);
        self::assertSame('http://127.0.0.1:11434', $claw['endpoint']);
    }

    public function testSendAndPollStayOnTheGateway(): void
    {
        $key = str_repeat('cd', 16);
        $seen = [];
        $memory = new LocalAgentMemory();
        $agents = $this->agents($key, [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['POST', '/api/sessions', 201, ['session' => ['id' => 'api_dash']]],
            ['POST', '/v1/runs', 202, ['run_id' => 'run_mcp', 'status' => 'started']],
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/v1/runs/run_mcp', 200, [
                'status' => 'completed',
                'output' => 'Done ' . $key,
                'session_id' => 'api_dash',
            ]],
        ], $seen, $memory);

        $started = $agents->hermesSend(4, '  Plan the week  ', null, 'Grok Bot v3');
        self::assertSame('run_mcp', $started['run_id']);
        self::assertSame('api_dash', $started['session_id']);
        self::assertSame('api_dash', $memory->read(4, Chat::SESSION));
        self::assertSame('', $memory->read(4, LocalAgents::SESSION));
        self::assertSame('run_mcp', $memory->read(4, Chat::WATCH));
        $opened = json_decode((string) $seen[1][2], true);
        self::assertSame('Dashboard', $opened['title']);
        $body = json_decode((string) $seen[2][2], true);
        self::assertSame('Plan the week', $body['input']);
        self::assertSame('api_dash', $body['session_id']);
        $polled = $agents->hermesPoll(4, 'run_mcp');
        self::assertSame('', $memory->read(4, Chat::WATCH));
        self::assertSame('Done [redacted]', $polled['output']);
        self::assertStringNotContainsString($key, json_encode($polled, JSON_THROW_ON_ERROR));
    }

    public function testOmittedSendJoinsTheDashboardSession(): void
    {
        $memory = new LocalAgentMemory();
        $memory->write(4, Chat::SESSION, 'api_dash');
        $memory->write(4, LocalAgents::SESSION, 'api_mcp');
        $seen = [];
        $agents = $this->agents(str_repeat('a', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_dash', 200, ['session' => ['id' => 'api_dash']]],
            ['POST', '/v1/runs', 202, ['run_id' => 'run_join', 'status' => 'started']],
        ], $seen, $memory);

        $started = $agents->hermesSend(4, 'Hi Hermes! Checking in from Chief of Staff via Life-Dashboard.', null, 'Grok Bot v3');
        self::assertSame('api_dash', $started['session_id']);
        self::assertSame('api_mcp', $memory->read(4, Chat::PRIOR));
        self::assertSame('api_mcp', $memory->read(4, LocalAgents::SESSION));
        $ledger = json_decode($memory->read(4, Chat::LEDGER), true);
        self::assertSame('mcp', $ledger[0]['origin']);
        self::assertSame('MCP · Grok Bot v3', $ledger[0]['label']);
        $body = json_decode((string) $seen[2][2], true);
        self::assertSame('api_dash', $body['session_id']);
    }

    public function testOnlyTheMcpSessionIsAdoptedAsTheSharedRoom(): void
    {
        $memory = new LocalAgentMemory();
        $memory->write(4, LocalAgents::SESSION, 'api_mcp');
        $seen = [];
        $agents = $this->agents(str_repeat('b', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_mcp', 200, ['session' => ['id' => 'api_mcp']]],
            ['POST', '/v1/runs', 202, ['run_id' => 'run_old', 'status' => 'started']],
        ], $seen, $memory);

        $started = $agents->hermesSend(4, 'Still there?', null);
        self::assertSame('api_mcp', $started['session_id']);
        self::assertSame('api_mcp', $memory->read(4, Chat::SESSION));
        self::assertSame('api_mcp', $memory->read(4, Chat::AGENT_SESSION));
        self::assertSame('', $memory->read(4, Chat::PRIOR));
    }

    public function testExplicitSessionStaysOutOfTheSharedRoom(): void
    {
        $memory = new LocalAgentMemory();
        $memory->write(4, Chat::SESSION, 'api_dash');
        $memory->write(4, LocalAgents::SESSION, 'api_mcp');
        $seen = [];
        $agents = $this->agents(str_repeat('c', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_side', 200, ['session' => ['id' => 'api_side']]],
            ['POST', '/v1/runs', 202, ['run_id' => 'run_side', 'status' => 'started']],
        ], $seen, $memory);

        $started = $agents->hermesSend(4, 'Side thread', 'api_side', 'Grok Bot v3');
        self::assertSame('api_side', $started['session_id']);
        self::assertSame('api_dash', $memory->read(4, Chat::SESSION));
        self::assertSame('api_mcp', $memory->read(4, LocalAgents::SESSION));
        self::assertSame('', $memory->read(4, Chat::WATCH));
        self::assertSame('', $memory->read(4, Chat::LEDGER));
        self::assertSame(Chat::LEGACY, LocalAgents::SESSION);
    }

    public function testPollRejectsARunThisTokenDidNotStart(): void
    {
        $agents = $this->agents(str_repeat('e', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
        ]);
        $this->expectException(NotFoundException::class);
        $agents->hermesPoll(4, 'run_other');
    }

    public function testSessionsKeepIdsAndTitlesOnly(): void
    {
        $key = str_repeat('f', 32);
        $agents = $this->agents($key, [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions', 200, ['data' => [[
                'id' => 'api_1',
                'title' => 'Notes ' . $key,
                'messages' => [['content' => 'secret ' . $key]],
            ]]]],
        ]);
        $listed = $agents->hermesSessions(4);
        self::assertSame([['id' => 'api_1', 'title' => 'Notes [redacted]']], $listed['sessions']);
        self::assertStringNotContainsString($key, json_encode($listed, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret', json_encode($listed, JSON_THROW_ON_ERROR));
    }

    public function testOpenClawAskIsAFixedArgv(): void
    {
        $seen = [];
        $command = new Command(function (string $binary, array $args, int $timeout) use (&$seen): array {
            $seen[] = [$binary, $args, $timeout];

            return ['code' => 0, 'out' => "ok\n", 'err' => ''];
        });
        $agents = new LocalAgents(new Gateway(str_repeat('a', 32), static fn (): array => ['status' => 0, 'json' => null]), new LocalAgentMemory(), $command);
        $asked = $agents->openclawAsk('  Reply with ok  ');
        self::assertTrue($asked['ok']);
        self::assertSame('ok', $asked['output']);
        self::assertSame(Command::OPENCLAW, $seen[0][0]);
        self::assertSame([
            'infer', 'model', 'run',
            '--model', 'ollama/lifedash-coder',
            '--prompt', 'Reply with ok',
        ], $seen[0][1]);
        self::assertSame(25, $seen[0][2]);
    }

    public function testOpenClawAskRejectsABlankPrompt(): void
    {
        $agents = new LocalAgents(new Gateway('', static fn (): array => ['status' => 0, 'json' => null]), new LocalAgentMemory(), new Command());
        $this->expectException(ValidationException::class);
        $agents->openclawAsk('   ');
    }

    public function testUnknownBinaryIsNotRun(): void
    {
        $command = new Command(function (): array {
            self::fail('A stand-in binary must not be executed.');
        });
        $ran = $command->run('/tmp/not-a-lifedash-binary', ['--version'], 1);
        self::assertSame(127, $ran['code']);
    }

    public function testHermesStopReportsAnUnsupportedGateway(): void
    {
        $memory = new LocalAgentMemory();
        $memory->write(3, LocalAgents::RUNS, json_encode(['run_1']));
        $gateway = new Gateway(str_repeat('ab', 16), static function (string $method, string $url, ?string $body, int $timeout): array {
            unset($method, $body, $timeout);
            if (str_ends_with($url, '/health')) {
                return ['status' => 200, 'json' => ['status' => 'ok']];
            }

            return ['status' => 405, 'json' => null];
        });
        $stopped = (new LocalAgents($gateway, $memory))->hermesStop(3, 'run_1');
        self::assertFalse($stopped['supported']);
        self::assertFalse($stopped['stopped']);
    }

    public function testPromptNamesTheAgentTools(): void
    {
        $prompt = AgentPrompt::text();
        foreach ([
            'hermes_status', 'hermes_list_sessions', 'hermes_start_session', 'hermes_send', 'hermes_poll', 'hermes_stop', 'hermes_thread',
            'opencode_status', 'openclaw_status', 'openclaw_ask',
        ] as $name) {
            self::assertStringContainsString($name, $prompt);
        }
        self::assertStringNotContainsString('opencode_ask', $prompt);
        self::assertStringNotContainsString('opencode_run', $prompt);
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: ?array<string, mixed>}> $script
     * @param list<array{0: string, 1: string, 2: ?string}> $seen
     */
    private function agents(string $key, array $script, array &$seen = [], ?ChatMemory $memory = null): LocalAgents
    {
        $gateway = new Gateway($key, function (string $method, string $url, ?string $body, int $timeout) use (&$script, &$seen): array {
            $seen[] = [$method, $url, $body];
            $step = array_shift($script);
            self::assertIsArray($step);
            self::assertSame($step[0], $method);
            self::assertStringEndsWith($step[1], $url);
            self::assertGreaterThan(0, $timeout);

            return ['status' => $step[2], 'json' => $step[3]];
        });

        return new LocalAgents($gateway, $memory ?? new LocalAgentMemory());
    }
}

final class LocalAgentMemory implements ChatMemory
{
    /** @var array<string, string> */
    private array $values = [];

    public function read(int $userId, string $key): string
    {
        return $this->values[$userId . ':' . $key] ?? '';
    }

    public function write(int $userId, string $key, string $value): void
    {
        $this->values[$userId . ':' . $key] = $value;
    }
}
