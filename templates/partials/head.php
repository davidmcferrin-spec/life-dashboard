<?php
/**
 * Shared document head (theme boot + assets)
 *
 * @var string $pageTitle
 * @var string $pageTitle
 * @var string $themePref light|dark|system
 * @var bool   $starfieldPref Starfield background in dark mode (per-user)
 * @var bool   $themeLocalOnly Use localStorage instead of server pref (login page)
 * @var list<string> $extraStyles Page-specific stylesheet paths under /assets (optional)
 */
$pageTitle = $pageTitle ?? 'Dashboard';
$themePref = $themePref ?? 'system';
$starfieldPref = $starfieldPref ?? false;
$themeLocalOnly = $themeLocalOnly ?? false;
if (!in_array($themePref, ['light', 'dark', 'system'], true)) {
    $themePref = 'system';
}
$starfieldPrefAttr = (!$themeLocalOnly && $starfieldPref) ? ' data-starfield-pref="1"' : '';
$starfieldBoot = (!$themeLocalOnly && $starfieldPref) ? 'true' : 'false';
$assetVer = '20261006a';
$extraStyles = $extraStyles ?? [];
$serverTimeMs = $themeLocalOnly ? null : (int) round(microtime(true) * 1000);
?>
<!DOCTYPE html>
<html lang="en" data-theme-pref="<?= e($themePref) ?>"<?= $themeLocalOnly ? ' data-theme-local="1"' : '' ?><?= $starfieldPrefAttr ?><?= $serverTimeMs !== null ? ' data-server-time-ms="' . e((string) $serverTimeMs) . '"' : '' ?>>
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
        var pref = document.documentElement.getAttribute('data-theme-pref') || 'system';
        var localOnly = document.documentElement.getAttribute('data-theme-local') === '1';
        var starfieldOn = <?= $themeLocalOnly ? 'false' : $starfieldBoot ?>;
        if (localOnly) {
            try {
                var s = localStorage.getItem('dashboard_theme');
                if (s === 'light' || s === 'dark' || s === 'system') pref = s;
            } catch (e) {}
        } else {
            try {
                var t = localStorage.getItem('dashboard_theme');
                if (t === 'light' || t === 'dark' || t === 'system') pref = t;
            } catch (e) {}
        }
        var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme-pref', pref);
        if (!localOnly) {
            try {
                localStorage.setItem('dashboard_starfield_pref', starfieldOn ? '1' : '0');
            } catch (e) {}
        }
    })();
    </script>
    <link rel="stylesheet" href="/assets/css/tailwind.css?v=<?= e($assetVer) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/dashboard.css?v=<?= e($assetVer) ?>">
    <?php foreach ($extraStyles as $sheet): ?>
    <link rel="stylesheet" href="<?= e($sheet) ?>?v=<?= e($assetVer) ?>">
    <?php endforeach; ?>
    <?php if (!$themeLocalOnly): ?>
    <script src="/assets/js/csrf.js?v=<?= e($assetVer) ?>"></script>
    <script src="/assets/js/server-time.js?v=<?= e($assetVer) ?>"></script>
    <script src="/assets/js/broadcast-week.js?v=<?= e($assetVer) ?>"></script>
    <?php endif; ?>
    <script src="/assets/js/theme.js?v=<?= e($assetVer) ?>" defer></script>
    <?php if (!$themeLocalOnly): ?>
    <script src="/assets/js/starfield.js?v=<?= e($assetVer) ?>" defer></script>
    <?php endif; ?>
</head>
