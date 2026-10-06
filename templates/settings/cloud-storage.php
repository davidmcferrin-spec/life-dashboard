<?php
/**
 * settings/cloud-storage.php — admin shared cloud file settings
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$prefService   = new PreferenceService(Database::getInstance());
$themePref     = $prefService->getTheme((int) $user['id']);
$starfieldPref = $prefService->getStarfield((int) $user['id']);
$pageTitle     = 'Cloud storage setup';
$active        = 'cloud-storage';

require __DIR__ . '/_shell-top.php';
?>

            <section class="surface p-5">
                <h1 class="text-primary text-xl font-medium mb-1">Cloud storage setup</h1>
                <p class="text-muted text-sm mb-4">
                    System-wide defaults for cloud file storage. Each user connects their own
                    <strong>Dropbox</strong> or <strong>S3</strong> account on
                    <a href="/settings/clipboards?tab=files" class="text-accent hover:underline">Cloud clipboards → Files</a>
                    (Dropbox app credentials are saved per user, encrypted).
                </p>
                <p id="cloud-admin-source" class="text-faint mono text-xs mb-4"></p>
            </section>

            <section class="surface p-5">
                <h2 class="text-primary font-medium mb-1">Shared settings</h2>
                <p class="text-muted text-sm mb-4">Folder name created in each user’s cloud account and per-user cloud quota.</p>
                <div class="grid sm:grid-cols-2 gap-3 max-w-2xl text-sm">
                    <label class="block">
                        <span class="text-faint text-xs mono uppercase tracking-wider">Root folder name</span>
                        <input type="text" id="cloud-root-folder" class="input mono w-full mt-1" maxlength="128"
                            placeholder="PersonalDashboard">
                    </label>
                    <label class="block">
                        <span class="text-faint text-xs mono uppercase tracking-wider">Cloud quota (bytes)</span>
                        <input type="text" id="cloud-quota-bytes" class="input mono w-full mt-1" inputmode="numeric"
                            placeholder="5368709120">
                        <span class="text-faint text-xs mono">Default 5 GB (5368709120)</span>
                    </label>
                </div>
            </section>

            <section class="surface p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" id="cloud-admin-save" class="btn-primary text-sm">Save settings</button>
                    <span id="cloud-admin-save-msg" class="text-xs mono"></span>
                </div>
            </section>

<?php
$settingsScripts = [
    '/assets/js/admin-cloud-storage.js?v=' . ($assetVer ?? '1'),
];
require __DIR__ . '/_shell-bottom.php';
