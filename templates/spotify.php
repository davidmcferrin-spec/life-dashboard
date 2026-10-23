<?php
/**
 * Synced playlists, staged edits, and the prompt library.
 *
 * @var array<string, mixed> $user
 */

$pageTitle   = 'Playlists';
$active      = 'spotify';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/partials/head.php';
?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="mx-auto px-4 py-6" id="spotify-app">
    <header class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <?php $pageEyebrow = 'Dashboard'; $pageHeading = 'Playlists'; require __DIR__ . '/partials/page-heading.php'; ?>
            <p class="text-muted text-sm mt-1">Edits stay on this server until you push them.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn" data-new-playlist>New playlist</button>
            <button type="button" class="btn" data-sync>Sync</button>
            <button type="button" class="btn-primary" data-push-all>Push all</button>
            <a class="btn" href="/settings/spotify">Settings</a>
        </div>
    </header>
    <p id="spotify-status" class="text-sm text-muted mb-3" role="status"></p>
    <div class="sp-layout">
        <aside class="sp-list surface" id="spotify-lists"></aside>
        <section class="sp-detail surface" id="spotify-detail">
            <?php
            $emptyTitle = 'Choose a playlist';
            $emptyBody = 'Open one from the list, or connect Spotify and sync.';
            $emptyAction = 'Connect Spotify';
            $emptyHref = '/settings/spotify';
            require __DIR__ . '/partials/empty-panel.php';
            ?>
        </section>
    </div>
    <div id="spotify-new-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="spotify-new-title">
        <div class="app-modal-backdrop" data-close-modal></div>
        <div class="app-modal-panel surface">
            <div class="sp-queue-head">
                <h2 id="spotify-new-title">New playlist</h2>
                <button type="button" class="app-modal-close" data-close-modal aria-label="Close">×</button>
            </div>
            <form id="spotify-create" class="grid gap-3 mt-3">
                <label class="block">
                    <span class="mono text-xs text-faint">Name</span>
                    <input name="name" class="input w-full mt-1" maxlength="100" required>
                </label>
                <label class="block">
                    <span class="mono text-xs text-faint">Description</span>
                    <input name="description" class="input w-full mt-1" maxlength="300">
                </label>
                <label class="text-sm"><input type="checkbox" name="public"> Public</label>
                <fieldset class="sp-kind">
                    <legend class="mono text-xs text-faint">Type</legend>
                    <label class="text-sm"><input type="radio" name="kind" value="regular" checked> Regular</label>
                    <label class="text-sm"><input type="radio" name="kind" value="ai"> AI</label>
                </fieldset>
                <div id="spotify-create-ai" hidden>
                    <label class="block">
                        <span class="mono text-xs text-faint">Prompt</span>
                        <textarea name="prompt" class="input w-full mt-1" rows="4" maxlength="8000"></textarea>
                    </label>
                    <label class="block mt-3">
                        <span class="mono text-xs text-faint">Schedule</span>
                        <input name="refresh_schedule" class="input w-full mt-1" maxlength="32" placeholder="weekly">
                    </label>
                </div>
                <div>
                    <button type="submit" class="btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
    <section class="surface p-4 mt-4" id="spotify-prompts" hidden>
        <h2 class="section-title">Prompts</h2>
        <p class="text-sm text-muted mb-3">An assistant reads these when it builds or updates a playlist. Marking a run only stores the time.</p>
        <form id="spotify-prompt-form" class="grid gap-3 sm:grid-cols-2 mb-4">
            <label class="block">
                <span class="mono text-xs text-faint">Name</span>
                <input name="name" class="input w-full mt-1" maxlength="120" required>
            </label>
            <label class="block">
                <span class="mono text-xs text-faint">Schedule</span>
                <input name="refresh_schedule" class="input w-full mt-1" maxlength="32" placeholder="weekly">
            </label>
            <label class="block sm:col-span-2">
                <span class="mono text-xs text-faint">Prompt</span>
                <textarea name="prompt" class="input w-full mt-1" rows="4" maxlength="8000" required></textarea>
            </label>
            <input type="hidden" name="target_playlist_id" value="">
            <label class="block">
                <span class="mono text-xs text-faint">Notes</span>
                <input name="notes" class="input w-full mt-1" maxlength="2000">
            </label>
            <label class="block">
                <span class="mono text-xs text-faint">Theme</span>
                <input name="theme" class="input w-full mt-1" maxlength="500" placeholder="warm, late-night">
            </label>
            <label class="block sm:col-span-2">
                <span class="mono text-xs text-faint">Arc energy (1–10): opener, build, peak, breather, closer</span>
                <span class="flex flex-wrap gap-2 mt-1">
                    <input name="arc_opener" class="input" inputmode="numeric" placeholder="3" maxlength="2">
                    <input name="arc_build" class="input" inputmode="numeric" placeholder="6" maxlength="2">
                    <input name="arc_peak" class="input" inputmode="numeric" placeholder="9" maxlength="2">
                    <input name="arc_breather" class="input" inputmode="numeric" placeholder="5" maxlength="2">
                    <input name="arc_closer" class="input" inputmode="numeric" placeholder="4" maxlength="2">
                </span>
            </label>
            <label class="block">
                <span class="mono text-xs text-faint">Season tags</span>
                <input name="season_tags" class="input w-full mt-1" placeholder="christmas">
            </label>
            <label class="block">
                <span class="mono text-xs text-faint">Allow out of season</span>
                <input name="allow_seasons" class="input w-full mt-1" placeholder="christmas">
            </label>
            <div class="sm:col-span-2">
                <button type="submit" class="btn-primary">Save prompt</button>
            </div>
        </form>
        <div id="spotify-prompt-list"></div>
    </section>
    <section class="surface p-4 mt-4" id="spotify-seasons">
        <h2 class="section-title">Seasons</h2>
        <p class="text-sm text-muted mb-3">Christmas and holiday start on Thanksgiving and end on January 6. A track tagged for a closed season is refused unless the prompt allows it.</p>
        <form id="spotify-season-form" class="flex flex-wrap gap-2 items-end">
            <label class="block text-sm">Season
                <input name="season" class="input mt-1" value="christmas" maxlength="32" required>
            </label>
            <label class="text-sm"><input type="checkbox" name="thanksgiving" checked> Start on Thanksgiving</label>
            <label class="block text-sm">Start month
                <input name="start_month" class="input mt-1" inputmode="numeric" maxlength="2">
            </label>
            <label class="block text-sm">Start day
                <input name="start_day" class="input mt-1" inputmode="numeric" maxlength="2">
            </label>
            <label class="block text-sm">End month
                <input name="end_month" class="input mt-1" value="1" inputmode="numeric" maxlength="2" required>
            </label>
            <label class="block text-sm">End day
                <input name="end_day" class="input mt-1" value="6" inputmode="numeric" maxlength="2" required>
            </label>
            <button type="submit" class="btn-primary">Save window</button>
        </form>
        <p id="spotify-season-list" class="text-sm text-muted mt-3"></p>
    </section>
    <div id="spotify-meta-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="spotify-meta-title">
        <div class="app-modal-backdrop" data-close-meta></div>
        <div class="app-modal-panel surface">
            <div class="sp-queue-head">
                <h2 id="spotify-meta-title">Track notes</h2>
                <button type="button" class="app-modal-close" data-close-meta aria-label="Close">×</button>
            </div>
            <form id="spotify-meta-form" class="grid gap-3 mt-3">
                <input type="hidden" name="uri" value="">
                <p class="text-sm" data-meta-name></p>
                <label class="block text-sm">BPM <input name="bpm" class="input w-full mt-1" inputmode="decimal"></label>
                <label class="block text-sm">Key <input name="musical_key" class="input w-full mt-1" placeholder="A minor"></label>
                <label class="block text-sm">Camelot <input name="camelot" class="input w-full mt-1" placeholder="8A"></label>
                <label class="block text-sm">Energy (1–10) <input name="energy" class="input w-full mt-1" inputmode="numeric"></label>
                <label class="block text-sm">Mood <input name="mood_tags" class="input w-full mt-1" placeholder="warm, late"></label>
                <label class="block text-sm">Seasons <input name="season_tags" class="input w-full mt-1" placeholder="christmas"></label>
                <label class="block text-sm">Source
                    <select name="source" class="input w-full mt-1">
                        <option value="manual">manual</option>
                        <option value="estimated">estimated</option>
                    </select>
                </label>
                <button type="submit" class="btn-primary">Save notes</button>
            </form>
        </div>
    </div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer ?? '20261022d') ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer ?? '20261022d') ?>"></script>
<script src="/assets/js/spotify.js?v=<?= e($assetVer ?? '20261022d') ?>"></script>
</body>
</html>
