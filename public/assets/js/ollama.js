/**
 * Local AI settings: save, test, pull, remove, and live status.
 */
'use strict';

(function () {
    const root = document.getElementById('ollama-app');
    if (!root || !window.LD) {
        return;
    }

    const health = root.querySelector('[data-ollama-health]');
    const hardware = root.querySelector('[data-ollama-hardware]');
    const job = root.querySelector('[data-ollama-job]');
    const models = root.querySelector('[data-ollama-models]');
    const error = root.querySelector('[data-ollama-error]');
    const testResult = root.querySelector('[data-ollama-test-result]');

    function showError(err) {
        const fields = err && err.fields ? err.fields : {};
        const first = Object.values(fields)[0];
        error.textContent = first || (err && err.message) || 'Could not save.';
    }

    function hardwareText(row) {
        if (!row || !row.accelerator) {
            return 'Hardware has not been recorded yet.';
        }
        return 'Accelerator ' + row.accelerator + ', RAM ' + row.ram_mib + ' MiB, VRAM ' + row.vram_mib + ' MiB. Defaults ' + row.chat_model + ' and ' + row.embed_model + '.';
    }

    function renderModels(list) {
        models.textContent = '';
        if (!list || list.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-sm text-muted';
            empty.textContent = 'No models installed yet.';
            models.appendChild(empty);
            return;
        }
        list.forEach(function (item) {
            const row = document.createElement('div');
            row.className = 'ld-ollama-row';
            const name = document.createElement('span');
            name.className = 'text-sm';
            name.textContent = item.name;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn';
            button.textContent = 'Remove';
            button.addEventListener('click', function () {
                removeModel(item.name);
            });
            row.appendChild(name);
            row.appendChild(button);
            models.appendChild(row);
        });
    }

    function render(data) {
        if (!data || !data.health) {
            return;
        }
        health.textContent = data.health.detail || '';
        health.dataset.status = data.health.status || 'ok';
        hardware.textContent = hardwareText(data.hardware);
        job.textContent = data.job && data.job.detail && data.job.state !== 'idle' ? data.job.detail : '';
        renderModels(data.models || []);
    }

    function refresh() {
        window.LD.api.get('/ollama/status').then(render).catch(function () {});
    }

    function removeModel(name) {
        error.textContent = '';
        window.LD.api.post('/ollama/remove', { model: name }).then(function () {
            refresh();
        }).catch(showError);
    }

    root.querySelector('[data-ollama-settings]').addEventListener('submit', function (event) {
        event.preventDefault();
        error.textContent = '';
        const form = event.currentTarget;
        window.LD.api.post('/ollama/settings', {
            base_url: form.base_url.value,
            chat_model: form.chat_model.value,
            embed_model: form.embed_model.value,
        }).then(function () {
            refresh();
        }).catch(showError);
    });

    root.querySelector('[data-ollama-pull]').addEventListener('submit', function (event) {
        event.preventDefault();
        error.textContent = '';
        const form = event.currentTarget;
        window.LD.api.post('/ollama/pull', { model: form.model.value }).then(function () {
            form.model.value = '';
            refresh();
        }).catch(showError);
    });

    root.querySelector('[data-ollama-test]').addEventListener('click', function () {
        testResult.textContent = '';
        window.LD.api.post('/ollama/test', {}).then(function (data) {
            testResult.textContent = data.detail || '';
        }).catch(function (err) {
            testResult.textContent = err.message || 'Ollama did not answer.';
        });
    });

    models.querySelectorAll('[data-ollama-remove]').forEach(function (button) {
        button.addEventListener('click', function () {
            removeModel(button.getAttribute('data-ollama-remove'));
        });
    });

    window.setInterval(refresh, 5000);
})();
