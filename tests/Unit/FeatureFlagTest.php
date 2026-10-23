<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Settings\FeatureFlags;
use PHPUnit\Framework\TestCase;

final class FeatureFlagTest extends TestCase
{
    public function testSecuritySettingsAreNotDashboardFlags(): void
    {
        foreach (array_keys(FeatureFlags::skipped()) as $key) {
            self::assertArrayNotHasKey($key, FeatureFlags::FLAGS, $key);
        }
        foreach (['allow_plain_http', 'mcp_updates', 'admin', 'system:deploy', 'system:read', 'spotify:playback'] as $key) {
            self::assertArrayHasKey($key, FeatureFlags::skipped());
            self::assertNotSame('', FeatureFlags::skipped()[$key]);
        }
        foreach (['show_broadcast_week', 'show_bookmark_icons', 'ai_presence', 'grid_max_width', 'starfield_background'] as $key) {
            self::assertArrayHasKey($key, FeatureFlags::FLAGS);
        }
    }
}
