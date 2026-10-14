<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Config;
use Dashboard\Settings\PreferenceService;
use Dashboard\Settings\SetupConfig;
use PHPUnit\Framework\TestCase;

final class SetupConfigTest extends TestCase
{
    private const PROBE = 'LIFEDASH_SETUP_PROBE';

    protected function tearDown(): void
    {
        Config::overlay(self::PROBE, null);
    }

    public function testBlankSecretKeepsTheSavedValue(): void
    {
        $group = SetupConfig::catalog()['weather'];
        $stored = SetupConfig::mergeGroup($group, ['OWM_API_KEY' => 'kept'], ['OWM_API_KEY' => '  ']);

        self::assertSame('kept', $stored['OWM_API_KEY']);
    }

    public function testClearRemovesASavedSecret(): void
    {
        $group = SetupConfig::catalog()['weather'];
        $stored = SetupConfig::mergeGroup($group, ['OWM_API_KEY' => 'kept'], [
            'OWM_API_KEY' => '',
            'clear'       => ['OWM_API_KEY'],
        ]);

        self::assertArrayNotHasKey('OWM_API_KEY', $stored);
    }

    public function testUncheckingACarrierClearsItsKeys(): void
    {
        $group = SetupConfig::catalog()['packages'];
        $stored = SetupConfig::mergeGroup($group, [
            'UPS_CLIENT_ID'     => 'id',
            'UPS_CLIENT_SECRET' => 'secret',
            'UPS_USE_SANDBOX'   => 'true',
            'DHL_API_KEY'       => 'dhl',
        ], [
            'use_ups'   => '0',
            'use_fedex' => '0',
            'use_dhl'   => '1',
            'DHL_API_KEY' => '',
        ]);

        self::assertArrayNotHasKey('UPS_CLIENT_ID', $stored);
        self::assertArrayNotHasKey('UPS_CLIENT_SECRET', $stored);
        self::assertArrayNotHasKey('UPS_USE_SANDBOX', $stored);
        self::assertSame('dhl', $stored['DHL_API_KEY']);
    }

    public function testSwitchingFlightProviderKeepsTheOtherSecrets(): void
    {
        $group = SetupConfig::catalog()['flights'];
        $stored = SetupConfig::mergeGroup($group, [
            'FLIGHT_PROVIDER'       => 'opensky',
            'OPENSKY_CLIENT_ID'     => 'id',
            'OPENSKY_CLIENT_SECRET' => 'secret',
        ], [
            'FLIGHT_PROVIDER'    => 'flightaware',
            'FLIGHTAWARE_API_KEY' => 'aero',
        ]);

        self::assertSame('flightaware', $stored['FLIGHT_PROVIDER']);
        self::assertSame('aero', $stored['FLIGHTAWARE_API_KEY']);
        self::assertSame('id', $stored['OPENSKY_CLIENT_ID']);
        self::assertSame('secret', $stored['OPENSKY_CLIENT_SECRET']);
    }

    public function testInvalidProviderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SetupConfig::mergeGroup(SetupConfig::catalog()['flights'], [], [
            'FLIGHT_PROVIDER' => 'scraped',
        ]);
    }

    public function testOverlayReplacesThenRestoresTheEnvironmentValue(): void
    {
        $this->seedConfig(self::PROBE, 'from-env');
        Config::overlay(self::PROBE, 'from-setup');

        self::assertSame('from-setup', Config::maybe(self::PROBE));
        self::assertSame('from-env', Config::envValue(self::PROBE));

        Config::overlay(self::PROBE, null);
        self::assertSame('from-env', Config::maybe(self::PROBE));
    }

    private function seedConfig(string $key, string $value): void
    {
        $ref = new \ReflectionClass(Config::class);
        $values = $ref->getProperty('values');
        $map = $values->getValue();
        $map[$key] = $value;
        $values->setValue(null, $map);

        $before = $ref->getProperty('beforeOverlay');
        $snap = $before->getValue();
        unset($snap[$key]);
        $before->setValue(null, $snap);
    }

    public function testLocalSearchIsADashboardEngine(): void
    {
        self::assertContains('local', PreferenceService::SEARCH_ENGINES);
        $engines = PreferenceService::searchEngineDefinitions();
        self::assertSame('This dashboard', $engines['local']['name']);
        self::assertSame('', $engines['local']['url']);
    }
}
