<?php
/**
 * settings/pings.php — system status ping target manager
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'System status';
$active    = 'pings';

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'System status'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    TCP and HTTP checks polled by cron every 30 minutes. Up to 10 targets per account.
                </p>
            </header>

            <p class="mb-4">
                <button type="button" id="ping-add-open" class="btn-primary">Add target</button>
            </p>

            <div id="ping-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="ping-add-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface ld-modal-wide">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="ping-add-title" class="section-title text-sm">Add target</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="ping-add-form" class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mono text-xs text-faint">Label</span>
                                <input type="text" name="label" required placeholder="Google DNS" maxlength="128" class="input w-full mt-1">
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">Host or URL</span>
                                <input type="text" name="target" required placeholder="8.8.8.8 or https://example.com" maxlength="255" class="input mono w-full mt-1">
                            </label>
                        </div>
                        <p id="ping-add-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary">Add target</button>
                            <button type="button" class="btn" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="ping-manage-list" class="space-y-2">
                <p class="empty-state mono">Loading…</p>
            </div>

<?php
$settingsScripts = ['/assets/js/pings-manage.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';
