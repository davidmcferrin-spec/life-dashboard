<?php
/**
 * Settings layout — top chrome + sidebar open
 *
 * Expects: $user, $active, $pageTitle (before head include), optional $flash
 * Optional: $settingsMainClass (main element classes), $settingsContentClass (content column)
 */
$flash = $flash ?? null;
$settingsMainClass    = $settingsMainClass ?? 'max-w-screen-xl mx-auto px-4 py-6';
$settingsContentClass = $settingsContentClass ?? 'flex-1 space-y-8 min-w-0';

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

    <div class="flex flex-col lg:flex-row gap-6">
        <?php require __DIR__ . '/_nav.php'; ?>

        <div class="<?= e($settingsContentClass) ?>">
