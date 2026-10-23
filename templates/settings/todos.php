<?php
/**
 * settings/todos.php — todo list tabs, sharing, dashboard pin
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Todo lists';
$active    = 'todos';

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Todo lists'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Manage list tabs, choose up to 4 for the dashboard widget, and share lists with other dashboard users.
                    Up to 10 lists per account.
                </p>
            </header>

            <form id="todo-list-add-form" class="surface p-4 space-y-3 mb-4">
                <h2 class="section-title">Add list</h2>
                <div class="flex flex-wrap gap-2 items-end">
                    <label class="block flex-1 min-w-[12rem]">
                        <span class="mono text-xs text-faint">Name</span>
                        <input type="text" name="name" required placeholder="Projects" maxlength="128" class="input w-full mt-1">
                    </label>
                    <button type="submit" class="btn-primary">Add list</button>
                </div>
            </form>

            <div id="todo-manage-lists" class="space-y-3 mb-6">
                <p class="empty-state mono text-xs">Loading…</p>
            </div>

            <section id="todo-share-panel" class="surface p-4 hidden">
                <h2 class="section-title mb-1">Share list</h2>
                <p class="text-muted text-sm mb-3">
                    Sharing <span id="todo-share-list-name" class="text-secondary"></span> with another dashboard user.
                    <strong class="text-primary">Write</strong> can add and edit tasks;
                    <strong class="text-primary">Read</strong> can view and complete tasks assigned to them.
                </p>

                <form id="todo-share-form" class="flex flex-wrap gap-2 items-end mb-4">
                    <input type="hidden" id="todo-share-list-id" name="list_id" value="">
                    <label class="block flex-1 min-w-[10rem]">
                        <span class="mono text-xs text-faint">Username or email</span>
                        <input type="text" name="identifier" required class="input w-full mt-1" placeholder="colleague">
                    </label>
                    <label class="block">
                        <span class="mono text-xs text-faint">Permission</span>
                        <select name="permission" class="input mt-1">
                            <option value="write">Write</option>
                            <option value="read">Read</option>
                        </select>
                    </label>
                    <button type="submit" class="btn-primary">Share</button>
                </form>

                <ul id="todo-share-users" class="space-y-2 text-sm"></ul>
            </section>

            <p class="text-faint text-xs mono mt-4">
                Open the <a href="/todos" class="text-accent hover:underline">full todo page</a> to add tasks with notes and links.
            </p>

<?php
$settingsScripts = ['/assets/js/todos-manage.js'];
require __DIR__ . '/_shell-bottom.php';
