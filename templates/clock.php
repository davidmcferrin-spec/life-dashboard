<?php
/**
 * clock.php — focus clock page
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$db            = Database::getInstance();
$userId        = (int) $user['id'];
$prefService   = new PreferenceService($db);
$themePref     = $prefService->getTheme($userId);
$starfieldPref = $prefService->getStarfield($userId);
$focusClock    = $prefService->getFocusClock($userId);
$fcAlarm       = $focusClock['alarm'] ?? [];
$pageTitle     = 'Clock';
$focusClockJson = json_encode($focusClock, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$datePos       = (string) ($focusClock['date_position'] ?? 'below');
$alarmOn       = !empty($fcAlarm['enabled']);
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<link rel="stylesheet" href="/assets/css/clock.css?v=<?= e($assetVer) ?>">
<body class="page min-h-screen" data-page="clock">

<nav class="chrome flex items-center justify-between px-6 py-3">
    <div class="flex items-center gap-3">
        <span class="brand">Clock</span>
        <a href="/" class="nav-link mono text-xs">← Dashboard</a>
    </div>
    <div class="nav-meta flex items-center gap-3 flex-wrap justify-end">
        <a href="/settings/clock"
           id="focus-clock-alarm-indicator"
           class="focus-clock-alarm-indicator<?= $alarmOn ? '' : ' hidden' ?>"
           title="<?= $alarmOn ? 'Alarm on — ' . e((string) ($fcAlarm['time'] ?? '07:00')) : 'Alarm off' ?>"
           aria-label="<?= $alarmOn ? 'Alarm enabled' : 'Alarm off' ?>">
            <svg class="focus-clock-alarm-icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 2c1.1 0 2 .9 2 2v.29c2.58.46 4.48 2.59 4.48 5.21v3.15l1.85 1.85c.63.63.18 1.7-.71 1.7H6.38c-.89 0-1.34-1.07-.71-1.7l1.85-1.85V9.5c0-2.62 1.9-4.75 4.48-5.21V4c0-1.1.9-2 2-2zm-1 18h2a2 2 0 0 1-4 0h2z"/>
            </svg>
        </a>
        <?php require __DIR__ . '/partials/appearance-nav.php'; ?>
        <span id="live-date"></span>
        <a href="/settings/clock" class="nav-link">Settings</a>
        <a href="/logout" class="nav-link nav-link-danger">Sign Out</a>
    </div>
</nav>

<main class="focus-clock-main">
    <?php $pageEyebrow = 'Dashboard'; $pageHeading = 'Clock'; require __DIR__ . '/partials/page-heading.php'; ?>
    <div
        id="focus-clock-stack"
        class="focus-clock-stack"
        data-date-position="<?= e($datePos) ?>"
        style="--fc-time-color: <?= e((string) ($focusClock['time_color'] ?? '#39ff14')) ?>; --fc-date-color: <?= e((string) ($focusClock['date_color'] ?? '#64748b')) ?>; --focus-clock-scale: <?= e((string) (($focusClock['scale_percent'] ?? 100) / 100)) ?>;"
    >
        <div id="focus-clock-meta" class="focus-clock-meta<?= $datePos === 'hidden' ? ' hidden' : '' ?>">
            <div id="focus-clock-date" class="focus-clock-date"></div>
            <div id="focus-clock-week" class="focus-clock-week<?= empty($focusClock['show_broadcast_week']) ? ' hidden' : '' ?>"></div>
        </div>
        <div id="focus-clock-time-wrap" class="focus-clock-time-wrap">
            <div
                id="focus-clock-time"
                class="focus-clock-time font-<?= e((string) ($focusClock['font'] ?? 'dseg7')) ?>"
                data-colon-blink="<?= !empty($focusClock['colon_blink']) && ($focusClock['font'] ?? '') === 'dseg7' && ($focusClock['show_seconds'] ?? true) ? '1' : '0' ?>"
                aria-live="polite"
            >--:--</div>
        </div>
    </div>
</main>

<script type="application/json" id="focus-clock-config"><?= $focusClockJson ?></script>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/focus-clock-tz.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/clock-alarm.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/clock-page.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
