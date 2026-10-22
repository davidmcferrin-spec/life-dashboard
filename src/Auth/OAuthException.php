<?php

declare(strict_types=1);

namespace Dashboard\Auth;

final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        public readonly ?int $retryAfter = null,
        public readonly bool $toClient = false,
    ) {
        parent::__construct($description);
    }
}
