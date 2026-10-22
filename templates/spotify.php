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
<nav class="chrome flex items-center justify-between px-4 py-3">
    <a href="/" class="brand hover:text-accent">Dashboard</a>
    <div class="nav-meta flex items-center gap-3">
        <?php require __DIR__ . '/partials/appearance-nav.php'; ?>
        <a href="/settings/spotify" class="nav-link">Spotify</a>
        <a href="/logout" class="nav-link nav-link-danger">Sign Out</a>
    </div>
</nav>
<main class="mx-auto px-4 py-6" id="spotify-app">
    <header class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-primary text-xl font-medium">Playlists</h1>
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
            <p class="empty-state mono text-xs">Choose a playlist.</p>
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
            <div class="sm:col-span-2">
                <button type="submit" class="btn-primary">Save prompt</button>
            </div>
        </form>
        <div id="spotify-prompt-list"></div>
    </section>
</main>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer ?? '20261022d') ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer ?? '20261022d') ?>"></script>
<script src="/assets/js/spotify.js?v=<?= e($assetVer ?? '20261022d') ?>"></script>
</body>
</html>
