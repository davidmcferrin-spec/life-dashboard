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
    }

    function renderLists() {
        const create = `<form id="spotify-create" class="sp-create">
            <input name="name" class="input w-full" maxlength="100" placeholder="New playlist" required>
            <button type="submit" class="btn">Create</button>
        </form>`;
        if (!playlists.length) {
            listEl.innerHTML = create + '<p class="empty-state mono text-xs">No playlists yet. Sync, or create one.</p>';
            return;
        }
        listEl.innerHTML = create + playlists.map((row) => `
            <button type="button" class="sp-row${row.id === current ? ' is-on' : ''}" data-open="${row.id}">
                <strong>${esc(row.name)}</strong>
                <span>${row.track_count} tracks${row.local_dirty ? ' · staged' : ''}${row.spotify_id ? '' : ' · draft'}</span>
            </button>`).join('');
    }

    async function openPlaylist(id, scroll) {
        current = id;
        renderLists();
        detailEl.innerHTML = '<p class="empty-state mono text-xs">Loading…</p>';
        detail = await api.get('/spotify/playlists/' + id);
        renderDetail();
        if (scroll) detailEl.scrollIntoView({ block: 'nearest' });
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
        const tracks = preview.map((track, index) => `
            <li class="sp-track">
                <span>${esc(track.name)} <span class="ld-meta">${esc((track.artists || []).join(', '))}</span></span>
                <span class="sp-track-actions">
                    <button type="button" data-up="${index}">Up</button>
                    <button type="button" data-down="${index}">Down</button>
                    <button type="button" data-remove="${esc(track.uri)}">Remove</button>
                </span>
            </li>`).join('');
        const hits = searchHits.map((hit) => `
            <li class="sp-track">
                <span>${esc(hit.name)} <span class="ld-meta">${esc((hit.artists || []).join(', '))}</span></span>
                <button type="button" data-add="${esc(hit.uri)}" data-name="${esc(hit.name)}" data-artists="${esc((hit.artists || []).join('|'))}">Add</button>
            </li>`).join('');
        detailEl.innerHTML = `
            <div class="sp-detail-head">
                <h2>${esc(playlist.name)}</h2>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn" data-sync-one="${playlist.id}">Sync</button>
                    <button type="button" class="btn-primary" data-push="${playlist.id}">Push</button>
                    <button type="button" class="btn" data-discard="${playlist.id}">Discard</button>
                </div>
            </div>
            <p class="ld-meta">${playlist.public ? 'Public' : 'Private'}${playlist.spotify_id ? '' : ' · not on Spotify yet'}</p>
            <form id="spotify-rename" class="sp-rename">
                <input name="name" class="input" maxlength="100" value="${esc(playlist.name)}">
                <input name="description" class="input" maxlength="300" value="${esc(playlist.description || '')}" placeholder="Description">
                <label class="text-sm"><input type="checkbox" name="public" ${playlist.public ? 'checked' : ''}> Public</label>
                <button type="submit" class="btn">Stage rename</button>
            </form>
            ${diff ? `<ul class="sp-diff">${diff}</ul>` : '<p class="ld-meta">No staged changes.</p>'}
            <form id="spotify-search" class="sp-search">
                <input name="q" class="input w-full" placeholder="Search Spotify" maxlength="200">
                <button type="submit" class="btn">Search</button>
            </form>
            ${hits ? `<ul class="sp-tracks">${hits}</ul>` : ''}
            <ul class="sp-tracks">${tracks || '<li class="ld-meta">No tracks.</li>'}</ul>`;
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

    function renderPrompts() {
        if (!prompts.length) {
            promptList.innerHTML = '<p class="empty-state mono text-xs">No prompts yet.</p>';
            return;
        }
        promptList.innerHTML = prompts.map((row) => `
            <article class="sp-prompt">
                <strong>${esc(row.name)}</strong>
                <p>${esc(row.prompt)}</p>
                <p class="ld-meta">${row.refresh_schedule ? esc(row.refresh_schedule) + ' · ' : ''}${row.last_run_at ? 'Last run ' + esc(row.last_run_at) : 'Not run'}${row.active ? '' : ' · paused'}</p>
                <button type="button" data-prompt-edit="${row.id}">Edit</button>
                <button type="button" data-prompt-run="${row.id}">Mark run</button>
                <button type="button" data-prompt-remove="${row.id}">Remove</button>
            </article>`).join('');
    }

    root.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        event.preventDefault();
        try {
            if (form.id === 'spotify-create') {
                const data = new FormData(form);
                const created = await api.post('/spotify/playlists', { name: String(data.get('name') || '') });
                form.reset();
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
                const target = String(data.get('target_playlist_id') || '');
                const body = {
                    name: String(data.get('name') || ''),
                    prompt: String(data.get('prompt') || ''),
                    refresh_schedule: String(data.get('refresh_schedule') || ''),
                    notes: String(data.get('notes') || ''),
                    target_playlist_id: target ? Number(target) : null,
                    active: true,
                };
                if (form.dataset.id) {
                    await api.post('/spotify/prompts/' + form.dataset.id + '/update', body);
                    delete form.dataset.id;
                    const submit = form.querySelector('button[type="submit"]');
                    if (submit) submit.textContent = 'Save prompt';
                    say('Prompt updated.');
                } else {
                    await api.post('/spotify/prompts', body);
                    say('Prompt saved.');
                }
                form.reset();
                await load();
            }
        } catch (err) {
            say(err.message || 'Could not save that.');
        }
    });

    root.addEventListener('click', async (event) => {
        const target = event.target.closest('button');
        if (!target) return;
        try {
            if (target.dataset.open) {
                searchHits = [];
                await openPlaylist(Number(target.dataset.open), true);
            } else if (target.dataset.sync !== undefined || target.dataset.syncOne) {
                const body = target.dataset.syncOne ? { playlist_id: Number(target.dataset.syncOne) } : {};
                say('Syncing…');
                await api.post('/spotify/sync', body);
                await load();
                say('Sync finished.');
            } else if (target.dataset.pushAll !== undefined) {
                say('Pushing…');
                await api.post('/spotify/push', {});
                await load();
                say('Push finished.');
            } else if (target.dataset.push) {
                say('Pushing…');
                await api.post('/spotify/push', { playlist_id: Number(target.dataset.push) });
                await load();
                say('Push finished.');
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
                promptForm.elements.name.value = row.name || '';
                promptForm.elements.prompt.value = row.prompt || '';
                promptForm.elements.refresh_schedule.value = row.refresh_schedule || '';
                promptForm.elements.target_playlist_id.value = row.target_playlist_id || '';
                promptForm.elements.notes.value = row.notes || '';
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
            }
        } catch (err) {
            say(err.message || 'Spotify request failed.');
        }
    });

    window.addEventListener('lifedash:event', (event) => {
        const table = event.detail && event.detail.table;
        if (table && String(table).indexOf('spotify_') === 0) schedule();
    });
    window.addEventListener('lifedash:poll', schedule);
    load();
})();
