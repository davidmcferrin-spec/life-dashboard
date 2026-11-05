<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Health';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page" id="health-app">
    <?php $pageEyebrow = 'Vitals'; $pageHeading = 'Health'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Readiness comes from stored sleep, HRV, and steps. Samsung Health and Health Connect arrive through Open Wearables. This page does not talk to Samsung.</p>
    <p id="health-error" class="ld-error hidden"></p>
    <div class="platform-grid">
        <section class="platform-card" id="health-hero"></section>
        <section class="platform-card" id="health-source"></section>
    </div>
    <section class="platform-card">
        <p class="platform-kicker">Workouts</p>
        <div id="health-workouts"></div>
        <div class="platform-actions">
            <button type="button" class="btn" id="health-sync">Sync Open Wearables</button>
            <a class="btn" href="/settings/health">Connection</a>
        </div>
    </section>
    <div class="platform-grid">
        <form class="platform-card platform-form" id="health-workout-form">
            <p class="platform-kicker">Log workout</p>
            <input class="input" name="title" placeholder="Title" required maxlength="80">
            <input class="input" name="minutes" type="number" min="1" max="1440" step="1" placeholder="Minutes" required>
            <input class="input" name="note" placeholder="Note" maxlength="500">
            <button class="btn-primary" type="submit">Save workout</button>
        </form>
        <form class="platform-card platform-form" id="health-meal-form">
            <p class="platform-kicker">Log meal</p>
            <input class="input" name="note" placeholder="What you ate" required maxlength="500">
            <input class="input" name="calories" type="number" min="0" max="20000" step="1" placeholder="Calories, optional">
            <button class="btn-primary" type="submit">Save meal</button>
        </form>
        <form class="platform-card platform-form" id="health-body-form">
            <p class="platform-kicker">Log body</p>
            <input class="input" name="weight_lb" type="number" min="1" max="1500" step="0.1" placeholder="Weight, pounds" required>
            <button class="btn-primary" type="submit">Save weight</button>
        </form>
    </div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/health.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
