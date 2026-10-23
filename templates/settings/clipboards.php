<?php
/**
 * settings/clipboards.php — cloud clipboard editor + private files
 *
 * @var array<string, mixed> $user
 */

$initialSlug = trim((string) ($_GET['board'] ?? ''));
$tabParam    = trim((string) ($_GET['tab'] ?? ''));
$initialTab  = in_array($tabParam, ['files', 'cloudpad'], true) ? $tabParam : 'boards';
$initialCanvasId = ($initialTab === 'cloudpad') ? max(0, (int) ($_GET['canvas'] ?? 0)) : 0;

$pageTitle            = 'Cloud clipboards';
$active               = 'clipboards';
$settingsMainClass    = 'max-w-screen-2xl mx-auto px-4 py-6';
$settingsContentClass = 'flex-1 min-w-0';

require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Settings'; $pageHeading = 'Cloud clipboards'; require __DIR__ . '/../partials/page-heading.php'; ?>
<?php if ($initialTab === 'cloudpad'): ?>
<link rel="stylesheet" href="/assets/css/cloudpad.css?v=<?= e($assetVer) ?>">
<?php endif; ?>

            <div class="flex gap-2 mb-4 border-b border-chrome pb-2">
                <a href="/settings/clipboards" id="clip-tab-boards"
                   class="clipboards-page-tab px-3 py-1.5 rounded mono text-sm<?= $initialTab === 'boards' ? ' clip-tab-active' : ' clip-tab' ?>">Boards</a>
                <a href="/settings/clipboards?tab=files" id="clip-tab-files"
                   class="clipboards-page-tab px-3 py-1.5 rounded mono text-sm<?= $initialTab === 'files' ? ' clip-tab-active' : ' clip-tab' ?>">Files</a>
                <a href="/settings/clipboards?tab=cloudpad" id="clip-tab-cloudpad"
                   class="clipboards-page-tab px-3 py-1.5 rounded mono text-sm<?= $initialTab === 'cloudpad' ? ' clip-tab-active' : ' clip-tab' ?>">CloudPad</a>
            </div>

            <div id="clipboards-tab-panel" class="<?= $initialTab !== 'boards' ? 'hidden' : '' ?>">
            <div class="flex flex-col lg:flex-row gap-4 min-h-[70vh]">
                <aside class="lg:w-56 flex-shrink-0 surface p-3">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="section-title">Boards</h2>
                        <button type="button" id="clip-new-board" class="text-accent hover:text-accent mono text-lg leading-none" title="New board">+</button>
                    </div>
                    <ul id="clip-board-list" class="space-y-1 text-sm">
                        <li class="text-faint mono text-xs py-2">Loading…</li>
                    </ul>
                </aside>

                <section class="flex-1 flex flex-col surface p-4 min-h-[60vh]">
                    <div class="flex flex-wrap items-center gap-3 mb-3 border-b border-chrome pb-3">
                        <input type="text" id="clip-board-name" class="input mono text-sm flex-1 min-w-[12rem] max-w-md" placeholder="Board name">
                        <label class="flex items-center gap-2 text-xs text-muted cursor-pointer">
                            <input type="checkbox" id="clip-pin-dashboard">
                            <span>Show on dashboard</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-muted cursor-pointer">
                            <input type="checkbox" id="clip-auto-save" checked>
                            <span>Auto-save</span>
                        </label>
                        <button type="button" id="clip-save-manual" class="hidden btn-primary">Save</button>
                        <button type="button" id="clip-sharing-jump" class="hidden btn">Sharing ↓</button>
                        <button type="button" id="clip-copy-local" class="btn">Copy</button>
                        <span id="clip-save-status" class="text-secondary mono text-xs ml-auto">Loading…</span>
                        <button type="button" id="clip-delete-board" class="btn-danger">Delete</button>
                    </div>

                    <div id="clip-remote-banner" class="hidden mb-2 mono text-xs px-3 py-2 rounded flash-warn">
                        Updated on another device.
                        <button type="button" id="clip-remote-reload" class="underline ml-2">Reload</button>
                    </div>

                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-faint mono text-xs">View:</span>
                        <button type="button" id="clip-view-edit" class="clip-view-tab clip-view-active mono text-xs px-2 py-1 rounded" aria-pressed="true">Edit</button>
                        <button type="button" id="clip-view-preview" class="clip-view-tab mono text-xs px-2 py-1 rounded" aria-pressed="false">Preview</button>
                    </div>

                    <div id="clip-editor-wrap" class="flex-1 flex flex-col min-h-[50vh]">
                        <textarea id="clip-editor" class="input flex-1 w-full min-h-[50vh] mono text-sm leading-relaxed" style="background: var(--editor-bg);"
                            placeholder="Paste text here — syncs across your devices. Use Markdown for headings, lists, and code blocks."
                            spellcheck="false"></textarea>
                        <div id="clip-preview" class="clipboard-preview hidden flex-1 w-full min-h-[50vh] overflow-auto p-3 text-sm leading-relaxed surface" tabindex="0" aria-label="Markdown preview"></div>
                    </div>

                    <p class="mt-2 text-faint mono text-xs">
                        Plain text storage — edit raw, preview formatted Markdown (headings, lists, <code class="clipboard-code">code</code>, links).
                    </p>

                    <section id="clip-sharing" class="hidden mt-6 pt-4 border-t border-chrome scroll-mt-6">
                        <h3 class="section-title mb-1">Sharing</h3>
                        <p id="clip-sharing-unavailable" class="hidden text-warn text-xs mono mb-4"></p>
                        <p id="clip-sharing-help" class="text-faint text-xs mono mb-4">Share links grant access to this clipboard only. In-app shares are visible to that user when they sign in.</p>

                        <div class="grid lg:grid-cols-2 gap-6">
                            <div>
                                <h4 class="text-muted text-xs mono uppercase tracking-wider mb-2">Public link</h4>
                                <form id="clip-share-link-form" class="space-y-2 mb-3">
                                    <select id="clip-link-permission" class="input mono text-sm w-full">
                                        <option value="read">Read only</option>
                                        <option value="write">Read &amp; write</option>
                                    </select>
                                    <select id="clip-link-expiry" class="input mono text-sm w-full">
                                        <option value="">No expiry</option>
                                        <option value="P1D">1 day</option>
                                        <option value="P7D">7 days</option>
                                        <option value="P30D">30 days</option>
                                        <option value="P90D">90 days</option>
                                    </select>
                                    <input type="text" id="clip-link-label" class="input mono text-sm w-full" placeholder="Label (optional)">
                                    <p id="clip-share-link-error" class="ld-field-error hidden" role="alert"></p>
                                    <button type="submit" class="btn-primary text-sm">Create share link</button>
                                </form>
                                <ul id="clip-share-links" class="space-y-2 text-xs mono"></ul>
                            </div>
                            <div>
                                <h4 class="text-muted text-xs mono uppercase tracking-wider mb-2">Share with user</h4>
                                <form id="clip-share-user-form" class="flex flex-wrap gap-2 mb-3">
                                    <input type="text" id="clip-share-identifier" class="input mono text-sm flex-1 min-w-[10rem]"
                                        placeholder="Username or email" required>
                                    <select id="clip-user-permission" class="input mono text-sm">
                                        <option value="read">Read</option>
                                        <option value="write">Write</option>
                                    </select>
                                    <button type="submit" class="btn text-sm">Add</button>
                                    <p id="clip-share-user-error" class="ld-field-error hidden w-full" role="alert"></p>
                                </form>
                                <ul id="clip-share-users" class="space-y-2 text-xs mono"></ul>
                            </div>
                        </div>
                    </section>
                </section>
            </div>

            <div id="clip-new-board-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="clip-new-board-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="clip-new-board-title" class="section-title text-sm">New board</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="clip-new-board-form" class="space-y-3">
                        <label class="block">
                            <span class="mono text-xs text-faint">Board name</span>
                            <input type="text" id="clip-new-board-name" class="input mono text-sm w-full mt-1" placeholder="Board name" maxlength="128" required>
                        </label>
                        <p id="clip-new-board-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary text-sm">Create</button>
                            <button type="button" id="clip-new-board-cancel" class="btn text-sm" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="clip-share-copy-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="clip-share-copy-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="clip-share-copy-title" class="section-title text-sm">Copy this share link</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <p class="text-sm mb-3">Shown once in the list below.</p>
                    <input type="text" id="clip-share-copy-url" readonly class="input mono text-xs w-full" onclick="this.select()">
                    <button type="button" class="btn text-sm mt-3" data-close>Close</button>
                </div>
            </div>
            </div>

            <div id="files-tab-panel" class="<?= $initialTab === 'files' ? '' : 'hidden' ?> space-y-6">
                <section id="cloud-storage-panel" class="surface p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <div>
                            <h2 id="storage-section-title" class="section-title">Storage</h2>
                            <p id="cloud-status-text" class="text-faint mono text-xs mt-1">Loading…</p>
                            <p id="storage-usage" class="text-faint mono text-xs mt-1 hidden"></p>
                        </div>
                        <div id="cloud-connect-actions" class="flex flex-wrap gap-2"></div>
                    </div>

                    <div id="cloud-dropbox-form-wrap" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="cloud-dropbox-title">
                        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                        <div class="app-modal-panel surface ld-modal-wide">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h2 id="cloud-dropbox-title" class="section-title text-sm">Dropbox</h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                        </div>
                        <p class="text-muted text-sm mb-3">
                            Create a <strong>Scoped access</strong> app at
                            <a href="https://www.dropbox.com/developers/apps" class="text-accent hover:underline" target="_blank" rel="noopener">dropbox.com/developers/apps</a>.
                            The dashboard only reads and writes inside your
                            <span class="mono text-faint">PersonalDashboard</span> folder (App folder access is fine).
                        </p>
                        <p class="text-muted text-sm mb-3">
                            On the app’s <strong>Permissions</strong> tab, enable
                            <span class="mono text-faint">files.content.read</span>,
                            <span class="mono text-faint">files.content.write</span>, and
                            <span class="mono text-faint">account_info.read</span> (for storage usage).
                            If you change permissions after authorizing, disconnect here and authorize again.
                        </p>
                        <p class="text-muted text-sm mb-3">
                            Under <strong>OAuth 2</strong>, add this redirect URI:
                        </p>
                        <div class="flex flex-wrap items-center gap-2 mb-4">
                            <code id="cloud-dropbox-redirect-uri" class="mono text-xs bg-chrome px-2 py-1 rounded break-all"></code>
                            <button type="button" id="cloud-dropbox-copy-uri" class="btn text-xs">Copy</button>
                        </div>
                        <form id="cloud-dropbox-form" class="grid sm:grid-cols-2 gap-3 text-sm">
                            <label class="block sm:col-span-2">
                                <span class="text-faint mono text-xs">App key</span>
                                <input type="text" name="app_key" id="cloud-dropbox-app-key" class="input mono text-sm w-full mt-1" required autocomplete="off">
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="text-faint mono text-xs">App secret</span>
                                <input type="password" name="app_secret" id="cloud-dropbox-app-secret" class="input mono text-sm w-full mt-1" autocomplete="new-password"
                                    placeholder="Required on first save">
                            </label>
                            <div class="sm:col-span-2 flex flex-wrap gap-2 pt-1">
                                <button type="button" id="cloud-dropbox-test" class="btn text-sm">Test credentials</button>
                                <button type="submit" class="btn-primary text-sm">Save app credentials</button>
                                <a id="cloud-dropbox-authorize" href="/api/storage/connect/dropbox" class="btn text-sm hidden">Authorize with Dropbox</a>
                                <button type="button" id="cloud-dropbox-cancel" class="btn text-sm" data-close>Cancel</button>
                            </div>
                            <p id="cloud-dropbox-form-msg" class="sm:col-span-2 text-xs mono"></p>
                        </form>
                        </div>
                    </div>

                    <div id="cloud-s3-form-wrap" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="cloud-s3-title">
                        <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                        <div class="app-modal-panel surface ld-modal-wide">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h2 id="cloud-s3-title" class="section-title text-sm">S3-compatible storage</h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                        </div>
                        <form id="cloud-s3-form" class="grid sm:grid-cols-2 gap-3 text-sm">
                            <label class="block sm:col-span-2">
                                <span class="text-faint mono text-xs">Access key</span>
                                <input type="text" name="access_key" class="input mono text-sm w-full mt-1" required autocomplete="off">
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="text-faint mono text-xs">Secret key</span>
                                <input type="password" name="secret_key" class="input mono text-sm w-full mt-1" required autocomplete="off">
                            </label>
                            <label class="block">
                                <span class="text-faint mono text-xs">Bucket</span>
                                <input type="text" name="bucket" class="input mono text-sm w-full mt-1" required>
                            </label>
                            <label class="block">
                                <span class="text-faint mono text-xs">Region</span>
                                <input type="text" name="region" class="input mono text-sm w-full mt-1" value="us-east-1">
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="text-faint mono text-xs">Endpoint</span>
                                <input type="url" name="endpoint" class="input mono text-sm w-full mt-1" value="https://s3.amazonaws.com">
                            </label>
                            <label class="block">
                                <span class="text-faint mono text-xs">Key prefix</span>
                                <input type="text" name="prefix" class="input mono text-sm w-full mt-1" value="personal-dashboard">
                            </label>
                            <label class="flex items-end gap-2 pb-1">
                                <input type="checkbox" name="path_style" value="1" class="rounded">
                                <span class="text-faint mono text-xs">Path-style URLs (MinIO / some S3)</span>
                            </label>
                            <p id="cloud-s3-error" class="ld-field-error hidden sm:col-span-2" role="alert"></p>
                            <div class="sm:col-span-2 flex flex-wrap gap-2 pt-1">
                                <button type="submit" class="btn-primary text-sm">Connect S3</button>
                                <button type="button" id="cloud-s3-cancel" class="btn text-sm" data-close>Cancel</button>
                            </div>
                        </form>
                        </div>
                    </div>

                    <div id="cloud-migrate-banner" class="hidden border border-chrome rounded p-4 mb-4 bg-surface-alt">
                        <p class="text-sm mb-2">
                            You have <span id="cloud-migrate-count">0</span> local file(s). Move them into your cloud
                            <span class="mono text-muted">PersonalDashboard</span> folder?
                        </p>
                        <p class="text-faint mono text-xs mb-3">Share links and in-app shares are preserved. Local copies are removed after a successful upload.</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" id="cloud-migrate-btn" class="btn-primary text-sm">Move to cloud</button>
                            <button type="button" id="cloud-migrate-dismiss" class="btn text-sm">Not now</button>
                        </div>
                        <p id="cloud-migrate-status" class="hidden mono text-xs mt-2"></p>
                    </div>

                    <div id="cloud-explorer" class="hidden">
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                            <nav id="cloud-breadcrumb" class="mono text-xs flex flex-wrap items-center gap-1 min-w-0"></nav>
                            <div class="flex flex-wrap gap-2 shrink-0">
                                <button type="button" id="cloud-new-folder-btn" class="btn text-xs">New folder</button>
                                <label class="btn text-xs cursor-pointer">
                                    Upload here
                                    <input type="file" id="cloud-upload-input" class="hidden" multiple>
                                </label>
                                <button type="button" id="cloud-disconnect-btn" class="btn-danger text-xs">Disconnect</button>
                            </div>
                        </div>
                        <div id="cloud-drop-zone" class="files-drop-zone mb-4 border border-dashed border-chrome rounded px-4 py-6 text-center mono text-xs">
                            <p class="text-muted">Drop files to upload to this folder</p>
                        </div>
                        <ul id="cloud-file-list" class="space-y-2 text-sm">
                            <li class="text-faint mono text-xs py-2">Loading…</li>
                        </ul>
                    </div>
                </section>

                <section id="local-storage-panel" class="surface p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="section-title">Local storage</h2>
                            <p id="files-quota" class="text-faint mono text-xs mt-1">Loading quota…</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <label class="btn cursor-pointer">
                                Upload file
                                <input type="file" id="files-upload-input" class="hidden" multiple
                                    accept=".jpg,.jpeg,.png,.gif,.webp,.zip,.exe,.msi,image/jpeg,image/png,image/gif,image/webp,application/zip,application/x-msdownload,application/x-msi">
                            </label>
                        </div>
                    </div>
                    <p class="text-faint mono text-xs mb-4">
                        Private by default. Allowed: JPEG, PNG, GIF, WebP, ZIP, EXE, MSI — max 50 MB each.
                    </p>
                    <p id="files-upload-status" class="hidden mono text-xs mb-3"></p>
                    <div id="files-drop-zone" class="files-drop-zone mb-4 border border-dashed border-chrome rounded px-4 py-8 text-center mono text-xs">
                        <p class="text-muted">Drag and drop files here</p>
                        <p class="text-faint mt-1">or use Upload file — multiple files supported</p>
                    </div>
                    <ul id="files-list" class="space-y-2 text-sm">
                        <li class="text-faint mono text-xs py-2">Loading…</li>
                    </ul>
                </section>

                <section class="surface p-4">
                    <h2 class="section-title mb-3">Shared with me</h2>
                    <ul id="files-shared-list" class="space-y-2 text-sm">
                        <li class="text-faint mono text-xs py-2">Loading…</li>
                    </ul>

                <div id="files-sharing-panel" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="files-sharing-title">
                    <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                    <div class="app-modal-panel surface ld-modal-wide">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h2 id="files-sharing-title" class="section-title text-sm">Sharing — <span id="files-sharing-name" class="text-muted font-normal"></span></h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                        </div>
                        <p id="files-sharing-unavailable" class="hidden text-warn text-xs mono mb-4"></p>
                        <p id="files-sharing-help" class="text-faint text-xs mono mb-4">
                            Public links allow download without signing in. In-app shares appear under Shared with me for that user.
                        </p>

                        <div class="grid lg:grid-cols-2 gap-6">
                            <div>
                                <h4 class="text-muted text-xs mono uppercase tracking-wider mb-2">Public link</h4>
                                <form id="files-share-link-form" class="space-y-2 mb-3">
                                    <select id="files-link-expiry" class="input mono text-sm w-full">
                                        <option value="">No expiry</option>
                                        <option value="P1D">1 day</option>
                                        <option value="P7D">7 days</option>
                                        <option value="P30D">30 days</option>
                                        <option value="P90D">90 days</option>
                                    </select>
                                    <input type="text" id="files-link-label" class="input mono text-sm w-full" placeholder="Label (optional)">
                                    <p id="files-share-link-error" class="ld-field-error hidden" role="alert"></p>
                                    <button type="submit" class="btn-primary text-sm">Create share link</button>
                                </form>
                                <ul id="files-share-links" class="space-y-2 text-xs mono"></ul>
                            </div>
                            <div>
                                <h4 class="text-muted text-xs mono uppercase tracking-wider mb-2">Share with user</h4>
                                <form id="files-share-user-form" class="flex flex-wrap gap-2 mb-3">
                                    <input type="text" id="files-share-identifier" class="input mono text-sm flex-1 min-w-[10rem]"
                                        placeholder="Username or email" required>
                                    <button type="submit" class="btn text-sm">Add</button>
                                    <p id="files-share-user-error" class="ld-field-error hidden w-full" role="alert"></p>
                                </form>
                                <ul id="files-share-users" class="space-y-2 text-xs mono"></ul>
                            </div>
                        </div>
                        <button type="button" id="files-sharing-close" class="btn mt-4 text-sm" data-close>Close</button>
                    </div>
                </div>

                <div id="cloud-folder-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="cloud-folder-title">
                    <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                    <div class="app-modal-panel surface">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h2 id="cloud-folder-title" class="section-title text-sm">New folder</h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                        </div>
                        <form id="cloud-folder-form" class="space-y-3">
                            <label class="block">
                                <span class="mono text-xs text-faint">New folder name</span>
                                <input type="text" id="cloud-folder-name" class="input mono text-sm w-full mt-1" autocomplete="off">
                            </label>
                            <p id="cloud-folder-error" class="ld-field-error hidden" role="alert"></p>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="btn-primary text-sm">Create</button>
                                <button type="button" class="btn text-sm" data-close>Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="files-share-copy-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="files-share-copy-title">
                    <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                    <div class="app-modal-panel surface">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h2 id="files-share-copy-title" class="section-title text-sm">Copy this share link</h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                        </div>
                        <input type="text" id="files-share-copy-url" readonly class="input mono text-xs w-full" onclick="this.select()">
                        <button type="button" class="btn text-sm mt-3" data-close>Close</button>
                    </div>
                </div>
                </section>
            </div>

            <div id="cloudpad-tab-panel" class="<?= $initialTab === 'cloudpad' ? '' : 'hidden' ?>">
                <div class="flex flex-col lg:flex-row gap-4 min-h-[70vh]">
                    <aside class="lg:w-56 flex-shrink-0 surface p-3">
                        <div class="flex items-center justify-between mb-1">
                            <h2 class="section-title">Canvases</h2>
                            <span id="cloudpad-canvas-count" class="mono text-xs text-faint">…</span>
                        </div>
                        <button type="button" id="cloudpad-new-canvas" class="text-accent hover:text-accent mono text-lg leading-none mb-3" title="New canvas">+</button>
                        <ul id="cloudpad-canvas-list" class="space-y-1 text-sm">
                            <li class="text-faint mono text-xs py-2">Loading…</li>
                        </ul>
                        <p class="text-faint mono text-xs mt-3">Desktop recommended for editing. Use <strong>Full screen</strong> for sharing controls on small screens.</p>
                    </aside>

                    <section class="flex-1 flex flex-col surface p-4 min-h-[60vh]">
                        <div id="cloudpad-embed-empty" class="flex-1 flex items-center justify-center min-h-[50vh]">
                            <?php
                            $emptyTitle = 'No canvas open';
                            $emptyBody = 'Pick one from the list, or create a canvas to start drawing.';
                            $emptyAction = 'New canvas';
                            $emptyButtonId = 'cloudpad-empty-new';
                            require __DIR__ . '/../partials/empty-panel.php';
                            ?>
                        </div>

                        <div id="cloudpad-embed-editor-wrap" class="hidden flex-1 flex flex-col min-h-[50vh]">
                            <div class="flex flex-wrap items-center gap-2 mb-3 border-b border-chrome pb-3">
                                <input type="text" id="cloudpad-embed-canvas-name" class="input mono text-sm flex-1 min-w-[10rem] max-w-md" placeholder="Canvas name" maxlength="128">
                                <button type="button" id="cloudpad-embed-save-now" class="btn-primary text-xs" title="Save now (Ctrl+S)">Save</button>
                                <button type="button" id="cloudpad-embed-fit-content" class="btn text-xs" title="Fit content">Fit</button>
                                <button type="button" id="cloudpad-embed-export-png" class="btn text-xs">PNG</button>
                                <button type="button" id="cloudpad-embed-save-files" class="btn text-xs">Save to Files</button>
                                <button type="button" id="cloudpad-embed-sharing-jump" class="hidden btn text-xs">Sharing ↓</button>
                                <button type="button" id="cloudpad-embed-sharing-toggle" class="hidden btn text-xs">Sharing</button>
                                <a id="cloudpad-embed-fullscreen" href="#" class="btn text-xs" title="Open full-screen editor">Full screen ↗</a>
                                <span id="cloudpad-embed-save-status" class="text-secondary mono text-xs ml-auto">Loading…</span>
                                <button type="button" id="cloudpad-embed-delete" class="btn-danger text-xs hidden">Delete</button>
                            </div>

                            <div id="cloudpad-embed-control-banner" class="hidden cloudpad-embed-control-banner mono text-xs px-3 py-2 mb-2 rounded"></div>

                            <div id="cloudpad-embed-root" class="cloudpad-root cloudpad-root-embedded flex-1 min-h-[45vh]"></div>

                            <section id="cloudpad-embed-sharing" class="hidden mt-4 pt-4 border-t border-chrome scroll-mt-6">
                                <h3 class="section-title mb-1">Sharing</h3>
                                <p class="text-faint text-xs mono mb-4">Public links are read-only. Logged-in users can be granted write access and edit control.</p>
                                <div class="grid lg:grid-cols-2 gap-6">
                                    <div>
                                        <h4 class="text-muted text-xs mono uppercase tracking-wider mb-2">Public link (read only)</h4>
                                        <form id="cloudpad-embed-share-link-form" class="space-y-2 mb-3">
                                            <select id="cloudpad-embed-link-expiry" class="input mono text-sm w-full">
                                                <option value="">No expiry</option>
                                                <option value="P1D">1 day</option>
                                                <option value="P7D">7 days</option>
                                                <option value="P30D">30 days</option>
                                                <option value="P90D">90 days</option>
                                            </select>
                                            <input type="text" id="cloudpad-embed-link-label" class="input mono text-sm w-full" placeholder="Label (optional)">
                                            <button type="submit" class="btn-primary text-sm">Create share link</button>
                                        </form>
                                        <ul id="cloudpad-embed-share-links" class="space-y-2 text-xs mono"></ul>
                                    </div>
                                    <div>
                                        <h4 class="text-muted text-xs mono uppercase tracking-wider mb-2">Share with user</h4>
                                        <form id="cloudpad-embed-share-user-form" class="flex flex-wrap gap-2 mb-3">
                                            <input type="text" id="cloudpad-embed-share-identifier" class="input mono text-sm flex-1 min-w-[10rem]"
                                                placeholder="Username or email" required>
                                            <select id="cloudpad-embed-user-permission" class="input mono text-sm">
                                                <option value="read">Read</option>
                                                <option value="write">Write</option>
                                            </select>
                                            <button type="submit" class="btn text-sm">Add</button>
                                        </form>
                                        <ul id="cloudpad-embed-share-users" class="space-y-2 text-xs mono"></ul>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </section>
                </div>

                <div id="cloudpad-new-canvas-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="cloudpad-new-canvas-title">
                    <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                    <div class="app-modal-panel surface">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h2 id="cloudpad-new-canvas-title" class="section-title text-sm">New canvas</h2>
                            <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                        </div>
                        <form id="cloudpad-new-canvas-form" class="space-y-3">
                            <label class="block">
                                <span class="mono text-xs text-faint">Canvas name</span>
                                <input type="text" id="cloudpad-new-canvas-name" class="input mono text-sm w-full mt-1" placeholder="Canvas name" maxlength="128" required>
                            </label>
                            <p id="cloudpad-new-canvas-error" class="ld-field-error hidden" role="alert"></p>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="btn-primary text-sm">Create</button>
                                <button type="button" id="cloudpad-new-canvas-cancel" class="btn text-sm" data-close>Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                window.EXCALIDRAW_ASSET_PATH = '/assets/';
                window.CLIPBOARDS_INITIAL_SLUG = <?= json_encode($initialSlug, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                window.CLIPBOARDS_INITIAL_TAB = <?= json_encode($initialTab, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                window.CLIPBOARDS_INITIAL_CANVAS_ID = <?= json_encode($initialCanvasId, JSON_THROW_ON_ERROR) ?>;
                window.CLOUDPAD_BUNDLE_VER = <?= json_encode($assetVer ?? '1', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            </script>

<?php
$settingsScripts = [
    '/assets/js/clipboard-markdown.js?v=' . ($assetVer ?? '1'),
    '/assets/js/clipboards.js?v=' . ($assetVer ?? '1'),
    '/assets/js/files.js?v=' . ($assetVer ?? '1'),
    '/assets/js/files-cloud.js?v=' . ($assetVer ?? '1'),
];
if ($initialTab === 'cloudpad') {
    $settingsScripts[] = '/assets/js/cloudpad-canvas.bundle.js?v=' . ($assetVer ?? '1');
}
$settingsScripts[] = '/assets/js/cloudpad-tab.js?v=' . ($assetVer ?? '1');
require __DIR__ . '/_shell-bottom.php';
