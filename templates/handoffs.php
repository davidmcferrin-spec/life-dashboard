<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Handoffs';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page" id="handoffs-app">
    <?php $pageEyebrow = 'Bots'; $pageHeading = 'Handoffs'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Work passed between bots. A card records who sent it, who it is for, and every status change.</p>
    <p id="handoff-error" class="ld-error hidden"></p>
    <form class="platform-card handoff-filters" id="handoff-filters">
        <label>Agent
            <select class="input" id="handoff-agent" name="agent">
                <option value="">All</option>
                <option value="__anyone__">Anyone</option>
            </select>
        </label>
        <label>Tag
            <input class="input" id="handoff-tag" name="tag" maxlength="32" placeholder="ops">
        </label>
        <button class="btn" type="submit">Filter</button>
    </form>
    <form class="platform-card handoff-create" id="handoff-create">
        <p class="platform-kicker">New handoff</p>
        <input class="input" name="title" placeholder="Title" required maxlength="200">
        <textarea class="input" name="body" placeholder="Markdown" rows="4" maxlength="20000"></textarea>
        <input class="input" name="to_agent" placeholder="To agent, or leave blank for anyone" maxlength="60" list="handoff-agents">
        <datalist id="handoff-agents"></datalist>
        <select class="input" name="priority" aria-label="Priority">
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="low">Low</option>
        </select>
        <input class="input" name="due_at" type="datetime-local" aria-label="Due">
        <input class="input" name="tags" placeholder="Tags, comma separated" maxlength="200">
        <input class="input" name="pages" placeholder="Vault page ids, comma separated" maxlength="200">
        <textarea class="input" name="urls" placeholder="Links, one per line" rows="2"></textarea>
        <button class="btn-primary" type="submit">Create</button>
    </form>
    <div class="handoff-board" id="handoff-board"></div>
    <section class="platform-card handoff-detail hidden" id="handoff-detail"></section>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/handoffs.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
