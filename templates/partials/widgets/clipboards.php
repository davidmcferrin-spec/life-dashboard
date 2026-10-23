<?php
/** Cloud clipboards widget (legacy.clipboards) — driven by clipboard-widget.js. */
if (empty($ldMediaWidgetsCss)) {
    $ldMediaWidgetsCss = true;
    ?>
<link rel="stylesheet" href="/assets/css/widgets-media.css?v=<?= e($assetVer ?? '') ?>">
    <?php
}
?>
<div id="clipboard-widget" class="ld-mk ld-mk-clip">
    <div class="ld-mk-clip-main">
        <div class="ld-mk-head dashboard-widget-head">
            <h2 class="section-title ld-mk-heading">
                <span class="ld-mk-ico" aria-hidden="true"><?php readfile(__DIR__ . '/../../../public/assets/vendor/lucide/file-text.svg'); ?></span>
                Cloud Clipboards
            </h2>
            <div class="ld-mk-tools">
                <span id="clip-widget-status" class="ld-mk-status"></span>
                <a href="/settings/clipboards#sharing" id="clip-widget-share" class="ld-mk-link">Sharing</a>
                <a href="/settings/clipboards" id="clip-widget-open" class="ld-mk-link">Manage
                    <span class="ld-mk-ico" aria-hidden="true"><?php readfile(__DIR__ . '/../../../public/assets/vendor/lucide/chevron-right.svg'); ?></span>
                </a>
            </div>
        </div>

        <div id="clip-widget-tabs"></div>

        <p id="clip-widget-remote" class="hidden text-warn">
            Updated elsewhere.
            <button type="button" id="clip-widget-reload" class="ld-mk-link">Reload</button>
        </p>

        <div id="clip-widget-empty" class="hidden">
            <?php
            $emptyTitle = 'No clipboards pinned';
            $emptyBody = 'Open Cloud Clipboards and turn on Show on dashboard for a board.';
            $emptyAction = 'Open Cloud Clipboards';
            $emptyHref = '/settings/clipboards';
            require __DIR__ . '/../empty-panel.php';
            ?>
        </div>

        <div id="clip-widget-view-toggle">
            <span class="ld-mk-view-label">View</span>
            <button type="button" id="clip-widget-view-edit" class="clip-view-tab clip-view-active" aria-pressed="true">Edit</button>
            <button type="button" id="clip-widget-view-preview" class="clip-view-tab" aria-pressed="false">Preview</button>
        </div>

        <button type="button" id="clip-widget-copy" class="btn">Copy to local clipboard</button>
    </div>

    <textarea id="clip-widget-editor" rows="4" class="input w-full ld-fill"
        placeholder="Select a pinned clipboard…" spellcheck="false"></textarea>
    <div id="clip-widget-preview" class="clipboard-preview hidden w-full overflow-auto" tabindex="0" aria-label="Markdown preview"></div>
</div>
