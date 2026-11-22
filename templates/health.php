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
            <select class="input" name="meal_type" aria-label="Meal" required>
                <option value="breakfast">Breakfast</option>
                <option value="lunch">Lunch</option>
                <option value="dinner">Dinner</option>
                <option value="snack">Snack</option>
                <option value="other">Other</option>
            </select>
            <input class="input" name="description" placeholder="What you ate" required maxlength="500">
            <input class="input" name="place" placeholder="Place, optional" maxlength="160">
            <input class="input" name="cost" type="number" min="0" max="100000" step="0.01" placeholder="Cost, optional">
            <label class="platform-meta"><input type="checkbox" name="eaten_out"> Eaten out</label>
            <button class="btn-primary" type="submit">Save meal</button>
            <div id="health-meals"></div>
        </form>
        <form class="platform-card platform-form" id="health-bp-form">
            <p class="platform-kicker">Blood pressure</p>
            <div id="health-bp"></div>
            <input class="input" name="systolic" type="number" min="50" max="300" step="1" placeholder="Systolic" required>
            <input class="input" name="diastolic" type="number" min="30" max="200" step="1" placeholder="Diastolic" required>
            <input class="input" name="pulse" type="number" min="20" max="250" step="1" placeholder="Pulse, optional">
            <button class="btn-primary" type="submit">Save reading</button>
        </form>
        <form class="platform-card platform-form" id="health-med-form">
            <p class="platform-kicker">Medications</p>
            <div id="health-meds"></div>
            <input class="input" name="name" placeholder="Name" required maxlength="160">
            <input class="input" name="dose" placeholder="Dose" maxlength="80">
            <input class="input" name="schedule" placeholder="Schedule" maxlength="160">
            <button class="btn-primary" type="submit">Add medication</button>
        </form>
        <form class="platform-card platform-form" id="health-appt-form">
            <p class="platform-kicker">Appointments</p>
            <div id="health-appts"></div>
            <input class="input" name="provider" placeholder="Provider" required maxlength="160">
            <input class="input" name="specialty" placeholder="Specialty" maxlength="80">
            <input class="input" name="starts_at" type="datetime-local" aria-label="When" required>
            <input class="input" name="location" placeholder="Location" maxlength="200">
            <button class="btn-primary" type="submit">Add appointment</button>
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
