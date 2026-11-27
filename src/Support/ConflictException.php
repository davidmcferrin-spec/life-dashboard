<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * The write was refused because the current row does not match what the caller read.
 */
final class ConflictException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        string $message,
        public readonly string $codeName = 'conflict',
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
