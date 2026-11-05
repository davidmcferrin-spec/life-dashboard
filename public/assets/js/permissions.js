(function () {
    const root = document.getElementById('permissions-app');
    if (!root || !window.LD) return;
    const esc = window.LD.esc;
    const error = document.getElementById('permissions-error');
    const sectors = ['money', 'home', 'mail', 'agents', 'health'];

    function fail(err) {
        error.textContent = err && err.message ? err.message : 'Permissions did not load.';
        error.classList.remove('hidden');
    }

    function paint(data) {
        const head = '<tr><th>Key</th>' + sectors.map(function (sector) { return '<th>' + esc(sector) + '</th>'; }).join('') + '</tr>';
        const rows = (data.principals || []).map(function (principal) {
            const cells = sectors.map(function (sector) {
                const grant = principal.grants[sector] || { mode: 'deny', last_used_at: null };
                const options = ['deny', 'once', 'always'].map(function (mode) {
                    return '<option value="' + mode + '"' + (grant.mode === mode ? ' selected' : '') + '>' + mode + '</option>';
                }).join('');
                return '<td><select data-kind="' + esc(principal.principal_kind) + '" data-id="' + esc(principal.principal_id) + '" data-sector="' + esc(sector) + '">' +
                    options + '</select><div class="platform-meta">' + esc(grant.last_used_at || 'never') + '</div></td>';
            }).join('');
            return '<tr><td>' + esc(principal.name) + '<div class="platform-meta">' + esc(principal.principal_kind) + '</div></td>' + cells + '</tr>';
        }).join('');
        document.getElementById('permissions-table').innerHTML = '<table class="platform-table"><thead>' + head + '</thead><tbody>' +
            (rows || '<tr><td>No keys yet.</td></tr>') + '</tbody></table>';
        const events = (data.events || []).map(function (event) {
            return '<div class="platform-row"><span>' + esc(event.tool || event.action) + ' · ' + esc(event.sector) + '</span><span>' + esc(event.grant_mode || '') + ' · ' + esc(event.created_at) + '</span></div>';
        }).join('');
        document.getElementById('permissions-audit').innerHTML = events || '<p class="platform-meta">No sector acts yet.</p>';
    }

    function load() {
        return Promise.all([
            window.LD.api.get('/grants'),
            window.LD.api.get('/grants/audit'),
        ]).then(function (parts) {
            error.classList.add('hidden');
            paint({ principals: parts[0].principals, events: parts[1].events });
        }).catch(fail);
    }

    document.getElementById('permissions-table').addEventListener('change', function (event) {
        const select = event.target;
        if (!select.matches('select[data-sector]')) return;
        window.LD.api.post('/grants', {
            principal_kind: select.dataset.kind,
            principal_id: Number(select.dataset.id),
            sector: select.dataset.sector,
            mode: select.value,
        }).then(load).catch(fail);
    });
    load();
})();
