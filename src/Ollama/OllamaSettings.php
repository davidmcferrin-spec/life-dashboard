<?php

declare(strict_types=1);

namespace Dashboard\Ollama;

use Dashboard\DB\Database;

/** The one machine-wide row. An empty chat model means the hardware default. */
final class OllamaSettings
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array{base_url: string, chat_model: string, embed_model: string}
     */
    public function get(): array
    {
        try {
            $row = $this->db->fetchOne(
                'SELECT base_url, chat_model, embed_model FROM ollama_settings WHERE id = 1'
            );
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            return [
                'base_url'    => OllamaEndpoint::DEFAULT_URL,
                'chat_model'  => '',
                'embed_model' => OllamaEndpoint::DEFAULT_EMBED,
            ];
        }

        return [
            'base_url'    => (string) $row['base_url'],
            'chat_model'  => (string) $row['chat_model'],
            'embed_model' => (string) $row['embed_model'],
        ];
    }

    /**
     * @return array{base_url: string, chat_model: string, embed_model: string}
     */
    public function save(string $baseUrl, string $chatModel, string $embedModel): array
    {
        $base = OllamaEndpoint::baseUrl($baseUrl);
        $chat = OllamaEndpoint::model($chatModel, 'chat_model', false);
        $embed = OllamaEndpoint::model($embedModel, 'embed_model', true);
        $this->db->execute(
            'UPDATE ollama_settings
             SET base_url = ?, chat_model = ?, embed_model = ?, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = 1',
            [$base, $chat, $embed]
        );

        return ['base_url' => $base, 'chat_model' => $chat, 'embed_model' => $embed];
    }

    /**
     * @param array{base_url: string, chat_model: string, embed_model: string} $row
     * @param array<string, mixed>|null $plan
     * @return array{base_url: string, chat_model: string, embed_model: string}
     */
    public static function effective(array $row, ?array $plan): array
    {
        $chat = $row['chat_model'];
        if ($chat === '') {
            $fromPlan = is_array($plan) ? (string) ($plan['chat_model'] ?? '') : '';
            $chat = $fromPlan !== '' ? $fromPlan : OllamaEndpoint::DEFAULT_CHAT;
        }
        $embed = $row['embed_model'] !== '' ? $row['embed_model'] : OllamaEndpoint::DEFAULT_EMBED;

        return [
            'base_url'    => $row['base_url'] !== '' ? $row['base_url'] : OllamaEndpoint::DEFAULT_URL,
            'chat_model'  => $chat,
            'embed_model' => $embed,
        ];
    }
}
