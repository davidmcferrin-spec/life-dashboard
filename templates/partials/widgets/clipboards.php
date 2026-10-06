<?php
/** Cloud clipboards widget (legacy.clipboards) — driven by clipboard-widget.js. */
?>
<div id="clipboard-widget">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2 dashboard-widget-head">
        <div class="flex items-center gap-3">
            <h2 class="section-title">Cloud Clipboards</h2>
            <div id="clip-widget-tabs" class="flex flex-wrap gap-1.5 min-h-[1.5rem]"></div>
        </div>
        <div class="flex items-center gap-3 mono text-xs">
            <span id="clip-widget-status" class="text-secondary mono text-xs"></span>
            <a href="/settings/clipboards#sharing" id="clip-widget-share" class="link text-xs">Sharing →</a>
            <a href="/settings/clipboards" id="clip-widget-open" class="nav-link mono text-xs">Manage</a>
        </div>
    </div>

    <p id="clip-widget-remote" class="hidden mb-2 mono text-xs text-warn">
        Updated elsewhere.
        <button type="button" id="clip-widget-reload" class="underline ml-1">Reload</button>
    </p>

    <p id="clip-widget-empty" class="hidden empty-state mono text-xs py-3 text-center text-muted">
        No clipboards pinned.
        <a href="/settings/clipboards" class="text-accent hover:underline">Open Cloud Clipboards</a>
        and enable <span class="text-secondary">Show on dashboard</span> for a board.
    </p>

    <div id="clip-widget-view-toggle" class="flex flex-wrap items-center gap-2 mb-2">
        <span class="text-faint mono text-xs">View:</span>
        <button type="button" id="clip-widget-view-edit" class="clip-view-tab clip-view-active mono text-xs px-2 py-1 rounded" aria-pressed="true">Edit</button>
        <button type="button" id="clip-widget-view-preview" class="clip-view-tab mono text-xs px-2 py-1 rounded" aria-pressed="false">Preview</button>
    </div>

    <textarea id="clip-widget-editor" rows="4" class="input w-full mono text-sm leading-relaxed ld-fill" style="background: var(--editor-bg);"
        placeholder="Select a pinned clipboard…" spellcheck="false"></textarea>
    <div id="clip-widget-preview" class="clipboard-preview hidden w-full min-h-[6rem] overflow-auto p-3 text-sm leading-relaxed surface" tabindex="0" aria-label="Markdown preview"></div>

    <div class="mt-2 flex justify-end">
        <button type="button" id="clip-widget-copy" class="btn">Copy to local clipboard</button>
    </div>
</div>
