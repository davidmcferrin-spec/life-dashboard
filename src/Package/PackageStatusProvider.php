<?php

declare(strict_types=1);

namespace Dashboard\Package;

interface PackageStatusProvider
{
    /**
     * @param array<string, mixed> $watch
     */
    public function fetchStatus(array $watch): PackageStatusResult;

    public function supportsCarrier(string $carrier): bool;
}
