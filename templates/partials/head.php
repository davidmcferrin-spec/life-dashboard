<?php
/**
 * Shared document head (theme boot + assets)
 *
 * @var string $pageTitle
 * @var string $themePref jarvis|light|dark|system
 * @var string $jarvisAccent #rrggbb JARVIS accent (links and gauges; default is the shared teal)
 * @var bool   $starfieldPref Starfield background in dark mode (per-user)
 * @var bool   $themeLocalOnly Use localStorage instead of server pref (login page)
 * @var list<string> $extraStyles Page-specific stylesheet paths under /assets (optional)
 */
$pageTitle = $pageTitle ?? 'Dashboard';
$themePref = $themePref ?? 'jarvis';
$starfieldPref = $starfieldPref ?? false;
$themeLocalOnly = $themeLocalOnly ?? false;
if (!in_array($themePref, ['jarvis', 'light', 'dark', 'system'], true)) {
    $themePref = 'jarvis';
}
if (!isset($jarvisAccent)) {
    $jarvisAccent = \Dashboard\Support\JarvisAccent::DEFAULT;
    if (!$themeLocalOnly && !empty($user['id'])) {
        try {
            $jarvisAccent = (new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance()))
                ->getJarvisAccent((int) $user['id']);
        } catch (\Throwable) {
            $jarvisAccent = \Dashboard\Support\JarvisAccent::DEFAULT;
        }
    }
}
$jarvisAccent = \Dashboard\Support\JarvisAccent::normalize($jarvisAccent);
$jarvisStyle = \Dashboard\Support\JarvisAccent::cssDeclaration($jarvisAccent);
$starfieldPrefAttr = (!$themeLocalOnly && $starfieldPref) ? ' data-starfield-pref="1"' : '';
$starfieldBoot = (!$themeLocalOnly && $starfieldPref) ? 'true' : 'false';
$assetVer = '20261023ak';
$extraStyles = $extraStyles ?? [];
$serverTimeMs = $themeLocalOnly ? null : (int) round(microtime(true) * 1000);
?>
<!DOCTYPE html>
<html lang="en" style="<?= e($jarvisStyle) ?>" data-jarvis-accent="<?= e($jarvisAccent) ?>" data-theme-pref="<?= e($themePref) ?>"<?= $themeLocalOnly ? ' data-theme-local="1"' : '' ?><?= $starfieldPrefAttr ?><?= $serverTimeMs !== null ? ' data-server-time-ms="' . e((string) $serverTimeMs) . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!$themeLocalOnly): ?>
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <meta name="dashboard-starfield-pref" content="<?= $starfieldPref ? '1' : '0' ?>">
    <meta name="dashboard-user-id" content="<?= e((string) ($user['id'] ?? '0')) ?>">
    <?php endif; ?>
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon_32.png">
    <link rel="apple-touch-icon" href="/favicon_512.png">
    <script>
    (function () {
        var pref = document.documentElement.getAttribute('data-theme-pref') || 'jarvis';
        var localOnly = document.documentElement.getAttribute('data-theme-local') === '1';
        var starfieldOn = <?= $themeLocalOnly ? 'false' : $starfieldBoot ?>;
        var allowed = { jarvis: 1, light: 1, dark: 1, system: 1 };
        try {
            var stored = localStorage.getItem('dashboard_theme');
            if (stored && allowed[stored]) pref = stored;
        } catch (e) {}
        var effective = pref;
        if (pref === 'system') {
            effective = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        } else if (pref !== 'light' && pref !== 'dark' && pref !== 'jarvis') {
            effective = 'jarvis';
        }
        document.documentElement.setAttribute('data-theme', effective);
        document.documentElement.setAttribute('data-theme-pref', pref);
        if (!localOnly) {
            try {
                localStorage.setItem('dashboard_starfield_pref', starfieldOn ? '1' : '0');
            } catch (e) {}
        }
    })();
    </script>
    <link rel="stylesheet" href="/assets/css/tailwind.css?v=<?= e($assetVer) ?>">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= e($assetVer) ?>">
    <?php if (!$themeLocalOnly): ?>
    <link rel="stylesheet" href="/assets/css/lifedash.css?v=<?= e($assetVer) ?>">
    <?php endif; ?>
    <?php foreach ($extraStyles as $sheet): ?>
    <?php if (str_contains($sheet, '/assets/css/lifedash.css')) { continue; } ?>
    <link rel="stylesheet" href="<?= e($sheet) ?>?v=<?= e($assetVer) ?>">
    <?php endforeach; ?>
    <?php if (!$themeLocalOnly): ?>
    <script src="/assets/js/csrf.js?v=<?= e($assetVer) ?>"></script>
    <script src="/assets/js/server-time.js?v=<?= e($assetVer) ?>"></script>
    <script src="/assets/js/broadcast-week.js?v=<?= e($assetVer) ?>"></script>
    <?php endif; ?>
    <script src="/assets/js/toast.js?v=<?= e($assetVer) ?>"></script>
    <script src="/assets/js/empty-panel.js?v=<?= e($assetVer) ?>"></script>
    <script src="/assets/js/app-modal.js?v=<?= e($assetVer) ?>" defer></script>
    <script src="/assets/js/theme.js?v=<?= e($assetVer) ?>" defer></script>
    <?php if (!$themeLocalOnly): ?>
    <script src="/assets/js/app-shell.js?v=<?= e($assetVer) ?>" defer></script>
    <script src="/assets/js/starfield.js?v=<?= e($assetVer) ?>" defer></script>
    <?php endif; ?>
    <?php if (!$themeLocalOnly && !empty($user['id'])): ?>
    <script src="/assets/js/spotify-player.js?v=<?= e($assetVer) ?>" defer></script>
    <?php endif; ?>
</head>
