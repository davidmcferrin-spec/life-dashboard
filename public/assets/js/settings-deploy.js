/**
 * Settings → System: queue a main-branch update, or dry-run the same check.
 */
'use strict';

(function () {
    const shaInput = document.getElementById('deploy-confirm-sha');
    const result = document.getElementById('deploy-result');
    const logEl = document.getElementById('deploy-log');
    const dry = document.getElementById('deploy-dry-run');
    const now = document.getElementById('deploy-now');
    if (!shaInput || !result || !dry || !now || !window.LD) return;

    const api = window.LD.api;

    async function showLog(jobId) {
        if (!logEl) return;
        const path = jobId ? ('/system/deploy/log?job_id=' + encodeURIComponent(jobId)) : '/system/deploy/log';
        try {
            const data = await api.get(path);
            logEl.textContent = data.log || '';
            logEl.classList.toggle('hidden', !data.log);
        } catch (err) {
            logEl.textContent = '';
            logEl.classList.add('hidden');
        }
    }

    async function submit(dryRun) {
        result.textContent = '';
        dry.disabled = true;
        now.disabled = true;
        try {
            const data = await api.post('/system/deploy', {
                confirm_sha: shaInput.value,
                dry_run: dryRun,
            });
            if (data.dry_run) {
                result.textContent = 'Dry run matched origin/main. Nothing was queued.';
                return;
            }
            const jobId = data.job_id || (data.job && data.job.id) || '';
            result.textContent = 'Queued ' + jobId + '. The update restarts services. Refresh this page for the result.';
            window.setTimeout(function () { showLog(jobId); }, 2000);
        } catch (err) {
            result.textContent = err.message || 'Could not queue the update.';
        } finally {
            dry.disabled = shaInput.value === '' || dry.dataset.ready === '0';
            now.disabled = shaInput.value === '' || now.dataset.spool === '0' || dry.dataset.ready === '0';
        }
    }

    dry.dataset.ready = dry.disabled ? '0' : '1';
    if (!now.disabled) now.dataset.spool = '1';
    else now.dataset.spool = '0';

    dry.addEventListener('click', function () { submit(true); });
    now.addEventListener('click', function () { submit(false); });

    const toggle = document.getElementById('mcp-updates');
    const state = document.getElementById('mcp-updates-state');
    if (toggle) {
        toggle.addEventListener('change', async () => {
            const want = toggle.checked;
            toggle.disabled = true;
            try {
                const data = await api.post('/system/deploy/mcp-updates', { enabled: want });
                if (state) state.textContent = data.mcp_updates ? 'on' : 'off';
                result.textContent = data.mcp_updates
                    ? 'MCP updates are allowed.'
                    : 'MCP updates are disabled.';
            } catch (err) {
                toggle.checked = !want;
                result.textContent = err.message || 'Could not save MCP updates.';
            } finally {
                toggle.disabled = false;
            }
        });
    }
})();
