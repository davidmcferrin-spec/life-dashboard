<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Hermes\Chat;
use Dashboard\Hermes\ChatMemory;
use Dashboard\Hermes\Gateway;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class HermesChatTest extends TestCase
{
    public function testKeyStaysOutOfTheThread(): void
    {
        $key = str_repeat('ab', 16);
        $env = tempnam(sys_get_temp_dir(), 'hermes');
        self::assertIsString($env);
        file_put_contents($env, "API_SERVER_ENABLED=true\nAPI_SERVER_KEY={$key}\n");
        self::assertSame($key, Gateway::keyFromEnv($env));
        unlink($env);
        self::assertSame('', Gateway::keyFromEnv($env));

        $memory = new ArrayChatMemory();
        $chat = $this->chat($memory, $key, [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_1', 200, ['session' => ['id' => 'api_1']]],
            ['GET', '/api/sessions/api_1/messages', 200, ['data' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'tool', 'content' => 'hidden ' . $key],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ]]],
        ]);
        $memory->write(7, Chat::SESSION, 'api_1');
        $thread = $chat->thread(7);
        self::assertTrue($thread['reachable']);
        self::assertSame([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi'],
        ], $thread['messages']);
        self::assertStringNotContainsString($key, json_encode($thread, JSON_THROW_ON_ERROR));
    }

    public function testSendStartsARunOnANewSession(): void
    {
        $memory = new ArrayChatMemory();
        $seen = [];
        $chat = $this->chat($memory, str_repeat('c', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['POST', '/api/sessions', 201, ['session' => ['id' => 'api_dash']]],
            ['POST', '/v1/runs', 202, ['run_id' => 'run_1', 'status' => 'started']],
        ], $seen);

        $started = $chat->send(3, '  Plan the pantry  ');
        self::assertSame('run_1', $started['run_id']);
        self::assertSame('api_dash', $memory->read(3, Chat::SESSION));
        self::assertSame('run_1', $memory->read(3, Chat::RUN));
        $body = json_decode((string) $seen[2][2], true);
        self::assertSame('Plan the pantry', $body['input']);
        self::assertSame('api_dash', $body['session_id']);
        self::assertStringStartsWith('http://127.0.0.1:8642/', $seen[2][1]);
    }

    public function testPollRejectsAnotherUsersRun(): void
    {
        $memory = new ArrayChatMemory();
        $memory->write(3, Chat::RUN, 'run_mine');
        $memory->write(3, Chat::SESSION, 'api_dash');
        $chat = $this->chat($memory, str_repeat('d', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/v1/runs/run_mine', 200, [
                'status' => 'completed',
                'output' => 'Done.',
                'session_id' => 'api_dash',
            ]],
        ]);
        $done = $chat->poll(3, 'run_mine');
        self::assertSame('Done.', $done['output']);
        self::assertSame('', $memory->read(3, Chat::RUN));

        $this->expectException(NotFoundException::class);
        $chat->poll(3, 'run_other');
    }

    public function testBlankMessageIsRejected(): void
    {
        $chat = $this->chat(new ArrayChatMemory(), str_repeat('e', 32), []);
        $this->expectException(ValidationException::class);
        $chat->send(1, '   ');
    }

    public function testQuietWhenTheGatewayIsDown(): void
    {
        $chat = $this->chat(new ArrayChatMemory(), str_repeat('f', 32), [
            ['GET', '/health', 0, null],
        ]);
        $thread = $chat->thread(1);
        self::assertFalse($thread['reachable']);
        self::assertSame([], $thread['messages']);
    }

    public function testBearerTokensCannotCallHermes(): void
    {
        self::assertTrue(Scope::sessionOnly('/api/v1/hermes/chat'));
        self::assertFalse(Scope::sessionOnly('/api/v1/ollama/generate'));
        self::assertFalse(Scope::sessionOnly('/api/v1/agents/hermes/runs'));
        self::assertSame('agents:read', Scope::forPath('/api/v1/agents/hermes/status', false));
        self::assertSame('agents:write', Scope::forPath('/api/v1/agents/hermes/runs', true));
        self::assertContains('agents:read', \Dashboard\Auth\OAuthRules::known(false));
    }

    /**
     * @param list<array{0: string, 1: string, 2: int, 3: ?array<string, mixed>}> $script
     * @param list<array{0: string, 1: string, 2: ?string}> $seen
     */
    private function chat(ChatMemory $memory, string $key, array $script, array &$seen = []): Chat
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

        return new Chat($gateway, $memory);
    }
}

final class ArrayChatMemory implements ChatMemory
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
