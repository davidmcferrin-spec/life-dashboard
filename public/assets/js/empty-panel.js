/**
 * Shared empty panel: title, one sentence, one action.
 * actionAttrs is a static attribute string from our own scripts.
 */
'use strict';

(function () {
    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function html(opts) {
        opts = opts || {};
        var action = '';
        if (opts.actionLabel && opts.actionHref) {
            action = '<a class="btn-primary" href="' + esc(opts.actionHref) + '">' + esc(opts.actionLabel) + '</a>';
        } else if (opts.actionLabel) {
            action = '<button ' + (opts.actionAttrs || 'type="button"') + ' class="btn-primary">' + esc(opts.actionLabel) + '</button>';
        }
        return '<div class="empty-panel">'
            + '<span class="empty-panel-mark" aria-hidden="true"></span>'
            + '<p class="empty-panel-title">' + esc(opts.title || '') + '</p>'
            + '<p class="empty-panel-body">' + esc(opts.body || '') + '</p>'
            + action
            + '</div>';
    }

    window.DashboardEmpty = { html: html };
})();
