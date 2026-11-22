<?php

declare(strict_types=1);

namespace Dashboard\Money;

interface PlaidTransport
{
    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $url, array $body): array;
}
