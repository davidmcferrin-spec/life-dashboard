<?php
/**
 * todos.php — full todo list manager
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$prefService   = new PreferenceService(Database::getInstance());
$themePref     = $prefService->getTheme((int) $user['id']);
$starfieldPref = $prefService->getStarfield((int) $user['id']);
$pageTitle     = 'Todo Lists';
$extraStyles   = ['/assets/css/remaining-pages.css'];
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page ld-shell-page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="max-w-3xl mx-auto px-4 py-8 space-y-4">
    <header>
        <?php $pageEyebrow = 'Schedule'; $pageHeading = 'Todo lists'; require __DIR__ . '/partials/page-heading.php'; ?>
        <p class="ld-page-lead">
            Tasks grouped by list. Completed items stay until you archive them.
            Share a list or assign tasks from the full editor below.
        </p>
    </header>

    <section class="surface p-4 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-faint mono text-xs">List</span>
                <select id="todo-page-list" class="input mono text-sm min-w-[10rem]" aria-label="Todo list"></select>
            </div>
            <div class="flex flex-wrap gap-1.5" id="todo-status-filters" role="group" aria-label="Filter by status">
                <button type="button" class="seg-btn seg-btn-active" data-status="open">Active</button>
                <button type="button" class="seg-btn" data-status="completed">Completed</button>
                <button type="button" class="seg-btn" data-status="archived">Archived</button>
                <button type="button" class="seg-btn" data-status="assigned">Assigned to me</button>
            </div>
        </div>

        <div id="todo-page-meta" class="flex flex-wrap items-center justify-between gap-2 text-faint mono text-xs"></div>

        <div id="todo-page-items" class="space-y-1">
            <?php
            $emptyTitle = 'Loading tasks';
            $emptyBody = 'They show up here after the list responds.';
            require __DIR__ . '/partials/empty-panel.php';
            ?>
        </div>

        <form id="todo-page-add" class="flex flex-wrap gap-2 pt-2 border-t border-chrome items-end">
            <label class="flex-1 min-w-[12rem]">
                <span class="mono text-xs text-faint block mb-1">Task</span>
                <input type="text" id="todo-page-input" class="input mono text-sm w-full" placeholder="Add a task…" maxlength="255" autocomplete="off">
            </label>
            <label class="w-auto">
                <span class="mono text-xs text-faint block mb-1">Due</span>
                <input type="date" id="todo-page-due" class="input mono text-sm">
            </label>
            <button type="submit" class="btn-primary text-sm self-end">Add</button>
        </form>
    </section>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/todo-due.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/todos-page.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
