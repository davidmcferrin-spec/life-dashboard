/**
 * csrf.js — shared CSRF token helper (load once before other app scripts)
 */
'use strict';

window.getCsrfToken = function () {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
};
