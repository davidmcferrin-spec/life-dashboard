<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Audit';
$active = 'audit';
$settingsAutosave = false;
$settingsFooterNote = 'This log is read-only.';
require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Account'; $pageHeading = 'Audit'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead">What keys and agents did. A valid key can call every tool. Each of those calls is written here. This page does not grant or deny anything.</p>
            <p id="audit-error" class="ld-error hidden"></p>
            <div id="audit-app" class="platform-card"></div>
<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/audit.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
