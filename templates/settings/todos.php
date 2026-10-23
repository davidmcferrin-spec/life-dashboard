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

            <p class="mb-4">
                <button type="button" id="todo-list-add-open" class="btn-primary">Add list</button>
            </p>

            <div id="todo-list-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="todo-list-add-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="todo-list-add-title" class="section-title text-sm">Add list</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="todo-list-add-form" class="space-y-3">
                        <label class="block">
                            <span class="mono text-xs text-faint">Name</span>
                            <input type="text" name="name" required placeholder="Projects" maxlength="128" class="input w-full mt-1">
                        </label>
                        <p id="todo-list-add-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary">Add list</button>
                            <button type="button" class="btn" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="todo-rename-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="todo-rename-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="todo-rename-title" class="section-title text-sm">Rename list</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="todo-rename-form" class="space-y-3">
                        <input type="hidden" id="todo-rename-id" name="list_id" value="">
                        <label class="block">
                            <span class="mono text-xs text-faint">Name</span>
                            <input type="text" id="todo-rename-input" name="name" maxlength="128" class="input mono text-xs w-full mt-1">
                        </label>
                        <p id="todo-rename-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary text-sm">Rename</button>
                            <button type="button" class="btn text-sm" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="todo-manage-lists" class="space-y-3 mb-6">
                <p class="empty-state mono text-xs">Loading…</p>
            </div>

            <section id="todo-share-panel" class="settings-block hidden">
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
$settingsScripts = ['/assets/js/todos-manage.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';
