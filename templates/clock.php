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

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

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
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<script type="application/json" id="focus-clock-config"><?= $focusClockJson ?></script>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/focus-clock-tz.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/clock-alarm.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/clock-page.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
