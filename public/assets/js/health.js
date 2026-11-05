(function () {
    const root = document.getElementById('health-app');
    if (!root || !window.LD) return;
    const esc = window.LD.esc;
    const error = document.getElementById('health-error');

    function showError(err) {
        error.textContent = err && err.message ? err.message : 'Health did not load.';
        error.classList.remove('hidden');
    }

    function line(label, value) {
        if (value === null || value === undefined || value === '') return '';
        return '<div class="platform-row"><span>' + esc(label) + '</span><span>' + esc(value) + '</span></div>';
    }

    function paint(status, readiness, workouts) {
        const row = (readiness && readiness.row) || (status && status.latest) || null;
        const ow = (status && status.open_wearables) || {};
        const sync = (status && status.sync) || {};
        const score = row && row.readiness_score !== null && row.readiness_score !== undefined ? String(row.readiness_score) : '—';
        const confidence = row ? String(row.readiness_confidence) : '0';
        document.getElementById('health-hero').innerHTML =
            '<p class="platform-kicker">Readiness</p>' +
            '<p class="platform-score">' + esc(score) + '</p>' +
            '<p class="platform-meta">Confidence ' + esc(confidence) + (row && row.day ? ' · ' + esc(row.day) : '') + '</p>' +
            line('Sleep (min)', row && row.sleep_minutes) +
            line('Sleep source', row && row.sleep_authority) +
            line('HRV (ms)', row && row.hrv_ms) +
            line('HRV source', row && row.hrv_authority) +
            line('Steps', row && row.steps) +
            line('Steps source', row && row.steps_authority) +
            line('Resting HR', row && row.resting_hr);
        document.getElementById('health-source').innerHTML =
            '<p class="platform-kicker">Open Wearables</p>' +
            '<p class="platform-meta">' + (ow.configured ? 'Connected' : 'Not connected') + '</p>' +
            line('URL', ow.api_url) +
            line('User', ow.user_id) +
            line('Last sync', sync.last_at) +
            line('Last error', sync.last_error) +
            '<p class="platform-meta">Samsung Health and Health Connect are the primary path. Garmin, WHOOP, and Oura can be stored later. The API key stays in Settings.</p>';
        const list = (workouts && workouts.workouts) || [];
        document.getElementById('health-workouts').innerHTML = list.length
            ? list.map(function (item) {
                return '<div class="platform-row"><span>' + esc(item.title) + '</span><span>' + esc(item.minutes) + ' min · ' + esc(item.authority) + '</span></div>';
            }).join('')
            : '<p class="platform-meta">No workouts stored.</p>';
    }

    function load() {
        return Promise.all([
            window.LD.api.get('/health/status'),
            window.LD.api.get('/health/readiness'),
            window.LD.api.get('/health/workouts'),
        ]).then(function (parts) {
            error.classList.add('hidden');
            paint(parts[0], parts[1], parts[2]);
        }).catch(showError);
    }

    document.getElementById('health-sync').addEventListener('click', function () {
        window.LD.api.post('/health/sync', {}).then(load).catch(showError);
    });
    document.getElementById('health-workout-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(event.target);
        window.LD.api.post('/health/workouts', {
            title: data.get('title'),
            minutes: Number(data.get('minutes')),
            note: data.get('note') || undefined,
        }).then(function () { event.target.reset(); return load(); }).catch(showError);
    });
    document.getElementById('health-meal-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(event.target);
        const body = { note: data.get('note') };
        if (data.get('calories')) body.calories = Number(data.get('calories'));
        window.LD.api.post('/health/meals', body).then(function () { event.target.reset(); }).catch(showError);
    });
    document.getElementById('health-body-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(event.target);
        window.LD.api.post('/health/body', { weight_lb: Number(data.get('weight_lb')) }).then(function () {
            event.target.reset();
            return load();
        }).catch(showError);
    });
    load();
})();
