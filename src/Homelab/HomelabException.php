<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

/**
 * A homelab app was not called, or it answered with an error the dashboard can explain.
 */
final class HomelabException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        string $message,
        public readonly string $codeName,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
