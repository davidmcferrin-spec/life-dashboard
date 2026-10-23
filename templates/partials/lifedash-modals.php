<?php
/**
 * Dashboard grid modals — "Add widget" catalog and per-widget configure/history.
 * The catalog list is filled by lifedash-grid.js from /api/v1/widget-types.
 * Groups live in widgets.js (no registry category field).
 */
?>
<div id="ld-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="ld-add-title">
    <div class="app-modal-backdrop" data-close></div>
    <div class="app-modal-panel surface ld-catalog-panel">
        <div class="ld-catalog-head">
            <h3 id="ld-add-title" class="ld-catalog-title">Add widget</h3>
            <button type="button" class="app-modal-close" data-close aria-label="Close"><span class="ld-catalog-x"><?php
                readfile(__DIR__ . '/../../public/assets/vendor/lucide/x.svg');
            ?></span></button>
        </div>
        <p class="ld-catalog-lead">Choose a widget to add to your home board.</p>
        <label class="ld-catalog-search">
            <span class="ld-catalog-search-icon" aria-hidden="true"><?php
                readfile(__DIR__ . '/../../public/assets/vendor/lucide/search.svg');
            ?></span>
            <input id="ld-catalog-query" type="search" placeholder="Search widgets" aria-label="Search widgets" autocomplete="off" enterkeyhint="search">
        </label>
        <div id="ld-add-list" class="ld-catalog"></div>
    </div>
</div>

<div id="ld-config-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="ld-config-title">
    <div class="app-modal-backdrop" data-close></div>
    <div class="app-modal-panel surface ld-modal-wide">
        <div class="flex items-center justify-between gap-2 mb-3">
            <h3 id="ld-config-title" class="section-title text-sm">Configure widget</h3>
            <button type="button" class="app-modal-close" data-close aria-label="Close"><span class="ld-catalog-x"><?php
                readfile(__DIR__ . '/../../public/assets/vendor/lucide/x.svg');
            ?></span></button>
        </div>
        <div class="seg-group mb-3" id="ld-config-tabs">
            <button type="button" class="seg-btn seg-btn-active" data-tab="settings">Settings</button>
            <button type="button" class="seg-btn" data-tab="history">History</button>
        </div>
        <form id="ld-config-form" class="space-y-3" novalidate>
            <div id="ld-config-fields" class="space-y-3"></div>
            <p id="ld-config-error" class="text-danger mono text-xs hidden" role="alert"></p>
            <div class="flex flex-wrap gap-2 pt-1">
                <button type="submit" class="btn-primary text-sm" id="ld-config-save">Save</button>
                <button type="button" class="btn text-sm" data-close>Cancel</button>
            </div>
        </form>
        <div id="ld-config-history" class="hidden">
            <ul id="ld-history-list" class="ld-history"></ul>
        </div>
    </div>
</div>
<script src="/assets/js/widgets.js?v=<?= e($assetVer) ?>"></script>
