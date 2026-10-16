<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

final class PreferencesHandler
{
    private PreferenceService $prefs;

    public function __construct(Database $db)
    {
        $this->prefs = new PreferenceService($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function get(array $user): void
    {
        $userId = (int) $user['id'];

        jsonResponse([
            'search_engine'        => $this->prefs->getSearchEngine($userId),
            'widget_visibility'    => $this->prefs->getWidgetVisibility($userId),
            'widget_order'         => $this->prefs->getWidgetOrder($userId),
            'widget_height'        => $this->prefs->getWidgetHeight($userId),
            'widget_columns'       => $this->prefs->getWidgetColumns($userId),
            'theme'                => $this->prefs->getTheme($userId),
            'jarvis_accent'        => $this->prefs->getJarvisAccent($userId),
            'clock_format'         => $this->prefs->getClockFormat($userId),
            'show_broadcast_week'  => $this->prefs->getShowBroadcastWeek($userId),
            'show_bookmark_icons'  => $this->prefs->getShowBookmarkIcons($userId),
            'grid_max_width'       => $this->prefs->getGridMaxWidth($userId),
            'starfield_background' => $this->prefs->getStarfield($userId),
            'focus_clock'          => $this->prefs->getFocusClock($userId),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function save(array $user): void
    {
        validateCsrf();

        $body   = $this->jsonBody();
        $userId = (int) $user['id'];

        if (isset($body['search_engine'])) {
            try {
                $this->prefs->setSearchEngine($userId, (string) $body['search_engine']);
            } catch (\InvalidArgumentException $e) {
                jsonResponse(['error' => $e->getMessage()], 422);
            }
        }

        if (isset($body['widget_visibility']) && is_array($body['widget_visibility'])) {
            $visibility = [];
            foreach ($body['widget_visibility'] as $key => $val) {
                $visibility[(string) $key] = (bool) $val;
            }
            $this->prefs->setWidgetVisibility($userId, $visibility);
        }

        if (isset($body['widget_order']) && is_array($body['widget_order'])) {
            $order = [];
            foreach ($body['widget_order'] as $id) {
                $order[] = (string) $id;
            }
            try {
                $this->prefs->setWidgetOrder($userId, $order);
            } catch (\InvalidArgumentException $e) {
                jsonResponse(['error' => $e->getMessage()], 422);
            }
        }

        if (isset($body['theme'])) {
            try {
                $this->prefs->setTheme($userId, (string) $body['theme']);
            } catch (\InvalidArgumentException $e) {
                jsonResponse(['error' => $e->getMessage()], 422);
            }
        }

        if (isset($body['jarvis_accent'])) {
            try {
                $this->prefs->setJarvisAccent($userId, (string) $body['jarvis_accent']);
            } catch (\InvalidArgumentException $e) {
                jsonResponse(['error' => $e->getMessage()], 422);
            }
        }

        if (isset($body['clock_format'])) {
            try {
                $this->prefs->setClockFormat($userId, (string) $body['clock_format']);
            } catch (\InvalidArgumentException $e) {
                jsonResponse(['error' => $e->getMessage()], 422);
            }
        }

        if (array_key_exists('show_broadcast_week', $body)) {
            $this->prefs->setShowBroadcastWeek(
                $userId,
                filter_var($body['show_broadcast_week'], FILTER_VALIDATE_BOOLEAN),
            );
        }

        if (array_key_exists('show_bookmark_icons', $body)) {
            $this->prefs->setShowBookmarkIcons(
                $userId,
                filter_var($body['show_bookmark_icons'], FILTER_VALIDATE_BOOLEAN),
            );
        }

        if (array_key_exists('grid_max_width', $body)) {
            $this->prefs->setGridMaxWidth(
                $userId,
                filter_var($body['grid_max_width'], FILTER_VALIDATE_BOOLEAN),
            );
        }

        if (array_key_exists('starfield_background', $body)) {
            $this->prefs->setStarfield($userId, filter_var($body['starfield_background'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($body['reset_widget_height'])) {
            $this->prefs->resetWidgetHeight($userId);
        } elseif (isset($body['widget_height'])) {
            $this->prefs->setWidgetHeight($userId, (int) $body['widget_height']);
        }

        if (isset($body['widget_columns'])) {
            try {
                $this->prefs->setWidgetColumns($userId, (string) $body['widget_columns']);
            } catch (\InvalidArgumentException $e) {
                jsonResponse(['error' => $e->getMessage()], 422);
            }
        }

        if (isset($body['key'], $body['value']) && is_string($body['key'])) {
            $key = $body['key'];
            if ($key === PreferenceService::KEY_THEME) {
                try {
                    $this->prefs->setTheme($userId, (string) $body['value']);
                } catch (\InvalidArgumentException $e) {
                    jsonResponse(['error' => $e->getMessage()], 422);
                }
            } elseif ($key === PreferenceService::KEY_JARVIS_ACCENT) {
                try {
                    $this->prefs->setJarvisAccent($userId, (string) $body['value']);
                } catch (\InvalidArgumentException $e) {
                    jsonResponse(['error' => $e->getMessage()], 422);
                }
            } elseif ($key === PreferenceService::KEY_CLOCK_FORMAT) {
                try {
                    $this->prefs->setClockFormat($userId, (string) $body['value']);
                } catch (\InvalidArgumentException $e) {
                    jsonResponse(['error' => $e->getMessage()], 422);
                }
            } elseif ($key === PreferenceService::KEY_SHOW_BROADCAST_WEEK) {
                $this->prefs->setShowBroadcastWeek($userId, filter_var($body['value'], FILTER_VALIDATE_BOOLEAN));
            } elseif ($key === PreferenceService::KEY_SHOW_BOOKMARK_ICONS) {
                $this->prefs->setShowBookmarkIcons($userId, filter_var($body['value'], FILTER_VALIDATE_BOOLEAN));
            } elseif ($key === PreferenceService::KEY_STARFIELD) {
                $this->prefs->setStarfield($userId, filter_var($body['value'], FILTER_VALIDATE_BOOLEAN));
            } else {
                $this->prefs->set($userId, $key, (string) $body['value']);
            }
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
