<?php
/**
 * Settings layout. Numbered steps, the page, and a sticky footer.
 *
 * Expects: $user, $active, $pageTitle. Optional $flash, $settingsFormId,
 * $settingsSubmitLabel, $settingsFooterNote, $settingsAutosave, $settingsMainClass,
 * $settingsWide, $settingsPreview.
 *
 * $settingsPreview, when a page sets it, is an array of kicker, text, and percent.
 * Nothing is filled in here.
 */
$flash = $flash ?? null;
$active = $active ?? 'general';
$settingsFormId = $settingsFormId ?? '';
$settingsSubmitLabel = $settingsSubmitLabel ?? 'Save';

$settingsRenderNav = false;
include __DIR__ . '/_nav.php';

$settingsIsAdmin = !empty($user) && (int) ($user['is_admin'] ?? 0) === 1;
$settingsGroups = settings_nav_groups($settingsIsAdmin);
$settingsPageLinks = settings_nav_page_links();
$settingsTrail = settings_nav_trail($settingsGroups);
$settingsStepTotal = count($settingsTrail);
$settingsStepIndex = null;
foreach ($settingsTrail as $index => $step) {
    if ($step['key'] === $active) {
        $settingsStepIndex = $index;
        break;
    }
}
$settingsCurrent = $settingsStepIndex !== null ? $settingsTrail[$settingsStepIndex] : null;
$settingsPrev = ($settingsStepIndex !== null && $settingsStepIndex > 0) ? $settingsTrail[$settingsStepIndex - 1] : null;
$settingsNext = ($settingsStepIndex !== null && $settingsStepIndex < $settingsStepTotal - 1)
    ? $settingsTrail[$settingsStepIndex + 1]
    : null;
$settingsGroupTitle = $settingsCurrent['group'] ?? 'Settings';

$settingsPreviewView = null;
if (isset($settingsPreview) && is_array($settingsPreview)) {
    $previewPercent = $settingsPreview['percent'] ?? null;
    if ($previewPercent !== null && $previewPercent !== '') {
        $previewPercent = max(0, min(100, (int) $previewPercent));
    } else {
        $previewPercent = null;
    }
    $settingsPreviewView = [
        'kicker'  => trim((string) ($settingsPreview['kicker'] ?? '')),
        'text'    => trim((string) ($settingsPreview['text'] ?? '')),
        'percent' => $previewPercent,
    ];
    if ($settingsPreviewView['kicker'] === '' && $settingsPreviewView['text'] === '' && $settingsPreviewView['percent'] === null) {
        $settingsPreviewView = null;
    }
}

$settingsNarrow = !isset($settingsMainClass) && empty($settingsWide);
if (!isset($settingsMainClass)) {
    $settingsMainClass = 'settings-main';
}
if (!str_contains($settingsMainClass, 'settings-shell')) {
    $settingsMainClass = trim($settingsMainClass . ' settings-shell');
}
if (!isset($settingsContentClass)) {
    $settingsContentClass = 'settings-panel';
} else {
    $settingsContentClass .= ' settings-panel';
}
if ($settingsNarrow) {
    $settingsContentClass .= ' is-narrow';
}
$settingsLayoutClass = 'settings-layout' . ($settingsPreviewView !== null ? ' has-preview' : '');

if (!isset($themePref) && !empty($user['id'])) {
    $shellPrefs    = new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance());
    $themePref     = $shellPrefs->getTheme((int) $user['id']);
    $starfieldPref = $shellPrefs->getStarfield((int) $user['id']);
}
?>
<?php require __DIR__ . '/../partials/head.php'; ?>
<body class="page min-h-screen">

<?php require __DIR__ . '/../partials/app-shell-open.php'; ?>

<main class="<?= e($settingsMainClass) ?>">
    <?php if ($flash): ?>
    <div class="mb-4 mono text-xs px-4 py-2 rounded flash-ok">
        <?= e((string) $flash) ?>
    </div>
    <?php endif; ?>

    <button type="button" class="settings-rail-toggle" id="settings-rail-toggle" aria-expanded="false" aria-controls="settings-rail">Sections</button>
    <button type="button" class="settings-rail-backdrop" id="settings-rail-backdrop" hidden aria-label="Close sections"></button>

    <div class="<?= e($settingsLayoutClass) ?>">
        <?php
        $settingsRenderNav = true;
        include __DIR__ . '/_nav.php';
        ?>

        <div class="<?= e($settingsContentClass) ?>">
            <?php if ($settingsCurrent !== null): ?>
            <p class="settings-kicker">Step <?= e(sprintf('%02d', $settingsCurrent['number'])) ?> of <?= e(sprintf('%02d', $settingsStepTotal)) ?></p>
            <?php endif; ?>
            <div class="settings-body">
