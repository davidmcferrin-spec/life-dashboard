<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * Stylesheet load order. The sequence is the old lifedash.css cascade, split
 * so a feature edit stays in that feature's file.
 */
final class Stylesheets
{
    /**
     * Shared shell first, then each feature in the order the rules used to appear.
     *
     * @return list<string>
     */
    public static function app(): array
    {
        return [
            '/assets/css/lifedash.css',
            '/assets/css/vault.css',
            '/assets/css/social-cards.css',
            '/assets/css/goals.css',
            '/assets/css/pantry.css',
            '/assets/css/spotify.css',
            '/assets/css/ollama.css',
            '/assets/css/hermes.css',
            '/assets/css/digest.css',
            '/assets/css/shell.css',
            '/assets/css/controls.css',
            '/assets/css/trackers.css',
            '/assets/css/finance-settings.css',
            '/assets/css/catalog.css',
            '/assets/css/finance-widgets.css',
            '/assets/css/music.css',
            '/assets/css/brain-map.css',
            '/assets/css/flights.css',
            '/assets/css/feature-widgets.css',
            '/assets/css/spotify-stage.css',
            '/assets/css/brain-mesh.css',
            '/assets/css/vault-editor.css',
            '/assets/css/shell-flat.css',
            '/assets/css/mixtape.css',
            '/assets/css/music-overview.css',
            '/assets/css/meals.css',
            '/assets/css/digest-items.css',
            '/assets/css/jarvis.css',
            '/assets/css/finance-menus.css',
        ];
    }

    /**
     * Media widget cards. The base sheet stays widgets-media.css.
     *
     * @return list<string>
     */
    public static function media(): array
    {
        return [
            '/assets/css/widgets-media.css',
            '/assets/css/media-knowledge.css',
            '/assets/css/media-reddit.css',
            '/assets/css/media-spotify.css',
            '/assets/css/media-custom.css',
            '/assets/css/media-news.css',
            '/assets/css/media-clipboards.css',
        ];
    }
}
