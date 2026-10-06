/**
 * admin-cloud-storage.js — admin shared cloud file settings (folder name, quota)
 */
(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);

    async function apiJson(url, options = {}) {
        const headers = {
            Accept: 'application/json',
            ...(options.headers || {}),
        };

        if (options.method && options.method !== 'GET') {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = getCsrfToken();
        }

        const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
        const text = await res.text();
        let data = {};
        try {
            data = JSON.parse(text);
        } catch (_) {
            if (text.trim()) data = { error: text.trim().slice(0, 240) };
        }
        return { res, data };
    }

    function setMsg(el, text, ok) {
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('text-ok', ok === true);
        el.classList.toggle('text-danger', ok === false);
        el.classList.toggle('text-muted', ok === undefined && !!text);
    }

    function formPayload() {
        return {
            cloud_root_folder_name: ($('cloud-root-folder')?.value || '').trim(),
            cloud_files_quota_bytes: ($('cloud-quota-bytes')?.value || '').trim(),
        };
    }

    function applyConfig(data) {
        if ($('cloud-root-folder')) $('cloud-root-folder').value = data.cloud_root_folder_name || 'PersonalDashboard';
        if ($('cloud-quota-bytes')) $('cloud-quota-bytes').value = String(data.cloud_files_quota_bytes || '');

        const sourceEl = $('cloud-admin-source');
        if (sourceEl) {
            sourceEl.textContent = data.source === 'database'
                ? 'Active settings: encrypted database (overrides .env).'
                : 'Active settings: .env file (nothing saved in database yet).';
        }
    }

    async function loadStatus() {
        const { res, data } = await apiJson('/api/admin/cloud-storage');
        if (!res.ok) {
            setMsg($('cloud-admin-save-msg'), data.error || 'Could not load settings.', false);
            return;
        }
        applyConfig(data);
    }

    async function saveSettings() {
        setMsg($('cloud-admin-save-msg'), 'Saving…', undefined);
        const { res, data } = await apiJson('/api/admin/cloud-storage', {
            method: 'POST',
            body: JSON.stringify(formPayload()),
        });

        if (!res.ok) {
            setMsg($('cloud-admin-save-msg'), data.error || 'Save failed.', false);
            return;
        }

        if (data.config) applyConfig(data.config);
        setMsg($('cloud-admin-save-msg'), data.message || 'Saved.', true);
    }

    $('cloud-admin-save')?.addEventListener('click', saveSettings);

    loadStatus();
}());
