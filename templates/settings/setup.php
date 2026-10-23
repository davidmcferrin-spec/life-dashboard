<?php
/**
 * Admin setup — integration keys that the browser is allowed to store.
 *
 * @var array<string, mixed> $user
 * @var array{groups: list<array<string, mixed>>} $setupState
 */

$pageTitle = 'Setup';
$active = 'setup';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <section class="surface p-5">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Setup'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mb-4">
                    Keys for weather, stocks, flights, packages, X, and the local model.
                    Saved values override the environment. The app secret and database password stay in the environment file.
                </p>
                <ul class="setup-list" id="setup-list">
                    <?php foreach ($setupState['groups'] as $group): ?>
                    <li class="setup-row">
                        <div class="min-w-0">
                            <div class="text-primary text-sm"><?= e($group['label']) ?></div>
                            <div class="text-faint text-xs mono"><?= e($group['blurb']) ?></div>
                        </div>
                        <span class="setup-status mono text-xs" data-status-for="<?= e($group['id']) ?>"><?= e($group['status']) ?></span>
                        <button type="button" class="btn text-xs" data-setup-open="<?= e($group['id']) ?>">Configure</button>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>

<div id="setup-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="setup-modal-title">
    <div class="app-modal-backdrop" data-setup-close></div>
    <div class="app-modal-panel surface ld-modal-wide">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h2 id="setup-modal-title" class="section-title text-sm">Configure</h2>
            <button type="button" class="app-modal-close" data-setup-close aria-label="Close">×</button>
        </div>
        <form id="setup-form" class="space-y-3" novalidate>
            <div id="setup-fields" class="space-y-3"></div>
            <p id="setup-error" class="text-danger mono text-xs hidden" role="alert"></p>
            <div class="flex flex-wrap gap-2 pt-1">
                <button type="submit" class="btn-primary text-sm">Save</button>
                <button type="button" class="btn text-sm" data-setup-close>Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
window.SETUP = <?= json_encode($setupState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php
$settingsScripts = ['/assets/js/settings-setup.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';
?>
