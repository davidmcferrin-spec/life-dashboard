/**
 * Floating Spotify player. Position stays on this device.
 * The Web Playback SDK is loaded only when the player is on, an account is connected,
 * and the saved grant includes the streaming scopes.
 */
'use strict';

function spotifySdkMessage(reason, detail) {
    const extra = typeof detail === 'string' ? detail.trim() : '';
    if (reason === 'scope') {
        return 'Reconnect Spotify to enable in-browser playback.';
    }
    if (reason === 'https') {
        return 'In-browser playback needs HTTPS.';
    }
    if (reason === 'premium') {
        return 'In-browser playback needs Spotify Premium.';
    }
    if (reason === 'autoplay') {
        return 'The browser blocked audio. Click Play to start in-browser playback.';
    }
    if (reason === 'script') {
        return 'The Spotify player script did not load.';
    }
    if (reason === 'token') {
        return 'Could not get a Spotify token for this browser.';
    }
    if (reason === 'auth') {
        return extra ? 'Spotify rejected the browser player (' + extra + ').' : 'Spotify rejected the browser player token.';
    }
    if (reason === 'init') {
        return extra ? 'The browser player did not start (' + extra + ').' : 'The browser player did not start.';
    }
    return extra;
}

function spotifySdkReason(event, message) {
    const text = String(message || '').toLowerCase();
    if (event === 'account_error' || text.indexOf('premium') !== -1) {
        return 'premium';
    }
    if (event === 'authentication_error') {
        return text.indexOf('scope') !== -1 ? 'scope' : 'auth';
    }
    if (text.indexOf('autoplay') !== -1 || text.indexOf('user gesture') !== -1 || text.indexOf('not allowed') !== -1) {
        return 'autoplay';
    }
    if (event === 'initialization_error' || event === 'playback_error') {
        return 'init';
    }
    return 'failed';
}

function spotifyClip(text, limit) {
    const full = String(text == null ? '' : text);
    const max = limit || 42;
    if (full.length <= max) return full;
    return full.slice(0, max - 1) + '…';
}

function spotifyDeviceLabel(device, limit) {
    const type = String(device && device.type ? device.type : 'Device');
    const name = String(device && device.name ? device.name : 'Unnamed');
    const active = device && device.is_active ? ' (active)' : '';
    return spotifyClip(type + ' · ' + name + active, limit || 42);
}

function spotifyDeviceIcon(type) {
    const key = String(type || '').toLowerCase();
    const computer = '<rect x="1.5" y="2" width="13" height="8.5" rx="1"/><path d="M6 13.5h4M8 10.5v3"/>';
    const phone = '<rect x="5" y="1.5" width="6" height="13" rx="1"/>';
    const speaker = '<rect x="4" y="1.5" width="8" height="13" rx="1.5"/><circle cx="8" cy="6" r="1.4"/>';
    const screen = '<rect x="1.5" y="3" width="13" height="8" rx="1"/><path d="M6 13.5h4"/>';
    const car = '<path d="M2.5 9.5h11l-1.2-3.2H4.2z"/><circle cx="5" cy="12" r="1.2"/><circle cx="11" cy="12" r="1.2"/>';
    let shape = speaker;
    if (key === 'computer' || key === 'castaudio') shape = computer;
    else if (key === 'smartphone' || key === 'tablet') shape = phone;
    else if (key === 'tv' || key === 'castvideo' || key === 'gameconsole') shape = screen;
    else if (key === 'automobile') shape = car;
    return '<svg viewBox="0 0 16 16" aria-hidden="true">' + shape + '</svg>';
}

// Long enough to choose a playlist and press Play; a quiet picker should not keep a stale choice.
const SPOTIFY_PICK_HOLD_MS = 30000;

function spotifyHoldId(id, at, now, limit, ids) {
    if (id == null || id === '') return '';
    const key = String(id);
    if (!Number.isFinite(Number(at)) || now - Number(at) > limit) return '';
    if (ids && ids.indexOf(key) === -1) return '';
    return key;
}

function spotifySyncSelect(select, open, optionKey, optionsHtml, value) {
    // An open picker keeps its node, options, and value so a poll cannot close it or clear the choice.
    if (!select || open) return;
    const next = value == null ? '' : String(value);
    if (select.dataset.options !== optionKey) {
        select.innerHTML = optionsHtml;
        select.dataset.options = optionKey;
        select.value = next;
        return;
    }
    if (select.value !== next) select.value = next;
}

function spotifyClock(ms) {
    const total = Math.max(0, Math.floor((Number(ms) || 0) / 1000));
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;
    return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
}

function spotifyTrackLine(now) {
    const artists = now && now.artists && now.artists.length ? now.artists.join(', ') : '';
    const album = now && now.album ? String(now.album) : '';
    if (artists && album) return artists + ' · ' + album;
    return artists || album;
}

function spotifyRecordHtml(art, playing) {
    return '<div class="sp-record' + (playing ? ' is-playing' : '') + '" aria-hidden="true">'
        + art
        + '<i class="sp-record-spindle"></i></div>';
}

// Heights draw the disc graphic. They are not measured audio levels.
function spotifyEqualizerHtml(playing) {
    const heights = [8, 15, 11, 20, 13, 18, 9, 16, 12, 19, 10, 14];
    return '<span class="sp-equalizer' + (playing ? ' is-playing' : '') + '" aria-hidden="true">'
        + heights.map(function (height) {
            return '<i style="height:' + height + 'px"></i>';
        }).join('')
        + '</span>';
}

function spotifyChangeDevice(root, refresh) {
    const openDeviceList = function () {
        const select = root && root.querySelector ? root.querySelector('[data-device]') : null;
        if (!select) return;
        select.focus();
        if (typeof select.showPicker === 'function') {
            try { select.showPicker(); } catch (err) { /* the focus still lands on the list */ }
        }
    };
    openDeviceList();
    // A refresh can replace the select, so open the list again once it is current.
    const pending = typeof refresh === 'function' ? refresh() : null;
    if (pending && typeof pending.then === 'function') {
        pending.then(openDeviceList);
    }
}

(function () {
    const STORE = 'ld_spotify_player';
    const PLAYER_NAME = 'Life Dashboard';
    let deviceId = null;
    let player = null;
    let pollTimer = null;
    let dragging = false;
    let sdkNote = '';
    let sdkReason = '';
    let sdkReady = false;
    let playing = false;
    let shuffleOn = false;
    let repeatMode = 'off';
    let volumeTimer = null;
    let volumeLevel = 50;
    let mutedFrom = 50;
    let view = { now: null, devices: [], playlists: [], queue: [] };
    let state = loadState();
    const lucideMarkup = {};

    function loadState() {
        try {
            const raw = JSON.parse(localStorage.getItem(STORE) || '{}');
            const x = Number(raw.x);
            const y = Number(raw.y);
            return {
                x: Number.isFinite(x) ? x : null,
                y: Number.isFinite(y) ? y : null,
                min: raw.min === true,
                on: raw.on === true ? true : (raw.on === false ? false : null),
            };
        } catch (err) {
            return { x: null, y: null, min: false, on: null };
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

    function clamp(el) {
        el.classList.toggle('is-min', state.min);
        const margin = 8;
        const inset = 18;
        const width = el.offsetWidth || 390;
        const height = el.offsetHeight || 62;
        const maxX = Math.max(margin, window.innerWidth - width - margin);
        const maxY = Math.max(margin, window.innerHeight - height - margin);
        if (state.x == null) state.x = Math.max(margin, window.innerWidth - width - inset);
        if (state.y == null) state.y = Math.max(margin, window.innerHeight - height - inset);
        state.x = Math.round(Math.min(maxX, Math.max(margin, state.x)));
        state.y = Math.round(Math.min(maxY, Math.max(margin, state.y)));
        // Inline edges override the narrow-screen dock so a saved position still moves.
        el.style.right = 'auto';
        el.style.bottom = 'auto';
        el.style.left = state.x + 'px';
        el.style.top = state.y + 'px';
    }

    function loadLucideIcons() {
        const names = ['chevron-up', 'chevron-down', 'volume-2', 'music-2', 'monitor'];
        return Promise.all(names.map(function (name) {
            return fetch('/assets/vendor/lucide/' + name + '.svg', { credentials: 'same-origin' })
                .then(function (res) { return res.ok ? res.text() : ''; })
                .then(function (text) {
                    if (text) lucideMarkup[name] = text;
                })
                .catch(function () { /* the control label still names the button */ });
        }));
    }

    function icon(name) {
        if (name === 'chevron-up' || name === 'chevron-down' || name === 'volume-2' || name === 'music-2' || name === 'monitor') {
            return lucideMarkup[name] || '';
        }
        const stroke = {
            previous: '<path d="M6 5v14"/><path d="M19 6 8 12l11 6z"/>',
            next: '<path d="M18 5v14"/><path d="M5 6l11 6-11 6z"/>',
            shuffle: '<path d="M4 7h3l10 10h3"/><path d="M14 7h6v6"/><path d="M4 17h3l4.2-4.2"/><path d="M14 17h6v-6"/>',
            repeat: '<path d="M17 3v5h-5"/><path d="M7 7h8a4 4 0 0 1 4 4v1"/><path d="M7 21v-5h5"/><path d="M17 17H9a4 4 0 0 1-4-4v-1"/>',
            'speaker-off': '<path d="M4 10h3l4-3v10l-4-3H4z"/><path d="M16 10l4 4"/><path d="M20 10l-4 4"/>',
            queue: '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h10"/>',
        };
        const filled = {
            play: '<path d="M8 5v14l12-7z"/>',
            pause: '<path d="M7 5h3.2v14H7zM13.8 5H17v14h-3.2z"/>',
        };
        if (name === 'repeat-one') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 3v5h-5"/><path d="M7 7h8a4 4 0 0 1 4 4v1"/><path d="M7 21v-5h5"/><path d="M17 17H9a4 4 0 0 1-4-4v-1"/><path d="M12 10v5"/><path d="M10.5 11.2 12 10"/></svg>';
        }
        if (filled[name]) {
            return '<svg class="sp-fill" viewBox="0 0 24 24" aria-hidden="true">' + filled[name] + '</svg>';
        }
        return '<svg viewBox="0 0 24 24" aria-hidden="true">' + (stroke[name] || '') + '</svg>';
    }

    function volumeIcon(level) {
        return icon(level > 0 ? 'volume-2' : 'speaker-off');
    }

    function paintNote(el) {
        const note = el.querySelector('.sp-sdk-note');
        if (!note) return;
        if (!sdkNote) {
            note.hidden = true;
            note.textContent = '';
            return;
        }
        note.hidden = false;
        if (sdkReason === 'scope') {
            note.innerHTML = '<a href="/settings/spotify/connect">Reconnect Spotify to enable in-browser playback.</a>';
            return;
        }
        note.textContent = sdkNote;
    }

    function setSdk(reason, detail) {
        sdkReason = reason;
        sdkNote = spotifySdkMessage(reason, detail);
    }

    function activeDevice(devices) {
        return (devices || []).filter(function (device) { return device.is_active; })[0] || null;
    }

    let playlistHoldId = '';
    let playlistHoldAt = 0;
    let deviceHoldId = '';
    let deviceHoldAt = 0;

    function notePick(kind, value) {
        const id = value == null ? '' : String(value);
        const at = id ? Date.now() : 0;
        if (kind === 'device') {
            deviceHoldId = id;
            deviceHoldAt = at;
            return;
        }
        playlistHoldId = id;
        playlistHoldAt = at;
    }

    function touchPick(kind) {
        if (kind === 'device') {
            if (deviceHoldId) deviceHoldAt = Date.now();
            return;
        }
        if (playlistHoldId) playlistHoldAt = Date.now();
    }

    function playlistIds(playlists) {
        return (playlists || []).filter(function (row) { return row.spotify_id; }).map(function (row) {
            return String(row.id);
        });
    }

    function heldPlaylistId(playlists) {
        const next = spotifyHoldId(playlistHoldId, playlistHoldAt, Date.now(), SPOTIFY_PICK_HOLD_MS, playlistIds(playlists));
        if (playlistHoldId && !next) {
            playlistHoldId = '';
            playlistHoldAt = 0;
        }
        return next;
    }

    function heldDeviceId(devices) {
        const ids = (devices || []).map(function (device) { return String(device.id); });
        const next = spotifyHoldId(deviceHoldId, deviceHoldAt, Date.now(), SPOTIFY_PICK_HOLD_MS, ids);
        if (deviceHoldId && !next) {
            deviceHoldId = '';
            deviceHoldAt = 0;
        }
        return next;
    }

    function playlistOptionKey(playlists) {
        return JSON.stringify((playlists || []).filter(function (row) { return row.spotify_id; }).map(function (row) {
            return [Number(row.id), String(row.name || '')];
        }).sort(function (a, b) { return a[0] - b[0]; }));
    }

    function deviceOptionKey(devices) {
        return JSON.stringify((devices || []).map(function (device) {
            return [String(device.id), String(device.type || ''), String(device.name || '')];
        }).sort(function (a, b) {
            return a[0] < b[0] ? -1 : (a[0] > b[0] ? 1 : 0);
        }));
    }

    function playlistOptionsHtml(playlists, selectedId) {
        return (playlists || []).filter(function (row) { return row.spotify_id; }).map(function (row) {
            const id = String(row.id);
            const selected = selectedId && id === String(selectedId) ? ' selected' : '';
            return '<option value="' + id + '"' + selected + '>' + escapeHtml(spotifyClip(row.name, 42)) + '</option>';
        }).join('');
    }

    function deviceOptionsHtml(devices, selectedId) {
        return (devices || []).map(function (device) {
            const id = String(device.id);
            const selected = selectedId && id === String(selectedId) ? ' selected' : '';
            return '<option value="' + escapeHtml(device.id) + '"' + selected + '>' + escapeHtml(spotifyDeviceLabel(device)) + '</option>';
        }).join('');
    }

    function pickerOpen(select) {
        return !!(select && document.activeElement === select);
    }

    function artHtml(url) {
        if (url) {
            return '<img class="sp-art" alt="" data-url="' + escapeHtml(url) + '" src="' + escapeHtml(url) + '">';
        }
        return '<div class="sp-art" data-url="">' + icon('music-2') + '</div>';
    }

    function paintArt(el, now) {
        const url = now && now.image_url ? String(now.image_url) : '';
        Array.prototype.slice.call(el.querySelectorAll('.sp-art')).forEach(function (art) {
            if ((art.dataset.url || '') === url) return;
            art.outerHTML = artHtml(url);
        });
    }

    function paintIconButton(button, name, label) {
        if (!button) return;
        if (label != null) {
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        }
        if (button.dataset.icon !== name) {
            button.dataset.icon = name;
            button.innerHTML = icon(name);
        }
    }

    function patchLive(el, now, devices, collapsed, volume, volumeFocused) {
        el.classList.toggle('is-min', collapsed);
        const spinning = !!(now && now.is_playing);
        el.querySelectorAll('.sp-record, .sp-equalizer').forEach(function (node) {
            node.classList.toggle('is-playing', spinning);
        });
        const toggleLabel = collapsed ? 'Expand player' : 'Collapse player';
        el.querySelectorAll('[data-collapse]').forEach(function (collapse) {
            collapse.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            collapse.setAttribute('aria-label', toggleLabel);
            collapse.setAttribute('title', toggleLabel);
            if (collapse.dataset.collapse === 'chevron') {
                paintIconButton(collapse, collapsed ? 'chevron-up' : 'chevron-down', null);
            }
        });
        paintArt(el, now);
        const titleText = now && now.name ? String(now.name) : 'Nothing playing';
        const artistText = spotifyTrackLine(now);
        el.querySelectorAll('.sp-title').forEach(function (title) {
            if (title.textContent !== titleText) title.textContent = titleText;
        });
        el.querySelectorAll('.sp-artists').forEach(function (artists) {
            if (artists.textContent !== artistText) artists.textContent = artistText;
        });
        const elapsed = el.querySelector('[data-elapsed]');
        const duration = el.querySelector('[data-duration]');
        const elapsedText = spotifyClock(now && now.progress_ms);
        const durationText = spotifyClock(now && now.duration_ms);
        if (elapsed && elapsed.textContent !== elapsedText) elapsed.textContent = elapsedText;
        if (duration && duration.textContent !== durationText) duration.textContent = durationText;
        const seek = el.querySelector('[data-seek]');
        if (seek && document.activeElement !== seek) {
            const max = now && now.duration_ms ? String(now.duration_ms) : '0';
            const value = now && now.progress_ms ? String(now.progress_ms) : '0';
            if (seek.max !== max) seek.max = max;
            if (seek.value !== value) seek.value = value;
        }
        const shuffle = el.querySelector('[data-cmd="shuffle"]');
        if (shuffle) {
            shuffle.classList.toggle('is-on', shuffleOn);
            shuffle.setAttribute('aria-pressed', shuffleOn ? 'true' : 'false');
        }
        const playName = playing ? 'pause' : 'play';
        const play = el.querySelector('.sp-play');
        if (play) {
            play.dataset.cmd = playName;
            paintIconButton(play, playName, playing ? 'Pause' : 'Play');
        }
        const repeatText = repeatMode === 'track' ? 'Repeat track' : (repeatMode === 'context' ? 'Repeat playlist' : 'Repeat off');
        const repeat = el.querySelector('[data-cmd="repeat"]');
        if (repeat) {
            repeat.classList.toggle('is-on', repeatMode !== 'off');
            repeat.setAttribute('aria-pressed', repeatMode === 'off' ? 'false' : 'true');
            paintIconButton(repeat, repeatMode === 'track' ? 'repeat-one' : 'repeat', repeatText);
        }
        const muteLabel = volume > 0 ? 'Mute' : 'Unmute';
        const mute = el.querySelector('[data-mute]');
        if (mute) {
            mute.setAttribute('aria-pressed', volume > 0 ? 'false' : 'true');
            paintIconButton(mute, volume > 0 ? 'volume-2' : 'speaker-off', muteLabel);
        }
        if (!volumeFocused) {
            el.querySelectorAll('[data-volume]').forEach(function (slider) {
                if (slider.value !== String(volume)) slider.value = String(volume);
            });
        }
        const active = activeDevice(devices);
        const deviceLine = !devices || !devices.length
            ? 'Device'
            : (active ? 'Active: ' + active.type + ' · ' + active.name : 'No device is active.');
        const line = el.querySelector('[data-device-line]');
        const lineText = spotifyClip(deviceLine, 48);
        if (line && line.textContent !== lineText) line.textContent = lineText;
        const head = el.querySelector('[data-device-head]');
        if (!head) return;
        const iconWrap = head.querySelector('.sp-device-icon');
        if (active) {
            const mark = spotifyDeviceIcon(active.type);
            if (!iconWrap) {
                head.insertAdjacentHTML('afterbegin', '<span class="sp-device-icon" data-type="' + escapeHtml(active.type || '') + '" aria-hidden="true">' + mark + '</span>');
            } else if (iconWrap.dataset.type !== String(active.type || '')) {
                iconWrap.dataset.type = String(active.type || '');
                iconWrap.innerHTML = mark;
            }
        } else if (iconWrap) {
            iconWrap.remove();
        }
    }

    function paintPlaylistSelect(el, playlists, playlistKey, chosenId) {
        const select = el.querySelector('[data-playlist]');
        if (!select) return;
        const options = '<option value="">Choose</option>' + playlistOptionsHtml(playlists, chosenId);
        spotifySyncSelect(select, pickerOpen(select), playlistKey, options, chosenId);
    }

    function paintDeviceLabels(select, devices) {
        if (!select || pickerOpen(select) || !select.options) return;
        const labels = {};
        (devices || []).forEach(function (device) {
            labels[String(device.id)] = spotifyDeviceLabel(device);
        });
        Array.prototype.forEach.call(select.options, function (option) {
            const label = labels[option.value];
            if (!label || option.text === label) return;
            option.text = label;
        });
    }

    function paintDeviceSelect(el, devices, deviceKey, chosenId) {
        const slot = el.querySelector('[data-device-slot]');
        if (!slot) return;
        const select = slot.querySelector('[data-device]');
        if (pickerOpen(select)) return;
        if (devices && devices.length) {
            const options = '<option value="">Choose a device</option>' + deviceOptionsHtml(devices, chosenId);
            if (!select) {
                slot.innerHTML = '<select data-device aria-label="Device">' + options + '</select>';
                const created = slot.querySelector('[data-device]');
                created.dataset.options = deviceKey;
                if (created.value !== chosenId) created.value = chosenId;
                return;
            }
            spotifySyncSelect(select, false, deviceKey, options, chosenId);
            paintDeviceLabels(select, devices);
            if (select.value !== chosenId) select.value = chosenId;
            return;
        }
        if (select) {
            slot.innerHTML = '<p class="sp-note">No devices are available. Open Spotify, then refresh.</p>';
        }
    }

    function writeShell(el, now, devices, playlists, collapsed, volume, playlistId, chosenDevice) {
        const art = artHtml(now && now.image_url ? String(now.image_url) : '');
        const title = now && now.name ? escapeHtml(now.name) : 'Nothing playing';
        const artists = escapeHtml(spotifyTrackLine(now));
        const active = activeDevice(devices);
        const deviceLine = !devices || !devices.length
            ? 'Device'
            : (active ? 'Active: ' + active.type + ' · ' + active.name : 'No device is active.');
        const deviceMark = active
            ? '<span class="sp-device-icon" data-type="' + escapeHtml(active.type || '') + '" aria-hidden="true">' + spotifyDeviceIcon(active.type) + '</span>'
            : '';
        const deviceControl = !devices || !devices.length
            ? '<p class="sp-note">No devices are available. Open Spotify, then refresh.</p>'
            : '<select data-device aria-label="Device"><option value="">Choose a device</option>' + deviceOptionsHtml(devices, chosenDevice) + '</select>';
        const playName = playing ? 'pause' : 'play';
        const repeatText = repeatMode === 'track' ? 'Repeat track' : (repeatMode === 'context' ? 'Repeat playlist' : 'Repeat off');
        const toggleLabel = collapsed ? 'Expand player' : 'Collapse player';
        const muteLabel = volume > 0 ? 'Mute' : 'Unmute';
        const elapsed = spotifyClock(now && now.progress_ms);
        const total = spotifyClock(now && now.duration_ms);
        el.innerHTML = `
            <div class="sp-stage sp-expanded-only">
                <div class="sp-stage-top">
                    <span class="sp-eyebrow">Now playing</span>
                    <span class="sp-bar-gap"></span>
                    <button type="button" data-reset>Reset</button>
                    <button type="button" data-player-off>Off</button>
                </div>
                <div class="sp-stage-art sp-expanded-visual">${spotifyRecordHtml(art, playing)}${spotifyEqualizerHtml(playing)}</div>
                <div class="sp-stage-meta">
                    <p class="sp-title">${title}</p>
                    <p class="sp-artists">${artists}</p>
                    <div class="sp-progress">
                        <input class="sp-seek" type="range" min="0" max="${now && now.duration_ms ? now.duration_ms : 0}" value="${now && now.progress_ms ? now.progress_ms : 0}" data-seek aria-label="Position">
                        <span class="sp-times"><span data-elapsed>${elapsed}</span><span data-duration>${total}</span></span>
                    </div>
                </div>
                <div class="sp-controls sp-transport-extra">
                    <button type="button" data-cmd="shuffle" class="${shuffleOn ? 'is-on' : ''}" aria-pressed="${shuffleOn ? 'true' : 'false'}" aria-label="Shuffle" title="Shuffle">${icon('shuffle')}</button>
                    <button type="button" data-cmd="repeat" class="${repeatMode === 'off' ? '' : 'is-on'}" aria-pressed="${repeatMode === 'off' ? 'false' : 'true'}" aria-label="${repeatText}" title="${repeatText}">${icon(repeatMode === 'track' ? 'repeat-one' : 'repeat')}</button>
                </div>
                <div class="sp-device sp-field">
                    <div class="sp-field-head" data-device-head>
                        ${deviceMark}<span class="sp-device-copy"><small class="sp-eyebrow">Playing on</small><span data-device-line>${escapeHtml(spotifyClip(deviceLine, 48))}</span></span>
                        <button type="button" data-change-device>Change device</button>
                        <button type="button" data-refresh-devices>Refresh</button>
                    </div>
                    <div data-device-slot>${deviceControl}</div>
                </div>
                <div class="sp-field">
                    <div class="sp-field-head"><span class="sp-eyebrow">Playlist</span></div>
                    <div class="sp-field-row">
                        <select data-playlist aria-label="Playlist"><option value="">Choose</option>${playlistOptionsHtml(playlists, playlistId)}</select>
                        <button type="button" data-play-list>Play</button>
                    </div>
                </div>
                <div class="sp-up-next">
                    <span class="sp-eyebrow">Up next</span>
                    <ol data-up-next></ol>
                </div>
                <label class="sp-volume">Vol <input type="range" min="0" max="100" value="${volume}" data-volume aria-label="Volume"></label>
                <button type="button" class="sp-expanded-only" data-queue>Queue</button>
            </div>
            <div class="sp-dock">
                <button type="button" class="sp-grip" data-drag aria-label="Move music player" title="Drag to move"></button>
                <button type="button" class="sp-art-btn" data-collapse="art">${art}</button>
                <div class="sp-dock-meta">
                    <p class="sp-title">${title}</p>
                    <p class="sp-artists">${artists}</p>
                </div>
                <div class="sp-controls">
                    <button type="button" data-cmd="previous" aria-label="Previous" title="Previous">${icon('previous')}</button>
                    <button type="button" class="sp-play" data-cmd="${playName}" aria-label="${playing ? 'Pause' : 'Play'}" title="${playing ? 'Pause' : 'Play'}">${icon(playName)}</button>
                    <button type="button" data-cmd="next" aria-label="Next" title="Next">${icon('next')}</button>
                    <button type="button" class="sp-queue-compact" data-queue aria-label="Queue" title="Queue">${icon('queue')}</button>
                    <div class="sp-vol-pop sp-mini-only">
                        <button type="button" data-mute aria-pressed="${volume > 0 ? 'false' : 'true'}" aria-label="${muteLabel}" title="${muteLabel}">${volumeIcon(volume)}</button>
                        <input type="range" min="0" max="100" value="${volume}" data-volume aria-label="Volume">
                    </div>
                </div>
                <div class="sp-dock-more">
                    <button type="button" data-devices aria-label="Choose device" title="Choose device">${icon('monitor')}</button>
                    <button type="button" data-collapse="chevron" aria-pressed="${collapsed ? 'true' : 'false'}" aria-label="${toggleLabel}" title="${toggleLabel}">${icon(collapsed ? 'chevron-up' : 'chevron-down')}</button>
                </div>
            </div>
            <p class="sp-sdk-note"${sdkNote ? '' : ' hidden'}></p>`;
    }

    function paintQueue(el, tracks) {
        const list = el.querySelector('[data-up-next]');
        if (!list) return;
        const rows = tracks || [];
        const key = rows.map(function (track) {
            return String(track && track.id ? track.id : '') + ':' + String(track && track.duration_ms ? track.duration_ms : 0);
        }).join('|');
        if (list.dataset.key === key) return;
        list.dataset.key = key;
        if (!rows.length) {
            list.innerHTML = '<li class="sp-note">Nothing is queued.</li>';
            return;
        }
        list.innerHTML = rows.map(function (track, index) {
            const who = track && track.artists ? track.artists.join(', ') : '';
            const n = index < 9 ? '0' + (index + 1) : String(index + 1);
            return '<li><span class="sp-q-index">' + n + '</span><p><strong>' + escapeHtml(track && track.name ? track.name : '') + '</strong><small>' + escapeHtml(who) + '</small></p><time>' + spotifyClock(track && track.duration_ms) + '</time></li>';
        }).join('');
    }

    function render(config, now, devices, playlists, queue) {
        const el = shell();
        const nextQueue = queue === undefined ? (view.queue || []) : (queue || []);
        view = { now: now || null, devices: devices || [], playlists: playlists || [], queue: nextQueue };
        if (!config.connected) {
            el.classList.remove('is-min');
            el.innerHTML = '<a class="sp-pill" href="/settings/spotify">Connect Spotify</a>';
            clamp(el);
            return;
        }
        playing = !!(now && now.is_playing);
        if (now) {
            shuffleOn = !!now.shuffle;
            if (now.repeat === 'off' || now.repeat === 'context' || now.repeat === 'track') {
                repeatMode = now.repeat;
            }
        }
        const collapsed = state.min === true;
        const active = activeDevice(devices);
        const reported = active && active.volume_percent != null ? active.volume_percent : volumeLevel;
        const volumeFocused = document.activeElement && el.contains(document.activeElement) && document.activeElement.matches('[data-volume]');
        if (!volumeFocused) {
            volumeLevel = reported;
            if (reported > 0) mutedFrom = reported;
        }
        const volume = volumeLevel;
        const playlistKey = playlistOptionKey(playlists);
        const deviceKey = deviceOptionKey(devices);
        const playlistId = heldPlaylistId(playlists);
        const chosenDevice = heldDeviceId(devices) || (active ? String(active.id) : '');
        const playlistSelect = el.querySelector('[data-playlist]');
        const deviceSelect = el.querySelector('[data-device]');
        if (playlistSelect && (pickerOpen(playlistSelect) || pickerOpen(deviceSelect))) {
            return;
        }
        if (!playlistSelect) {
            el.classList.toggle('is-min', collapsed);
            writeShell(el, now, devices, playlists, collapsed, volume, playlistId, chosenDevice);
            const createdPlaylist = el.querySelector('[data-playlist]');
            const createdDevice = el.querySelector('[data-device]');
            if (createdPlaylist) {
                createdPlaylist.dataset.options = playlistKey;
                if (createdPlaylist.value !== playlistId) createdPlaylist.value = playlistId;
            }
            if (createdDevice) {
                createdDevice.dataset.options = deviceKey;
                if (createdDevice.value !== chosenDevice) createdDevice.value = chosenDevice;
            }
        } else {
            patchLive(el, now, devices, collapsed, volume, volumeFocused);
            paintPlaylistSelect(el, playlists, playlistKey, playlistId);
            paintDeviceSelect(el, devices, deviceKey, chosenDevice);
        }
        paintQueue(el, view.queue);
        paintNote(el);
        if (!pickerOpen(el.querySelector('[data-playlist]')) && !pickerOpen(el.querySelector('[data-device]'))) clamp(el);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function refresh(config, force) {
        let now = null;
        let devices = [];
        let playlists = [];
        try {
            const playingNow = await api('GET', '/spotify/player');
            now = playingNow.now;
        } catch (err) {
            now = null;
        }
        try {
            devices = (await api('GET', '/spotify/devices')).devices || [];
        } catch (err) {
            devices = view.devices || [];
        }
        try {
            playlists = (await api('GET', '/spotify/playlists')).playlists || [];
        } catch (err) {
            playlists = view.playlists || [];
        }
        let queue = view.queue || [];
        if (!state.min) {
            try {
                queue = (await api('GET', '/spotify/queue')).queue || [];
            } catch (err) {
                queue = view.queue || [];
            }
        }
        const host = document.getElementById('sp-player');
        if (!force && document.activeElement && host && host.contains(document.activeElement) && document.activeElement.matches('input, select')) {
            view.queue = queue;
            return;
        }
        render(config, now, devices, playlists, queue);
    }

    async function command(name, body) {
        await api('POST', '/spotify/playback/' + name, body || {});
        if (sdkReason === 'command') {
            sdkNote = '';
            sdkReason = '';
            const host = document.getElementById('sp-player');
            if (host) paintNote(host);
        }
    }

    function showCommandError(err) {
        const message = err && err.message ? String(err.message) : 'Spotify request failed.';
        sdkReason = 'command';
        sdkNote = message;
        const host = document.getElementById('sp-player');
        if (host) paintNote(host);
    }

    async function sendVolume(value) {
        const level = Math.max(0, Math.min(100, Math.round(Number(value))));
        if (!Number.isFinite(level)) return;
        volumeLevel = level;
        if (level > 0) mutedFrom = level;
        window.clearTimeout(volumeTimer);
        volumeTimer = null;
        await command('volume', { volume_percent: level });
    }

    function closeQueue() {
        const modal = document.getElementById('sp-queue-modal');
        if (modal) modal.remove();
        if (!document.querySelector('.app-modal')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    async function openQueue() {
        const data = await api('GET', '/spotify/queue');
        closeQueue();
        const rows = (data.queue || []).map(function (track) {
            const who = track && track.artists ? track.artists.join(', ') : '';
            return '<li><span class="sp-title">' + escapeHtml(track.name) + '</span><span class="sp-artists">' + escapeHtml(who) + '</span></li>';
        }).join('');
        const modal = document.createElement('div');
        modal.id = 'sp-queue-modal';
        modal.className = 'app-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'sp-queue-title');
        modal.innerHTML = '<div class="app-modal-backdrop" data-close></div>'
            + '<div class="app-modal-panel surface">'
            + '<div class="sp-queue-head"><h2 id="sp-queue-title">Queue</h2>'
            + '<button type="button" class="app-modal-close" data-close aria-label="Close">×</button></div>'
            + (rows ? '<ol class="sp-queue-list">' + rows + '</ol>' : '<p class="sp-note">Nothing is queued.</p>')
            + '</div>';
        document.body.appendChild(modal);
        document.body.classList.add('app-modal-open');
        modal.addEventListener('click', function (event) {
            if (event.target.closest('[data-close]')) closeQueue();
        });
        const closeBtn = modal.querySelector('.app-modal-close');
        if (closeBtn) closeBtn.focus();
    }

    function wakeSdk() {
        if (player && typeof player.activateElement === 'function') {
            try { player.activateElement(); } catch (err) { /* already active */ }
        }
    }

    function bind(config) {
        const el = shell();
        el.addEventListener('click', async function (event) {
            const button = event.target.closest('button');
            if (!button) return;
            wakeSdk();
            if (button.dataset.drag !== undefined) return;
            if (button.dataset.collapse !== undefined) {
                state.min = !state.min;
                saveState();
                render(config, view.now, view.devices, view.playlists);
                if (!state.min) refresh(config, true);
                return;
            }
            if (button.dataset.devices !== undefined) {
                if (state.min) {
                    state.min = false;
                    saveState();
                    render(config, view.now, view.devices, view.playlists);
                }
                refresh(config, true).then(function () {
                    const select = el.querySelector('[data-device]');
                    if (select) select.focus();
                });
                return;
            }
            if (button.dataset.reset !== undefined) {
                state.x = null;
                state.y = null;
                clamp(el);
                saveState();
                return;
            }
            if (button.dataset.playerOff !== undefined) {
                setOn(false);
                return;
            }
            if (button.dataset.changeDevice !== undefined) {
                spotifyChangeDevice(el, function () { return refresh(config, true); });
                return;
            }
            if (button.dataset.refreshDevices !== undefined) {
                refresh(config, true);
                return;
            }
            try {
                if (button.dataset.cmd === 'play') {
                    await command('play', deviceId ? { device_id: deviceId } : {});
                    notePick('device', '');
                } else if (button.dataset.cmd) {
                    const map = { pause: 'pause', next: 'next', previous: 'previous', shuffle: 'shuffle', repeat: 'repeat' };
                    const action = map[button.dataset.cmd];
                    const body = deviceId ? { device_id: deviceId } : {};
                    if (action === 'shuffle') await command('shuffle', Object.assign({ state: !shuffleOn }, body));
                    else if (action === 'repeat') {
                        const nextMode = repeatMode === 'off' ? 'context' : (repeatMode === 'context' ? 'track' : 'off');
                        await command('repeat', Object.assign({ state: nextMode }, body));
                    } else if (action) await command(action, body);
                } else if (button.dataset.playList !== undefined) {
                    const select = el.querySelector('[data-playlist]');
                    if (select && select.value) {
                        await command('play', { playlist_id: Number(select.value), device_id: deviceId || undefined });
                        notePick('playlist', '');
                        notePick('device', '');
                        select.value = '';
                    }
                } else if (button.dataset.queue !== undefined) {
                    await openQueue();
                } else if (button.dataset.mute !== undefined) {
                    const next = volumeLevel > 0 ? 0 : (mutedFrom > 0 ? mutedFrom : 50);
                    if (volumeLevel > 0) mutedFrom = volumeLevel;
                    el.querySelectorAll('[data-volume]').forEach(function (slider) {
                        slider.value = String(next);
                    });
                    await sendVolume(next);
                    button.innerHTML = volumeIcon(next);
                    button.setAttribute('aria-label', next > 0 ? 'Mute' : 'Unmute');
                    button.setAttribute('title', next > 0 ? 'Mute' : 'Unmute');
                    button.setAttribute('aria-pressed', next > 0 ? 'false' : 'true');
                    return;
                }
            } catch (err) {
                showCommandError(err);
            }
            window.setTimeout(function () { refresh(config, true); }, 400);
        });
        el.addEventListener('input', function (event) {
            if (!event.target.matches('[data-volume]')) return;
            const value = Number(event.target.value);
            if (Number.isFinite(value)) volumeLevel = value;
            el.querySelectorAll('[data-volume]').forEach(function (slider) {
                if (slider !== event.target) slider.value = event.target.value;
            });
            const mute = el.querySelector('[data-mute]');
            if (mute && Number.isFinite(value)) {
                mute.innerHTML = volumeIcon(value);
                mute.setAttribute('aria-label', value > 0 ? 'Mute' : 'Unmute');
                mute.setAttribute('title', value > 0 ? 'Mute' : 'Unmute');
                mute.setAttribute('aria-pressed', value > 0 ? 'false' : 'true');
            }
            window.clearTimeout(volumeTimer);
            volumeTimer = window.setTimeout(function () {
                sendVolume(value).catch(showCommandError);
            }, 300);
        });
        el.addEventListener('change', async function (event) {
            try {
                if (event.target.matches('[data-seek]')) {
                    await command('seek', { position_ms: Number(event.target.value) });
                } else if (event.target.matches('[data-volume]')) {
                    window.clearTimeout(volumeTimer);
                    await sendVolume(Number(event.target.value));
                    el.querySelectorAll('[data-volume]').forEach(function (slider) {
                        if (slider !== event.target) slider.value = event.target.value;
                    });
                    const mute = el.querySelector('[data-mute]');
                    if (mute) {
                        const level = Number(event.target.value);
                        mute.innerHTML = volumeIcon(level);
                        mute.setAttribute('aria-label', level > 0 ? 'Mute' : 'Unmute');
                        mute.setAttribute('title', level > 0 ? 'Mute' : 'Unmute');
                        mute.setAttribute('aria-pressed', level > 0 ? 'false' : 'true');
                    }
                    return;
                } else if (event.target.matches('[data-playlist]')) {
                    notePick('playlist', event.target.value);
                    return;
                } else if (event.target.matches('[data-device]')) {
                    notePick('device', event.target.value);
                    if (event.target.value) await command('transfer', { device_id: event.target.value });
                }
            } catch (err) {
                showCommandError(err);
            }
            window.setTimeout(function () { refresh(config, true); }, 400);
        });
        el.addEventListener('focusin', function (event) {
            if (event.target.matches('[data-playlist]')) touchPick('playlist');
            else if (event.target.matches('[data-device]')) touchPick('device');
        });
        el.addEventListener('pointerdown', function (event) {
            if (event.target.matches('[data-playlist]')) touchPick('playlist');
            else if (event.target.matches('[data-device]')) touchPick('device');
        });
        el.addEventListener('pointerdown', function (event) {
            const handle = event.target.closest('[data-drag]');
            if (!handle) return;
            const pressed = event.target.closest('button, a, input, select, label');
            if (pressed && pressed !== handle) return;
            event.preventDefault();
            dragging = true;
            handle.classList.add('is-dragging');
            el.classList.add('is-dragging');
            if (handle.setPointerCapture) {
                try { handle.setPointerCapture(event.pointerId); } catch (err) { /* already released */ }
            }
            const startX = event.clientX;
            const startY = event.clientY;
            const originX = state.x;
            const originY = state.y;
            function move(ev) {
                if (!dragging) return;
                state.x = originX + ev.clientX - startX;
                state.y = originY + ev.clientY - startY;
                clamp(el);
                saveState();
            }
            function up() {
                if (!dragging) return;
                dragging = false;
                handle.classList.remove('is-dragging');
                el.classList.remove('is-dragging');
                clamp(el);
                saveState();
                handle.removeEventListener('pointermove', move);
                handle.removeEventListener('pointerup', up);
                handle.removeEventListener('pointercancel', up);
            }
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', up);
        });
    }

    function onSdkFailure(eventName) {
        return function (payload) {
            const message = payload && payload.message ? String(payload.message) : '';
            const reason = spotifySdkReason(eventName, message);
            if (sdkReady && reason === 'autoplay') {
                setSdk('autoplay');
            } else if (!sdkReady) {
                setSdk(reason, reason === 'auth' || reason === 'init' ? message : '');
            } else if (reason !== 'autoplay') {
                setSdk(reason, reason === 'auth' || reason === 'init' ? message : '');
            }
            const el = document.getElementById('sp-player');
            if (el) paintNote(el);
        };
    }

    function connectSdk() {
        player = new window.Spotify.Player({
            name: PLAYER_NAME,
            getOAuthToken: function (cb) {
                api('GET', '/spotify/playback/token').then(function (data) {
                    cb(data.access_token);
                }).catch(function () {
                    setSdk('token');
                    const el = document.getElementById('sp-player');
                    if (el) paintNote(el);
                });
            },
            volume: 0.5,
        });
        player.addListener('ready', function (ready) {
            deviceId = ready.device_id;
            sdkReady = true;
            sdkNote = '';
            sdkReason = '';
            const el = document.getElementById('sp-player');
            if (el) paintNote(el);
            if (config) refresh(config, true);
        });
        player.addListener('not_ready', function () { deviceId = null; });
        player.addListener('authentication_error', onSdkFailure('authentication_error'));
        player.addListener('account_error', onSdkFailure('account_error'));
        player.addListener('initialization_error', onSdkFailure('initialization_error'));
        player.addListener('playback_error', onSdkFailure('playback_error'));
        player.connect();
    }

    function startSdk(config) {
        if (player || sdkReady) return;
        const missing = config && config.missing_playback_scopes;
        if (missing && missing.length) {
            setSdk('scope');
            const el = document.getElementById('sp-player');
            if (el) paintNote(el);
            return;
        }
        if (!window.isSecureContext) {
            setSdk('https');
            const el = document.getElementById('sp-player');
            if (el) paintNote(el);
            return;
        }
        window.onSpotifyWebPlaybackSDKReady = connectSdk;
        if (window.Spotify && window.Spotify.Player) {
            connectSdk();
            return;
        }
        const script = document.createElement('script');
        script.src = 'https://sdk.scdn.co/spotify-player.js';
        script.async = true;
        script.onerror = function () {
            setSdk('script');
            const el = document.getElementById('sp-player');
            if (el) paintNote(el);
        };
        document.head.appendChild(script);
    }

    let config = null;
    let started = false;

    function paintToggles() {
        const on = state.on === true;
        document.querySelectorAll('[data-player-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.textContent = on ? 'Player on' : 'Player off';
        });
    }

    function hidePlayer() {
        const el = document.getElementById('sp-player');
        if (el) el.hidden = true;
        if (pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function showPlayer() {
        if (!config) return;
        const el = shell();
        el.hidden = false;
        render(config, null, [], []);
        if (!started) {
            bind(config);
            started = true;
        }
        if (!config.connected) return;
        startSdk(config);
        refresh(config, true);
        if (!pollTimer) {
            pollTimer = window.setInterval(function () {
                if (!document.hidden && !dragging) refresh(config, false);
            }, 5000);
        }
    }

    function setOn(on) {
        state.on = !!on;
        saveState();
        paintToggles();
        if (state.on) showPlayer();
        else hidePlayer();
    }

    async function boot() {
        await loadLucideIcons();
        try {
            config = await api('GET', '/spotify/player-config');
        } catch (err) {
            paintToggles();
            return;
        }
        if (state.on === null) state.on = !!config.enabled;
        paintToggles();
        if (state.on) showPlayer();
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && document.getElementById('sp-queue-modal')) {
            event.preventDefault();
            closeQueue();
        }
    });
    document.addEventListener('click', function (event) {
        const btn = event.target.closest('[data-player-toggle]');
        if (!btn) return;
        event.preventDefault();
        setOn(state.on !== true);
    });
    document.addEventListener('ld-spotify-player', function (event) {
        setOn(!!(event.detail && event.detail.on));
    });
    window.addEventListener('resize', function () {
        const el = document.getElementById('sp-player');
        if (!el || el.hidden) return;
        clamp(el);
        saveState();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
