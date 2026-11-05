(function () {
    const root = document.getElementById('audit-app');
    if (!root || !window.LD) return;
    const esc = window.LD.esc;
    const error = document.getElementById('audit-error');

    function fail(err) {
        error.textContent = err && err.message ? err.message : 'The audit log did not load.';
        error.classList.remove('hidden');
    }

    window.LD.api.get('/audit').then(function (data) {
        error.classList.add('hidden');
        const rows = (data.events || []).map(function (event) {
            const detail = event.detail || {};
            const sector = detail.sector ? ' · ' + detail.sector : '';
            const tool = event.tool || event.action || 'act';
            return '<div class="platform-row"><span>' + esc(tool) + esc(sector) + '</span><span>' +
                esc(event.actor || '') + ' · ' + esc(event.created_at || '') + '</span></div>';
        }).join('');
        root.innerHTML = rows || '<p class="platform-meta">No acts yet.</p>';
    }).catch(fail);
})();
