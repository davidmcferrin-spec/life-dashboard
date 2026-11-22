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

    function paintMeals(payload) {
        const list = (payload && payload.meals) || [];
        document.getElementById('health-meals').innerHTML = list.length
            ? list.map(function (meal) {
                const where = meal.eaten_out ? (meal.place || 'Out') : (meal.place || 'At home');
                const cost = meal.cost === null || meal.cost === undefined ? '' : ' · $' + meal.cost;
                return '<div class="platform-row"><span>' + esc(meal.meal_type) + ' · ' + esc(meal.description) + '</span><span>' + esc(meal.date) + ' · ' + esc(where) + esc(cost) + '</span></div>';
            }).join('')
            : '<p class="platform-meta">No meals logged in this range.</p>';
    }

    function paintPressure(payload) {
        const rows = (payload && payload.readings) || [];
        const avg = (payload && payload.average) || {};
        const summary = avg.count
            ? '<p class="platform-meta">Average ' + esc(avg.systolic) + '/' + esc(avg.diastolic) + (avg.pulse ? ' · pulse ' + esc(avg.pulse) : '') + ' · ' + esc(avg.count) + '</p>'
            : '<p class="platform-meta">No readings in this range.</p>';
        const list = rows.slice(0, 5).map(function (row) {
            const pulse = row.pulse ? ' · ' + row.pulse : '';
            return '<div class="platform-row"><span>' + esc(row.systolic) + '/' + esc(row.diastolic) + esc(pulse) + '</span><span>' + esc(row.taken_at) + '</span></div>';
        }).join('');
        document.getElementById('health-bp').innerHTML = summary + list;
    }

    function paintMeds(payload) {
        const rows = (payload && payload.medications) || [];
        document.getElementById('health-meds').innerHTML = rows.length
            ? rows.map(function (row) {
                const detail = [row.dose, row.schedule].filter(Boolean).join(' · ');
                return '<div class="platform-row"><span>' + esc(row.name) + '</span><span>' + esc(detail) + '</span></div>';
            }).join('')
            : '<p class="platform-meta">No active medications.</p>';
    }

    function paintAppointments(payload) {
        const rows = (payload && payload.appointments) || [];
        document.getElementById('health-appts').innerHTML = rows.length
            ? rows.map(function (row) {
                const detail = [row.specialty, row.location].filter(Boolean).join(' · ');
                return '<div class="platform-row"><span>' + esc(row.provider) + (detail ? ' · ' + esc(detail) : '') + '</span><span>' + esc(row.starts_at) + '</span></div>';
            }).join('')
            : '<p class="platform-meta">No upcoming appointments.</p>';
    }

    function load() {
        return Promise.all([
            window.LD.api.get('/health/status'),
            window.LD.api.get('/health/readiness'),
            window.LD.api.get('/health/workouts'),
            window.LD.api.get('/health/meal-log'),
            window.LD.api.get('/health/blood-pressure'),
            window.LD.api.get('/health/medications?active=1'),
            window.LD.api.get('/health/appointments?upcoming=1'),
        ]).then(function (parts) {
            error.classList.add('hidden');
            paint(parts[0], parts[1], parts[2]);
            paintMeals(parts[3]);
            paintPressure(parts[4]);
            paintMeds(parts[5]);
            paintAppointments(parts[6]);
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
        const body = {
            meal_type: data.get('meal_type'),
            description: data.get('description'),
            eaten_out: data.get('eaten_out') === 'on',
        };
        if (data.get('place')) body.place = data.get('place');
        if (data.get('cost')) body.cost = Number(data.get('cost'));
        window.LD.api.post('/health/meal-log', body).then(function () {
            event.target.reset();
            return load();
        }).catch(showError);
    });
    document.getElementById('health-bp-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(event.target);
        const body = { systolic: Number(data.get('systolic')), diastolic: Number(data.get('diastolic')) };
        if (data.get('pulse')) body.pulse = Number(data.get('pulse'));
        window.LD.api.post('/health/blood-pressure', body).then(function () {
            event.target.reset();
            return load();
        }).catch(showError);
    });
    document.getElementById('health-med-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(event.target);
        const body = { name: data.get('name') };
        if (data.get('dose')) body.dose = data.get('dose');
        if (data.get('schedule')) body.schedule = data.get('schedule');
        window.LD.api.post('/health/medications', body).then(function () {
            event.target.reset();
            return load();
        }).catch(showError);
    });
    document.getElementById('health-appt-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(event.target);
        const body = {
            provider: data.get('provider'),
            starts_at: data.get('starts_at'),
        };
        if (data.get('specialty')) body.specialty = data.get('specialty');
        if (data.get('location')) body.location = data.get('location');
        window.LD.api.post('/health/appointments', body).then(function () {
            event.target.reset();
            return load();
        }).catch(showError);
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
