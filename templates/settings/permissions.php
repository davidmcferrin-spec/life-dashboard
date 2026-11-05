<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Permissions';
$active = 'permissions';
$settingsAutosave = false;
$settingsFooterNote = 'Grants save when you change them.';
require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Account'; $pageHeading = 'Permissions'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead">Each key starts at deny. Allow once is spent after one successful act. Allow always does not ask again, and every act is still audited. Money movement and health writes are never granted on their own.</p>
            <p id="permissions-error" class="ld-error hidden"></p>
            <div id="permissions-app">
                <div id="permissions-table"></div>
                <section class="platform-card" id="permissions-audit"></section>
            </div>
<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/permissions.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
