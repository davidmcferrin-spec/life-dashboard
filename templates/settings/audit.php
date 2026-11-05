<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Audit';
$active = 'audit';
$settingsAutosave = false;
$settingsFooterNote = 'This log is read-only.';
require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Account'; $pageHeading = 'Audit'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead">What each key did. This list is read-only. It does not change access and it does not revoke a key. Set access or revoke a connection on <a href="/settings/agents">AI connections</a>.</p>
            <p id="audit-error" class="ld-error hidden"></p>
            <div id="audit-app" class="platform-card"></div>
<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/audit.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
