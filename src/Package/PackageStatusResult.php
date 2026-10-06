<?php

declare(strict_types=1);

namespace Dashboard\Package;

/**
 * Normalized package status from any carrier provider.
 */
final class PackageStatusResult
{
    public function __construct(
        public readonly string $statusLabel,
        public readonly ?string $statusDetail = null,
        public readonly ?string $estimatedDeliveryAt = null,
        public readonly ?string $lastEventAt = null,
        public readonly ?string $externalUrl = null,
        public readonly ?array $raw = null,
        public readonly bool $markComplete = false,
    ) {}
}
