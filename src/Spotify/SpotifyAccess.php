<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\Deploy\DeployService;
use Dashboard\Support\ForbiddenException;

/** Writes and playback honor the same Allow MCP updates switch as other agent writes. */
final class SpotifyAccess
{
    public static function assertChange(?int $tokenId, bool $mcpUpdatesEnabled): void
    {
        if (!DeployService::mcpRequestAllowed($mcpUpdatesEnabled, $tokenId)) {
            throw new ForbiddenException(DeployService::MCP_DISABLED);
        }
    }
}
