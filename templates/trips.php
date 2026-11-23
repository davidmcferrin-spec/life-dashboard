<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Trips';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page" id="trips-app">
    <?php $pageEyebrow = 'Travel'; $pageHeading = 'Trips'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Ideas, plans, and booked trips. A template writes the vault page in 04 Travel.</p>
    <p id="board-error" class="ld-error hidden"></p>
    <form class="platform-card board-create" id="trip-create">
        <p class="platform-kicker">New trip</p>
        <input class="input" name="name" placeholder="Name" required maxlength="200">
        <input class="input" name="destination" placeholder="Destination" maxlength="200">
        <input class="input" name="start_date" type="date" aria-label="Start">
        <input class="input" name="end_date" type="date" aria-label="End">
        <select class="input" name="status" aria-label="Status">
            <option value="idea">Idea</option>
            <option value="planning">Planning</option>
            <option value="booked">Booked</option>
            <option value="done">Done</option>
        </select>
        <input class="input" name="owner_bot" placeholder="Owner bot" maxlength="80">
        <input class="input" name="tags" placeholder="Tags, comma separated" maxlength="200">
        <textarea class="input" name="notes" placeholder="Notes" rows="3" maxlength="8000"></textarea>
        <label class="board-check"><input type="checkbox" name="template"> Trip template</label>
        <button class="btn-primary" type="submit">Create</button>
    </form>
    <div class="board-list" id="trip-list"></div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/boards.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
