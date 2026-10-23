/**
 * lifedash-board.js — assistant, clock, and feature widgets.
 *
 * Loaded after lifedash-renderers.js and before the grid so these
 * renderers exist on the first paint. News and clipboard renderers stay
 * in lifedash-renderers.js.
 */
'use strict';

(function () {
    const esc = window.LD.esc;
    let recorder = null;
    let chunks = [];

    function ico(name) {
        const tpl = document.getElementById('ld-ico-' + name);
        return tpl && tpl.innerHTML.indexOf('<svg') !== -1 ? tpl.innerHTML : '';
    }

    function assistant(ctx) {
        let status = ctx.data && ctx.data.status ? String(ctx.data.status) : 'Nothing is scheduled.';
        if (status.indexOf('All systems are calm') !== -1 || status.indexOf('42 minutes') !== -1) {
            status = 'Nothing is scheduled.';
        }
        const active = typeof getActiveEngine === 'function'
            ? getActiveEngine()
            : (ctx.data && ctx.data.engine) || 'local';
        const engines = (ctx.data && ctx.data.engines) || [];
        const chips = engines.map((engine) => {
            const on = engine.key === active ? ' seg-btn-active' : '';
            const shield = engine.local
                ? `<span class="ld-assistant-shield" aria-hidden="true">${ico('shield-check')}</span>`
                : '';
            return `<button type="button" class="search-engine-btn seg-btn ld-assistant-engine${on}" data-engine="${esc(engine.key)}" data-url="${esc(engine.url)}" data-param="${esc(engine.param)}">${shield}${esc(engine.label)}</button>`;
        }).join('');
        ctx.body.innerHTML = `
            <div class="ld-assistant">
                <p class="ld-assistant-live"><span class="ld-assistant-orb" aria-hidden="true">${ico('brain')}</span> Assistant online</p>
                <h3 class="ld-assistant-title">How can I assist you?</h3>
                <p class="ld-assistant-status">${esc(status)}</p>
                <form class="ld-assistant-form">
                    <span class="ld-assistant-command" aria-hidden="true">${ico('command')}</span>
                    <input class="ld-assistant-input input" type="text" name="q" placeholder="How can I assist you?" autocomplete="off" aria-label="How can I assist you?">
                    <button type="button" class="btn ld-assistant-mic" aria-pressed="false" aria-label="Dictate">${ico('mic')}</button>
                    <button type="submit" class="btn-primary ld-assistant-send" aria-label="Send">${ico('arrow-up-right')}</button>
                </form>
                <p class="ld-assistant-engines-label">Search with</p>
                <div class="ld-assistant-engines">${chips}</div>
            </div>`;
    }

    function clock(ctx) {
        const format = ctx.data && ctx.data.format === '24h' ? '24h' : '12h';
        const zones = (ctx.data && ctx.data.zones) || [];
        const cells = zones.map((zone) => `
            <div class="clock-cell text-center">
                <div class="clock-label">${esc(zone.label)}</div>
                <div class="clock-hover">
                    <div class="clock-time clock" data-tz="${esc(zone.tz)}" tabindex="0">--:--</div>
                    <div class="clock-popover" role="tooltip" aria-hidden="true">
                        <div class="clock-popover-offset"></div>
                        <div class="clock-popover-jam"></div>
                        <button type="button" class="clock-jam-btn">Jam (Alt+J)</button>
                    </div>
                </div>
                <div class="clock-ampm ampm" data-tz="${esc(zone.tz)}"></div>
            </div>`).join('');
        const week = ctx.data && ctx.data.broadcast_week
            ? `<div id="broadcast-week-line" class="broadcast-week-line" aria-live="polite"><p class="broadcast-week-primary"></p><p class="broadcast-week-detail"></p></div>`
            : '';
        ctx.body.innerHTML = `<div id="timezone-bar" class="ld-clock" data-clock-format="${format}">${cells}${week}</div>`;
        if (ctx.tools) ctx.tools.innerHTML = '<a class="ld-tool-link" href="/clock">Focus clock</a>';
        if (typeof updateClocks === 'function') updateClocks();
    }

    function features(ctx) {
        const rows = (ctx.data && ctx.data.features) || [];
        ctx.body.innerHTML = `<div class="ld-features">${rows.map((row) => `
            <div class="ld-feature">
                <span class="ld-feature-icon" aria-hidden="true">${ico(row.icon)}</span>
                <span class="ld-feature-copy">
                    <strong>${esc(row.label)}</strong>
                    <span>${esc(row.detail)}</span>
                </span>
                <button type="button" class="ld-toggle" data-feature="${esc(row.key)}" aria-pressed="${row.enabled ? 'true' : 'false'}">${row.enabled ? 'On' : 'Off'}</button>
            </div>`).join('')}</div>`;
    }

    async function transcribe(blob) {
        const body = new FormData();
        body.append('audio', blob, 'speech.webm');
        const res = await fetch('/api/v1/speech/transcribe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-Token': window.getCsrfToken ? window.getCsrfToken() : '',
            },
            body,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || 'Speech recognition failed.');
        return String(data.text || '');
    }

    async function toggleMic(button, input) {
        if (recorder && recorder.state === 'recording') {
            recorder.stop();
            return;
        }
        if (!navigator.mediaDevices || typeof MediaRecorder === 'undefined') {
            window.LD.status('This browser has no microphone recorder.', 'error');
            return;
        }
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (err) {
            window.LD.status('The microphone is unavailable.', 'error');
            return;
        }
        const rec = new MediaRecorder(stream);
        chunks = [];
        rec.addEventListener('dataavailable', (ev) => {
            if (ev.data && ev.data.size) chunks.push(ev.data);
        });
        rec.addEventListener('stop', async () => {
            stream.getTracks().forEach((track) => track.stop());
            button.setAttribute('aria-pressed', 'false');
            recorder = null;
            const blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
            chunks = [];
            try {
                const text = await transcribe(blob);
                if (input) input.value = text;
                const search = document.getElementById('search-input');
                if (search) search.value = text;
            } catch (err) {
                window.LD.status(err.message || 'Speech recognition failed.', 'error');
            }
        });
        recorder = rec;
        rec.start();
        button.setAttribute('aria-pressed', 'true');
    }

    function submitAssistant(form) {
        const input = form.querySelector('.ld-assistant-input');
        const q = input ? input.value.trim() : '';
        const engine = typeof getActiveEngine === 'function' ? getActiveEngine() : 'local';
        if (engine === 'local') {
            const search = document.getElementById('search-input');
            const searchForm = document.getElementById('search-form');
            if (search) search.value = q;
            if (searchForm) searchForm.requestSubmit();
            return;
        }
        const fixed = document.querySelector('.ld-search-row .search-engine-btn[data-engine="' + CSS.escape(engine) + '"]');
        const url = fixed ? fixed.dataset.url : '';
        const param = fixed && fixed.dataset.param ? fixed.dataset.param : 'q';
        if (!url) return;
        const join = url.indexOf('?') === -1 ? '?' : '&';
        window.open(url + join + encodeURIComponent(param) + '=' + encodeURIComponent(q), '_blank', 'noopener');
    }

    async function setFeature(button) {
        const key = button.dataset.feature;
        const enabled = button.getAttribute('aria-pressed') !== 'true';
        button.disabled = true;
        try {
            await window.LD.api.post('/features', { key: key, enabled: enabled });
            button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            button.textContent = enabled ? 'On' : 'Off';
            if (key === 'grid_max_width') {
                const grid = document.getElementById('ld-grid');
                if (grid) {
                    grid.dataset.gridCap = enabled ? '1' : '0';
                    window.dispatchEvent(new Event('resize'));
                }
                return;
            }
            window.location.reload();
        } catch (err) {
            window.LD.status(err.message || 'Could not save that feature.', 'error');
        } finally {
            button.disabled = false;
        }
    }

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('ld-assistant-form')) return;
        e.preventDefault();
        submitAssistant(form);
    });

    document.addEventListener('click', (e) => {
        const mic = e.target.closest('.ld-assistant-mic');
        if (mic) {
            const form = mic.closest('.ld-assistant-form');
            toggleMic(mic, form ? form.querySelector('.ld-assistant-input') : null);
            return;
        }
        const feature = e.target.closest('.ld-features [data-feature]');
        if (feature) setFeature(feature);
    });

    window.LD.renderers = window.LD.renderers || {};
    window.LD.renderers.assistant = assistant;
    window.LD.renderers.clock = clock;
    window.LD.renderers.features = features;
})();
