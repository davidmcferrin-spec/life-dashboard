<?php
/**
 * settings/bookmarks.php — bookmark folder and link management (API-driven)
 *
 * @var array<string, mixed> $user
 * @var string $bookmarkletHref
 */

$pageTitle = 'Bookmarks';
$active    = 'bookmarks';

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Bookmarks'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Folders and links shown on the dashboard widget. Icons are fetched from each site and stored on the server.
                </p>
                <p id="bm-backfill-status" class="text-faint mono text-xs mt-2" aria-live="polite"></p>
            </header>

            <details class="settings-block mb-4">
                <summary class="text-sm font-medium text-primary cursor-pointer">Capture icon from open tab (local / LAN sites)</summary>
                <div class="mt-3 space-y-2 text-sm text-muted">
                    <p>Drag this link to your bookmarks bar, then click it while viewing a site. The page sends its favicon to your dashboard (matches an existing link by URL or creates an unfiled one).</p>
                    <p>
                        <a href="<?= e($bookmarkletHref ?? '') ?>" class="btn text-sm inline-block" onclick="alert('Drag this link to your bookmarks bar instead of clicking it here.'); return false;">Grab dashboard icon</a>
                    </p>
                    <p class="text-faint mono text-xs">Works on LAN and authenticated pages where automatic fetch cannot reach. Manual upload in the edit dialog remains available.</p>
                </div>
            </details>

            <div class="flex flex-wrap gap-2 mb-4">
                <button type="button" id="bm-folder-add-open" class="btn text-sm">Add folder</button>
                <button type="button" id="bm-link-add-open" class="btn-primary text-sm">Add link</button>
            </div>

            <div id="bm-manage-root" class="space-y-3">
                <p class="empty-state mono text-xs">Loading…</p>
            </div>

            <div id="bm-folder-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="bm-folder-add-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="bm-folder-add-title" class="section-title text-sm">Add folder</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="bm-folder-add-form" class="space-y-3">
                        <label class="block">
                            <span class="mono text-xs text-faint">New folder</span>
                            <input type="text" name="name" required placeholder="Work" maxlength="128" class="input w-full mt-1 text-sm">
                        </label>
                        <label class="block">
                            <span class="mono text-xs text-faint">Icon</span>
                            <input type="text" name="icon" placeholder="📁" maxlength="16" class="input w-full mt-1 text-sm">
                        </label>
                        <p id="bm-folder-add-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn text-sm">Add folder</button>
                            <button type="button" class="btn text-sm" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="bm-link-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="bm-link-add-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface ld-modal-wide">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="bm-link-add-title" class="section-title text-sm">Add link</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="bm-link-add-form" class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mono text-xs text-faint">Title</span>
                                <input type="text" name="title" required placeholder="GitHub" maxlength="255" class="input w-full mt-1 text-sm">
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">URL</span>
                                <input type="text" name="url" required placeholder="https://github.com" maxlength="512" class="input mono w-full mt-1 text-sm">
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="mono text-xs text-faint">Folder</span>
                                <select name="folder_id" id="bm-add-folder-select" class="input mono w-full mt-1 text-sm max-w-xs">
                                    <option value="">Unfiled</option>
                                </select>
                            </label>
                            <label class="flex items-center gap-2 sm:col-span-2 cursor-pointer text-sm text-muted">
                                <input type="checkbox" name="fetch_favicon" id="bm-add-fetch-icon" value="1" checked>
                                Fetch site icon automatically
                            </label>
                        </div>
                        <p id="bm-link-add-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary text-sm">Add link</button>
                            <button type="button" class="btn text-sm" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="bm-folder-rename-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="bm-folder-rename-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="bm-folder-rename-title" class="section-title text-sm">Rename folder</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="bm-folder-rename-form" class="space-y-3">
                        <input type="hidden" id="bm-folder-rename-id" value="">
                        <label class="block">
                            <span class="mono text-xs text-faint">Folder name</span>
                            <input type="text" id="bm-folder-rename-name" maxlength="128" class="input w-full mt-1 text-sm">
                        </label>
                        <label class="block">
                            <span class="mono text-xs text-faint">Folder icon (optional emoji/text)</span>
                            <input type="text" id="bm-folder-rename-icon" maxlength="64" class="input w-full mt-1 text-sm">
                        </label>
                        <p id="bm-folder-rename-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary text-sm">Rename</button>
                            <button type="button" class="btn text-sm" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="bm-edit-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="bm-edit-modal-title">
                <div class="app-modal-backdrop" id="bm-edit-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="bm-edit-modal-title" class="section-title text-sm">Edit link</h2>
                        <button type="button" id="bm-modal-close" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="bm-edit-form" class="space-y-3">
                        <input type="hidden" name="id" id="bm-edit-id">
                        <div class="flex items-center gap-3 mb-1">
                            <img id="bm-edit-icon-preview" src="" alt="" class="bm-edit-icon-preview hidden" width="32" height="32">
                            <img id="bm-edit-icon-external-preview" src="" alt="" class="bm-edit-icon-preview hidden opacity-80" width="32" height="32" title="Live preview from site (not saved yet)">
                            <span id="bm-edit-icon-empty" class="text-faint mono text-xs">No icon stored</span>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-1">
                            <label class="block">
                                <span class="mono text-xs text-faint">Title</span>
                                <input type="text" name="title" id="bm-edit-title" required maxlength="255" class="input w-full mt-1 text-sm">
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">URL</span>
                                <input type="text" name="url" id="bm-edit-url" required maxlength="512" class="input mono w-full mt-1 text-sm">
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">Folder</span>
                                <select name="folder_id" id="bm-edit-folder-select" class="input mono w-full mt-1 text-sm">
                                    <option value="">Unfiled</option>
                                </select>
                            </label>
                        </div>
                        <div class="flex flex-wrap gap-2 items-center">
                            <button type="button" id="bm-fetch-icon" class="btn text-sm">Fetch / update icon</button>
                            <button type="button" id="bm-open-icon-tab" class="btn text-sm">Open icon in new tab</button>
                            <button type="button" id="bm-upload-icon" class="btn text-sm">Upload icon</button>
                            <input type="file" id="bm-icon-file" class="hidden" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,.ico">
                            <span id="bm-fetch-icon-status" class="text-faint mono text-xs" aria-live="polite"></span>
                        </div>
                        <p id="bm-icon-candidates" class="text-faint mono text-xs hidden"></p>
                        <p class="text-faint mono text-xs">When automatic fetch fails (often CORS on local sites), open the icon URL, save the image, then Upload. Bookmarklet works on the full page.</p>
                        <p id="bm-edit-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <button type="submit" class="btn-primary text-sm">Save</button>
                            <button type="button" id="bm-edit-cancel" class="btn text-sm" data-close>Cancel</button>
                            <button type="button" id="bm-edit-delete" class="btn-danger text-sm ml-auto">Delete link</button>
                        </div>
                    </form>
                </div>
            </div>

<?php
$settingsScripts = [
    '/assets/js/bookmark-favicon-client.js',
    '/assets/js/bookmarks-manage.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
