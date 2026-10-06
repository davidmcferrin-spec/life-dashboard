<?php

declare(strict_types=1);

namespace Dashboard\Package;

final class PackageProviderFactory
{
    /**
     * @return list<PackageStatusProvider>
     */
    public static function makeAll(): array
    {
        $providers = [
            new UpsPackageProvider(new UpsTokenManager()),
            new FedExPackageProvider(new FedExTokenManager()),
            new DhlPackageProvider(),
        ];

        foreach (CarrierDetector::LINK_ONLY_META as $carrier => $meta) {
            $providers[] = new LinkOnlyPackageProvider(
                $carrier,
                $meta['label'],
                $meta['detail'],
            );
        }

        return $providers;
    }

    public static function forCarrier(string $carrier): ?PackageStatusProvider
    {
        foreach (self::makeAll() as $provider) {
            if ($provider->supportsCarrier($carrier)) {
                return $provider;
            }
        }

        return null;
    }
}
