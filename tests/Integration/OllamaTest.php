<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Ollama\OllamaSettings;
use Dashboard\Support\ValidationException;

final class OllamaTest extends PgsqlTestCase
{
    public function testSettingsRoundTripAndRejectsAPublicUrl(): void
    {
        $this->migrate();
        $settings = new OllamaSettings($this->db);
        $fresh = $settings->get();
        self::assertSame('http://127.0.0.1:11434', $fresh['base_url']);
        self::assertSame('', $fresh['chat_model']);
        self::assertSame('nomic-embed-text', $fresh['embed_model']);

        $saved = $settings->save('http://localhost:11434', 'qwen2.5:7b', 'nomic-embed-text');
        self::assertSame('http://localhost:11434', $saved['base_url']);
        self::assertSame($saved, $settings->get());

        try {
            $settings->save('http://10.0.0.8:11434', 'qwen2.5:7b', 'nomic-embed-text');
            self::fail('a public URL was stored');
        } catch (ValidationException $e) {
            self::assertStringContainsString('this machine', $e->getMessage());
        }
        self::assertSame('http://localhost:11434', $settings->get()['base_url']);
    }
}
