<?php
/**
 * Connect Spotify and show the last sync.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $spotify
 * @var string|null $flash
 */

$pageTitle   = 'Spotify';
$active      = 'spotify';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Spotify'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Connect a Premium account. Playlists and listening history sync on a timer.
                    Edits wait here until you push them.
                    <a href="/spotify" class="text-accent hover:underline">Open playlists</a>.
                </p>
            </header>

            <section class="surface p-4 space-y-3 mb-4">
                <h2 class="section-title">Account</h2>
                <?php if (empty($spotify['configured'])): ?>
                <p class="text-sm text-muted">
                    Set the client id, client secret, and redirect URI on Settings → Setup before connecting.
                </p>
                <?php elseif (empty($spotify['connected'])): ?>
                <p class="text-sm text-muted">Not connected<?= ($spotify['status'] ?? '') === 'needs_reauth' ? '. Spotify needs to be connected again.' : '.' ?></p>
                <?php if (!empty($spotify['status_detail'])): ?>
                <p class="text-sm"><?= e((string) $spotify['status_detail']) ?></p>
                <?php endif; ?>
                <a class="btn-primary inline-block" href="/settings/spotify/connect">Connect</a>
                <?php else: ?>
                <p class="text-sm text-primary"><?= e((string) $spotify['display_name']) ?></p>
                <?php if (!empty($spotify['missing_playback_scopes'])): ?>
                <p class="text-sm">Reconnect Spotify to enable in-browser playback.</p>
                <a class="btn inline-block" href="/settings/spotify/connect">Reconnect</a>
                <?php endif; ?>
                <p class="mono text-xs text-faint"><?= e(implode(' ', $spotify['scopes'] ?? [])) ?></p>
                <p class="text-sm text-muted">
                    Last sync: <?= e((string) ($spotify['last_sync_at'] ?? 'never')) ?>
                    <?php if (!empty($spotify['last_sync_status'])): ?>
                    (<?= e((string) $spotify['last_sync_status']) ?>)
                    <?php endif; ?>
                </p>
                <?php if (!empty($spotify['last_sync_error'])): ?>
                <p class="text-sm"><?= e((string) $spotify['last_sync_error']) ?></p>
                <?php endif; ?>
                <div class="flex flex-wrap gap-2">
                    <form method="post" action="/settings/spotify/sync">
                        <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="btn-primary">Sync</button>
                    </form>
                    <form method="post" action="/settings/spotify/disconnect">
                        <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="btn">Disconnect</button>
                    </form>
                </div>
                <?php endif; ?>
            </section>

            <section class="surface p-4 space-y-3">
                <h2 class="section-title">Player</h2>
                <p class="text-sm text-muted">
                    The Player button on the dashboard shows or hides the floating player on this browser.
                    The same button here follows that choice. In-browser audio needs this site on HTTPS
                    or http://127.0.0.1. Otherwise it controls the active Spotify device.
                </p>
                <button type="button" class="btn" data-player-toggle aria-pressed="false">Player</button>
            </section>

<?php require __DIR__ . '/_shell-bottom.php'; ?>
