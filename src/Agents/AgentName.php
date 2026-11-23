<?php

declare(strict_types=1);

namespace Dashboard\Agents;

/**
 * Agent name taken from an API key label.
 * Hermes shows the same cleaned name with an MCP prefix. Handoffs store the
 * label itself so one bot can address another by the name on its key.
 */
final class AgentName
{
    public static function clean(?string $value): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '');

        return $name === '' ? '' : mb_substr($name, 0, 60);
    }

    public static function mcpLabel(?string $tokenName): string
    {
        $name = self::clean($tokenName);

        return $name === '' ? 'AI Agent (MCP)' : 'MCP · ' . $name;
    }

    /** Bearer calls use the key label. A signed-in page uses the username. */
    public static function identity(bool $ai, ?string $tokenName, string $editorName): string
    {
        if ($ai) {
            $name = self::clean($tokenName);

            return $name === '' ? 'AI Agent (MCP)' : $name;
        }
        $name = self::clean($editorName);

        return $name === '' ? 'user' : $name;
    }
}
