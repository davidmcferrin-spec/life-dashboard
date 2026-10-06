/**
 * share-file.js — public shared file download page
 */
'use strict';

const token = window.SHARE_FILE_TOKEN || '';

function denyAccess() {
    window.location.replace('/share/denied');
}

async function loadFile() {
    const wrap  = document.getElementById('share-file-wrap');
    const status = document.getElementById('share-file-status');

    if (!token) {
        denyAccess();
        return;
    }

    try {
        const res = await fetch('/api/share/file/' + encodeURIComponent(token), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (res.status === 403) {
            denyAccess();
            return;
        }

        const data = await res.json();

        if (!res.ok || !data.file) {
            denyAccess();
            return;
        }

        const file = data.file;
        const nameEl = document.getElementById('share-file-name');
        const metaEl = document.getElementById('share-file-meta');
        const dlEl   = document.getElementById('share-file-download');

        if (nameEl) nameEl.textContent = file.original_name || 'File';
        if (metaEl) {
            const size = Number(file.size_bytes) || 0;
            const sizeStr = size < 1024 * 1024
                ? `${Math.round(size / 1024)} KB`
                : `${(size / (1024 * 1024)).toFixed(1)} MB`;
            metaEl.textContent = `${(file.ext || '').toUpperCase()} · ${sizeStr}`;
        }
        if (dlEl) {
            dlEl.href = '/api/share/file/' + encodeURIComponent(token) + '/download';
            dlEl.setAttribute('download', file.original_name || 'download');
        }

        wrap?.classList.remove('hidden');
        if (status) status.textContent = 'Read-only share';
    } catch (_) {
        denyAccess();
    }
}

loadFile();
