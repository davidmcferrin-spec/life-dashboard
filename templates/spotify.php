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
    'layout-dashboard', 'settings', 'refresh-cw', 'lock-keyhole', 'archive', 'activity',
    'undo-2', 'eye', 'plus', 'tag', 'cloud-sun', 'pencil', 'play',
    'pause', 'skip-back', 'skip-forward', 'volume-2', 'ellipsis',
    'external-link', 'grip-vertical', 'check',
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
            <button type="button" class="btn" id="music-drawer-play" hidden><?php $musicIcon('play'); ?> Play</button>
            <div id="music-waveform" class="music-waveform" hidden></div>
            <p id="music-waveform-empty" class="music-muted" hidden>No loudness samples stored.</p>
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
            <form id="music-genre-form" class="music-form">
                <div class="music-field">Genre
                    <div id="music-genre-tags" class="music-genre-tags"></div>
                    <div class="music-genre-add">
                        <input id="music-genre-input" class="input" maxlength="40" placeholder="Add a genre" autocomplete="off">
                        <button type="submit" class="btn">Add</button>
                    </div>
                </div>
            </form>
            <form id="music-drawer-add" class="music-form" hidden>
                <label class="music-field">Add to playlist
                    <select name="playlist_id" class="input"></select>
                </label>
                <button type="submit" class="btn">Add track</button>
            </form>
            <section id="music-appears" class="music-appears" hidden>
                <p class="music-kicker">Appears in</p>
                <div id="music-appears-list"></div>
            </section>
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
    <div id="music-season-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="music-season-title">
        <div class="app-modal-backdrop" data-close-modal></div>
        <div class="app-modal-panel">
            <div class="music-drawer-head">
                <h2 id="music-season-title">Add season</h2>
                <button type="button" class="app-modal-close" data-close-modal aria-label="Close"><?php $musicIcon('x'); ?></button>
            </div>
            <form id="music-season-form" class="music-form">
                <label class="music-field">Season name
                    <input name="season" class="input" maxlength="32" required placeholder="christmas">
                </label>
                <label class="music-check"><input type="checkbox" name="thanksgiving"> Start on Thanksgiving</label>
                <div class="music-split">
                    <label class="music-field">Start month <input name="start_month" class="input" inputmode="numeric" maxlength="2"></label>
                    <label class="music-field">Start day <input name="start_day" class="input" inputmode="numeric" maxlength="2"></label>
                    <label class="music-field">End month <input name="end_month" class="input" inputmode="numeric" maxlength="2" required></label>
                    <label class="music-field">End day <input name="end_day" class="input" inputmode="numeric" maxlength="2" required></label>
                </div>
                <label class="music-check"><input type="checkbox" name="allow_outside"> Allow outside window</label>
                <button type="submit" class="btn-primary">Save window</button>
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
