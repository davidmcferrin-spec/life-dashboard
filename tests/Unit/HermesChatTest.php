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

        $seen = [];
        $memory = new ArrayChatMemory();
        $chat = $this->chat($memory, $key, [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_1', 200, ['session' => ['id' => 'api_1']]],
            ['GET', '/api/sessions/api_1/messages', 200, ['data' => [
                ['role' => 'user', 'content' => 'Hello', 'timestamp' => 1700000000],
                ['role' => 'tool', 'content' => 'hidden ' . $key],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'secret']], 'display_content' => 'Hi'],
            ]]],
        ], $seen);
        $memory->write(7, Chat::SESSION, 'api_1');
        $thread = $chat->thread(7);
        self::assertTrue($thread['reachable']);
        self::assertFalse($thread['has_more']);
        self::assertSame('', $thread['next_before_id']);
        self::assertSame([
            ['id' => 'i0', 'role' => 'user', 'content' => 'Hello', 'ts' => gmdate('c', 1700000000), 'sender' => 'You', 'origin' => 'you'],
            ['id' => 'i1', 'role' => 'assistant', 'content' => 'Hi', 'ts' => '', 'sender' => 'Hermes', 'origin' => 'hermes'],
        ], $thread['messages']);
        self::assertStringNotContainsString($key, json_encode($thread, JSON_THROW_ON_ERROR));
        $messageUrls = $this->messageUrls($seen);
        self::assertCount(1, $messageUrls);
        self::assertStringContainsString('limit=' . Chat::PAGE, $messageUrls[0]);
        self::assertStringContainsString('order=latest', $messageUrls[0]);
    }

    public function testScrollUpReadsOlderPagesFromTheSession(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['id' => $i, 'role' => $i % 2 === 1 ? 'user' : 'assistant', 'content' => 'm' . $i];
        }
        $seen = [];
        $memory = new ArrayChatMemory();
        $memory->write(7, Chat::SESSION, 'api_1');
        $script = [];
        for ($n = 0; $n < 4; $n++) {
            $script[] = ['GET', '/health', 200, ['status' => 'ok']];
            $script[] = ['GET', '/api/sessions/api_1', 200, ['session' => ['id' => 'api_1']]];
            $script[] = ['GET', '/api/sessions/api_1/messages', 200, ['messages' => array_reverse($rows)]];
        }
        $chat = $this->chat($memory, str_repeat('a', 32), $script, $seen);

        $newest = $chat->thread(7, '', 2);
        self::assertSame(['m4', 'm5'], array_column($newest['messages'], 'content'));
        self::assertTrue($newest['has_more']);
        self::assertSame('4', $newest['next_before_id']);

        $older = $chat->thread(7, '4', 2);
        self::assertSame(['m2', 'm3'], array_column($older['messages'], 'content'));
        self::assertTrue($older['has_more']);
        self::assertSame('2', $older['next_before_id']);

        $rest = $chat->thread(7, '2', 2);
        self::assertSame(['m1'], array_column($rest['messages'], 'content'));
        self::assertFalse($rest['has_more']);
        self::assertSame('', $rest['next_before_id']);

        $done = $chat->thread(7, '1', 2);
        self::assertSame([], $done['messages']);
        self::assertFalse($done['has_more']);

        $urls = $this->messageUrls($seen);
        self::assertStringContainsString('order=latest', $urls[0]);
        self::assertStringNotContainsString('before_id', $urls[0]);
        self::assertStringContainsString('before_id=4', $urls[1]);
        self::assertStringContainsString('before_id=1', $urls[3]);
    }

    public function testOffsetPagesWhenTheGatewayIgnoresTheCursor(): void
    {
        $memory = new ArrayChatMemory();
        $memory->write(7, Chat::SESSION, 'api_1');
        $latest = [
            'messages' => [
                ['id' => 4, 'role' => 'user', 'content' => 'm4'],
                ['id' => 5, 'role' => 'assistant', 'content' => 'm5'],
            ],
            'pagination' => ['order' => 'latest', 'limit' => 2, 'offset' => 0, 'returned' => 2],
        ];
        $seen = [];
        $script = [];
        $push = function (array $messages, int $offset) use (&$script): void {
            $script[] = ['GET', '/health', 200, ['status' => 'ok']];
            $script[] = ['GET', '/api/sessions/api_1', 200, ['session' => ['id' => 'api_1']]];
            $script[] = ['GET', '/api/sessions/api_1/messages', 200, [
                'messages' => $messages,
                'pagination' => ['order' => 'latest', 'limit' => 2, 'offset' => $offset, 'returned' => count($messages)],
            ]];
        };
        $push($latest['messages'], 0);
        $push($latest['messages'], 0);
        $script[] = ['GET', '/api/sessions/api_1/messages', 200, [
            'messages' => [
                ['id' => 2, 'role' => 'user', 'content' => 'm2'],
                ['id' => 3, 'role' => 'assistant', 'content' => 'm3'],
            ],
            'pagination' => ['order' => 'latest', 'limit' => 2, 'offset' => 2, 'returned' => 2],
        ]];
        $chat = $this->chat($memory, str_repeat('a', 32), $script, $seen);

        $newest = $chat->thread(7, '', 2);
        self::assertSame(['m4', 'm5'], array_column($newest['messages'], 'content'));
        self::assertTrue($newest['has_more']);

        $older = $chat->thread(7, '4', 2);
        self::assertSame(['m2', 'm3'], array_column($older['messages'], 'content'));
        self::assertTrue($older['has_more']);
        self::assertSame('2', $older['next_before_id']);

        $urls = $this->messageUrls($seen);
        self::assertStringContainsString('before_id=4', $urls[1]);
        self::assertStringContainsString('offset=2', $urls[2]);
        self::assertStringNotContainsString('before_id', $urls[2]);
    }

    public function testOldestGatewayPageIsNotTheOpeningView(): void
    {
        $memory = new ArrayChatMemory();
        $memory->write(7, Chat::SESSION, 'api_1');
        $seen = [];
        $chat = $this->chat($memory, str_repeat('b', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_1', 200, ['session' => ['id' => 'api_1']]],
            ['GET', '/api/sessions/api_1/messages', 200, [
                'messages' => [['id' => 1, 'role' => 'user', 'content' => 'Old']],
                'pagination' => ['order' => 'oldest', 'limit' => 1, 'returned' => 1, 'offset' => 0],
            ]],
            ['GET', '/api/sessions/api_1/messages', 200, ['data' => [
                ['id' => 1, 'role' => 'user', 'content' => 'Old'],
                ['id' => 2, 'role' => 'assistant', 'content' => 'New'],
            ]]],
        ], $seen);

        $thread = $chat->thread(7, '', 1);
        self::assertSame(['New'], array_column($thread['messages'], 'content'));
        self::assertTrue($thread['has_more']);
        self::assertSame('2', $thread['next_before_id']);
        $urls = $this->messageUrls($seen);
        self::assertCount(2, $urls);
        self::assertStringContainsString('order=latest', $urls[0]);
        self::assertStringNotContainsString('?', $urls[1]);
    }

    public function testBadHistoryCursorIsRejected(): void
    {
        $seen = [];
        $chat = $this->chat(new ArrayChatMemory(), str_repeat('c', 32), [], $seen);
        $this->expectException(ValidationException::class);
        try {
            $chat->thread(1, '../secret', 10);
        } finally {
            self::assertSame([], $seen);
        }
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

    public function testLegacyMcpHistoryShowsInTheSharedRoom(): void
    {
        $memory = new ArrayChatMemory();
        $memory->write(7, Chat::SESSION, 'api_dash');
        $memory->write(7, Chat::LEGACY, 'api_mcp');
        $youAt = 1700000000;
        $agentAt = 1700000100;
        $seen = [];
        $chat = $this->chat($memory, str_repeat('a', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_dash', 200, ['session' => ['id' => 'api_dash']]],
            ['GET', '/api/sessions/api_mcp', 200, ['session' => ['id' => 'api_mcp']]],
            ['GET', '/api/sessions/api_dash/messages', 200, ['messages' => [
                ['id' => 1, 'role' => 'user', 'content' => 'From the browser', 'timestamp' => $youAt],
                ['id' => 2, 'role' => 'assistant', 'content' => 'Browser reply', 'timestamp' => $youAt + 5],
            ]]],
            ['GET', '/api/sessions/api_mcp/messages', 200, ['messages' => [
                ['id' => 1, 'role' => 'user', 'content' => 'Hi Hermes! Checking in from Chief of Staff via Life-Dashboard.', 'timestamp' => $agentAt],
                ['id' => 2, 'role' => 'assistant', 'content' => 'Hello from Hermes.', 'timestamp' => $agentAt + 5],
            ]]],
        ], $seen);

        $thread = $chat->thread(7);
        self::assertSame([
            'From the browser',
            'Browser reply',
            'Hi Hermes! Checking in from Chief of Staff via Life-Dashboard.',
            'Hello from Hermes.',
        ], array_column($thread['messages'], 'content'));
        self::assertSame(['You', 'Hermes', 'AI Agent (MCP)', 'Hermes'], array_column($thread['messages'], 'sender'));
        self::assertSame(['you', 'hermes', 'mcp', 'hermes'], array_column($thread['messages'], 'origin'));
        self::assertSame('api_mcp', $memory->read(7, Chat::PRIOR));
        self::assertFalse($thread['has_more']);
        self::assertStringStartsWith('d.', $thread['messages'][0]['id']);
        self::assertStringStartsWith('m.', $thread['messages'][2]['id']);
    }

    public function testMergedRoomPagesOlderRowsWithoutDroppingEitherSide(): void
    {
        $memory = new ArrayChatMemory();
        $memory->write(7, Chat::SESSION, 'api_dash');
        $memory->write(7, Chat::LEGACY, 'api_mcp');
        $dash = [];
        $mcp = [];
        for ($i = 1; $i <= 4; $i++) {
            $dash[] = ['id' => $i, 'role' => $i % 2 === 1 ? 'user' : 'assistant', 'content' => 'd' . $i, 'timestamp' => 1700000000 + ($i * 10)];
            $mcp[] = ['id' => $i, 'role' => $i % 2 === 1 ? 'user' : 'assistant', 'content' => 'm' . $i, 'timestamp' => 1700000005 + ($i * 10)];
        }
        $full = function (string $session, array $rows): array {
            return ['GET', '/api/sessions/' . $session . '/messages', 200, ['messages' => array_reverse($rows)]];
        };
        $open = [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_dash', 200, ['session' => ['id' => 'api_dash']]],
            ['GET', '/api/sessions/api_mcp', 200, ['session' => ['id' => 'api_mcp']]],
        ];
        $seen = [];
        $chat = $this->chat($memory, str_repeat('b', 32), array_merge(
            $open,
            [$full('api_dash', $dash), $full('api_mcp', $mcp)],
            $open,
            [$full('api_dash', $dash), $full('api_mcp', $mcp), $full('api_mcp', $mcp)]
        ), $seen);

        $newest = $chat->thread(7, '', 2);
        self::assertSame(['d4', 'm4'], array_column($newest['messages'], 'content'));
        self::assertTrue($newest['has_more']);
        $cursor = $newest['next_before_id'];
        self::assertStringStartsWith('d.', $cursor);

        $older = $chat->thread(7, $cursor, 2);
        self::assertSame(['d3', 'm3'], array_column($older['messages'], 'content'));
        self::assertTrue($older['has_more']);
        self::assertNotSame($cursor, $older['messages'][0]['id']);
    }

    public function testNewChatDoesNotBringBackTheMcpTranscript(): void
    {
        $memory = new ArrayChatMemory();
        $memory->write(7, Chat::SESSION, 'api_dash');
        $memory->write(7, Chat::LEGACY, 'api_mcp');
        $memory->write(7, Chat::WATCH, 'run_old');
        $seen = [];
        $chat = $this->chat($memory, str_repeat('c', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/health', 200, ['status' => 'ok']],
        ], $seen);

        $fresh = $chat->reset(7);
        self::assertSame([], $fresh['messages']);
        self::assertSame('', $fresh['run_id']);
        self::assertSame('1', $memory->read(7, Chat::PRIOR_CLOSED));
        self::assertSame('', $memory->read(7, Chat::PRIOR));
        self::assertSame('', $memory->read(7, Chat::LEGACY));

        $memory->write(7, Chat::LEGACY, 'api_mcp');
        $again = $chat->thread(7);
        self::assertSame([], $again['messages']);
        self::assertSame('', $memory->read(7, Chat::PRIOR));
        $urls = $this->messageUrls($seen);
        self::assertSame([], $urls);
    }

    public function testLedgerLabelsTheMatchingTurn(): void
    {
        $memory = new ArrayChatMemory();
        $memory->write(4, Chat::SESSION, 'api_dash');
        $now = time();
        $seen = [];
        $chat = $this->chat($memory, str_repeat('d', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_dash', 200, ['session' => ['id' => 'api_dash']]],
            ['GET', '/api/sessions/api_dash/messages', 200, ['data' => [
                ['id' => 1, 'role' => 'user', 'content' => 'Ping', 'timestamp' => $now - 5000],
                ['id' => 2, 'role' => 'assistant', 'content' => 'Pong', 'timestamp' => $now - 4990],
                ['id' => 3, 'role' => 'user', 'content' => 'Ping', 'timestamp' => $now],
                ['id' => 4, 'role' => 'assistant', 'content' => 'Here.', 'timestamp' => $now + 2],
            ]]],
        ], $seen);
        $chat->notePrompt(4, 'api_dash', 'Ping', 'mcp', 'MCP · Grok Bot v3');

        $thread = $chat->thread(4);
        self::assertSame(['You', 'Hermes', 'MCP · Grok Bot v3', 'Hermes'], array_column($thread['messages'], 'sender'));
        self::assertSame(['you', 'hermes', 'mcp', 'hermes'], array_column($thread['messages'], 'origin'));
    }

    public function testBrowserPollFollowsAnMcpRunOnTheSharedSession(): void
    {
        $memory = new ArrayChatMemory();
        $memory->write(4, Chat::SESSION, 'api_dash');
        $memory->write(4, Chat::WATCH, 'run_mcp');
        $chat = $this->chat($memory, str_repeat('e', 32), [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/v1/runs/run_mcp', 200, [
                'status' => 'completed',
                'output' => 'Done.',
                'session_id' => 'api_dash',
            ]],
        ]);
        $done = $chat->poll(4, 'run_mcp');
        self::assertSame('completed', $done['status']);
        self::assertSame('', $memory->read(4, Chat::WATCH));
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
        self::assertFalse($thread['has_more']);
    }

    public function testBearerTokensCannotCallHermes(): void
    {
        self::assertTrue(Scope::sessionOnly('/api/v1/hermes/chat'));
        self::assertFalse(Scope::sessionOnly('/api/v1/ollama/generate'));
        self::assertFalse(Scope::sessionOnly('/api/v1/agents/hermes/runs'));
        self::assertFalse(Scope::sessionOnly('/api/v1/agents/hermes/thread'));
        self::assertSame('agents:read', Scope::forPath('/api/v1/agents/hermes/thread', false));
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
            $path = parse_url($url, PHP_URL_PATH);
            self::assertSame($step[1], $path);
            self::assertGreaterThan(0, $timeout);

            return ['status' => $step[2], 'json' => $step[3]];
        });

        return new Chat($gateway, $memory);
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $seen
     * @return list<string>
     */
    private function messageUrls(array $seen): array
    {
        $urls = [];
        foreach ($seen as $row) {
            if (str_contains($row[1], '/messages')) {
                $urls[] = $row[1];
            }
        }

        return $urls;
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
