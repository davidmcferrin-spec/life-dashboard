/**
 * Playlists page: synced lists, staged diffs, search, and prompts.
 */
'use strict';

(function () {
    const root = document.getElementById('spotify-app');
    const { api, esc } = window.LD || {};
    if (!root || !api) return;

    const statusEl = document.getElementById('spotify-status');
    const listEl = document.getElementById('spotify-lists');
    const detailEl = document.getElementById('spotify-detail');
    const promptList = document.getElementById('spotify-prompt-list');
    const promptForm = document.getElementById('spotify-prompt-form');

    let playlists = [];
    let prompts = [];
    let promptStamp = 0;
    let current = null;
    let detail = null;
    let searchHits = [];
    let timer = null;

    function say(text) {
        statusEl.textContent = text || '';
    }

    function schedule() {
        window.clearTimeout(timer);
        timer = window.setTimeout(load, 400);
    }

    async function load() {
        try {
            const [lists, promptRes] = await Promise.all([
                api.get('/spotify/playlists'),
                api.get('/spotify/prompts'),
            ]);
            playlists = lists.playlists || [];
            prompts = promptRes.prompts || [];
            renderLists();
            renderPrompts();
            if (current) await openPlaylist(current, false);
        } catch (err) {
            say(err.message || 'Could not load playlists.');
        }
        try {
            await loadSeasons();
        } catch (err) {
            say(err.message || 'Could not load seasons.');
        }
    }

    function readOnlyLabel(row) {
        if (!row || row.is_owned || !row.spotify_id) return '';
        if (row.owner_name) return 'Read-only (owned by ' + row.owner_name + ')';
        return 'Followed';
    }

    function renderLists() {
        if (!playlists.length) {
            listEl.innerHTML = window.DashboardEmpty.html({
                title: 'No playlists',
                body: 'Sync from Spotify, or create one here.',
                actionLabel: 'New playlist',
                actionAttrs: 'type="button" data-new-playlist',
            });
            return;
        }
        listEl.innerHTML = playlists.map((row) => {
            const label = readOnlyLabel(row);
            const extra = row.local_dirty ? ' · staged' : '';
            const draft = row.spotify_id ? '' : ' · draft';
            const error = row.sync_error
                ? `<span class="sp-row-error" title="${esc(row.sync_error)}">${esc(row.sync_error)}</span>`
                : '';
            return `
            <button type="button" class="sp-row${row.id === current ? ' is-on' : ''}" data-open="${row.id}">
                <span class="sp-row-name" title="${esc(row.name)}">${esc(row.name)}</span>
                <span class="sp-row-count">${row.track_count} tracks${extra}${draft}</span>
                ${error || (label ? `<span class="sp-row-error">${esc(label)}</span>` : '')}
            </button>`;
        }).join('');
    }

    async function openPlaylist(id, scroll) {
        current = id;
        renderLists();
        detailEl.innerHTML = '<p class="empty-state mono text-xs">Loading…</p>';
        detail = await api.get('/spotify/playlists/' + id);
        renderDetail();
        syncPromptBox();
        if (scroll) detailEl.scrollIntoView({ block: 'nearest' });
    }

    function ownedPlaylist(playlist) {
        return !!(playlist && playlist.is_owned);
    }

    function aiPlaylist(playlist) {
        return !!(playlist && playlist.origin === 'ai_created' && playlist.is_owned);
    }

    function renderDetail() {
        if (!detail) return;
        const playlist = detail.playlist;
        const changes = detail.changes || [];
        const preview = detail.preview || [];
        const diff = changes.map((change) => {
            const summary = changeSummary(change);
            const failed = change.status === 'failed' ? ` — ${esc(change.error || 'failed')}` : '';
            return `<li>${esc(change.op)}: ${esc(summary)}${failed}</li>`;
        }).join('');
        const tracks = preview.map((track, index) => trackRow(track, index, true)).join('');
        const owned = ownedPlaylist(playlist);
        const label = readOnlyLabel(playlist);
        const hits = searchHits.map((hit) => `
            <li class="sp-track">
                <span>${esc(hit.name)} <span class="ld-meta">${esc((hit.artists || []).join(', '))}</span></span>
                <span class="sp-track-actions">
                    <button type="button" data-meta="${esc(hit.uri)}" data-meta-name="${esc(hit.name)}">Notes</button>
                    <button type="button" data-add="${esc(hit.uri)}" data-name="${esc(hit.name)}" data-artists="${esc((hit.artists || []).join('|'))}">Add</button>
                </span>
            </li>`).join('');
        detailEl.innerHTML = `
            <div class="sp-detail-head">
                <h2>${esc(playlist.name)}</h2>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn" data-sync-one="${playlist.id}">Sync</button>
                    ${owned ? `<button type="button" class="btn-primary" data-push="${playlist.id}">Push</button>` : ''}
                    <button type="button" class="btn" data-discard="${playlist.id}">Discard</button>
                </div>
            </div>
            <p class="ld-meta">${playlist.public ? 'Public' : 'Private'}${playlist.spotify_id ? '' : ' · not on Spotify yet'}</p>
            ${label ? `<p class="sp-readonly">${esc(label)}</p>` : ''}
            ${playlist.sync_error ? `<p class="sp-readonly">${esc(playlist.sync_error)}</p>` : ''}
            ${owned ? `<form id="spotify-rename" class="sp-rename">
                <input name="name" class="input" maxlength="100" value="${esc(playlist.name)}">
                <input name="description" class="input" maxlength="300" value="${esc(playlist.description || '')}" placeholder="Description">
                <label class="text-sm"><input type="checkbox" name="public" ${playlist.public ? 'checked' : ''}> Public</label>
                <button type="submit" class="btn">Stage rename</button>
            </form>` : ''}
            ${diff ? `<ul class="sp-diff">${diff}</ul>` : '<p class="ld-meta">No staged changes.</p>'}
            ${owned ? `<form id="spotify-search" class="sp-search">
                <input name="q" class="input w-full" placeholder="Search Spotify" maxlength="200">
                <button type="submit" class="btn">Search</button>
            </form>` : ''}
            ${owned && hits ? `<ul class="sp-tracks">${hits}</ul>` : ''}
            <ul class="sp-tracks">${owned ? (tracks || '<li class="ld-meta">No tracks.</li>') : (preview.map((track) => trackRow(track, 0, false)).join('') || '<li class="ld-meta">No tracks.</li>')}</ul>`;
    }

    function trackRow(track, index, owned) {
        const artists = (track.artists || []).join(', ');
        const notes = `<button type="button" data-meta="${esc(track.uri)}" data-meta-name="${esc(track.name)}">Notes</button>`;
        const move = owned ? `
                    <button type="button" data-up="${index}">Up</button>
                    <button type="button" data-down="${index}">Down</button>
                    <button type="button" data-remove="${esc(track.uri)}">Remove</button>` : '';
        return `
            <li class="sp-track">
                <span>${esc(track.name)} <span class="ld-meta">${esc(artists)}</span>${metaLine(track.meta)}</span>
                <span class="sp-track-actions">${notes}${move}</span>
            </li>`;
    }

    function metaLine(meta) {
        if (!meta) return '';
        const bits = [];
        if (meta.bpm) bits.push(String(meta.bpm) + ' bpm');
        if (meta.camelot) bits.push(meta.camelot);
        else if (meta.musical_key) bits.push(meta.musical_key);
        if (meta.energy) bits.push('energy ' + meta.energy);
        if (meta.season_tags && meta.season_tags.length) bits.push(meta.season_tags.join(', '));
        if (meta.mood_tags && meta.mood_tags.length) bits.push(meta.mood_tags.join(', '));
        if (meta.source === 'estimated') bits.push('estimated');
        return bits.length ? `<span class="sp-meta">${esc(bits.join(' · '))}</span>` : '';
    }

    function changeSummary(change) {
        const payload = change.payload || {};
        if (change.op === 'add' || change.op === 'remove' || change.op === 'replace') {
            return (payload.uris || []).length + ' tracks';
        }
        if (change.op === 'rename') return payload.name || (payload.public ? 'public' : 'private');
        if (change.op === 'describe') return payload.description || '';
        if (change.op === 'reorder') return 'move ' + (payload.range_start ?? '');
        if (change.op === 'create') return payload.name || 'create';
        return change.op;
    }

    function syncPromptBox() {
        const section = document.getElementById('spotify-prompts');
        if (!section) return;
        const playlist = detail && detail.playlist;
        const show = aiPlaylist(playlist);
        section.hidden = !show;
        if (show && promptForm && !promptForm.dataset.id) {
            promptForm.elements.target_playlist_id.value = String(playlist.id);
        }
        renderPrompts();
    }

    function renderPrompts() {
        if (!promptList) return;
        const playlist = detail && detail.playlist;
        const rows = aiPlaylist(playlist)
            ? prompts.filter((row) => Number(row.target_playlist_id) === Number(playlist.id) || !row.target_playlist_id)
            : [];
        if (!rows.length) {
            promptList.innerHTML = window.DashboardEmpty.html({
                title: 'No prompts',
                body: 'Save a prompt to reuse when a playlist is built.',
                actionLabel: 'Write a prompt',
                actionAttrs: 'type="button" data-focus-prompt',
            });
            return;
        }
        promptList.innerHTML = rows.map((row) => `
            <article class="sp-prompt">
                <strong>${esc(row.name)}</strong>
                <p>${esc(row.prompt)}</p>
                <p class="ld-meta">${promptMeta(row)}</p>
                <button type="button" data-prompt-edit="${row.id}">Edit</button>
                <button type="button" data-prompt-run="${row.id}">Mark run</button>
                <button type="button" data-prompt-remove="${row.id}">Remove</button>
            </article>`).join('');
    }

    function promptMeta(row) {
        const bits = [];
        if (row.theme) bits.push(row.theme);
        if (row.arc) {
            bits.push(['opener', 'build', 'peak', 'breather', 'closer'].map((section) => row.arc[section]).join('-'));
        }
        if (row.season_tags && row.season_tags.length) bits.push(row.season_tags.join(', '));
        if (row.allow_seasons && row.allow_seasons.length) bits.push('allows ' + row.allow_seasons.join(', '));
        if (row.refresh_schedule) bits.push(row.refresh_schedule);
        bits.push(row.last_run_at ? 'Last run ' + row.last_run_at : 'Not run');
        if (!row.active) bits.push('paused');
        return bits.join(' · ');
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

    let seasons = [];

    async function loadSeasons() {
        const list = document.getElementById('spotify-season-list');
        if (!list) return;
        const [all, active] = await Promise.all([
            api.get('/spotify/seasons'),
            api.get('/spotify/seasons/active'),
        ]);
        seasons = all.seasons || [];
        const open = new Set((active.seasons || []).map((row) => row.season));
        if (!seasons.length) {
            list.textContent = 'No season windows.';
            return;
        }
        list.innerHTML = seasons.map((row) => {
            const start = row.start_anchor === 'thanksgiving'
                ? 'Thanksgiving'
                : (row.start_month + '/' + row.start_day);
            const state = open.has(row.season) ? 'open' : 'closed';
            return `<button type="button" class="sp-season" data-season-edit="${esc(row.season)}">${esc(row.season)} ${esc(start)}–${row.end_month}/${row.end_day} (${state})</button>`;
        }).join(' ');
    }

    function fillSeason(name) {
        const form = document.getElementById('spotify-season-form');
        const row = seasons.find((item) => item.season === name);
        if (!form || !row) return;
        form.elements.season.value = row.season;
        form.elements.thanksgiving.checked = row.start_anchor === 'thanksgiving';
        form.elements.start_month.value = row.start_month ?? '';
        form.elements.start_day.value = row.start_day ?? '';
        form.elements.end_month.value = row.end_month;
        form.elements.end_day.value = row.end_day;
    }

    async function openMeta(uri, name) {
        const modal = document.getElementById('spotify-meta-modal');
        const form = document.getElementById('spotify-meta-form');
        if (!modal || !form) return;
        const known = (detail && detail.preview || []).find((track) => track.uri === uri);
        let meta = (known && known.meta) || {};
        if (!meta.source) {
            const res = await api.get('/spotify/tracks/meta?uris=' + encodeURIComponent(uri));
            meta = (res.tracks && res.tracks[0]) || meta;
        }
        form.elements.uri.value = uri;
        form.elements.bpm.value = meta.bpm ?? '';
        form.elements.musical_key.value = meta.musical_key || '';
        form.elements.camelot.value = meta.camelot || '';
        form.elements.energy.value = meta.energy ?? '';
        form.elements.mood_tags.value = (meta.mood_tags || []).join(', ');
        form.elements.season_tags.value = (meta.season_tags || []).join(', ');
        form.elements.source.value = meta.source === 'manual' ? 'manual' : 'estimated';
        const label = form.querySelector('[data-meta-name]');
        if (label) label.textContent = name || (known && known.name) || uri;
        modal.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
        form.elements.bpm.focus();
    }

    function closeMeta() {
        const modal = document.getElementById('spotify-meta-modal');
        if (modal) modal.classList.add('hidden');
        if (!document.querySelector('.app-modal:not(.hidden)')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    root.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        event.preventDefault();
        try {
            if (form.id === 'spotify-create') {
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
                form.reset();
                const regular = form.querySelector('input[name="kind"][value="regular"]');
                if (regular) regular.checked = true;
                const aiFields = document.getElementById('spotify-create-ai');
                if (aiFields) aiFields.hidden = true;
                closeNew();
                current = created.playlist.id;
                await load();
                say('Playlist staged.');
            } else if (form.id === 'spotify-rename' && detail) {
                const data = new FormData(form);
                await api.post('/spotify/playlists/' + detail.playlist.id + '/update', {
                    name: String(data.get('name') || ''),
                    description: String(data.get('description') || ''),
                    public: data.get('public') === 'on',
                });
                await openPlaylist(detail.playlist.id, false);
                say('Rename staged.');
            } else if (form.id === 'spotify-search' && detail) {
                const data = new FormData(form);
                const res = await api.get('/spotify/search?q=' + encodeURIComponent(String(data.get('q') || '')) + '&type=track');
                searchHits = res.tracks || [];
                renderDetail();
            } else if (form.id === 'spotify-prompt-form') {
                const data = new FormData(form);
                const editing = form.dataset.id || '';
                const stamp = String(++promptStamp);
                form.dataset.stamp = stamp;
                const target = String(data.get('target_playlist_id') || '');
                const body = {
                    name: String(data.get('name') || ''),
                    prompt: String(data.get('prompt') || ''),
                    refresh_schedule: String(data.get('refresh_schedule') || ''),
                    notes: String(data.get('notes') || ''),
                    theme: String(data.get('theme') || ''),
                    arc: arcFrom(data),
                    season_tags: String(data.get('season_tags') || ''),
                    allow_seasons: String(data.get('allow_seasons') || ''),
                    target_playlist_id: target ? Number(target) : null,
                    active: true,
                };
                if (editing) {
                    await api.post('/spotify/prompts/' + editing + '/update', body);
                    say('Prompt updated.');
                } else {
                    await api.post('/spotify/prompts', body);
                    say('Prompt saved.');
                }
                // A live refresh can paint the saved prompt and the user can click Edit
                // before this request returns. Leave that edit on the form.
                if (form.dataset.stamp === stamp) {
                    delete form.dataset.id;
                    const submit = form.querySelector('button[type="submit"]');
                    if (submit) submit.textContent = 'Save prompt';
                    form.reset();
                }
                await load();
            } else if (form.id === 'spotify-season-form') {
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
                await loadSeasons();
                say('Season window saved.');
            } else if (form.id === 'spotify-meta-form') {
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
                closeMeta();
                if (detail) await openPlaylist(detail.playlist.id, false);
                say('Track notes saved.');
            }
        } catch (err) {
            say(err.message || 'Could not save that.');
        }
    });

    root.addEventListener('click', async (event) => {
        const target = event.target.closest('button');
        if (!target) return;
        try {
            if (target.dataset.focusPrompt !== undefined) {
                const name = promptForm && promptForm.elements.name;
                if (name) name.focus();
            } else if (target.dataset.newPlaylist !== undefined) {
                openNew();
            } else if (target.dataset.closeModal !== undefined) {
                closeNew();
            } else if (target.dataset.open) {
                searchHits = [];
                await openPlaylist(Number(target.dataset.open), true);
            } else if (target.dataset.sync !== undefined || target.dataset.syncOne) {
                const body = target.dataset.syncOne ? { playlist_id: Number(target.dataset.syncOne) } : {};
                say('Syncing…');
                const synced = await api.post('/spotify/sync', body);
                await load();
                say(synced && synced.queued ? 'Sync queued.' : 'Sync finished.');
            } else if (target.dataset.pushAll !== undefined) {
                say('Pushing…');
                const pushedAll = await api.post('/spotify/push', {});
                await load();
                say(pushedAll && pushedAll.queued ? 'Push queued.' : 'Push finished.');
            } else if (target.dataset.push) {
                say('Pushing…');
                const pushed = await api.post('/spotify/push', { playlist_id: Number(target.dataset.push) });
                await load();
                say(pushed && pushed.queued ? 'Push queued.' : 'Push finished.');
            } else if (target.dataset.discard) {
                await api.post('/spotify/playlists/' + target.dataset.discard + '/discard', {});
                await openPlaylist(Number(target.dataset.discard), false);
                say('Staged changes discarded.');
            } else if (target.dataset.add && detail) {
                await api.post('/spotify/playlists/' + detail.playlist.id + '/tracks', {
                    uris: [target.dataset.add],
                    tracks: [{
                        uri: target.dataset.add,
                        name: target.dataset.name || '',
                        artists: (target.dataset.artists || '').split('|').filter(Boolean),
                    }],
                });
                await openPlaylist(detail.playlist.id, false);
            } else if (target.dataset.remove && detail) {
                await api.post('/spotify/playlists/' + detail.playlist.id + '/tracks/remove', { uris: [target.dataset.remove] });
                await openPlaylist(detail.playlist.id, false);
            } else if ((target.dataset.up || target.dataset.down) && detail) {
                const index = Number(target.dataset.up ?? target.dataset.down);
                const next = target.dataset.up !== undefined ? index - 1 : index + 1;
                const preview = detail.preview || [];
                if (next < 0 || next >= preview.length) return;
                await api.post('/spotify/playlists/' + detail.playlist.id + '/tracks/reorder', {
                    range_start: index,
                    insert_before: target.dataset.up !== undefined ? next : next + 1,
                    length: 1,
                });
                await openPlaylist(detail.playlist.id, false);
            } else if (target.dataset.promptEdit) {
                const row = prompts.find((item) => String(item.id) === target.dataset.promptEdit);
                if (!row || !promptForm) return;
                promptForm.dataset.stamp = String(++promptStamp);
                promptForm.elements.name.value = row.name || '';
                promptForm.elements.prompt.value = row.prompt || '';
                promptForm.elements.refresh_schedule.value = row.refresh_schedule || '';
                promptForm.elements.target_playlist_id.value = row.target_playlist_id || '';
                promptForm.elements.notes.value = row.notes || '';
                promptForm.elements.theme.value = row.theme || '';
                promptForm.elements.arc_opener.value = row.arc ? row.arc.opener : '';
                promptForm.elements.arc_build.value = row.arc ? row.arc.build : '';
                promptForm.elements.arc_peak.value = row.arc ? row.arc.peak : '';
                promptForm.elements.arc_breather.value = row.arc ? row.arc.breather : '';
                promptForm.elements.arc_closer.value = row.arc ? row.arc.closer : '';
                promptForm.elements.season_tags.value = (row.season_tags || []).join(', ');
                promptForm.elements.allow_seasons.value = (row.allow_seasons || []).join(', ');
                promptForm.dataset.id = String(row.id);
                const submit = promptForm.querySelector('button[type="submit"]');
                if (submit) submit.textContent = 'Save changes';
                say('Editing ' + row.name);
            } else if (target.dataset.promptRun) {
                await api.post('/spotify/prompts/' + target.dataset.promptRun + '/run', {});
                await load();
            } else if (target.dataset.promptRemove) {
                await api.post('/spotify/prompts/' + target.dataset.promptRemove + '/remove', {});
                await load();
            } else if (target.dataset.meta) {
                await openMeta(target.dataset.meta, target.dataset.metaName || '');
            } else if (target.dataset.seasonEdit) {
                fillSeason(target.dataset.seasonEdit);
            } else if (target.dataset.closeMeta !== undefined) {
                closeMeta();
            }
        } catch (err) {
            say(err.message || 'Spotify request failed.');
        }
    });

    function openNew() {
        const modal = document.getElementById('spotify-new-modal');
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
        const name = modal.querySelector('input[name="name"]');
        if (name) name.focus();
    }

    function closeNew() {
        const modal = document.getElementById('spotify-new-modal');
        if (modal) modal.classList.add('hidden');
        if (!document.querySelector('.app-modal:not(.hidden)')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    root.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.name !== 'kind') return;
        const aiFields = document.getElementById('spotify-create-ai');
        if (aiFields) aiFields.hidden = input.value !== 'ai' || !input.checked;
    });
    root.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('[data-close-modal]')) closeNew();
        if (event.target.closest('[data-close-meta]')) closeMeta();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeNew();
            closeMeta();
        }
    });

    window.addEventListener('lifedash:event', (event) => {
        const table = event.detail && event.detail.table;
        if (table && String(table).indexOf('spotify_') === 0) schedule();
    });
    window.addEventListener('lifedash:poll', schedule);
    load();
})();
