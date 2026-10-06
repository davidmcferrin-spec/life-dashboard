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
                <h1 class="text-primary text-xl font-medium">System status</h1>
                <p class="text-muted text-sm mt-1">
                    TCP and HTTP checks polled by cron every 30 minutes. Up to 10 targets per account.
                </p>
            </header>

            <form id="ping-add-form" class="surface p-4 space-y-3 mb-4">
                <h2 class="section-title">Add target</h2>
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
                <button type="submit" class="btn-primary">Add target</button>
            </form>

            <div id="ping-manage-list" class="space-y-2">
                <p class="empty-state mono">Loading…</p>
            </div>

<?php
$settingsScripts = ['/assets/js/pings-manage.js'];
require __DIR__ . '/_shell-bottom.php';
