(function () {
    const root = document.getElementById('agents-app');
    if (!root || !window.LD) return;
    const esc = window.LD.esc;
    const error = document.getElementById('agents-error');
    let runId = '';

    function fail(err) {
        error.textContent = err && err.message ? err.message : 'Agents did not load.';
        error.classList.remove('hidden');
    }

    function card(title, lines) {
        return '<article class="platform-card"><p class="platform-kicker">' + esc(title) + '</p>' +
            lines.map(function (line) { return '<p class="platform-meta">' + esc(line) + '</p>'; }).join('') +
            '</article>';
    }

    function load() {
        return Promise.all([
            window.LD.api.get('/agents/hermes/status'),
            window.LD.api.get('/agents/hermes/sessions'),
            window.LD.api.get('/agents/opencode/status'),
            window.LD.api.get('/agents/openclaw/status'),
        ]).then(function (parts) {
            error.classList.add('hidden');
            const hermes = parts[0];
            const sessions = (parts[1].sessions || []).map(function (row) { return row.title || row.id; });
            document.getElementById('agents-cards').innerHTML = [
                card('Hermes', [
                    hermes.gateway && hermes.gateway.detail ? hermes.gateway.detail : 'No gateway detail.',
                    hermes.version ? 'Version ' + hermes.version : 'Version not reported.',
                    sessions.length ? 'Sessions: ' + sessions.join(', ') : 'No sessions yet.',
                ]),
                card('OpenCode', [parts[2].detail || 'No detail.']),
                card('OpenClaw', [parts[3].detail || 'No detail.']),
            ].join('');
        }).catch(fail);
    }

    document.getElementById('agents-send').addEventListener('submit', function (event) {
        event.preventDefault();
        const message = new FormData(event.target).get('message');
        window.LD.api.post('/agents/hermes/runs', { message: message }).then(function (data) {
            runId = data.run_id || '';
            document.getElementById('agents-output').textContent = 'Started ' + runId;
            event.target.reset();
        }).catch(fail);
    });
    document.getElementById('agents-poll').addEventListener('click', function () {
        if (!runId) { fail({ message: 'Send a message first.' }); return; }
        window.LD.api.get('/agents/hermes/runs/' + encodeURIComponent(runId)).then(function (data) {
            document.getElementById('agents-output').textContent = (data.status || '') + (data.output ? '\n' + data.output : '');
        }).catch(fail);
    });
    document.getElementById('agents-stop').addEventListener('click', function () {
        if (!runId) { fail({ message: 'Send a message first.' }); return; }
        window.LD.api.post('/agents/hermes/runs/' + encodeURIComponent(runId) + '/stop', {}).then(function (data) {
            document.getElementById('agents-output').textContent = data.detail || (data.supported ? 'Stopped.' : 'Stop is not supported.');
        }).catch(fail);
    });
    document.getElementById('agents-ask').addEventListener('submit', function (event) {
        event.preventDefault();
        const prompt = new FormData(event.target).get('prompt');
        window.LD.api.post('/agents/openclaw/ask', { prompt: prompt }).then(function (data) {
            document.getElementById('agents-output').textContent = data.output || data.detail || '';
        }).catch(fail);
    });
    load();
})();
