<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Morning brief';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page" id="brief-app">
    <?php $pageEyebrow = 'Today'; $pageHeading = 'Morning brief'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Digests, payday pressure, Home Assistant when it is connected, Spotify when something is playing, and health readiness from stored vitals. Missing sources stay missing.</p>
    <p id="brief-error" class="ld-error hidden"></p>
    <div class="platform-actions">
        <button type="button" class="btn-primary" id="brief-now">Brief me</button>
    </div>
    <pre class="platform-card platform-pre" id="brief-output"></pre>
    <form class="platform-card platform-form" id="brief-schedule">
        <p class="platform-kicker">Schedule</p>
        <label class="platform-meta"><input type="checkbox" name="enabled" value="1"> Run each day</label>
        <input class="input" name="local_time" value="07:00" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" required>
        <button class="btn" type="submit">Save schedule</button>
        <p class="platform-meta">The server job is <span class="hermes-mono">cron/morning_brief.php</span>. It runs only after this time, once a day.</p>
    </form>
    <div class="platform-list" id="brief-history"></div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brief.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
