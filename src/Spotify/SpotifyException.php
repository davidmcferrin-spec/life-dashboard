<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

final class SpotifyException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        public readonly bool $reauth = false,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
