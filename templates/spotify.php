<?php
/**
 * Music page: playlists, mixtape builder, track notes, seasons, activity.
 *
 * @var array<string, mixed> $user
 * @var string $assetVer
 */

$pageTitle   = 'Playlists';
$active      = 'spotify';
$extraStyles = ['/assets/css/lifedash.css'];

$musicTabs = [
    'overview' => ['Overview', 'music-2'],
    'playlists' => ['Playlists', 'list-music'],
    'builder' => ['Mixtape Builder', 'wand-sparkles'],
    'library' => ['Track Library', 'disc-3'],
    'seasons' => ['Seasons', 'sprout'],
    'activity' => ['Activity', 'history'],
];
$musicIconNames = [
    'music-2', 'list-music', 'disc-3', 'wand-sparkles', 'sprout', 'history',
    'shuffle', 'repeat', 'arrow-left-right', 'speaker', 'smartphone', 'monitor',
    'chevron-right', 'chevron-down', 'search', 'x', 'sparkles', 'bot',
    'layout-dashboard', 'settings',
];
$musicIcon = static function (string $name) use ($musicIconNames): void {
    if (!in_array($name, $musicIconNames, true)) {
        return;
    }
    $path = __DIR__ . '/../public/assets/vendor/lucide/' . $name . '.svg';
    if (is_file($path)) {
        readfile($path);
    }
};

$musicTab = (string) ($_GET['tab'] ?? 'overview');
if (!isset($musicTabs[$musicTab])) {
    $musicTab = 'overview';
}
$musicPlaylist = (string) ($_GET['id'] ?? '');
if ($musicTab !== 'playlists' || !ctype_digit($musicPlaylist)) {
    $musicPlaylist = '';
}

require __DIR__ . '/partials/head.php';
?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="music-page" id="spotify-app">
    <header class="music-heading">
        <div>
            <?php
            $pageEyebrow = 'Spotify';
            $pageHeading = 'Music';
            require __DIR__ . '/partials/page-heading.php';
            ?>
            <p class="music-lead">Plan, shape and maintain the soundtrack around your life.</p>
        </div>
        <div class="music-heading-actions">
            <p id="music-connection" class="music-badge">Checking Spotify…</p>
            <a class="btn" href="/settings/spotify">Settings</a>
            <span id="music-actions" hidden>
                <button type="button" class="btn" data-sync>Sync</button>
                <button type="button" class="btn" data-push-all>Push all</button>
                <button type="button" class="btn" data-new-playlist>New playlist</button>
                <a class="btn-primary" href="?tab=builder">New mixtape</a>
            </span>
        </div>
    </header>
    <nav class="music-tabs" aria-label="Music">
        <?php foreach ($musicTabs as $id => [$label, $icon]): ?>
        <a href="?tab=<?= e($id) ?>"<?= ($musicTab === $id && $musicPlaylist === '') ? ' class="is-on" aria-current="page"' : '' ?>>
            <span class="music-tab-icon" aria-hidden="true"><?php $musicIcon($icon); ?></span>
            <span><?= e($label) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <p id="music-status" class="music-status" role="status"></p>
    <div id="music-banner" class="music-banner" hidden></div>
    <div class="music-stage" id="music-stage">
        <div id="music-panel" class="music-panel" data-tab="<?= e($musicTab) ?>" data-id="<?= e($musicPlaylist) ?>"></div>
        <aside id="music-drawer" class="music-drawer" hidden>
            <header class="music-drawer-head">
                <p class="music-kicker">Track details</p>
                <button type="button" class="music-icon-btn" data-close-drawer aria-label="Close track details"><?php $musicIcon('x'); ?></button>
            </header>
            <h2 id="music-drawer-title" class="music-drawer-title"></h2>
            <p id="music-drawer-artist" class="music-muted"></p>
            <form id="music-meta-form" class="music-form">
                <input type="hidden" name="uri" value="">
                <label class="music-field">BPM
                    <input name="bpm" class="input" inputmode="decimal" maxlength="6">
                </label>
                <label class="music-field">Key
                    <input name="musical_key" class="input" maxlength="32" placeholder="A minor">
                </label>
                <label class="music-field">Camelot
                    <input name="camelot" class="input" maxlength="3" placeholder="8A">
                </label>
                <label class="music-field">Energy (1–10)
                    <input name="energy" class="input" inputmode="numeric" maxlength="2">
                </label>
                <label class="music-field">Mood
                    <input name="mood_tags" class="input" maxlength="200" placeholder="warm, late">
                </label>
                <label class="music-field">Seasons
                    <input name="season_tags" class="input" maxlength="200" placeholder="christmas">
                </label>
                <label class="music-field">Source
                    <select name="source" class="input">
                        <option value="manual">manual</option>
                        <option value="estimated">estimated</option>
                    </select>
                </label>
                <button type="submit" class="btn-primary">Save notes</button>
            </form>
            <form id="music-drawer-add" class="music-form" hidden>
                <label class="music-field">Add to playlist
                    <select name="playlist_id" class="input"></select>
                </label>
                <button type="submit" class="btn">Add track</button>
            </form>
        </aside>
    </div>
    <div id="music-icons" hidden>
        <?php foreach ($musicIconNames as $name): ?>
        <span id="music-icon-<?= e($name) ?>"><?php $musicIcon($name); ?></span>
        <?php endforeach; ?>
    </div>
    <div id="music-new-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="music-new-title">
        <div class="app-modal-backdrop" data-close-modal></div>
        <div class="app-modal-panel">
            <div class="music-drawer-head">
                <h2 id="music-new-title">New playlist</h2>
                <button type="button" class="app-modal-close" data-close-modal aria-label="Close"><?php $musicIcon('x'); ?></button>
            </div>
            <form id="music-create" class="music-form">
                <label class="music-field">Name
                    <input name="name" class="input" maxlength="100" required>
                </label>
                <label class="music-field">Description
                    <input name="description" class="input" maxlength="300">
                </label>
                <label class="music-check"><input type="checkbox" name="public"> Public</label>
                <fieldset class="music-choice">
                    <legend class="music-kicker">Type</legend>
                    <label class="music-check"><input type="radio" name="kind" value="regular" checked> Regular</label>
                    <label class="music-check"><input type="radio" name="kind" value="ai"> AI</label>
                </fieldset>
                <div id="music-create-ai" hidden>
                    <label class="music-field">Prompt
                        <textarea name="prompt" class="input" rows="4" maxlength="8000"></textarea>
                    </label>
                    <label class="music-field">Schedule
                        <input name="refresh_schedule" class="input" maxlength="32" placeholder="weekly">
                    </label>
                </div>
                <button type="submit" class="btn-primary">Create</button>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/spotify-mixtape.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/spotify.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
