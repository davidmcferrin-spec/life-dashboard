<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Settings\FeatureFlags;
use Dashboard\Settings\PreferenceService;
use Dashboard\Support\ValidationException;

final class FeatureFlagTest extends PgsqlTestCase
{
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $this->user = (int) $this->db->lastInsertId();
    }

    public function testTogglesPersistAndSecurityKeysWriteNothing(): void
    {
        $prefs = new PreferenceService($this->db);
        FeatureFlags::set($prefs, $this->user, 'show_bookmark_icons', false);
        FeatureFlags::set($prefs, $this->user, 'grid_max_width', false);
        self::assertSame('0', $prefs->get($this->user, PreferenceService::KEY_SHOW_BOOKMARK_ICONS));
        self::assertSame('0', $prefs->get($this->user, PreferenceService::KEY_GRID_MAX_WIDTH));
        self::assertFalse($prefs->getShowBookmarkIcons($this->user));
        self::assertFalse($prefs->getGridMaxWidth($this->user));

        $before = $this->db->fetchAll(
            'SELECT pref_key, pref_value FROM user_preferences WHERE user_id = ? ORDER BY pref_key',
            [$this->user]
        );
        foreach (array_keys(FeatureFlags::skipped()) as $key) {
            try {
                FeatureFlags::set($prefs, $this->user, $key, true);
                self::fail($key . ' was stored');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('key', $e->fields());
            }
        }
        $after = $this->db->fetchAll(
            'SELECT pref_key, pref_value FROM user_preferences WHERE user_id = ? ORDER BY pref_key',
            [$this->user]
        );
        self::assertSame($before, $after);
    }
}
