<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * Thrown instead of exiting when LIFEDASH_TEST_CAPTURE=1, so tests can
 * inspect a JSON response without ending the process.
 */
final class CapturedResponse extends \RuntimeException
{
    /** @param mixed $payload */
    public function __construct(public readonly mixed $payload, public readonly int $status)
    {
        parent::__construct('captured response');
    }
}
