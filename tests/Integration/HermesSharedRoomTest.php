<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Agents\LocalAgents;
use Dashboard\Hermes\Chat;
use Dashboard\Hermes\Gateway;
use Dashboard\Hermes\PreferenceChatMemory;
use Dashboard\Settings\PreferenceService;

/**
 * The shared Hermes room is stored on the user's preferences.
 * Another user does not see it, and New chat keeps the old MCP transcript shut.
 */
final class HermesSharedRoomTest extends PgsqlTestCase
{
    public function testEachUserKeepsTheirOwnRoomAndResetStaysShut(): void
    {
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $david = (int) $this->db->lastInsertId();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('other', 'o@x', 'h')");
        $other = (int) $this->db->lastInsertId();

        $memory = new PreferenceChatMemory(new PreferenceService($this->db));
        $memory->write($david, Chat::SESSION, 'api_dash');
        $memory->write($david, Chat::LEGACY, 'api_mcp');
        $youAt = 1700000000;
        $agentAt = 1700000100;
        $script = [
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_dash', 200, ['session' => ['id' => 'api_dash']]],
            ['GET', '/api/sessions/api_mcp', 200, ['session' => ['id' => 'api_mcp']]],
            ['GET', '/api/sessions/api_dash/messages', 200, ['messages' => [
                ['id' => 1, 'role' => 'user', 'content' => 'From the browser', 'timestamp' => $youAt],
                ['id' => 2, 'role' => 'assistant', 'content' => 'Browser reply', 'timestamp' => $youAt + 5],
            ]]],
            ['GET', '/api/sessions/api_mcp/messages', 200, ['messages' => [
                ['id' => 1, 'role' => 'user', 'content' => 'Hi from the agent', 'timestamp' => $agentAt],
                ['id' => 2, 'role' => 'assistant', 'content' => 'Hello.', 'timestamp' => $agentAt + 5],
            ]]],
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/api/sessions/api_dash', 200, ['session' => ['id' => 'api_dash']]],
            ['POST', '/v1/runs', 202, ['run_id' => 'run_join', 'status' => 'started']],
            ['GET', '/health', 200, ['status' => 'ok']],
            ['GET', '/health', 200, ['status' => 'ok']],
        ];
        $gateway = new Gateway(str_repeat('ab', 16), function (string $method, string $url, ?string $body, int $timeout) use (&$script): array {
            unset($body, $timeout);
            $step = array_shift($script);
            self::assertIsArray($step);
            self::assertSame($step[0], $method);
            self::assertSame($step[1], parse_url($url, PHP_URL_PATH));

            return ['status' => $step[2], 'json' => $step[3]];
        });
        $chat = new Chat($gateway, $memory);
        $agents = new LocalAgents($gateway, $memory);

        $thread = $chat->thread($david);
        self::assertSame(
            ['From the browser', 'Browser reply', 'Hi from the agent', 'Hello.'],
            array_column($thread['messages'], 'content')
        );
        self::assertSame(['You', 'Hermes', 'AI Agent (MCP)', 'Hermes'], array_column($thread['messages'], 'sender'));
        self::assertSame('api_mcp', $memory->read($david, Chat::PRIOR));
        self::assertSame('', $memory->read($other, Chat::PRIOR));
        self::assertSame('', $memory->read($other, Chat::SESSION));

        $joined = $agents->hermesSend($david, 'Still in the same room', null, 'Grok Bot v3');
        self::assertSame('api_dash', $joined['session_id']);
        self::assertSame('run_join', $memory->read($david, Chat::WATCH));
        self::assertSame('', $memory->read($other, Chat::WATCH));

        $fresh = $chat->reset($david);
        self::assertSame([], $fresh['messages']);
        self::assertSame('1', $memory->read($david, Chat::PRIOR_CLOSED));
        self::assertSame('', $memory->read($david, Chat::LEGACY));
        $memory->write($david, Chat::LEGACY, 'api_mcp');
        $again = $chat->thread($david);
        self::assertSame([], $again['messages']);
        self::assertSame('', $memory->read($david, Chat::PRIOR));
        self::assertSame([], $script);
    }
}
