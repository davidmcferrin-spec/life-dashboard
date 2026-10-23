/**
 * Music page. Tabs come from ?tab= and read the existing Spotify endpoints.
 */
'use strict';

(function () {
    const root = document.getElementById('spotify-app');
    const panel = document.getElementById('music-panel');
    const stage = document.getElementById('music-stage');
    const drawer = document.getElementById('music-drawer');
    const { api, esc } = window.LD || {};
    if (!root || !panel || !api || !esc) return;

    const tab = panel.dataset.tab || 'overview';
    const playlistId = panel.dataset.id || '';
    const statusEl = document.getElementById('music-status');
    const bannerEl = document.getElementById('music-banner');

    let playlists = [];
    let prompts = [];
    let seasons = [];
    let activeSeasons = [];
    let detail = null;
    let searchHits = [];
    let account = null;
    let playlistQuery = '';
    let playlistView = 'grid';
    let playlistOwner = 'all';
    let playlistSeason = 'all';
    let playlistMood = 'all';
    let playlistEnergy = 'all';
    let playlistCreated = 'all';
    let facets = {};
    let catalogStale = 0;
    let activeDevice = '';
    let libraryMode = 'saved';
    let libraryRows = [];
    let promptStamp = 0;
    let dragFrom = null;
    let timer = null;
    let drawerArtists = '';
    let drawerUri = '';
    let librarySelected = new Set();
    let libraryTrackOpened = false;

    function icon(name) {
        const node = document.getElementById('music-icon-' + name);
        return node ? node.innerHTML : '';
    }

    function say(text) {
        if (statusEl) statusEl.textContent = text || '';
    }

    function errText(err) {
        if (err && err.fields) {
            const bits = Object.keys(err.fields).map((key) => err.fields[key]).filter(Boolean);
            if (bits.length) return bits.join(' ');
        }
        return (err && err.message) || 'Spotify request failed.';
    }

    function isRate(err) {
        const msg = String((err && err.message) || '');
        return !!(err && (err.status === 429 || /\b429\b|rate limit|too many requests/i.test(msg)));
    }

    function showBanner(text) {
        if (!bannerEl) return;
        bannerEl.replaceChildren();
        if (!text) {
            bannerEl.hidden = true;
            return;
        }
        const strong = document.createElement('strong');
        strong.textContent = text;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn';
        button.dataset.dismissBanner = '';
        button.textContent = 'Dismiss';
        bannerEl.append(strong, button);
        bannerEl.hidden = false;
    }

    function fail(err) {
        const text = errText(err);
        if (isRate(err)) showBanner('Spotify is rate-limiting requests. ' + text);
        say(text);
        if (window.DashboardToast) window.DashboardToast.show(text, 'error');
    }

    function ok(text) {
        say(text);
        if (window.DashboardToast) window.DashboardToast.show(text, 'ok');
    }

    function needsSetup() {
        return window.DashboardEmpty.html({
            title: 'Spotify is not connected',
            body: 'Connect an account to see playlists, plan a mixtape, and read activity.',
            actionLabel: 'Connect Spotify',
            actionHref: '/settings/spotify',
        });
    }

    function emptyBlock(title, body, label, attrs) {
        return window.DashboardEmpty.html({
            title: title,
            body: body,
            actionLabel: label || '',
            actionAttrs: attrs || '',
            actionHref: label && !attrs ? '/settings/spotify' : '',
        });
    }

    function safeUrl(url) {
        return typeof url === 'string' && /^https:\/\//i.test(url) ? url : '';
    }

    function spotifyUrl(id) {
        return id && /^[A-Za-z0-9]+$/.test(id) ? 'https://open.spotify.com/playlist/' + id : '';
    }

    function clock(ms) {
        const total = Math.max(0, Math.round(Number(ms) / 1000));
        const minutes = Math.floor(total / 60);
        const seconds = total % 60;
        return minutes + ':' + String(seconds).padStart(2, '0');
    }

    function durationLabel(ms) {
        const total = Math.max(0, Math.round(Number(ms) / 1000));
        if (!total) return '0m';
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        return hours ? hours + 'h ' + minutes + 'm' : minutes + 'm';
    }

    function when(value) {
        const text = String(value || '').trim();
        if (!text) return '';
        const iso = text.includes('T') ? text : text.replace(' ', 'T');
        const dated = /[zZ]|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : iso + 'Z';
        const date = new Date(dated);
        if (Number.isNaN(date.getTime())) return text;
        return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function artistsOf(row) {
        return (row && row.artists || []).join(', ');
    }

    function arcMarkup(values) {
        const nums = (values || []).map((value) => (value === null || value === undefined || value === '' ? null : Number(value)));
        const known = nums.filter((value) => Number.isFinite(value));
        if (!known.length) return '<p class="music-empty-note">No energy notes on this sequence yet.</p>';
        const width = 620;
        const height = 130;
        const coords = [];
        nums.forEach((value, index) => {
            if (!Number.isFinite(value)) return;
            const x = nums.length === 1 ? width / 2 : (index / (nums.length - 1)) * width;
            const y = 118 - ((Math.min(10, Math.max(1, value)) - 1) / 9) * 96;
            coords.push([x, y]);
        });
        const line = coords.map((point, index) => (index ? 'L' : 'M') + point[0].toFixed(1) + ' ' + point[1].toFixed(1)).join(' ');
        const area = line + ' L' + width + ' ' + height + ' L0 ' + height + ' Z';
        const dots = coords.map((point) => '<circle cx="' + point[0].toFixed(1) + '" cy="' + point[1].toFixed(1) + '" r="3"></circle>').join('');
        return '<div class="music-arc"><svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" role="img" aria-label="Energy arc"><path class="music-arc-area" d="' + area + '"></path><path class="music-arc-line" d="' + line + '"></path>' + dots + '</svg></div>';
    }

    function energyCell(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return '<span class="music-muted">—</span>';
        const width = Math.max(0, Math.min(100, Math.round((number / 10) * 100)));
        return '<span class="music-energy" title="Energy ' + number + '"><i style="width:' + width + '%"></i></span><span>' + number + '</span>';
    }

    function cover(url, label) {
        const src = safeUrl(url);
        if (src) return '<span class="music-cover"><img src="' + esc(src) + '" alt=""></span>';
        return '<span class="music-cover" aria-hidden="true">' + icon('music-2') + '</span>';
    }

    function deviceIcon(type) {
        const kind = String(type || '').toLowerCase();
        if (kind === 'smartphone' || kind === 'tablet' || kind === 'automobile') return 'smartphone';
        if (kind === 'computer' || kind === 'tv' || kind === 'castvideo' || kind === 'gameconsole') return 'monitor';
        return 'speaker';
    }

    function owned(playlist) {
        return !!(playlist && playlist.is_owned);
    }

    function aiPlaylist(playlist) {
        return !!(playlist && playlist.origin === 'ai_created' && playlist.is_owned);
    }

    function readOnlyLabel(row) {
        if (!row || row.is_owned || !row.spotify_id) return '';
        if (row.owner_name) return 'Read-only (owned by ' + row.owner_name + ')';
        return 'Followed';
    }

    function paintConnection() {
        const badge = document.getElementById('music-connection');
        const actions = document.getElementById('music-actions');
        const connected = !!(account && account.connected);
        if (badge) {
            badge.classList.toggle('is-on', connected);
            const name = account && account.display_name ? ' · ' + account.display_name : '';
            badge.textContent = connected ? 'Spotify connected' + name : 'Not connected';
        }
        if (actions) actions.hidden = !connected;
    }

    function facetOf(row) {
        const bag = facets && !Array.isArray(facets) ? facets : {};
        return bag[String(row.id)] || null;
    }

    function energyMatch(facet, band) {
        if (band === 'all') return true;
        if (!facet || facet.energy_min === null || facet.energy_min === undefined) return false;
        const bands = { low: [1, 3], mid: [4, 7], high: [8, 10] };
        const range = bands[band];
        if (!range) return true;
        return Number(facet.energy_max) >= range[0] && Number(facet.energy_min) <= range[1];
    }

    function createdMatch(value, band) {
        if (band === 'all') return true;
        const text = String(value || '').trim();
        if (!text) return false;
        const iso = text.includes('T') ? text : text.replace(' ', 'T');
        const dated = /[zZ]|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : iso + 'Z';
        const date = new Date(dated);
        if (Number.isNaN(date.getTime())) return false;
        const now = Date.now();
        if (band === '7d') return now - date.getTime() <= 7 * 86400000;
        if (band === '30d') return now - date.getTime() <= 30 * 86400000;
        if (band === 'year') return date.getUTCFullYear() === new Date().getUTCFullYear();
        return true;
    }

    function choiceOptions(values, current, allLabel) {
        const tags = values.slice();
        if (current !== 'all' && tags.indexOf(current) === -1) tags.push(current);
        const options = ['<option value="all">' + esc(allLabel) + '</option>'];
        tags.forEach((tag) => {
            options.push('<option value="' + esc(tag) + '"' + (tag === current ? ' selected' : '') + '>' + esc(tag) + '</option>');
        });
        return options.join('');
    }

    function visiblePlaylists() {
        const query = playlistQuery.trim().toLowerCase();
        return playlists.filter((row) => {
            if (query) {
                const blob = (row.name + ' ' + (row.owner_name || '')).toLowerCase();
                if (!blob.includes(query)) return false;
            }
            if (playlistOwner === 'mine' && !row.is_owned) return false;
            if (playlistOwner === 'ai' && row.origin !== 'ai_created' && !row.managed_by_ai) return false;
            if (playlistOwner === 'followed' && (row.is_owned || !row.spotify_id)) return false;
            const facet = facetOf(row);
            if (playlistSeason !== 'all' && (!facet || (facet.seasons || []).indexOf(playlistSeason) === -1)) return false;
            if (playlistMood !== 'all' && (!facet || (facet.moods || []).indexOf(playlistMood) === -1)) return false;
            if (!energyMatch(facet, playlistEnergy)) return false;
            if (!createdMatch(facet && facet.created_at, playlistCreated)) return false;
            return true;
        });
    }

    function playlistCard(row) {
        const facet = facetOf(row);
        const bits = [row.track_count + ' tracks'];
        if (row.local_dirty) bits.push('staged');
        if (!row.spotify_id) bits.push('draft');
        if (row.owner_name) bits.push(row.owner_name);
        const updated = when(row.last_synced_at || (facet && facet.created_at) || '');
        if (updated) bits.push(updated);
        const mark = (row.origin === 'ai_created' || row.managed_by_ai) ? icon('bot') : '';
        const season = facet && facet.season ? facet.season : 'No season';
        const remote = spotifyUrl(row.spotify_id);
        const play = row.spotify_id ? '<button type="button" data-play-playlist="' + row.id + '">Play</button>' : '';
        const openRemote = remote ? '<a href="' + remote + '" target="_blank" rel="noopener noreferrer">Open in Spotify</a>' : '';
        return `
            <article class="music-playlist is-${playlistView}">
                <a class="music-playlist-open" href="?tab=playlists&id=${row.id}">
                    ${cover(row.image_url, row.name)}
                    <span class="music-playlist-copy">
                        <strong>${esc(row.name)}</strong>
                        <small>${esc(bits.join(' · '))}</small>
                        ${mark ? '<span class="music-owner">' + mark + '</span>' : ''}
                        ${row.sync_error ? '<span class="music-warn">' + esc(row.sync_error) + '</span>' : ''}
                    </span>
                </a>
                <span class="music-pill">${icon('cloud-sun')} ${esc(season)}</span>
                <details class="music-menu">
                    <summary class="music-icon-btn" aria-label="${esc(row.name)} actions">${icon('ellipsis')}</summary>
                    <div>
                        ${play}
                        ${openRemote}
                        <a href="?tab=playlists&id=${row.id}">Open</a>
                    </div>
                </details>
            </article>`;
    }

    function metaBits(meta) {
        if (!meta) return '';
        const bits = [];
        if (meta.bpm) bits.push(meta.bpm + ' bpm');
        if (meta.camelot) bits.push(meta.camelot);
        else if (meta.musical_key) bits.push(meta.musical_key);
        if (meta.mood_tags && meta.mood_tags.length) bits.push(meta.mood_tags.join(', '));
        if (meta.season_tags && meta.season_tags.length) bits.push(meta.season_tags.join(', '));
        return bits.join(' · ');
    }

    function changeSummary(change) {
        const payload = change.payload || {};
        if (change.op === 'add' || change.op === 'remove' || change.op === 'replace') {
            return ((payload.uris || []).length) + ' tracks';
        }
        if (change.op === 'rename') return payload.name || (payload.public ? 'public' : 'private');
        if (change.op === 'describe') return payload.description || 'description';
        if (change.op === 'reorder') return 'move from ' + (payload.range_start ?? '');
        if (change.op === 'create') return payload.name || 'create';
        return change.op;
    }

    function equalizer(playing) {
        // Heights are decoration. Whether the bars move follows the player.
        const heights = [18, 34, 25, 46, 30, 52, 22, 41, 28, 49, 20, 36];
        return '<span class="music-eq' + (playing ? ' is-on' : '') + '" aria-hidden="true">' + heights.map((height, index) => {
            return '<i style="--h:' + height + ';animation-delay:' + (index * 0.08) + 's"></i>';
        }).join('') + '</span>';
    }

    function spotifyTrackUrl(id) {
        return id && /^[A-Za-z0-9]+$/.test(id) ? 'https://open.spotify.com/track/' + id : 'https://open.spotify.com';
    }

    function transport(now) {
        const playing = !!(now && now.is_playing);
        const shuffle = !!(now && now.shuffle);
        const repeat = now && now.repeat ? now.repeat : 'off';
        const repeatOn = repeat !== 'off';
        return `<div class="music-transport">
            <button type="button" class="music-icon-btn${shuffle ? ' is-on' : ''}" data-transport="shuffle" data-on="${shuffle ? '1' : '0'}" aria-pressed="${shuffle ? 'true' : 'false'}" aria-label="Shuffle">${icon('shuffle')}</button>
            <button type="button" class="music-icon-btn" data-transport="previous" aria-label="Previous">${icon('skip-back')}</button>
            <button type="button" class="music-play" data-transport="toggle" data-playing="${playing ? '1' : '0'}" aria-label="${playing ? 'Pause' : 'Play'}">${icon(playing ? 'pause' : 'play')}</button>
            <button type="button" class="music-icon-btn" data-transport="next" aria-label="Next">${icon('skip-forward')}</button>
            <button type="button" class="music-icon-btn${repeatOn ? ' is-on' : ''}" data-transport="repeat" data-mode="${esc(repeat)}" aria-pressed="${repeatOn ? 'true' : 'false'}" aria-label="Repeat ${esc(repeat)}">${icon('repeat')}</button>
        </div>`;
    }

    async function renderOverview() {
        const [listRes, seasonRes, activeRes, nowRes, deviceRes, facetRes, catalogRes] = await Promise.all([
            api.get('/spotify/playlists').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/seasons').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/seasons/active').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/player').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/devices').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/playlists/facets').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/tracks/catalog').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
        ]);
        [listRes, seasonRes, activeRes, nowRes, deviceRes, facetRes, catalogRes].forEach((result) => {
            if (!result.ok) fail(result.error);
        });
        playlists = listRes.ok ? (listRes.value.playlists || []) : [];
        seasons = seasonRes.ok ? (seasonRes.value.seasons || []) : [];
        activeSeasons = activeRes.ok ? (activeRes.value.seasons || []) : [];
        facets = facetRes.ok && facetRes.value.facets && !Array.isArray(facetRes.value.facets) ? facetRes.value.facets : {};
        catalogStale = catalogRes.ok ? Number(catalogRes.value.stale || 0) : 0;
        const now = nowRes.ok ? nowRes.value.now : null;
        const devices = deviceRes.ok ? (deviceRes.value.devices || []) : null;
        const recent = playlists.slice().sort((a, b) => String(b.last_synced_at || '').localeCompare(String(a.last_synced_at || ''))).slice(0, 3);
        const aiRows = playlists.filter((row) => row.origin === 'ai_created' || row.managed_by_ai).slice(0, 6);
        const open = new Set(activeSeasons.map((row) => row.season));
        const playing = !!(now && now.is_playing);
        const staleLine = catalogStale > 0
            ? catalogStale + ' stored tracks are older than a day'
            : 'Names, art, and length from Spotify';
        const listen = spotifyTrackUrl(now && now.id);
        panel.innerHTML = `
            <div class="music-hero">
                <div class="music-hero-art">${now && safeUrl(now.image_url) ? '<img src="' + esc(safeUrl(now.image_url)) + '" alt="">' : icon('music-2')}${equalizer(playing)}</div>
                <div class="music-hero-copy">
                    <p class="music-kicker">${now && now.name ? (playing ? 'Now playing' : 'Paused') : 'Player'}</p>
                    <h2>${esc(now && now.name ? now.name : 'Nothing is playing')}</h2>
                    <p class="music-muted">${esc(now && now.name ? (artistsOf(now) + (now.album ? ' · ' + now.album : '')) : 'Playback uses the Spotify account already connected.')}</p>
                    ${now && now.duration_ms ? progressBar(now) : ''}
                    ${transport(now)}
                </div>
                <div>${deviceBlock(devices, now)}</div>
            </div>
            <div class="music-overview">
                <section class="music-card music-span">
                    <header class="music-card-head">
                        <div><p class="music-kicker">Your rotation</p><h2 class="music-card-title">Recent playlists</h2></div>
                        <a class="music-inline" href="?tab=playlists">View all ${icon('chevron-right')}</a>
                    </header>
                    ${recent.length ? '<div class="music-stack">' + recent.map(playlistCard).join('') + '</div>' : emptyBlock('No playlists yet', 'Sync from Spotify, or create one here.', 'Sync', 'type="button" data-sync')}
                </section>
                <section class="music-card">
                    <header class="music-card-head"><div><p class="music-kicker">Music tools</p><h2 class="music-card-title">Quick actions</h2></div></header>
                    <div class="music-quick">
                        <a href="?tab=builder">${icon('wand-sparkles')}<span><strong>New mixtape</strong><small>Plan a sequence from seeds</small></span>${icon('chevron-right')}</a>
                        <button type="button" data-refresh-meta>${icon('refresh-cw')}<span><strong>Refresh metadata</strong><small>${esc(staleLine)}</small></span>${icon('chevron-right')}</button>
                        <a href="${esc(listen)}" target="_blank" rel="noopener noreferrer">${icon('external-link')}<span><strong>Open in Spotify</strong><small>${now && now.name ? 'This track' : 'Web player'}</small></span>${icon('external-link')}</a>
                    </div>
                </section>
                <section class="music-card music-span">
                    <header class="music-card-head"><div><p class="music-kicker">Created here</p><h2 class="music-card-title">AI playlists</h2></div></header>
                    ${aiRows.length ? '<div class="music-stack">' + aiRows.map(playlistCard).join('') + '</div>' : '<p class="music-muted">No AI playlists yet. The mixtape builder can stage one.</p>'}
                </section>
                <section class="music-card">
                    <header class="music-card-head">
                        <div><p class="music-kicker">Windows</p><h2 class="music-card-title">Seasons</h2></div>
                        <a class="music-inline" href="?tab=seasons">Manage ${icon('chevron-right')}</a>
                    </header>
                    ${seasonList(seasons, open)}
                </section>
            </div>`;
        if (account && account.last_sync_error) say(account.last_sync_error);
    }

    function progressBar(now) {
        const max = Number(now.duration_ms) || 0;
        const value = Math.max(0, Number(now.progress_ms) || 0);
        const width = max ? Math.max(0, Math.min(100, Math.round((value / max) * 100))) : 0;
        return '<div class="music-progress" role="progressbar" aria-valuemin="0" aria-valuemax="' + max + '" aria-valuenow="' + Math.min(value, max) + '"><i style="width:' + width + '%"></i></div><p class="music-muted">' + esc(clock(value)) + ' / ' + esc(clock(max)) + '</p>';
    }

    function flags(now) {
        const repeat = now.repeat && now.repeat !== 'off';
        return `<p class="music-flags">
            <span class="music-flag${now.shuffle ? ' is-on' : ''}">${icon('shuffle')} Shuffle ${now.shuffle ? 'on' : 'off'}</span>
            <span class="music-flag${repeat ? ' is-on' : ''}">${icon('repeat')} Repeat ${esc(now.repeat || 'off')}</span>
        </p>`;
    }

    function deviceBlock(devices, now) {
        if (devices === null) {
            activeDevice = '';
            return '<p class="music-muted">Devices unavailable.</p>';
        }
        if (!devices.length) {
            activeDevice = '';
            return emptyBlock('No device', 'Open Spotify on a phone, computer, or speaker.', '', '');
        }
        const active = devices.find((device) => device.is_active) || devices.find((device) => now && now.device && device.name === now.device) || null;
        activeDevice = active && active.id ? active.id : '';
        const volume = active && active.volume_percent !== null && active.volume_percent !== undefined
            ? `<label class="music-volume">${icon('volume-2')}<input id="music-volume" type="range" min="0" max="100" value="${Number(active.volume_percent)}" data-device="${esc(active.id || '')}" aria-label="Volume"></label>`
            : '<p class="music-muted">This device does not report volume.</p>';
        const items = devices.map((device) => {
            if (!device.id) return '';
            const on = !!(active && device.id === active.id);
            const level = device.volume_percent === null || device.volume_percent === undefined ? '' : device.volume_percent + '%';
            return `<li><button type="button" class="music-device${on ? ' is-on' : ''}" data-transfer="${esc(device.id)}" aria-pressed="${on ? 'true' : 'false'}">${icon(deviceIcon(device.type))}<span><strong>${esc(device.name || device.type || 'Device')}</strong><small>${esc(device.type || '')}${level ? ' · ' + esc(level) : ''}</small></span>${on ? icon('check') : ''}</button></li>`;
        }).join('');
        return volume + '<p class="music-kicker">Playing on</p><ul class="music-devices">' + items + '</ul>';
    }

    function seasonList(rows, open) {
        if (!rows.length) return '<p class="music-muted">No season windows yet.</p>';
        return '<ul class="music-season-mini">' + rows.slice(0, 4).map((row) => {
            const start = row.start_anchor === 'thanksgiving' ? 'Thanksgiving' : ((row.start_month || '—') + '/' + (row.start_day || '—'));
            const counts = (Number(row.track_count) || 0) + ' tracks';
            return `<li><span class="music-season-symbol">${icon('cloud-sun')}</span><span><strong>${esc(row.season)}</strong><small>${esc(start)} – ${row.end_month}/${row.end_day} · ${esc(counts)} · ${open.has(row.season) ? 'open' : 'closed'}</small></span></li>`;
        }).join('') + '</ul>';
    }

    function facetChoices() {
        const seasonsFound = [];
        const moodsFound = [];
        playlists.forEach((row) => {
            const facet = facetOf(row);
            if (!facet) return;
            (facet.seasons || []).forEach((tag) => {
                if (seasonsFound.indexOf(tag) === -1) seasonsFound.push(tag);
            });
            (facet.moods || []).forEach((tag) => {
                if (moodsFound.indexOf(tag) === -1) moodsFound.push(tag);
            });
        });
        seasonsFound.sort();
        moodsFound.sort();
        return { seasons: seasonsFound, moods: moodsFound };
    }

    async function renderPlaylistTab() {
        const [res, facetRes] = await Promise.all([
            api.get('/spotify/playlists'),
            api.get('/spotify/playlists/facets'),
        ]);
        playlists = res.playlists || [];
        facets = facetRes.facets && !Array.isArray(facetRes.facets) ? facetRes.facets : {};
        const choices = facetChoices();
        panel.innerHTML = `
            <div class="music-tools">
                <label class="music-search">${icon('search')}
                    <input id="music-playlist-q" class="input" type="search" maxlength="200" placeholder="Search playlists" value="${esc(playlistQuery)}">
                </label>
                <label class="music-filter">Owner
                    <select id="music-playlist-owner" class="input">
                        <option value="all"${playlistOwner === 'all' ? ' selected' : ''}>All</option>
                        <option value="mine"${playlistOwner === 'mine' ? ' selected' : ''}>Mine</option>
                        <option value="ai"${playlistOwner === 'ai' ? ' selected' : ''}>AI</option>
                        <option value="followed"${playlistOwner === 'followed' ? ' selected' : ''}>Followed</option>
                    </select>
                </label>
                <label class="music-filter">Season
                    <select id="music-playlist-season" class="input">${choiceOptions(choices.seasons, playlistSeason, 'Any season')}</select>
                </label>
                <label class="music-filter">Mood
                    <select id="music-playlist-mood" class="input">${choiceOptions(choices.moods, playlistMood, 'Any mood')}</select>
                </label>
                <label class="music-filter">Energy
                    <select id="music-playlist-energy" class="input">
                        <option value="all"${playlistEnergy === 'all' ? ' selected' : ''}>Any energy</option>
                        <option value="low"${playlistEnergy === 'low' ? ' selected' : ''}>Low (1–3)</option>
                        <option value="mid"${playlistEnergy === 'mid' ? ' selected' : ''}>Mid (4–7)</option>
                        <option value="high"${playlistEnergy === 'high' ? ' selected' : ''}>High (8–10)</option>
                    </select>
                </label>
                <label class="music-filter">Created
                    <select id="music-playlist-created" class="input">
                        <option value="all"${playlistCreated === 'all' ? ' selected' : ''}>Any time</option>
                        <option value="7d"${playlistCreated === '7d' ? ' selected' : ''}>Last 7 days</option>
                        <option value="30d"${playlistCreated === '30d' ? ' selected' : ''}>Last 30 days</option>
                        <option value="year"${playlistCreated === 'year' ? ' selected' : ''}>This year</option>
                    </select>
                </label>
                <div class="music-segment">
                    <button type="button" class="music-icon-btn${playlistView === 'grid' ? ' is-on' : ''}" data-view="grid" aria-label="Grid" aria-pressed="${playlistView === 'grid'}">${icon('layout-dashboard')}</button>
                    <button type="button" class="music-icon-btn${playlistView === 'list' ? ' is-on' : ''}" data-view="list" aria-label="List" aria-pressed="${playlistView === 'list'}">${icon('list-music')}</button>
                </div>
            </div>
            <div id="music-playlist-results"></div>`;
        paintPlaylists();
    }

    function paintPlaylists() {
        const host = document.getElementById('music-playlist-results');
        if (!host) return;
        const rows = visiblePlaylists();
        if (!playlists.length) {
            host.innerHTML = emptyBlock('No playlists', 'Sync from Spotify, or create one here.', 'New playlist', 'type="button" data-new-playlist');
            return;
        }
        if (!rows.length) {
            host.innerHTML = '<p class="music-muted">No playlists match.</p>';
            return;
        }
        host.innerHTML = '<div class="music-grid is-' + playlistView + '">' + rows.map(playlistCard).join('') + '</div>';
    }

    async function renderDetail(id) {
        detail = await api.get('/spotify/playlists/' + id);
        prompts = [];
        if (aiPlaylist(detail.playlist)) {
            const promptRes = await api.get('/spotify/prompts');
            prompts = promptRes.prompts || [];
        }
        paintDetail();
    }

    function paintDetail() {
        if (!detail) return;
        const playlist = detail.playlist;
        const preview = detail.preview || [];
        const canEdit = owned(playlist);
        const label = readOnlyLabel(playlist);
        const remote = spotifyUrl(playlist.spotify_id);
        const changes = (detail.changes || []).map((change) => {
            const failed = change.status === 'failed' ? ' — ' + esc(change.error || 'failed') : '';
            return '<li>' + esc(change.op) + ': ' + esc(changeSummary(change)) + failed + '</li>';
        }).join('');
        const ms = preview.reduce((sum, track) => sum + (Number(track.duration_ms) || 0), 0);
        panel.innerHTML = `
            <a class="music-back" href="?tab=playlists">Back to playlists</a>
            <header class="music-detail-head">
                ${cover(playlist.image_url, playlist.name)}
                <div>
                    <p class="music-kicker">${playlist.public ? 'Public' : 'Private'}${playlist.spotify_id ? '' : ' · draft'}${playlist.local_dirty ? ' · staged' : ''}</p>
                    <h2>${esc(playlist.name)}</h2>
                    <p class="music-muted">${esc(playlist.description || '')}</p>
                    <p class="music-chips"><span class="music-chip">${preview.length} in this order</span><span class="music-chip">${esc(durationLabel(ms))}</span>${playlist.owner_name ? '<span class="music-chip">' + esc(playlist.owner_name) + '</span>' : ''}</p>
                    ${detailTags(preview)}
                    ${label ? '<p class="music-warn">' + esc(label) + '</p>' : ''}
                    ${playlist.sync_error ? '<p class="music-warn">' + esc(playlist.sync_error) + '</p>' : ''}
                </div>
                <div class="music-inline">
                    ${canEdit ? '<button type="button" class="btn" data-edit-details>' + icon('pencil') + ' Edit details</button>' : ''}
                    ${playlist.spotify_id ? '<button type="button" class="btn-primary" data-play-playlist="' + playlist.id + '">' + icon('play') + ' Play</button>' : ''}
                    <button type="button" class="btn" data-sync-one="${playlist.id}">Sync</button>
                    ${canEdit ? '<button type="button" class="btn" data-push="' + playlist.id + '">Push</button>' : ''}
                    ${canEdit ? '<button type="button" class="btn" data-discard="' + playlist.id + '">Discard</button>' : ''}
                    ${remote ? '<a class="btn" href="' + remote + '" target="_blank" rel="noopener noreferrer">Open in Spotify</a>' : ''}
                </div>
            </header>
            <section class="music-card">
                <header class="music-card-head"><div><p class="music-kicker">Running order</p><h2 class="music-card-title">Energy arc</h2></div></header>
                ${arcMarkup(preview.map((track) => track.meta ? track.meta.energy : null))}
            </section>
            ${canEdit ? `<form id="music-rename" class="music-form" hidden>
                    <label class="music-field">Name <input name="name" class="input" maxlength="100" value="${esc(playlist.name)}"></label>
                    <label class="music-field">Description <input name="description" class="input" maxlength="300" value="${esc(playlist.description || '')}"></label>
                    <label class="music-check"><input type="checkbox" name="public" ${playlist.public ? 'checked' : ''}> Public</label>
                    <button type="submit" class="btn">Stage rename</button>
                </form>` : ''}
            ${changes ? '<ul class="music-diff">' + changes + '</ul>' : '<p class="music-muted">No staged changes.</p>'}
            ${canEdit ? `<form id="music-search" class="music-tools">
                <label class="music-search">${icon('search')}<input name="q" class="input" maxlength="200" placeholder="Search Spotify to add a track"></label>
                <button type="submit" class="btn">Search</button>
            </form>` : ''}
            ${searchHits.length ? '<ul class="music-hit-list">' + searchHits.map((hit) => `
                <li><span>${esc(hit.name)} <span class="music-muted">${esc(artistsOf(hit))}</span></span>
                <span class="music-inline">
                    <button type="button" class="music-linkish" data-meta="${esc(hit.uri)}" data-meta-name="${esc(hit.name)}" data-meta-artists="${esc(artistsOf(hit))}">Notes</button>
                    ${canEdit ? '<button type="button" class="btn" data-add="' + esc(hit.uri) + '" data-name="' + esc(hit.name) + '" data-artists="' + esc((hit.artists || []).join('|')) + '">Add</button>' : ''}
                </span></li>`).join('') + '</ul>' : ''}
            <section class="music-card">
                <header class="music-card-head"><div><p class="music-kicker">${canEdit ? 'Drag to reorder' : 'Tracks'}</p><h2 class="music-card-title">Track sequence</h2></div>${canEdit ? '<button type="button" class="btn" data-add-tracks>' + icon('plus') + ' Add tracks</button>' : ''}</header>
                ${trackTable(preview, canEdit)}
            </section>
            ${aiPlaylist(playlist) ? promptBlock(playlist) : ''}`;
    }

    function detailTags(preview) {
        const seasonsFound = [];
        const moodsFound = [];
        preview.forEach((track) => {
            const meta = track.meta || {};
            (meta.season_tags || []).forEach((tag) => {
                if (seasonsFound.indexOf(tag) === -1) seasonsFound.push(tag);
            });
            (meta.mood_tags || []).forEach((tag) => {
                if (moodsFound.indexOf(tag) === -1) moodsFound.push(tag);
            });
        });
        const bits = seasonsFound.map((tag) => '<span class="music-pill">' + icon('cloud-sun') + ' ' + esc(tag) + '</span>');
        moodsFound.forEach((tag) => bits.push('<span class="music-chip">' + esc(tag) + '</span>'));
        return bits.length ? '<p class="music-tags">' + bits.join('') + '</p>' : '';
    }

    function trackMenu(track, canEdit) {
        const remoteId = track.id && /^[A-Za-z0-9]+$/.test(track.id) ? track.id : '';
        const openRemote = remoteId ? '<a href="https://open.spotify.com/track/' + remoteId + '" target="_blank" rel="noopener noreferrer">Open in Spotify</a>' : '';
        const remove = canEdit ? '<button type="button" data-remove="' + esc(track.uri) + '">Remove</button>' : '';
        return `<details class="music-menu">
            <summary class="music-icon-btn" aria-label="${esc(track.name)} actions">${icon('ellipsis')}</summary>
            <div>
                <button type="button" data-play-uri="${esc(track.uri)}">Play</button>
                <button type="button" data-meta="${esc(track.uri)}" data-meta-name="${esc(track.name)}" data-meta-artists="${esc(artistsOf(track))}">Notes</button>
                ${openRemote}
                ${remove}
            </div>
        </details>`;
    }

    function trackTable(preview, canEdit) {
        if (!preview.length) return '<p class="music-muted">No tracks.</p>';
        const rows = preview.map((track, index) => {
            const meta = track.meta || {};
            const move = canEdit ? `
                <button type="button" class="music-drag" draggable="true" data-drag="${index}" aria-label="Drag to reorder">${icon('grip-vertical')}</button>
                <button type="button" class="music-linkish" data-up="${index}">Up</button>
                <button type="button" class="music-linkish" data-down="${index}">Down</button>` : '';
            const seasonsCell = meta.season_tags && meta.season_tags.length
                ? meta.season_tags.map((tag) => '<span class="music-pill">' + icon('cloud-sun') + ' ' + esc(tag) + '</span>').join(' ')
                : '—';
            return `<tr data-row="${index}">
                <td>${move}</td>
                <td>${String(index + 1).padStart(2, '0')}</td>
                <td><button type="button" class="music-icon-btn" data-play-uri="${esc(track.uri)}" aria-label="Play ${esc(track.name)}">${icon('play')}</button> <strong>${esc(track.name)}</strong></td>
                <td>${esc(artistsOf(track))}</td>
                <td>${meta.bpm ? esc(meta.bpm) : '—'}</td>
                <td>${esc(meta.camelot || meta.musical_key || '—')}</td>
                <td class="music-energy-cell">${energyCell(meta.energy)}</td>
                <td>${meta.mood_tags && meta.mood_tags.length ? '<span class="music-chip">' + esc(meta.mood_tags[0]) + '</span>' : '—'}</td>
                <td>${seasonsCell}</td>
                <td>${clock(track.duration_ms)}</td>
                <td>${trackMenu(track, canEdit)}</td>
            </tr>`;
        }).join('');
        return `<div class="music-table-wrap"><table class="music-table"><thead><tr><th></th><th>#</th><th>Title</th><th>Artist</th><th>BPM</th><th>Key</th><th>Energy</th><th>Mood</th><th>Season</th><th>Length</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`;
    }

    function promptBlock(playlist) {
        const rows = prompts.filter((row) => Number(row.target_playlist_id) === Number(playlist.id) || !row.target_playlist_id);
        const list = rows.length ? rows.map((row) => `
            <article class="music-prompt">
                <strong>${esc(row.name)}</strong>
                <p>${esc(row.prompt)}</p>
                <p class="music-muted">${esc(promptMeta(row))}</p>
                <button type="button" class="music-linkish" data-prompt-edit="${row.id}">Edit</button>
                <button type="button" class="music-linkish" data-prompt-run="${row.id}">Mark run</button>
                <button type="button" class="music-linkish" data-prompt-remove="${row.id}">Remove</button>
            </article>`).join('') : emptyBlock('No prompts', 'Save a prompt to reuse when this playlist is built.', 'Write a prompt', 'type="button" data-focus-prompt');
        return `<section class="music-card" id="music-prompts">
            <header class="music-card-head"><div><p class="music-kicker">AI playlist</p><h2 class="music-card-title">Prompts</h2></div></header>
            <form id="music-prompt-form" class="music-form">
                <input type="hidden" name="target_playlist_id" value="${playlist.id}">
                <label class="music-field">Name <input name="name" class="input" maxlength="120" required></label>
                <label class="music-field">Prompt <textarea name="prompt" class="input" rows="4" maxlength="8000" required></textarea></label>
                <label class="music-field">Schedule <input name="refresh_schedule" class="input" maxlength="32" placeholder="weekly"></label>
                <label class="music-field">Notes <input name="notes" class="input" maxlength="2000"></label>
                <label class="music-field">Theme <input name="theme" class="input" maxlength="500"></label>
                <div class="music-split">
                    ${['opener', 'build', 'peak', 'breather', 'closer'].map((section) => `<label class="music-field">${section}<input name="arc_${section}" class="input" inputmode="numeric" maxlength="2"></label>`).join('')}
                </div>
                <label class="music-field">Season tags <input name="season_tags" class="input" placeholder="christmas"></label>
                <label class="music-field">Allow out of season <input name="allow_seasons" class="input" placeholder="christmas"></label>
                <button type="submit" class="btn-primary">Save prompt</button>
            </form>
            <div id="music-prompt-list">${list}</div>
        </section>`;
    }

    function promptMeta(row) {
        const bits = [];
        if (row.theme) bits.push(row.theme);
        if (row.arc) bits.push(['opener', 'build', 'peak', 'breather', 'closer'].map((section) => row.arc[section]).join('-'));
        if (row.season_tags && row.season_tags.length) bits.push(row.season_tags.join(', '));
        if (row.allow_seasons && row.allow_seasons.length) bits.push('allows ' + row.allow_seasons.join(', '));
        if (row.refresh_schedule) bits.push(row.refresh_schedule);
        bits.push(row.last_run_at ? 'Last run ' + row.last_run_at : 'Not run');
        if (!row.active) bits.push('paused');
        return bits.join(' · ');
    }

    async function renderBuilder() {
        const [all, active] = await Promise.all([
            api.get('/spotify/seasons'),
            api.get('/spotify/seasons/active'),
        ]);
        seasons = all.seasons || [];
        activeSeasons = active.seasons || [];
        if (!window.SpotifyMixtape) {
            panel.innerHTML = emptyBlock('Builder unavailable', 'Reload the page.', 'Spotify settings', '');
            return;
        }
        window.SpotifyMixtape.mount(panel, {
            api: api,
            esc: esc,
            icon: icon,
            seasons: seasons,
            active: active,
            arc: arcMarkup,
            duration: durationLabel,
            say: say,
            fail: fail,
            ok: ok,
            onCreated: (id) => { window.location.href = '?tab=playlists&id=' + id; },
        });
    }

    function genreLabels(meta) {
        return ((meta && meta.genre_tags) || []).map((tag) => (typeof tag === 'string' ? tag : (tag && tag.name) || '')).filter(Boolean);
    }

    function needsReview(row) {
        const meta = row.meta || {};
        // Review means stored BPM, energy, and genre are all missing.
        return !meta.bpm && !meta.energy && !genreLabels(meta).length;
    }

    function sourceLabel(source) {
        if (source === 'manual') return 'Manual';
        if (source === 'estimated') return 'Estimated';
        return '—';
    }

    function selectedLibrary() {
        return libraryRows.filter((row) => row.uri && librarySelected.has(row.uri));
    }

    function syncLibraryAll() {
        const boxes = panel.querySelectorAll('[data-library-check]');
        const all = panel.querySelector('[data-library-all]');
        if (!all || !boxes.length) return;
        const on = Array.from(boxes).filter((box) => box.checked).length;
        all.checked = on === boxes.length;
        all.indeterminate = on > 0 && on < boxes.length;
    }

    async function libraryPack(rows) {
        const uris = rows.map((row) => row.uri).filter(Boolean);
        const res = await api.get('/spotify/library/notes?uris=' + encodeURIComponent(uris.join(',')));
        const byUri = {};
        (res.tracks || []).forEach((meta) => { byUri[meta.uri] = meta; });
        rows.forEach((row) => { row.meta = byUri[row.uri] || null; });
        return res;
    }

    async function renderLibrary() {
        let rows = [];
        if (libraryMode === 'top') {
            const res = await api.get('/spotify/top?kind=track&time_range=medium_term');
            rows = (res.items || []).filter((row) => row.kind === 'track').map(fromTop);
        } else if (libraryMode === 'search') {
            rows = libraryRows.slice();
        } else {
            const res = await api.get('/spotify/saved?limit=50');
            rows = (res.saved || []).map((row) => row.track || {}).filter((track) => track.uri);
        }
        if (libraryMode !== 'search') libraryRows = rows;
        const shown = libraryMode === 'search' ? libraryRows : rows;
        const pack = await libraryPack(shown);
        const review = shown.filter(needsReview).length;
        const kicker = shown.length + ' shown' + (review ? ' · ' + review + ' need review' : '');
        const seasonOptions = (pack.seasons || []).map((season) => '<option value="' + esc(season) + '">' + esc(season) + '</option>').join('');
        const playlistOptions = (pack.playlists || []).map((row) => '<option value="' + esc(row.id) + '">' + esc(row.name) + '</option>').join('');
        panel.innerHTML = `
            <form id="music-library-search" class="music-tools">
                <label class="music-search">${icon('search')}<input name="q" class="input" maxlength="200" placeholder="Search Spotify" value="${esc(libraryMode === 'search' ? libraryQuery : '')}"></label>
                <button type="submit" class="btn">Search</button>
                <button type="button" class="btn${libraryMode === 'saved' ? ' is-on' : ''}" data-library="saved">Saved</button>
                <button type="button" class="btn${libraryMode === 'top' ? ' is-on' : ''}" data-library="top">Top</button>
            </form>
            <div class="music-tools">
                <button type="button" class="btn" data-library-analyze>${icon('refresh-cw')} Re-analyze</button>
                ${seasonOptions ? '<label class="music-field">Season <select id="music-library-season" class="input">' + seasonOptions + '</select></label>' : ''}
                <button type="button" class="btn" data-library-season>${icon('cloud-sun')} Set season</button>
                ${playlistOptions ? '<label class="music-field">Playlist <select id="music-library-playlist" class="input">' + playlistOptions + '</select></label>' : ''}
                <button type="button" class="btn" data-library-add>${icon('plus')} Add to playlist</button>
            </div>
            <section class="music-card">
                <header class="music-card-head"><div><p class="music-kicker">${esc(kicker)}</p><h2 class="music-card-title">Track metadata</h2></div></header>
                ${libraryTable(shown)}
            </section>`;
        syncLibraryAll();
        const wanted = new URLSearchParams(window.location.search).get('track') || '';
        if (!libraryTrackOpened && wanted) {
            const row = shown.find((item) => item.uri === wanted);
            await openDrawer(wanted, row ? (row.name || '') : '', row ? artistsOf(row) : '');
            libraryTrackOpened = true;
        }
    }

    function fromTop(row) {
        const payload = row.payload || {};
        let artists = [];
        if (Array.isArray(payload.artists)) {
            artists = payload.artists.map((artist) => (typeof artist === 'string' ? artist : (artist && artist.name) || '')).filter(Boolean);
        }
        return {
            uri: 'spotify:track:' + row.spotify_id,
            name: row.name,
            artists: artists,
            album: payload.album && payload.album.name ? payload.album.name : (payload.album || ''),
            duration_ms: payload.duration_ms || 0,
        };
    }

    function libraryTable(rows) {
        if (!rows.length) {
            const title = libraryMode === 'search' ? 'No matches' : (libraryMode === 'top' ? 'No top tracks' : 'No saved tracks');
            const body = libraryMode === 'search' ? 'Try another search.' : 'Sync from Spotify, or search for a track.';
            return emptyBlock(title, body, libraryMode === 'search' ? '' : 'Sync', libraryMode === 'search' ? '' : 'type="button" data-sync');
        }
        const body = rows.map((row) => {
            const meta = row.meta || {};
            const artists = artistsOf(row);
            const genres = genreLabels(meta);
            const genreCell = '<div class="music-genres">' + (genres.length ? genres.map((name) => '<span class="music-chip">' + esc(name) + '</span>').join('') : '<span class="music-muted">—</span>') + '<button type="button" class="music-icon-btn" data-meta="' + esc(row.uri) + '" data-meta-name="' + esc(row.name || '') + '" data-meta-artists="' + esc(artists) + '" data-focus-genre="1" aria-label="Add genre">' + icon('plus') + '</button></div>';
            const mood = meta.mood_tags && meta.mood_tags.length ? esc(meta.mood_tags.join(', ')) : '—';
            const season = meta.season_tags && meta.season_tags.length ? meta.season_tags.map((tag) => '<span class="music-chip">' + esc(tag) + '</span>').join(' ') : '—';
            const checked = librarySelected.has(row.uri) ? ' checked' : '';
            const selected = row.uri === drawerUri ? ' is-selected' : '';
            return `<tr class="${selected.trim()}" data-library-row data-uri="${esc(row.uri)}" data-name="${esc(row.name || '')}" data-artists="${esc(artists)}">
                <td><input type="checkbox" data-library-check value="${esc(row.uri)}"${checked} aria-label="Select ${esc(row.name || 'track')}"></td>
                <td><strong>${esc(row.name || row.uri)}</strong></td>
                <td>${esc(artists)}</td>
                <td>${meta.bpm ? esc(meta.bpm) : '—'}</td>
                <td>${esc(meta.camelot || meta.musical_key || '—')}</td>
                <td class="music-energy-cell">${energyCell(meta.energy)}</td>
                <td><button type="button" class="music-linkish" data-meta="${esc(row.uri)}" data-meta-name="${esc(row.name || '')}" data-meta-artists="${esc(artists)}">${mood} ${icon('pencil')}</button></td>
                <td>${season}</td>
                <td>${genreCell}</td>
                <td>${esc(sourceLabel(meta.source))}</td>
            </tr>`;
        }).join('');
        return `<div class="music-table-wrap"><table class="music-table"><thead><tr><th><input type="checkbox" data-library-all aria-label="Select all tracks"></th><th>Track</th><th>Artist</th><th>BPM</th><th>Key</th><th>Energy</th><th>Mood</th><th>Season</th><th>Genre</th><th>Source</th></tr></thead><tbody>${body}</tbody></table></div>`;
    }

    function monthOn(month) {
        return seasons.some((season) => {
            const start = season.start_anchor === 'thanksgiving' ? 11 : Number(season.start_month);
            const end = Number(season.end_month);
            if (!start || !end) return false;
            if (start <= end) return month >= start && month <= end;
            return month >= start || month <= end;
        });
    }

    async function renderSeasons() {
        const [all, active] = await Promise.all([
            api.get('/spotify/seasons'),
            api.get('/spotify/seasons/active'),
        ]);
        seasons = all.seasons || [];
        activeSeasons = active.seasons || [];
        const open = new Set(activeSeasons.map((row) => row.season));
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        panel.innerHTML = `
            <div class="music-season-strip">${months.map((label, index) => `<span class="${monthOn(index + 1) ? 'is-on' : ''}">${label}</span>`).join('')}</div>
            <header class="music-card-head">
                <div><h2 class="music-card-title">Season windows</h2><p class="music-muted">A track tagged for a closed season is refused unless that season allows it, or the prompt does.</p></div>
                <button type="button" class="btn-primary" data-add-season>${icon('plus')} Add season</button>
            </header>
            ${seasons.length ? '<div class="music-season-grid">' + seasons.map((row) => seasonCard(row, open.has(row.season))).join('') + '</div>' : '<p class="music-muted">No season windows yet.</p>'}`;
    }

    function seasonCard(row, isOpen) {
        const start = row.start_anchor === 'thanksgiving' ? 'Thanksgiving' : ((row.start_month || '—') + '/' + (row.start_day || '—'));
        const counts = (Number(row.playlist_count) || 0) + ' playlists · ' + (Number(row.track_count) || 0) + ' tracks';
        return `<article class="music-season-card">
            <header><span class="music-season-symbol">${icon('cloud-sun')}</span>
                <details class="music-menu">
                    <summary class="music-icon-btn" aria-label="${esc(row.season)} actions">${icon('ellipsis')}</summary>
                    <div><button type="button" data-season="${esc(row.season)}">Edit window</button></div>
                </details>
            </header>
            <h3>${esc(row.season)}</h3>
            <div class="music-season-range"><span><small>Start</small>${esc(start)}</span><i></i><span><small>End</small>${row.end_month}/${row.end_day}</span></div>
            <p>${esc(counts)}</p>
            <p class="music-muted">${isOpen ? 'Open today' : 'Closed today'}</p>
            <footer class="music-season-foot">
                <span>Allow outside window</span>
                <button type="button" class="music-toggle${row.allow_outside ? ' is-on' : ''}" data-season-allow="${esc(row.season)}" aria-pressed="${row.allow_outside ? 'true' : 'false'}" aria-label="Allow ${esc(row.season)} outside its window"><i></i></button>
            </footer>
        </article>`;
    }

    async function renderActivity() {
        const res = await api.get('/spotify/library/activity');
        const monitor = res.monitoring || { state: 'idle', label: 'No sync recorded' };
        const badge = monitor.state === 'ok' ? ' is-on' : (monitor.state === 'error' ? ' is-down' : '');
        const rows = (res.entries || []).map((item) => {
            const undo = item.can_undo && item.id
                ? '<button type="button" class="btn" data-undo="' + esc(item.id) + '">' + icon('undo-2') + ' Undo</button>'
                : '';
            const view = item.href
                ? '<a class="btn" href="' + esc(item.href) + '">' + icon('eye') + ' View</a>'
                : '';
            return `<article>
                <span class="music-timeline-mark">${icon(item.icon || 'music-2')}</span>
                <time datetime="${esc(item.at || '')}">${esc(when(item.at))}</time>
                <div>
                    <h3>${esc(item.title || '')}</h3>
                    <p>${esc(item.summary || '')}</p>
                    ${item.undone ? '<p>Undone.</p>' : ''}
                </div>
                <div class="music-log-actions">${undo}${view}</div>
            </article>`;
        }).join('');
        panel.innerHTML = `
            <div class="music-log">
                <header class="music-log-head">
                    <span class="music-season-symbol">${icon('bot')}</span>
                    <div>
                        <h2>Music Director activity</h2>
                        <p>A transparent history of automated playlist and metadata changes.</p>
                    </div>
                    <p class="music-badge${badge}">${esc(monitor.label || '')}</p>
                </header>
                ${rows ? '<div class="music-log-list">' + rows + '</div>' : emptyBlock('No automation yet', 'Re-analyze tracks, set a season, or add tracks to a playlist.', 'Sync', 'type="button" data-sync')}
            </div>`;
    }

    function arcFrom(data) {
        const arc = {};
        ['opener', 'build', 'peak', 'breather', 'closer'].forEach((section) => {
            const value = String(data.get('arc_' + section) || '').trim();
            if (value !== '') arc[section] = Number(value);
        });
        return arc;
    }

    function numberOrNull(value) {
        const text = String(value ?? '').trim();
        return text === '' ? null : Number(text);
    }

    function numberOrBlank(value) {
        const text = String(value ?? '').trim();
        return text === '' ? '' : Number(text);
    }

    function closeDrawer() {
        drawerUri = '';
        if (drawer) drawer.hidden = true;
        if (stage) stage.classList.remove('is-split');
        panel.querySelectorAll('[data-library-row].is-selected').forEach((row) => row.classList.remove('is-selected'));
    }

    function paintGenres(tags) {
        const box = document.getElementById('music-genre-tags');
        if (!box) return;
        const names = genreLabels({ genre_tags: tags || [] });
        box.innerHTML = names.length ? names.map((name) => '<span class="music-chip">' + esc(name) + '</span>').join('') : '<span class="music-muted">No genre stored.</span>';
    }

    function paintWaveform(bars) {
        const wave = document.getElementById('music-waveform');
        const empty = document.getElementById('music-waveform-empty');
        if (!wave || !empty) return;
        // Bar heights come from stored loudness. An empty list stays an empty state.
        if (!bars || !bars.length) {
            wave.hidden = true;
            wave.innerHTML = '';
            empty.hidden = false;
            return;
        }
        empty.hidden = true;
        wave.hidden = false;
        wave.innerHTML = bars.map((height) => '<i style="height:' + Math.max(0, Math.min(100, Number(height) || 0)) + '%"></i>').join('');
    }

    function paintAppears(rows) {
        const section = document.getElementById('music-appears');
        const list = document.getElementById('music-appears-list');
        if (!section || !list) return;
        section.hidden = false;
        if (!rows || !rows.length) {
            list.innerHTML = '<p class="music-muted">This track is not on a playlist yet.</p>';
            return;
        }
        list.innerHTML = rows.map((row) => '<a href="' + esc(row.href) + '">' + icon('music-2') + '<span>' + esc(row.name) + '</span>' + icon('chevron-right') + '</a>').join('');
    }

    async function addDrawerGenre() {
        const form = document.getElementById('music-meta-form');
        const input = document.getElementById('music-genre-input');
        const uri = form && form.elements.uri ? String(form.elements.uri.value || '') : '';
        const genre = input ? String(input.value || '').trim() : '';
        if (!uri || !genre) {
            say('Enter a genre.');
            return;
        }
        await api.post('/spotify/library/genres', { uri: uri, genre: genre });
        if (input) input.value = '';
        const title = document.getElementById('music-drawer-title');
        const artist = document.getElementById('music-drawer-artist');
        await openDrawer(uri, title ? title.textContent : '', artist ? artist.textContent : '', true);
        if (tab === 'library') await renderLibrary();
        ok('Genre saved.');
    }

    async function openDrawer(uri, name, artists, focusGenre) {
        const form = document.getElementById('music-meta-form');
        if (!form || !drawer) return;
        const res = await api.get('/spotify/library/drawer?uri=' + encodeURIComponent(uri));
        const meta = res.track || {};
        form.elements.uri.value = uri;
        form.elements.bpm.value = meta.bpm ?? '';
        form.elements.musical_key.value = meta.musical_key || '';
        form.elements.camelot.value = meta.camelot || '';
        form.elements.energy.value = meta.energy ?? '';
        form.elements.mood_tags.value = (meta.mood_tags || []).join(', ');
        form.elements.season_tags.value = (meta.season_tags || []).join(', ');
        form.elements.source.value = meta.source === 'manual' ? 'manual' : 'estimated';
        paintGenres(meta.genre_tags);
        paintWaveform(meta.waveform);
        paintAppears(res.appears_in);
        const title = document.getElementById('music-drawer-title');
        const artist = document.getElementById('music-drawer-artist');
        if (title) title.textContent = name || uri;
        if (artist) artist.textContent = artists || '';
        drawerArtists = artists || '';
        drawerUri = uri;
        panel.querySelectorAll('[data-library-row]').forEach((row) => {
            row.classList.toggle('is-selected', row.dataset.uri === uri);
        });
        const play = document.getElementById('music-drawer-play');
        if (play) {
            play.hidden = false;
            play.dataset.uri = uri;
        }
        const add = document.getElementById('music-drawer-add');
        if (!playlists.length) {
            try {
                const lists = await api.get('/spotify/playlists');
                playlists = lists.playlists || [];
            } catch (err) {
                fail(err);
            }
        }
        const choices = playlists.filter((row) => row.is_owned);
        if (add && choices.length) {
            add.hidden = false;
            add.elements.playlist_id.innerHTML = choices.map((row) => '<option value="' + row.id + '">' + esc(row.name) + '</option>').join('');
            add.dataset.uri = uri;
            add.dataset.name = name || '';
        } else if (add) {
            add.hidden = true;
        }
        drawer.hidden = false;
        if (stage) stage.classList.add('is-split');
        if (focusGenre) {
            const genre = document.getElementById('music-genre-input');
            if (genre) genre.focus();
        } else {
            form.elements.bpm.focus();
        }
    }

    function openSeason(name) {
        const modal = document.getElementById('music-season-modal');
        const form = document.getElementById('music-season-form');
        const title = document.getElementById('music-season-title');
        if (!modal || !form) return;
        if (name) {
            const row = seasons.find((item) => item.season === name);
            if (!row) return;
            form.elements.season.value = row.season;
            form.elements.thanksgiving.checked = row.start_anchor === 'thanksgiving';
            form.elements.start_month.value = row.start_month ?? '';
            form.elements.start_day.value = row.start_day ?? '';
            form.elements.end_month.value = row.end_month;
            form.elements.end_day.value = row.end_day;
            form.elements.allow_outside.checked = !!row.allow_outside;
            if (title) title.textContent = 'Edit season';
        } else {
            form.reset();
            if (title) title.textContent = 'Add season';
        }
        modal.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
        form.elements.season.focus();
    }

    function closeSeason() {
        const modal = document.getElementById('music-season-modal');
        if (modal) modal.classList.add('hidden');
        if (!document.querySelector('.app-modal:not(.hidden)')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    function fillSeason(name) {
        openSeason(name);
    }

    function playbackBody() {
        return activeDevice ? { device_id: activeDevice } : {};
    }

    function openNew() {
        const modal = document.getElementById('music-new-modal');
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
        const name = modal.querySelector('input[name="name"]');
        if (name) name.focus();
    }

    function closeNew() {
        const modal = document.getElementById('music-new-modal');
        if (modal) modal.classList.add('hidden');
        if (!document.querySelector('.app-modal:not(.hidden)')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    async function reloadDetail() {
        if (detail && detail.playlist) await renderDetail(detail.playlist.id);
    }

    async function load() {
        if (tab === 'builder' && panel.querySelector('.music-builder')) return;
        try {
            account = await api.get('/spotify/status');
        } catch (err) {
            fail(err);
            paintConnection();
            panel.innerHTML = emptyBlock('Could not check Spotify', errText(err), 'Spotify settings', '');
            return;
        }
        paintConnection();
        if (!account.connected) {
            closeDrawer();
            panel.innerHTML = needsSetup();
            return;
        }
        try {
            if (tab === 'overview') await renderOverview();
            else if (tab === 'playlists' && playlistId) await renderDetail(Number(playlistId));
            else if (tab === 'playlists') await renderPlaylistTab();
            else if (tab === 'builder') await renderBuilder();
            else if (tab === 'library') await renderLibrary();
            else if (tab === 'seasons') await renderSeasons();
            else await renderActivity();
        } catch (err) {
            fail(err);
            panel.innerHTML = emptyBlock('Could not load this tab', errText(err), 'Spotify settings', '');
        }
    }

    root.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        event.preventDefault();
        try {
            if (form.id === 'music-create') {
                const data = new FormData(form);
                const ai = String(data.get('kind') || '') === 'ai';
                const prompt = String(data.get('prompt') || '').trim();
                if (ai && !prompt) {
                    say('An AI playlist needs a prompt.');
                    return;
                }
                const created = await api.post('/spotify/playlists', {
                    name: String(data.get('name') || ''),
                    description: String(data.get('description') || ''),
                    public: data.get('public') === 'on',
                    ai: ai,
                    prompt: ai ? prompt : '',
                    refresh_schedule: ai ? String(data.get('refresh_schedule') || '') : '',
                });
                closeNew();
                ok('Playlist staged.');
                window.location.href = '?tab=playlists&id=' + created.playlist.id;
            } else if (form.id === 'music-rename' && detail) {
                const data = new FormData(form);
                await api.post('/spotify/playlists/' + detail.playlist.id + '/update', {
                    name: String(data.get('name') || ''),
                    description: String(data.get('description') || ''),
                    public: data.get('public') === 'on',
                });
                await reloadDetail();
                ok('Rename staged.');
            } else if (form.id === 'music-search' && detail) {
                const data = new FormData(form);
                const res = await api.get('/spotify/search?q=' + encodeURIComponent(String(data.get('q') || '')) + '&type=track');
                searchHits = res.tracks || [];
                paintDetail();
                if (!searchHits.length) say('No tracks matched.');
            } else if (form.id === 'music-prompt-form' && detail) {
                const data = new FormData(form);
                const editing = form.dataset.id || '';
                const stamp = String(++promptStamp);
                form.dataset.stamp = stamp;
                const body = {
                    name: String(data.get('name') || ''),
                    prompt: String(data.get('prompt') || ''),
                    refresh_schedule: String(data.get('refresh_schedule') || ''),
                    notes: String(data.get('notes') || ''),
                    theme: String(data.get('theme') || ''),
                    arc: arcFrom(data),
                    season_tags: String(data.get('season_tags') || ''),
                    allow_seasons: String(data.get('allow_seasons') || ''),
                    target_playlist_id: detail.playlist.id,
                    active: true,
                };
                if (editing) await api.post('/spotify/prompts/' + editing + '/update', body);
                else await api.post('/spotify/prompts', body);
                if (form.dataset.stamp === stamp) {
                    delete form.dataset.id;
                    const submit = form.querySelector('button[type="submit"]');
                    if (submit) submit.textContent = 'Save prompt';
                    form.reset();
                    form.elements.target_playlist_id.value = String(detail.playlist.id);
                }
                await reloadDetail();
                ok(editing ? 'Prompt updated.' : 'Prompt saved.');
            } else if (form.id === 'music-season-form') {
                const data = new FormData(form);
                const thanksgiving = data.get('thanksgiving') === 'on';
                const body = {
                    season: String(data.get('season') || ''),
                    start_anchor: thanksgiving ? 'thanksgiving' : '',
                    end_month: numberOrBlank(data.get('end_month')),
                    end_day: numberOrBlank(data.get('end_day')),
                    allow_outside: data.get('allow_outside') === 'on',
                };
                if (!thanksgiving) {
                    body.start_month = numberOrBlank(data.get('start_month'));
                    body.start_day = numberOrBlank(data.get('start_day'));
                }
                await api.post('/spotify/seasons', body);
                closeSeason();
                if (tab === 'seasons') await renderSeasons();
                else if (tab === 'overview') await renderOverview();
                ok('Season window saved.');
            } else if (form.id === 'music-genre-form') {
                await addDrawerGenre();
            } else if (form.id === 'music-meta-form') {
                const data = new FormData(form);
                await api.post('/spotify/tracks/meta', {
                    tracks: [{
                        uri: String(data.get('uri') || ''),
                        bpm: numberOrNull(data.get('bpm')),
                        musical_key: String(data.get('musical_key') || ''),
                        camelot: String(data.get('camelot') || ''),
                        energy: numberOrNull(data.get('energy')),
                        mood_tags: String(data.get('mood_tags') || ''),
                        season_tags: String(data.get('season_tags') || ''),
                        source: String(data.get('source') || 'estimated'),
                    }],
                });
                closeDrawer();
                if (tab === 'playlists' && detail) await reloadDetail();
                else if (tab === 'library') await renderLibrary();
                ok('Track notes saved.');
            } else if (form.id === 'music-drawer-add') {
                const uri = form.dataset.uri || '';
                const id = String(new FormData(form).get('playlist_id') || '');
                if (!uri || !id) return;
                await api.post('/spotify/playlists/' + id + '/tracks', {
                    uris: [uri],
                    tracks: [{
                        uri: uri,
                        name: form.dataset.name || '',
                        artists: drawerArtists.split(',').map((part) => part.trim()).filter(Boolean),
                    }],
                });
                ok('Track staged on that playlist.');
            } else if (form.id === 'music-library-search') {
                const query = String(new FormData(form).get('q') || '').trim();
                if (!query) {
                    say('Enter a track or artist to search.');
                    return;
                }
                const res = await api.get('/spotify/search?q=' + encodeURIComponent(query) + '&type=track');
                libraryMode = 'search';
                libraryQuery = query;
                libraryRows = res.tracks || [];
                await renderLibrary();
                if (!libraryRows.length) say('No tracks matched.');
            }
        } catch (err) {
            fail(err);
        }
    });

    root.addEventListener('click', async (event) => {
        if (event.target.closest('[data-close-modal]')) {
            const modal = event.target.closest('.app-modal');
            if (modal && modal.id === 'music-season-modal') closeSeason();
            else closeNew();
            return;
        }
        if (event.target.closest('[data-close-drawer]')) {
            closeDrawer();
            return;
        }
        const libraryRow = event.target.closest('[data-library-row]');
        if (libraryRow && !event.target.closest('input, button, a, select')) {
            try {
                await openDrawer(libraryRow.dataset.uri || '', libraryRow.dataset.name || '', libraryRow.dataset.artists || '');
            } catch (err) {
                fail(err);
            }
            return;
        }
        if (event.target.closest('[data-dismiss-banner]')) {
            showBanner('');
            return;
        }
        const target = event.target.closest('button');
        if (!target) return;
        try {
            if (target.dataset.focusPrompt !== undefined) {
                const form = document.getElementById('music-prompt-form');
                if (form && form.elements.name) form.elements.name.focus();
            } else if (target.dataset.newPlaylist !== undefined) {
                openNew();
            } else if (target.dataset.view) {
                playlistView = target.dataset.view === 'list' ? 'list' : 'grid';
                await renderPlaylistTab();
            } else if (target.dataset.library) {
                libraryMode = target.dataset.library === 'top' ? 'top' : 'saved';
                await renderLibrary();
            } else if (target.dataset.sync !== undefined || target.dataset.syncOne) {
                const body = target.dataset.syncOne ? { playlist_id: Number(target.dataset.syncOne) } : {};
                say('Syncing…');
                const synced = await api.post('/spotify/sync', body);
                ok(synced && synced.queued ? 'Sync queued.' : 'Sync finished.');
                await load();
            } else if (target.dataset.pushAll !== undefined) {
                say('Pushing…');
                const pushed = await api.post('/spotify/push', {});
                ok(pushed && pushed.queued ? 'Push queued.' : 'Push finished.');
                await load();
            } else if (target.dataset.push) {
                say('Pushing…');
                const pushed = await api.post('/spotify/push', { playlist_id: Number(target.dataset.push) });
                ok(pushed && pushed.queued ? 'Push queued.' : 'Push finished.');
                await reloadDetail();
            } else if (target.dataset.discard) {
                await api.post('/spotify/playlists/' + target.dataset.discard + '/discard', {});
                await reloadDetail();
                ok('Staged changes discarded.');
            } else if (target.dataset.add && detail) {
                await api.post('/spotify/playlists/' + detail.playlist.id + '/tracks', {
                    uris: [target.dataset.add],
                    tracks: [{
                        uri: target.dataset.add,
                        name: target.dataset.name || '',
                        artists: (target.dataset.artists || '').split('|').filter(Boolean),
                    }],
                });
                searchHits = [];
                await reloadDetail();
                ok('Track staged.');
            } else if (target.dataset.remove && detail) {
                await api.post('/spotify/playlists/' + detail.playlist.id + '/tracks/remove', { uris: [target.dataset.remove] });
                await reloadDetail();
            } else if ((target.dataset.up !== undefined || target.dataset.down !== undefined) && detail) {
                const index = Number(target.dataset.up !== undefined ? target.dataset.up : target.dataset.down);
                const preview = detail.preview || [];
                const next = target.dataset.up !== undefined ? index - 1 : index + 1;
                if (next < 0 || next >= preview.length) return;
                await api.post('/spotify/playlists/' + detail.playlist.id + '/tracks/reorder', {
                    range_start: index,
                    insert_before: target.dataset.up !== undefined ? next : next + 1,
                    length: 1,
                });
                await reloadDetail();
            } else if (target.id === 'music-drawer-play') {
                const uri = target.dataset.uri || '';
                if (!uri) return;
                await api.post('/spotify/playback/play', { uris: [uri] });
                ok('Playing.');
            } else if (target.dataset.libraryAnalyze !== undefined) {
                const picked = selectedLibrary();
                if (!picked.length) {
                    say('Select at least one track.');
                    return;
                }
                say('Re-analyzing…');
                const result = await api.post('/spotify/library/reanalyze', { uris: picked.map((row) => row.uri) });
                await renderLibrary();
                ok(result.summary || 'Re-analyze finished.');
            } else if (target.dataset.librarySeason !== undefined) {
                const picked = selectedLibrary();
                const select = document.getElementById('music-library-season');
                if (!picked.length) {
                    say('Select at least one track.');
                    return;
                }
                if (!select || !select.value) {
                    say('Save a season window first.');
                    return;
                }
                const result = await api.post('/spotify/library/season', {
                    uris: picked.map((row) => row.uri),
                    season: select.value,
                });
                await renderLibrary();
                ok(result.summary || 'Season saved.');
            } else if (target.dataset.libraryAdd !== undefined) {
                const picked = selectedLibrary();
                const select = document.getElementById('music-library-playlist');
                if (!picked.length) {
                    say('Select at least one track.');
                    return;
                }
                if (!select || !select.value) {
                    say('Choose a playlist you own.');
                    return;
                }
                const result = await api.post('/spotify/library/playlist', {
                    playlist_id: Number(select.value),
                    uris: picked.map((row) => row.uri),
                    tracks: picked.map((row) => ({
                        uri: row.uri,
                        name: row.name || '',
                        artists: Array.isArray(row.artists) ? row.artists : [],
                        album: row.album || null,
                        duration_ms: row.duration_ms || 0,
                    })),
                });
                await renderLibrary();
                ok(result.summary || 'Tracks staged.');
            } else if (target.dataset.undo) {
                await api.post('/spotify/library/undo', { id: Number(target.dataset.undo) });
                await renderActivity();
                ok('Undone.');
            } else if (target.dataset.meta) {
                await openDrawer(target.dataset.meta, target.dataset.metaName || '', target.dataset.metaArtists || '', target.dataset.focusGenre !== undefined);
            } else if (target.dataset.promptEdit) {
                const row = prompts.find((item) => String(item.id) === target.dataset.promptEdit);
                const form = document.getElementById('music-prompt-form');
                if (!row || !form) return;
                form.dataset.id = String(row.id);
                form.elements.name.value = row.name || '';
                form.elements.prompt.value = row.prompt || '';
                form.elements.refresh_schedule.value = row.refresh_schedule || '';
                form.elements.notes.value = row.notes || '';
                form.elements.theme.value = row.theme || '';
                form.elements.arc_opener.value = row.arc ? row.arc.opener : '';
                form.elements.arc_build.value = row.arc ? row.arc.build : '';
                form.elements.arc_peak.value = row.arc ? row.arc.peak : '';
                form.elements.arc_breather.value = row.arc ? row.arc.breather : '';
                form.elements.arc_closer.value = row.arc ? row.arc.closer : '';
                form.elements.season_tags.value = (row.season_tags || []).join(', ');
                form.elements.allow_seasons.value = (row.allow_seasons || []).join(', ');
                const submit = form.querySelector('button[type="submit"]');
                if (submit) submit.textContent = 'Save changes';
                say('Editing ' + row.name);
            } else if (target.dataset.promptRun) {
                await api.post('/spotify/prompts/' + target.dataset.promptRun + '/run', {});
                await reloadDetail();
                ok('Run marked.');
            } else if (target.dataset.promptRemove) {
                await api.post('/spotify/prompts/' + target.dataset.promptRemove + '/remove', {});
                await reloadDetail();
                ok('Prompt removed.');
            } else if (target.dataset.addSeason !== undefined) {
                openSeason('');
            } else if (target.dataset.editDetails !== undefined) {
                const form = document.getElementById('music-rename');
                if (form) {
                    form.hidden = false;
                    if (form.elements.name) form.elements.name.focus();
                }
            } else if (target.dataset.addTracks !== undefined) {
                const input = document.querySelector('#music-search input[name="q"]');
                if (input) input.focus();
            } else if (target.dataset.refreshMeta !== undefined) {
                say('Refreshing metadata…');
                const refreshed = await api.post('/spotify/tracks/catalog', {});
                if (!refreshed.checked) ok('Stored tracks are already current.');
                else ok('Updated ' + (refreshed.updated || 0) + ' of ' + refreshed.checked + ' tracks from Spotify.');
                if (tab === 'overview') await renderOverview();
            } else if (target.dataset.transport) {
                const body = playbackBody();
                const action = target.dataset.transport;
                if (action === 'toggle') {
                    await api.post('/spotify/playback/' + (target.dataset.playing === '1' ? 'pause' : 'play'), body);
                } else if (action === 'shuffle') {
                    body.state = target.dataset.on !== '1';
                    await api.post('/spotify/playback/shuffle', body);
                } else if (action === 'repeat') {
                    const order = ['off', 'context', 'track'];
                    const current = order.indexOf(target.dataset.mode || 'off');
                    body.state = order[(current + 1) % order.length];
                    await api.post('/spotify/playback/repeat', body);
                } else {
                    await api.post('/spotify/playback/' + action, body);
                }
                if (tab === 'overview') await renderOverview();
            } else if (target.dataset.transfer) {
                if (target.getAttribute('aria-pressed') === 'true') return;
                await api.post('/spotify/playback/transfer', { device_id: target.dataset.transfer });
                if (tab === 'overview') await renderOverview();
            } else if (target.dataset.playUri) {
                const body = playbackBody();
                body.uris = [target.dataset.playUri];
                await api.post('/spotify/playback/play', body);
                ok('Playing.');
            } else if (target.dataset.playPlaylist) {
                const body = playbackBody();
                body.playlist_id = Number(target.dataset.playPlaylist);
                await api.post('/spotify/playback/play', body);
                ok('Playing playlist.');
            } else if (target.dataset.seasonAllow) {
                const row = seasons.find((item) => item.season === target.dataset.seasonAllow);
                if (!row) return;
                const thanksgiving = row.start_anchor === 'thanksgiving';
                const body = {
                    season: row.season,
                    start_anchor: thanksgiving ? 'thanksgiving' : '',
                    end_month: row.end_month,
                    end_day: row.end_day,
                    allow_outside: !row.allow_outside,
                };
                if (!thanksgiving) {
                    body.start_month = row.start_month;
                    body.start_day = row.start_day;
                }
                await api.post('/spotify/seasons', body);
                await renderSeasons();
                ok(body.allow_outside ? 'Season can play outside its window.' : 'Season stays inside its window.');
            } else if (target.dataset.season) {
                fillSeason(target.dataset.season);
            }
        } catch (err) {
            fail(err);
        }
    });

    root.addEventListener('input', (event) => {
        if (event.target && event.target.id === 'music-playlist-q') {
            playlistQuery = event.target.value;
            paintPlaylists();
        }
    });

    root.addEventListener('change', (event) => {
        const input = event.target;
        if (input instanceof HTMLInputElement && input.dataset.libraryAll !== undefined) {
            const on = input.checked;
            panel.querySelectorAll('[data-library-check]').forEach((box) => {
                box.checked = on;
                if (on) librarySelected.add(box.value);
                else librarySelected.delete(box.value);
            });
            input.indeterminate = false;
            return;
        }
        if (input instanceof HTMLInputElement && input.dataset.libraryCheck !== undefined) {
            if (input.checked) librarySelected.add(input.value);
            else librarySelected.delete(input.value);
            syncLibraryAll();
            return;
        }
        if (input && input.id === 'music-volume') {
            const body = { volume_percent: Number(input.value) };
            if (input.dataset.device) body.device_id = input.dataset.device;
            api.post('/spotify/playback/volume', body).then(function () {
                ok('Volume updated.');
            }).catch(fail);
            return;
        }
        if (input && input.id === 'music-playlist-owner') {
            playlistOwner = input.value;
            paintPlaylists();
        }
        if (input && input.id === 'music-playlist-season') {
            playlistSeason = input.value;
            paintPlaylists();
        }
        if (input && input.id === 'music-playlist-mood') {
            playlistMood = input.value;
            paintPlaylists();
        }
        if (input && input.id === 'music-playlist-energy') {
            playlistEnergy = input.value;
            paintPlaylists();
        }
        if (input && input.id === 'music-playlist-created') {
            playlistCreated = input.value;
            paintPlaylists();
        }
        if (!(input instanceof HTMLInputElement) || input.name !== 'kind') return;
        const aiFields = document.getElementById('music-create-ai');
        if (aiFields) aiFields.hidden = input.value !== 'ai' || !input.checked;
    });

    root.addEventListener('dragstart', (event) => {
        const handle = event.target.closest('[data-drag]');
        if (!handle || !event.dataTransfer) return;
        dragFrom = Number(handle.dataset.drag);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(dragFrom));
    });

    root.addEventListener('dragover', (event) => {
        if (dragFrom === null || !event.target.closest('[data-row]')) return;
        event.preventDefault();
    });

    root.addEventListener('drop', async (event) => {
        const row = event.target.closest('[data-row]');
        if (!row || dragFrom === null || !detail) return;
        event.preventDefault();
        const from = dragFrom;
        const to = Number(row.dataset.row);
        dragFrom = null;
        if (from === to || Number.isNaN(to)) return;
        // insert_before counts positions in the list before the move.
        // Moving an earlier row down has to aim one past the drop row.
        const insertBefore = from < to ? to + 1 : to;
        try {
            await api.post('/spotify/playlists/' + detail.playlist.id + '/tracks/reorder', {
                range_start: from,
                insert_before: insertBefore,
                length: 1,
            });
            await reloadDetail();
        } catch (err) {
            fail(err);
        }
    });

    function schedule() {
        if (tab === 'builder') return;
        const active = document.activeElement;
        if (active && root.contains(active) && active.matches('input, textarea, select')) return;
        if (document.querySelector('#music-new-modal:not(.hidden), #music-season-modal:not(.hidden)')) return;
        if (panel.querySelector('details[open]')) return;
        if (drawer && !drawer.hidden) return;
        window.clearTimeout(timer);
        timer = window.setTimeout(load, 400);
    }

    window.addEventListener('lifedash:event', (event) => {
        const table = event.detail && event.detail.table;
        if (table && String(table).indexOf('spotify_') === 0) schedule();
    });
    window.addEventListener('lifedash:poll', schedule);
    load();
})();
