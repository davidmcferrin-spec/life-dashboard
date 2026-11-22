<?php

declare(strict_types=1);

namespace Dashboard\Money;

/** A Plaid error code and a short message. The access token is not included. */
final class PlaidException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
