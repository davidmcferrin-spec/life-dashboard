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
    let libraryMode = 'saved';
    let libraryRows = [];
    let promptStamp = 0;
    let dragFrom = null;
    let timer = null;
    let drawerArtists = '';

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
            return true;
        });
    }

    function playlistCard(row) {
        const bits = [row.track_count + ' tracks'];
        if (row.local_dirty) bits.push('staged');
        if (!row.spotify_id) bits.push('draft');
        if (row.owner_name) bits.push(row.owner_name);
        const mark = (row.origin === 'ai_created' || row.managed_by_ai) ? icon('bot') : '';
        return `
            <a class="music-playlist is-${playlistView}" href="?tab=playlists&id=${row.id}">
                ${cover(row.image_url, row.name)}
                <span class="music-playlist-copy">
                    <strong>${esc(row.name)}</strong>
                    <small>${esc(bits.join(' · '))}</small>
                    ${mark ? '<span class="music-owner">' + mark + '</span>' : ''}
                </span>
                ${row.sync_error ? '<span class="music-warn">' + esc(row.sync_error) + '</span>' : ''}
            </a>`;
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

    async function renderOverview() {
        const [listRes, seasonRes, activeRes, nowRes, deviceRes] = await Promise.all([
            api.get('/spotify/playlists').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/seasons').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/seasons/active').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/player').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
            api.get('/spotify/devices').then((value) => ({ ok: true, value: value })).catch((error) => ({ ok: false, error: error })),
        ]);
        [listRes, seasonRes, activeRes, nowRes, deviceRes].forEach((result) => {
            if (!result.ok) fail(result.error);
        });
        playlists = listRes.ok ? (listRes.value.playlists || []) : [];
        seasons = seasonRes.ok ? (seasonRes.value.seasons || []) : [];
        activeSeasons = activeRes.ok ? (activeRes.value.seasons || []) : [];
        const now = nowRes.ok ? nowRes.value.now : null;
        const devices = deviceRes.ok ? (deviceRes.value.devices || []) : null;
        const recent = playlists.slice().sort((a, b) => String(b.last_synced_at || '').localeCompare(String(a.last_synced_at || ''))).slice(0, 3);
        const aiRows = playlists.filter((row) => row.origin === 'ai_created' || row.managed_by_ai).slice(0, 6);
        const open = new Set(activeSeasons.map((row) => row.season));
        panel.innerHTML = `
            <div class="music-hero">
                <div class="music-hero-art">${now && safeUrl(now.image_url) ? '<img src="' + esc(safeUrl(now.image_url)) + '" alt="">' : icon('music-2')}</div>
                <div class="music-hero-copy">
                    <p class="music-kicker">${now && now.name ? (now.is_playing ? 'Now playing' : 'Paused') : 'Player'}</p>
                    <h2>${esc(now && now.name ? now.name : 'Nothing is playing')}</h2>
                    <p class="music-muted">${esc(now && now.name ? (artistsOf(now) + (now.album ? ' · ' + now.album : '')) : 'The floating player stays in charge of play and pause.')}</p>
                    ${now && now.duration_ms ? progressBar(now) : ''}
                    ${now ? flags(now) : ''}
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
                        <button type="button" data-sync>${icon('sparkles')}<span><strong>Sync library</strong><small>Pull playlists and saved tracks</small></span>${icon('chevron-right')}</button>
                        <a href="/settings/spotify">${icon('settings')}<span><strong>Spotify settings</strong><small>Account, scopes, and the player</small></span>${icon('chevron-right')}</a>
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
        if (devices === null) return '<p class="music-muted">Devices unavailable.</p>';
        if (!devices.length) {
            return emptyBlock('No device', 'Open Spotify on a phone, computer, or speaker. Playback stays on the floating player.', '', '');
        }
        const activeName = now && now.device ? now.device : '';
        return `<p class="music-kicker">Playing on</p><ul class="music-devices">${devices.map((device) => {
            const on = device.is_active || (activeName && device.name === activeName);
            const volume = device.volume_percent === null || device.volume_percent === undefined ? '' : device.volume_percent + '%';
            return `<li class="music-device${on ? ' is-on' : ''}">${icon(deviceIcon(device.type))}<span><strong>${esc(device.name || device.type || 'Device')}</strong><small>${esc(device.type || '')}${volume ? ' · ' + esc(volume) : ''}</small></span></li>`;
        }).join('')}</ul>`;
    }

    function seasonList(rows, open) {
        if (!rows.length) return '<p class="music-muted">No season windows yet.</p>';
        return '<ul class="music-season-mini">' + rows.slice(0, 4).map((row) => {
            const start = row.start_anchor === 'thanksgiving' ? 'Thanksgiving' : ((row.start_month || '—') + '/' + (row.start_day || '—'));
            return `<li><span class="music-season-symbol">${icon('sprout')}</span><span><strong>${esc(row.season)}</strong><small>${esc(start)} – ${row.end_month}/${row.end_day} · ${open.has(row.season) ? 'open' : 'closed'}</small></span></li>`;
        }).join('') + '</ul>';
    }

    async function renderPlaylistTab() {
        const res = await api.get('/spotify/playlists');
        playlists = res.playlists || [];
        panel.innerHTML = `
            <div class="music-tools">
                <label class="music-search">${icon('search')}
                    <input id="music-playlist-q" class="input" type="search" maxlength="200" placeholder="Search playlists" value="${esc(playlistQuery)}">
                </label>
                <label class="music-field">Owner
                    <select id="music-playlist-owner" class="input">
                        <option value="all">All</option>
                        <option value="mine">Mine</option>
                        <option value="ai">AI</option>
                        <option value="followed">Followed</option>
                    </select>
                </label>
                <div class="music-segment">
                    <button type="button" class="music-icon-btn${playlistView === 'grid' ? ' is-on' : ''}" data-view="grid" aria-label="Grid" aria-pressed="${playlistView === 'grid'}">${icon('layout-dashboard')}</button>
                    <button type="button" class="music-icon-btn${playlistView === 'list' ? ' is-on' : ''}" data-view="list" aria-label="List" aria-pressed="${playlistView === 'list'}">${icon('list-music')}</button>
                </div>
            </div>
            <div id="music-playlist-results"></div>`;
        const owner = document.getElementById('music-playlist-owner');
        if (owner) owner.value = playlistOwner;
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
                    ${label ? '<p class="music-warn">' + esc(label) + '</p>' : ''}
                    ${playlist.sync_error ? '<p class="music-warn">' + esc(playlist.sync_error) + '</p>' : ''}
                </div>
                <div class="music-inline">
                    <button type="button" class="btn" data-sync-one="${playlist.id}">Sync</button>
                    ${canEdit ? '<button type="button" class="btn-primary" data-push="' + playlist.id + '">Push</button>' : ''}
                    ${canEdit ? '<button type="button" class="btn" data-discard="' + playlist.id + '">Discard</button>' : ''}
                    ${remote ? '<a class="btn" href="' + remote + '" target="_blank" rel="noopener noreferrer">Open in Spotify</a>' : ''}
                </div>
            </header>
            <section class="music-card">
                <header class="music-card-head"><div><p class="music-kicker">Running order</p><h2 class="music-card-title">Energy arc</h2></div></header>
                ${arcMarkup(preview.map((track) => track.meta ? track.meta.energy : null))}
            </section>
            ${canEdit ? `<details class="music-details"><summary>Edit details</summary>
                <form id="music-rename" class="music-form">
                    <label class="music-field">Name <input name="name" class="input" maxlength="100" value="${esc(playlist.name)}"></label>
                    <label class="music-field">Description <input name="description" class="input" maxlength="300" value="${esc(playlist.description || '')}"></label>
                    <label class="music-check"><input type="checkbox" name="public" ${playlist.public ? 'checked' : ''}> Public</label>
                    <button type="submit" class="btn">Stage rename</button>
                </form>
            </details>` : ''}
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
                <header class="music-card-head"><div><p class="music-kicker">${canEdit ? 'Drag to reorder' : 'Tracks'}</p><h2 class="music-card-title">Track sequence</h2></div></header>
                ${trackTable(preview, canEdit)}
            </section>
            ${aiPlaylist(playlist) ? promptBlock(playlist) : ''}`;
    }

    function trackTable(preview, canEdit) {
        if (!preview.length) return '<p class="music-muted">No tracks.</p>';
        const rows = preview.map((track, index) => {
            const meta = track.meta || {};
            const move = canEdit ? `
                <button type="button" class="music-drag" draggable="true" data-drag="${index}" aria-label="Drag to reorder">${icon('arrow-left-right')}</button>
                <button type="button" class="music-linkish" data-up="${index}">Up</button>
                <button type="button" class="music-linkish" data-down="${index}">Down</button>
                <button type="button" class="music-linkish" data-remove="${esc(track.uri)}">Remove</button>` : '';
            return `<tr data-row="${index}">
                <td>${move}</td>
                <td>${String(index + 1).padStart(2, '0')}</td>
                <td><strong>${esc(track.name)}</strong></td>
                <td>${esc(artistsOf(track))}</td>
                <td>${meta.bpm ? esc(meta.bpm) : '—'}</td>
                <td>${esc(meta.camelot || meta.musical_key || '—')}</td>
                <td class="music-energy-cell">${energyCell(meta.energy)}</td>
                <td>${meta.mood_tags && meta.mood_tags.length ? '<span class="music-chip">' + esc(meta.mood_tags[0]) + '</span>' : '—'}</td>
                <td>${meta.season_tags && meta.season_tags.length ? '<span class="music-chip">' + esc(meta.season_tags.join(', ')) + '</span>' : '—'}</td>
                <td>${clock(track.duration_ms)}</td>
                <td><button type="button" class="music-linkish" data-meta="${esc(track.uri)}" data-meta-name="${esc(track.name)}" data-meta-artists="${esc(artistsOf(track))}">Notes</button></td>
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
        await attachMeta(libraryMode === 'search' ? libraryRows : rows);
        const shown = libraryMode === 'search' ? libraryRows : rows;
        panel.innerHTML = `
            <form id="music-library-search" class="music-tools">
                <label class="music-search">${icon('search')}<input name="q" class="input" maxlength="200" placeholder="Search Spotify" value="${esc(libraryMode === 'search' ? libraryQuery : '')}"></label>
                <button type="submit" class="btn">Search</button>
                <button type="button" class="btn${libraryMode === 'saved' ? ' is-on' : ''}" data-library="saved">Saved</button>
                <button type="button" class="btn${libraryMode === 'top' ? ' is-on' : ''}" data-library="top">Top</button>
            </form>
            <section class="music-card">
                <header class="music-card-head"><div><p class="music-kicker">${shown.length} shown</p><h2 class="music-card-title">Track metadata</h2></div></header>
                ${libraryTable(shown)}
            </section>`;
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

    async function attachMeta(rows) {
        const uris = rows.map((row) => row.uri).filter(Boolean);
        if (!uris.length) return;
        const res = await api.get('/spotify/tracks/meta?uris=' + encodeURIComponent(uris.join(',')));
        const byUri = {};
        (res.tracks || []).forEach((meta) => { byUri[meta.uri] = meta; });
        rows.forEach((row) => { row.meta = byUri[row.uri] || null; });
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
            return `<tr>
                <td><strong>${esc(row.name || row.uri)}</strong></td>
                <td>${esc(artists)}</td>
                <td>${meta.bpm ? esc(meta.bpm) : '—'}</td>
                <td>${esc(meta.camelot || meta.musical_key || '—')}</td>
                <td class="music-energy-cell">${energyCell(meta.energy)}</td>
                <td>${meta.mood_tags && meta.mood_tags.length ? esc(meta.mood_tags.join(', ')) : '—'}</td>
                <td>${meta.season_tags && meta.season_tags.length ? esc(meta.season_tags.join(', ')) : '—'}</td>
                <td>${esc(meta.source || 'no notes')}</td>
                <td><button type="button" class="music-linkish" data-meta="${esc(row.uri)}" data-meta-name="${esc(row.name || '')}" data-meta-artists="${esc(artists)}">Notes</button></td>
            </tr>`;
        }).join('');
        return `<div class="music-table-wrap"><table class="music-table"><thead><tr><th>Track</th><th>Artist</th><th>BPM</th><th>Key</th><th>Energy</th><th>Mood</th><th>Season</th><th>Source</th><th></th></tr></thead><tbody>${body}</tbody></table></div>`;
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
                <div><h2 class="music-card-title">Season windows</h2><p class="music-muted">A track tagged for a closed season is refused unless the prompt allows it.</p></div>
            </header>
            ${seasons.length ? '<div class="music-season-grid">' + seasons.map((row) => seasonCard(row, open.has(row.season))).join('') + '</div>' : '<p class="music-muted">No season windows yet.</p>'}
            <form id="music-season-form" class="music-form music-card">
                <h2 class="music-card-title">Save a window</h2>
                <label class="music-field">Season <input name="season" class="input" maxlength="32" required placeholder="christmas"></label>
                <label class="music-check"><input type="checkbox" name="thanksgiving"> Start on Thanksgiving</label>
                <div class="music-split">
                    <label class="music-field">Start month <input name="start_month" class="input" inputmode="numeric" maxlength="2"></label>
                    <label class="music-field">Start day <input name="start_day" class="input" inputmode="numeric" maxlength="2"></label>
                    <label class="music-field">End month <input name="end_month" class="input" inputmode="numeric" maxlength="2" required></label>
                    <label class="music-field">End day <input name="end_day" class="input" inputmode="numeric" maxlength="2" required></label>
                </div>
                <button type="submit" class="btn-primary">Save window</button>
            </form>`;
    }

    function seasonCard(row, isOpen) {
        const start = row.start_anchor === 'thanksgiving' ? 'Thanksgiving' : ((row.start_month || '—') + '/' + (row.start_day || '—'));
        return `<article class="music-season-card">
            <header><span class="music-season-symbol">${icon('sprout')}</span><button type="button" class="music-linkish" data-season="${esc(row.season)}">Edit</button></header>
            <h3>${esc(row.season)}</h3>
            <div class="music-season-range"><span><small>Start</small>${esc(start)}</span><i></i><span><small>End</small>${row.end_month}/${row.end_day}</span></div>
            <p>${isOpen ? 'Open today' : 'Closed today'}</p>
        </article>`;
    }

    async function renderActivity() {
        const [listRes, historyRes] = await Promise.all([
            api.get('/spotify/playlists'),
            api.get('/spotify/history?limit=40'),
        ]);
        playlists = listRes.playlists || [];
        const items = [];
        (historyRes.plays || []).forEach((play) => {
            const track = play.track || {};
            items.push({
                at: play.played_at,
                title: 'Played ' + (track.name || 'a track'),
                body: artistsOf(track),
                href: '',
                iconName: 'history',
            });
        });
        const dirty = playlists.filter((row) => row.open_changes > 0);
        const bundles = await Promise.all(dirty.map((row) => api.get('/spotify/playlists/' + row.id + '/changes').then((res) => ({ row: row, changes: res.changes || [] })).catch((err) => {
            fail(err);
            return { row: row, changes: [] };
        })));
        bundles.forEach((bundle) => {
            bundle.changes.forEach((change) => {
                items.push({
                    at: change.created_at,
                    title: 'Staged ' + change.op + ' on ' + bundle.row.name,
                    body: changeSummary(change) + (change.status === 'failed' && change.error ? ' — ' + change.error : ''),
                    href: '?tab=playlists&id=' + bundle.row.id,
                    iconName: change.created_by && change.created_by !== 'david' ? 'sparkles' : 'music-2',
                });
            });
        });
        items.sort((a, b) => String(b.at || '').localeCompare(String(a.at || '')));
        const rows = items.slice(0, 40).map((item) => `
            <article>
                <span class="music-timeline-mark">${icon(item.iconName)}</span>
                <time datetime="${esc(item.at || '')}">${esc(when(item.at))}</time>
                <div>
                    <h3>${esc(item.title)}</h3>
                    <p>${esc(item.body)}</p>
                    ${item.href ? '<a href="' + esc(item.href) + '">View</a>' : ''}
                </div>
            </article>`).join('');
        panel.innerHTML = `
            <div class="music-activity">
                <div class="music-activity-intro">
                    <span class="music-season-symbol">${icon('history')}</span>
                    <div><h2>Activity</h2><p>Staged playlist edits and recent plays. This list is read-only.</p></div>
                </div>
                ${rows ? '<div class="music-timeline">' + rows + '</div>' : emptyBlock('No activity yet', 'Sync playlists or play something, then come back.', 'Sync', 'type="button" data-sync')}
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
        if (drawer) drawer.hidden = true;
        if (stage) stage.classList.remove('is-split');
    }

    async function openDrawer(uri, name, artists) {
        const form = document.getElementById('music-meta-form');
        if (!form || !drawer) return;
        const res = await api.get('/spotify/tracks/meta?uris=' + encodeURIComponent(uri));
        const meta = (res.tracks && res.tracks[0]) || {};
        form.elements.uri.value = uri;
        form.elements.bpm.value = meta.bpm ?? '';
        form.elements.musical_key.value = meta.musical_key || '';
        form.elements.camelot.value = meta.camelot || '';
        form.elements.energy.value = meta.energy ?? '';
        form.elements.mood_tags.value = (meta.mood_tags || []).join(', ');
        form.elements.season_tags.value = (meta.season_tags || []).join(', ');
        form.elements.source.value = meta.source === 'manual' ? 'manual' : 'estimated';
        const title = document.getElementById('music-drawer-title');
        const artist = document.getElementById('music-drawer-artist');
        if (title) title.textContent = name || uri;
        if (artist) artist.textContent = artists || '';
        drawerArtists = artists || '';
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
        form.elements.bpm.focus();
    }

    function fillSeason(name) {
        const form = document.getElementById('music-season-form');
        const row = seasons.find((item) => item.season === name);
        if (!form || !row) return;
        form.elements.season.value = row.season;
        form.elements.thanksgiving.checked = row.start_anchor === 'thanksgiving';
        form.elements.start_month.value = row.start_month ?? '';
        form.elements.start_day.value = row.start_day ?? '';
        form.elements.end_month.value = row.end_month;
        form.elements.end_day.value = row.end_day;
        form.elements.season.focus();
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
                };
                if (!thanksgiving) {
                    body.start_month = numberOrBlank(data.get('start_month'));
                    body.start_day = numberOrBlank(data.get('start_day'));
                }
                await api.post('/spotify/seasons', body);
                await renderSeasons();
                ok('Season window saved.');
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
            closeNew();
            return;
        }
        if (event.target.closest('[data-close-drawer]')) {
            closeDrawer();
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
            } else if (target.dataset.meta) {
                await openDrawer(target.dataset.meta, target.dataset.metaName || '', target.dataset.metaArtists || '');
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
        if (input && input.id === 'music-playlist-owner') {
            playlistOwner = input.value;
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
        const modal = document.getElementById('music-new-modal');
        if (modal && !modal.classList.contains('hidden')) return;
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
