(function () {
    const root = document.getElementById('brief-app');
    if (!root || !window.LD) return;
    const esc = window.LD.esc;
    const error = document.getElementById('brief-error');
    const out = document.getElementById('brief-output');
    const history = document.getElementById('brief-history');

    function fail(err) {
        error.textContent = err && err.message ? err.message : 'The brief did not load.';
        error.classList.remove('hidden');
    }

    function paint(data) {
        const rows = (data && data.briefs) || [];
        history.innerHTML = rows.length ? rows.map(function (row) {
            return '<article class="platform-card"><p class="platform-meta">' + esc(row.composed_at) + '</p><p class="platform-pre">' + esc(row.body) + '</p></article>';
        }).join('') : '<p class="platform-meta">No briefs yet.</p>';
    }

    function load() {
        return window.LD.api.get('/brief/history').then(function (data) {
            error.classList.add('hidden');
            paint(data);
        }).catch(fail);
    }

    document.getElementById('brief-now').addEventListener('click', function () {
        window.LD.api.post('/brief', {}).then(function (data) {
            out.textContent = data.brief && data.brief.body ? data.brief.body : '';
            return load();
        }).catch(fail);
    });
    document.getElementById('brief-schedule').addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(event.target);
        window.LD.api.post('/brief/schedule', {
            enabled: data.get('enabled') === '1',
            local_time: data.get('local_time'),
        }).then(function () {
            error.classList.add('hidden');
        }).catch(fail);
    });
    window.LD.api.get('/brief/schedule').then(function (data) {
        const form = document.getElementById('brief-schedule');
        form.local_time.value = data.local_time || '07:00';
        form.enabled.checked = !!data.enabled;
    }).catch(function () {});
    load();
})();
