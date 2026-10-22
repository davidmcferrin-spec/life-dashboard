<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

final class SpotifyException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        public readonly bool $reauth = false,
    ) {
        parent::__construct($message);
    }
}
