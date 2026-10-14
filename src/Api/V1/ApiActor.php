<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

/**
 * Who is calling /api/v1. Bearer tokens are "ai" and write source=ai.
 * A browser session writes source=api.
 */
final class ApiActor
{
    public function __construct(
        public readonly string $kind,
        public readonly int $userId,
        public readonly ?string $tool,
    ) {}

    public function source(): string
    {
        return $this->kind === 'ai' ? 'ai' : 'api';
    }
}
