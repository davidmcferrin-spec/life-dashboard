<?php
/**
 * cloudpad/canvas.php — full-page Excalidraw editor
 *
 * @var array<string, mixed> $user
 * @var int $canvasId
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$prefService   = new PreferenceService(Database::getInstance());
$themePref     = $prefService->getTheme((int) $user['id']);
$starfieldPref = $prefService->getStarfield((int) $user['id']);
$pageTitle     = 'CloudPad canvas';
$extraStyles   = ['/assets/css/remaining-pages.css'];
$canvasId      = (int) ($canvasId ?? 0);
?>
<?php require __DIR__ . '/../partials/head.php'; ?>
<link rel="stylesheet" href="/assets/css/cloudpad.css?v=<?= e($assetVer) ?>">
<body class="page ld-shell-page cloudpad-page min-h-screen">

<?php require __DIR__ . '/../partials/app-shell-open.php'; ?>

<?php $pageEyebrow = 'Life Dashboard'; $pageHeading = 'CloudPad'; require __DIR__ . '/../partials/page-heading.php'; ?>

<div id="cloudpad-mobile-warn" class="hidden flash-warn mono text-xs px-4 py-2 text-center">
    CloudPad editing works best on desktop. Viewing is supported on smaller screens.
</div>

<div id="cloudpad-control-banner" class="hidden cloudpad-control-banner mono text-xs px-4 py-2"></div>

<div id="cloudpad-root" class="cloudpad-root"></div>

<section id="cloudpad-sharing" class="hidden cloudpad-sharing surface mx-4 mb-4 p-4">
    <h3 class="section-title mb-2">Sharing</h3>
    <p class="text-faint text-xs mono mb-4">Public links are read-only. Logged-in users can be granted write access and edit control.</p>
    <div class="grid lg:grid-cols-2 gap-6">
        <div>
            <h4 class="text-muted text-xs mono uppercase tracking-wider mb-2">Public link (read only)</h4>
            <form id="cloudpad-share-link-form" class="space-y-2 mb-3">
                <select id="cloudpad-link-expiry" class="input mono text-sm w-full">
                    <option value="">No expiry</option>
                    <option value="P1D">1 day</option>
                    <option value="P7D">7 days</option>
                    <option value="P30D">30 days</option>
                    <option value="P90D">90 days</option>
                </select>
                <input type="text" id="cloudpad-link-label" class="input mono text-sm w-full" placeholder="Label (optional)">
                <button type="submit" class="btn-primary text-sm">Create share link</button>
            </form>
            <ul id="cloudpad-share-links" class="space-y-2 text-xs mono"></ul>
        </div>
        <div>
            <h4 class="text-muted text-xs mono uppercase tracking-wider mb-2">Share with user</h4>
            <form id="cloudpad-share-user-form" class="flex flex-wrap gap-2 mb-3">
                <input type="text" id="cloudpad-share-identifier" class="input mono text-sm flex-1 min-w-[10rem]"
                    placeholder="Username or email" required>
                <select id="cloudpad-user-permission" class="input mono text-sm">
                    <option value="read">Read</option>
                    <option value="write">Write</option>
                </select>
                <button type="submit" class="btn text-sm">Add</button>
            </form>
            <ul id="cloudpad-share-users" class="space-y-2 text-xs mono"></ul>
        </div>
    </div>
</section>

<script>
    window.EXCALIDRAW_ASSET_PATH = '/assets/';
    window.CLOUDPAD_CANVAS_ID = <?= json_encode($canvasId, JSON_THROW_ON_ERROR) ?>;
</script>
<?php require __DIR__ . '/../partials/app-shell-close.php'; ?>
<script src="/assets/js/cloudpad-canvas.bundle.js?v=<?= e($assetVer) ?>" defer></script>
</body>
</html>
