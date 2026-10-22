/**
 * Floating Spotify player. Position stays on this device.
 * The Web Playback SDK is loaded only when the player is on and an account is connected.
 */
'use strict';

(function () {
    const STORE = 'ld_spotify_player';
    let deviceId = null;
    let player = null;
    let pollTimer = null;
    let dragging = false;
    let sdkNote = '';
    let state = loadState();

    function loadState() {
        try {
            const raw = JSON.parse(localStorage.getItem(STORE) || '{}');
            return {
                x: Number(raw.x) || 16,
                y: Number(raw.y) || 16,
                min: raw.min === true,
            };
        } catch (err) {
            return { x: 16, y: 16, min: false };
        }
    }

    function saveState() {
        localStorage.setItem(STORE, JSON.stringify(state));
    }

    function csrf() {
        return window.getCsrfToken ? window.getCsrfToken() : '';
    }

    async function api(method, path, body) {
        const opts = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = csrf();
            opts.body = JSON.stringify(body || {});
        }
        const res = await fetch('/api/v1' + path, opts);
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok) {
            const error = new Error(data.error || 'Spotify request failed.');
            error.status = res.status;
            throw error;
        }
        return data;
    }

    function phone() {
        return window.matchMedia('(max-width: 700px)').matches;
    }

    function shell() {
        let el = document.getElementById('sp-player');
        if (el) return el;
        el = document.createElement('section');
        el.id = 'sp-player';
        el.className = 'sp-player';
        el.setAttribute('role', 'region');
        el.setAttribute('aria-label', 'Spotify player');
        document.body.appendChild(el);
        return el;
    }

    function place(el) {
        el.classList.toggle('is-min', state.min);
        if (phone()) {
            el.style.left = '';
            el.style.top = '';
            el.style.right = '';
            return;
        }
        el.style.left = state.x + 'px';
        el.style.top = state.y + 'px';
    }

    function render(config, now, devices, playlists) {
        const el = shell();
        place(el);
        if (!config.connected) {
            el.innerHTML = '<a class="sp-pill" href="/settings/spotify">Connect Spotify</a>';
            return;
        }
        if (state.min) {
            const label = now && now.name ? now.name : 'Spotify';
            el.innerHTML = '<button type="button" class="sp-pill" data-expand>' + escapeHtml(label) + '</button>';
            return;
        }
        const art = now && now.image_url ? '<img class="sp-art" alt="" src="' + escapeHtml(now.image_url) + '">' : '<div class="sp-art"></div>';
        const title = now && now.name ? escapeHtml(now.name) : 'Nothing playing';
        const artists = now && now.artists ? escapeHtml(now.artists.join(', ')) : '';
        const deviceOptions = (devices || []).map(function (device) {
            return '<option value="' + escapeHtml(device.id) + '"' + (device.is_active ? ' selected' : '') + '>' + escapeHtml(device.name) + '</option>';
        }).join('');
        const playlistOptions = (playlists || []).filter(function (row) { return row.spotify_id; }).map(function (row) {
            return '<option value="' + row.id + '">' + escapeHtml(row.name) + '</option>';
        }).join('');
        el.innerHTML = `
            <div class="sp-bar" data-drag>
                <strong>Life Dashboard</strong>
                <button type="button" data-min aria-label="Hide player">Hide</button>
            </div>
            <div class="sp-body">
                ${art}
                <div>
                    <p class="sp-title">${title}</p>
                    <p class="sp-artists">${artists}</p>
                    <input class="sp-seek" type="range" min="0" max="${now && now.duration_ms ? now.duration_ms : 0}" value="${now && now.progress_ms ? now.progress_ms : 0}" data-seek>
                </div>
            </div>
            <div class="sp-controls">
                <button type="button" data-cmd="previous">Prev</button>
                <button type="button" data-cmd="${now && now.is_playing ? 'pause' : 'play'}">${now && now.is_playing ? 'Pause' : 'Play'}</button>
                <button type="button" data-cmd="next">Next</button>
                <button type="button" data-cmd="shuffle">Shuffle</button>
                <button type="button" data-cmd="repeat">Repeat</button>
            </div>
            <label class="sp-volume">Volume <input type="range" min="0" max="100" value="50" data-volume></label>
            <label class="sp-select">Device <select data-device><option value="">Active device</option>${deviceOptions}</select></label>
            <label class="sp-select">Playlist <select data-playlist><option value="">Choose</option>${playlistOptions}</select> <button type="button" data-play-list>Play</button></label>
            <button type="button" data-queue>Queue</button>
            <ul class="sp-queue" hidden></ul>
            <p class="sp-note"${sdkNote ? '' : ' hidden'}>${escapeHtml(sdkNote)}</p>`;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function refresh(config) {
        let now = null;
        let devices = [];
        let playlists = [];
        try {
            const playing = await api('GET', '/spotify/player');
            now = playing.now;
        } catch (err) {
            now = null;
        }
        try {
            devices = (await api('GET', '/spotify/devices')).devices || [];
        } catch (err) {
            devices = [];
        }
        try {
            playlists = (await api('GET', '/spotify/playlists')).playlists || [];
        } catch (err) {
            playlists = [];
        }
        if (document.activeElement && document.getElementById('sp-player') && document.getElementById('sp-player').contains(document.activeElement)) {
            return;
        }
        render(config, now, devices, playlists);
    }

    async function command(name, body) {
        await api('POST', '/spotify/playback/' + name, body || {});
    }

    function bind(config) {
        const el = shell();
        el.addEventListener('click', async function (event) {
            const button = event.target.closest('button');
            if (!button) return;
            if (button.dataset.expand !== undefined) {
                state.min = false;
                saveState();
                refresh(config);
                return;
            }
            if (button.dataset.min !== undefined) {
                state.min = true;
                saveState();
                render(config, null, [], []);
                return;
            }
            try {
                if (button.dataset.cmd === 'play') {
                    await command('play', {});
                } else if (button.dataset.cmd) {
                    const map = { pause: 'pause', next: 'next', previous: 'previous', shuffle: 'shuffle', repeat: 'repeat' };
                    const action = map[button.dataset.cmd];
                    if (action === 'shuffle') await command('shuffle', { state: true });
                    else if (action === 'repeat') await command('repeat', { state: 'context' });
                    else if (action) await command(action, {});
                } else if (button.dataset.playList !== undefined) {
                    const select = el.querySelector('[data-playlist]');
                    if (select && select.value) await command('play', { playlist_id: Number(select.value) });
                } else if (button.dataset.queue !== undefined) {
                    const list = el.querySelector('.sp-queue');
                    const data = await api('GET', '/spotify/queue');
                    list.hidden = false;
                    list.innerHTML = (data.queue || []).slice(0, 20).map(function (track) {
                        return '<li>' + escapeHtml(track.name) + '</li>';
                    }).join('') || '<li>Queue is empty.</li>';
                }
            } catch (err) {
                sdkNote = err.message || 'Spotify request failed.';
                const note = el.querySelector('.sp-note');
                if (note) {
                    note.hidden = false;
                    note.textContent = sdkNote;
                }
            }
            window.setTimeout(function () { refresh(config); }, 400);
        });
        el.addEventListener('change', async function (event) {
            if (event.target.matches('[data-seek]')) {
                await command('seek', { position_ms: Number(event.target.value) });
            } else if (event.target.matches('[data-volume]')) {
                await command('volume', { volume_percent: Number(event.target.value) });
            } else if (event.target.matches('[data-device]') && event.target.value) {
                await command('transfer', { device_id: event.target.value, play: true });
            }
        });
        el.addEventListener('pointerdown', function (event) {
            if (!event.target.closest('[data-drag]') || phone()) return;
            dragging = true;
            const startX = event.clientX;
            const startY = event.clientY;
            const originX = state.x;
            const originY = state.y;
            function move(ev) {
                if (!dragging) return;
                state.x = Math.max(8, originX + ev.clientX - startX);
                state.y = Math.max(8, originY + ev.clientY - startY);
                place(el);
            }
            function up() {
                dragging = false;
                saveState();
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
            }
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        });
    }

    function startSdk() {
        if (player) return;
        if (!window.isSecureContext) {
            showSdkFallback();
            return;
        }
        window.onSpotifyWebPlaybackSDKReady = function () {
            player = new window.Spotify.Player({
                name: 'Life Dashboard',
                getOAuthToken: function (cb) {
                    api('GET', '/spotify/playback/token').then(function (data) {
                        cb(data.access_token);
                    }).catch(function () {
                        const note = document.querySelector('.sp-note');
                        if (note) {
                            note.hidden = false;
                            note.textContent = 'Could not start the browser player. Playback uses the active device.';
                        }
                    });
                },
                volume: 0.5,
            });
            player.addListener('ready', function (ready) { deviceId = ready.device_id; });
            player.addListener('authentication_error', showSdkFallback);
            player.addListener('account_error', showSdkFallback);
            player.addListener('initialization_error', showSdkFallback);
            player.connect();
        };
        const script = document.createElement('script');
        script.src = 'https://sdk.scdn.co/spotify-player.js';
        script.async = true;
        document.head.appendChild(script);
    }

    function showSdkFallback() {
        sdkNote = 'This browser is not a Spotify Connect device. Playback uses the active device.';
        const note = document.querySelector('.sp-note');
        if (!note) return;
        note.hidden = false;
        note.textContent = sdkNote;
    }

    async function boot() {
        let config;
        try {
            config = await api('GET', '/spotify/player-config');
        } catch (err) {
            return;
        }
        if (!config.enabled) return;
        render(config, null, [], []);
        bind(config);
        if (config.connected) {
            startSdk();
            refresh(config);
            pollTimer = window.setInterval(function () {
                if (!document.hidden && !dragging) refresh(config);
            }, 5000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
