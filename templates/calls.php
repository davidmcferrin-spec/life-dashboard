<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Calls';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page" id="calls-app">
    <?php $pageEyebrow = 'Dial'; $pageHeading = 'Calls'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Calls the dial bot places, and contacts entered here. Nothing is imported.</p>
    <p id="board-error" class="ld-error hidden"></p>
    <form class="platform-card board-create" id="call-log">
        <p class="platform-kicker">Log a call</p>
        <input class="input" name="to_number" placeholder="To" maxlength="20">
        <input class="input" name="from_number" placeholder="From" maxlength="20">
        <input class="input" name="purpose" placeholder="Purpose" maxlength="240">
        <input class="input" name="outcome" placeholder="Outcome" maxlength="80">
        <input class="input" name="placed_by" placeholder="Placed by" maxlength="80">
        <input class="input" name="duration" type="number" min="0" max="86400" placeholder="Seconds" aria-label="Duration">
        <textarea class="input" name="summary" placeholder="Summary" rows="3" maxlength="4000"></textarea>
        <button class="btn-primary" type="submit">Log</button>
    </form>
    <form class="platform-card board-create" id="contact-add">
        <p class="platform-kicker">Add a contact</p>
        <input class="input" name="name" placeholder="Name" required maxlength="160">
        <input class="input" name="phones" placeholder="Phones, comma separated" maxlength="200">
        <input class="input" name="emails" placeholder="Emails, comma separated" maxlength="300">
        <input class="input" name="org" placeholder="Organization" maxlength="160">
        <input class="input" name="tags" placeholder="Tags, comma separated" maxlength="200">
        <textarea class="input" name="notes" placeholder="Notes" rows="2" maxlength="4000"></textarea>
        <button class="btn-primary" type="submit">Save contact</button>
    </form>
    <form class="platform-card board-filters" id="contact-find">
        <label>Find
            <input class="input" name="q" placeholder="Name, phone, email, org, or tag" maxlength="160">
        </label>
        <button class="btn" type="submit">Find</button>
    </form>
    <div class="board-split">
        <section>
            <h2 class="platform-kicker">Recent calls</h2>
            <div id="call-list"></div>
        </section>
        <section>
            <h2 class="platform-kicker">Contacts</h2>
            <div id="contact-list"></div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/boards.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
