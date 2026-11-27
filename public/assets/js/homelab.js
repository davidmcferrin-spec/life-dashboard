(function (root) {
    'use strict';

    function needsConfirm(action) {
        return action === 'delete';
    }

    function confirmCopy(label, deleteFiles) {
        var name = label && String(label).trim() ? String(label).trim() : 'this item';
        if (deleteFiles) {
            return 'Delete ' + name + ' and its files? This cannot be undone.';
        }
        return 'Delete ' + name + '?';
    }

    function errorText(payload) {
        var code = payload && payload.code ? String(payload.code) : '';
        var message = payload && payload.error ? String(payload.error) : '';
        if (code === 'homelab_unconfigured') {
            return message || 'Not configured. Save it under Settings → Homelab.';
        }
        if (code === 'homelab_disabled') {
            return message || 'Turned off in Settings → Homelab.';
        }
        if (code === 'homelab_unreachable') {
            return message || 'The service is unreachable.';
        }
        if (code === 'homelab_unauthorized') {
            return message || 'The service rejected the credentials.';
        }
        if (code === 'confirmation_required') {
            return message || 'That action needs confirmation.';
        }
        return message || 'The service did not answer.';
    }

    root.HomelabCard = {
        needsConfirm: needsConfirm,
        confirmCopy: confirmCopy,
        errorText: errorText
    };

    if (typeof document === 'undefined') {
        return;
    }

    function csrf() {
        return root.getCsrfToken ? root.getCsrfToken() : '';
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function bytes(value) {
        var n = Number(value) || 0;
        if (n < 1024) return n + ' B';
        var units = ['KB', 'MB', 'GB', 'TB'];
        var i = -1;
        do {
            n /= 1024;
            i += 1;
        } while (n >= 1024 && i < units.length - 1);
        return n.toFixed(n >= 10 ? 0 : 1) + ' ' + units[i];
    }

    async function getJson(url) {
        var res = await fetch(url, { headers: { Accept: 'application/json' } });
        var payload = await res.json().catch(function () { return {}; });
        if (!res.ok) {
            var err = new Error(errorText(payload));
            err.payload = payload;
            throw err;
        }
        return payload;
    }

    async function postJson(url, body) {
        var res = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf()
            },
            body: JSON.stringify(body || {})
        });
        var payload = await res.json().catch(function () { return {}; });
        if (!res.ok) {
            throw new Error(errorText(payload));
        }
        return payload;
    }

    function table(headers, rows) {
        if (!rows.length) {
            return '<p class="text-sm text-muted">Nothing here.</p>';
        }
        var head = headers.map(function (header) {
            return '<th class="' + (header.hide ? 'homelab-hide-sm' : '') + '">' + esc(header.label) + '</th>';
        }).join('');
        var body = rows.map(function (row) {
            return '<tr>' + headers.map(function (header) {
                return '<td class="' + (header.hide ? 'homelab-hide-sm' : '') + '">' + row[header.key] + '</td>';
            }).join('') + '</tr>';
        }).join('');
        return '<table class="homelab-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
    }

    function block(title, html) {
        return '<section class="homelab-block platform-card"><h3>' + esc(title) + '</h3>' + html + '</section>';
    }

    function actions(service, id, label, extra) {
        var buttons = '<button type="button" class="btn text-xs" data-homelab-act="search" data-service="' + esc(service) + '" data-id="' + esc(id) + '">Search</button>';
        buttons += '<button type="button" class="btn text-xs" data-homelab-act="delete" data-service="' + esc(service) + '" data-id="' + esc(id) + '" data-label="' + esc(label) + '">Delete</button>';
        if (extra) buttons += extra;
        return '<span class="homelab-actions">' + buttons + '</span>';
    }

    function renderServarr(service, data) {
        var status = data.status || {};
        var queue = (data.queue && data.queue.items) || [];
        var calendar = (data.calendar && data.calendar.items) || [];
        var missing = (data.missing && data.missing.items) || [];
        var library = (data.library && data.library.items) || [];
        var health = (data.health && data.health.items) || [];
        var html = '';
        html += block('Queue', table([
            { key: 'title', label: 'Title' },
            { key: 'status', label: 'Status' },
            { key: 'left', label: 'Left', hide: true }
        ], queue.map(function (row) {
            return { title: esc(row.title), status: esc(row.status), left: esc(bytes(row.left)) };
        })));
        html += block('Upcoming', table([
            { key: 'title', label: 'Title' },
            { key: 'when', label: 'When', hide: true }
        ], calendar.map(function (row) {
            var title = row.parent ? row.parent + ' · ' + row.title : row.title;
            return { title: esc(title), when: esc(String(row.when || '').slice(0, 16)) };
        })));
        html += block('Missing', '<p class="text-xs text-muted">' + esc(String((data.missing && data.missing.total) || missing.length)) + '</p>' + table([
            { key: 'title', label: 'Title' }
        ], missing.map(function (row) {
            var title = row.parent ? row.parent + ' · ' + row.title : row.title;
            return { title: esc(title) };
        })));
        html += block('Library', '<p class="text-xs text-muted">' + esc(String((data.library && data.library.total) || library.length)) + ' · ' + esc(status.version || '') + '</p>' + table([
            { key: 'title', label: 'Title' },
            { key: 'complete', label: 'Files', hide: true },
            { key: 'actions', label: '' }
        ], library.map(function (row) {
            return {
                title: esc(row.title),
                complete: esc(row.complete),
                actions: actions(service, row.id, row.title)
            };
        })));
        html += block('Health', health.length ? '<ul class="homelab-list">' + health.map(function (row) {
            return '<li>' + esc(row.type) + ' · ' + esc(row.message) + '</li>';
        }).join('') + '</ul>' : '<p class="text-sm text-muted">No health warnings.</p>');
        return html;
    }

    function renderProwlarr(data) {
        var indexers = (data.indexers && data.indexers.items) || [];
        var apps = (data.apps && data.apps.items) || [];
        var health = (data.health && data.health.items) || [];
        return block('Indexers', table([
            { key: 'name', label: 'Name' },
            { key: 'protocol', label: 'Protocol', hide: true },
            { key: 'enabled', label: 'On' }
        ], indexers.map(function (row) {
            return { name: esc(row.name), protocol: esc(row.protocol), enabled: row.enabled ? 'yes' : 'no' };
        }))) + block('Apps', table([
            { key: 'name', label: 'App' },
            { key: 'sync', label: 'Sync' }
        ], apps.map(function (row) {
            return { name: esc(row.name), sync: esc(row.sync_level) };
        }))) + block('Health', health.length ? '<ul class="homelab-list">' + health.map(function (row) {
            return '<li>' + esc(row.message) + '</li>';
        }).join('') + '</ul>' : '<p class="text-sm text-muted">No health warnings.</p>');
    }

    function renderQbit(data) {
        var items = (data.torrents && data.torrents.items) || [];
        return block('Torrents', table([
            { key: 'name', label: 'Name' },
            { key: 'state', label: 'State' },
            { key: 'progress', label: '%', hide: true },
            { key: 'actions', label: '' }
        ], items.map(function (row) {
            var pct = Math.round((Number(row.progress) || 0) * 100);
            var buttons = '<span class="homelab-actions">'
                + '<button type="button" class="btn text-xs" data-qbit="pause" data-hash="' + esc(row.hash) + '">Pause</button>'
                + '<button type="button" class="btn text-xs" data-qbit="resume" data-hash="' + esc(row.hash) + '">Resume</button>'
                + '<button type="button" class="btn text-xs" data-qbit="recheck" data-hash="' + esc(row.hash) + '">Recheck</button>'
                + '<button type="button" class="btn text-xs" data-qbit="delete" data-hash="' + esc(row.hash) + '" data-label="' + esc(row.name) + '">Delete</button>'
                + '</span>';
            return { name: esc(row.name), state: esc(row.state), progress: esc(String(pct)), actions: buttons };
        })));
    }

    var reads = {
        sonarr: ['status', 'queue', 'calendar', 'missing', 'library', 'health'],
        radarr: ['status', 'queue', 'calendar', 'missing', 'library', 'health'],
        prowlarr: ['status', 'indexers', 'health', 'apps'],
        qbittorrent: ['status', 'torrents']
    };

    function pathFor(service, part) {
        if (part === 'library') {
            return '/api/v1/' + service + '/' + (service === 'sonarr' ? 'series' : 'movies');
        }
        if (part === 'torrents') return '/api/v1/qbittorrent/torrents';
        if (part === 'indexers') return '/api/v1/prowlarr/indexers';
        if (part === 'apps') return '/api/v1/prowlarr/apps';
        return '/api/v1/' + service + '/' + part;
    }

    async function loadPanel(panel) {
        var service = panel.getAttribute('data-panel');
        var statusEl = panel.querySelector('[data-homelab-status]');
        var body = panel.querySelector('[data-homelab-body]');
        var parts = reads[service] || [];
        var data = {};
        try {
            for (var i = 0; i < parts.length; i += 1) {
                data[parts[i]] = await getJson(pathFor(service, parts[i]));
            }
        } catch (err) {
            statusEl.textContent = err.message || 'Could not load.';
            body.innerHTML = '';
            return;
        }
        var status = data.status || {};
        if (service === 'qbittorrent') {
            statusEl.textContent = (status.version || 'connected') + ' · down ' + bytes(status.download_speed) + '/s · up ' + bytes(status.upload_speed) + '/s';
            body.innerHTML = renderQbit(data);
        } else if (service === 'prowlarr') {
            statusEl.textContent = (status.app || 'Prowlarr') + ' ' + (status.version || '');
            body.innerHTML = renderProwlarr(data);
        } else {
            statusEl.textContent = (status.app || service) + ' ' + (status.version || '') + (status.instance ? ' · ' + status.instance : '');
            body.innerHTML = renderServarr(service, data);
        }
        if (service === 'sonarr' || service === 'radarr') {
            fillOptions(panel, service);
        }
    }

    async function fillOptions(panel, service) {
        var form = panel.querySelector('[data-add]');
        if (!form || form.dataset.ready === '1') return;
        try {
            var options = await getJson('/api/v1/' + service + '/options');
            var profiles = form.querySelector('[name="quality_profile_id"]');
            var folders = form.querySelector('[name="root_folder"]');
            profiles.innerHTML = (options.profiles || []).map(function (row) {
                return '<option value="' + esc(row.id) + '">' + esc(row.name) + '</option>';
            }).join('');
            folders.innerHTML = (options.folders || []).map(function (row) {
                return '<option value="' + esc(row.path) + '">' + esc(row.path) + '</option>';
            }).join('');
            form.dataset.ready = '1';
        } catch (err) {
            var note = form.querySelector('[data-add-note]');
            if (note) note.textContent = err.message || '';
        }
    }

    function ask(title, message, confirmLabel, cancelLabel) {
        var modal = root.AppModal && root.AppModal.confirm;
        if (!modal) {
            return Promise.resolve(null);
        }
        return modal({
            title: title,
            message: message,
            confirmLabel: confirmLabel,
            cancelLabel: cancelLabel || 'Cancel',
            danger: true
        });
    }

    function noteOn(node, message) {
        var panel = node.closest('[data-panel]');
        var status = panel ? panel.querySelector('[data-homelab-status]') : null;
        if (status) status.textContent = message;
    }

    async function runAct(act) {
        var serviceName = act.getAttribute('data-service');
        var id = act.getAttribute('data-id');
        var kind = act.getAttribute('data-homelab-act');
        var noun = serviceName === 'sonarr' ? 'series' : 'movie';
        var panel = act.closest('[data-panel]');
        try {
            if (kind === 'search') {
                await postJson('/api/v1/' + serviceName + '/' + noun + '/' + encodeURIComponent(id) + '/search', {});
                if (panel) loadPanel(panel);
                return;
            }
            var ok = await ask('Delete', confirmCopy(act.getAttribute('data-label'), false), 'Delete');
            if (ok === null) {
                noteOn(act, 'Confirmation is not available.');
                return;
            }
            if (ok !== true) {
                noteOn(act, 'Not deleted.');
                return;
            }
            var files = await ask('Files', confirmCopy(act.getAttribute('data-label'), true), 'Delete files', 'Keep files');
            if (files === null) {
                noteOn(act, 'Confirmation is not available.');
                return;
            }
            await postJson('/api/v1/' + serviceName + '/' + noun + '/' + encodeURIComponent(id) + '/delete', {
                confirm: true,
                delete_files: files === true
            });
            if (panel) loadPanel(panel);
        } catch (err) {
            noteOn(act, err.message || 'The service did not answer.');
        }
    }

    async function runQbit(qbit) {
        var hash = qbit.getAttribute('data-hash');
        var verb = qbit.getAttribute('data-qbit');
        var panel = qbit.closest('[data-panel]');
        var body = {};
        try {
            if (verb === 'delete') {
                var ok = await ask('Delete torrent', confirmCopy(qbit.getAttribute('data-label'), false), 'Delete');
                if (ok === null) {
                    noteOn(qbit, 'Confirmation is not available.');
                    return;
                }
                if (ok !== true) {
                    noteOn(qbit, 'Not deleted.');
                    return;
                }
                var files = await ask('Files', confirmCopy(qbit.getAttribute('data-label'), true), 'Delete files', 'Keep files');
                if (files === null) {
                    noteOn(qbit, 'Confirmation is not available.');
                    return;
                }
                body = { confirm: true, delete_files: files === true };
            }
            await postJson('/api/v1/qbittorrent/torrents/' + encodeURIComponent(hash) + '/' + verb, body);
            if (panel) loadPanel(panel);
        } catch (err) {
            noteOn(qbit, err.message || 'qBittorrent did not answer.');
        }
    }

    function wirePage(page) {
        page.querySelectorAll('[data-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-tab');
                page.querySelectorAll('[data-tab]').forEach(function (other) {
                    var on = other === tab;
                    other.classList.toggle('is-active', on);
                    other.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                page.querySelectorAll('[data-panel]').forEach(function (panel) {
                    var on = panel.getAttribute('data-panel') === name;
                    panel.classList.toggle('is-hidden', !on);
                    panel.hidden = !on;
                    if (on && panel.dataset.loaded !== '1') {
                        panel.dataset.loaded = '1';
                        loadPanel(panel);
                    }
                });
            });
        });
        var first = page.querySelector('[data-panel]');
        if (first) {
            first.dataset.loaded = '1';
            loadPanel(first);
        }
        page.addEventListener('click', function (event) {
            var lookup = event.target.closest('[data-lookup]');
            if (lookup) {
                event.preventDefault();
                var form = lookup.closest('form');
                var service = form.getAttribute('data-add');
                var term = form.querySelector('[name="term"]').value;
                getJson('/api/v1/' + service + '/lookup?term=' + encodeURIComponent(term)).then(function (payload) {
                    var select = form.querySelector('[name="remote_id"]');
                    select.innerHTML = (payload.items || []).map(function (row) {
                        return '<option value="' + esc(row.remote_id) + '" data-title="' + esc(row.title) + '">' + esc(row.title) + (row.year ? ' (' + esc(row.year) + ')' : '') + '</option>';
                    }).join('');
                    var chosen = select.options[0];
                    if (chosen) form.querySelector('[name="title"]').value = chosen.getAttribute('data-title') || '';
                }).catch(function (err) {
                    form.querySelector('[data-add-note]').textContent = err.message || '';
                });
                return;
            }
            var act = event.target.closest('[data-homelab-act]');
            if (act) {
                event.preventDefault();
                runAct(act);
                return;
            }
            var qbit = event.target.closest('[data-qbit]');
            if (!qbit) return;
            event.preventDefault();
            runQbit(qbit);
        });
        page.querySelectorAll('[data-add]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var service = form.getAttribute('data-add');
                var remote = form.querySelector('[name="remote_id"]');
                var option = remote.options[remote.selectedIndex];
                postJson('/api/v1/' + service + '/' + (service === 'sonarr' ? 'series' : 'movies'), {
                    title: form.querySelector('[name="title"]').value || (option ? option.getAttribute('data-title') : ''),
                    remote_id: Number(remote.value || 0),
                    quality_profile_id: Number(form.querySelector('[name="quality_profile_id"]').value || 0),
                    root_folder: form.querySelector('[name="root_folder"]').value || '',
                    search: form.querySelector('[name="search"]').checked
                }).then(function () {
                    form.querySelector('[data-add-note]').textContent = 'Added.';
                    var panel = form.closest('[data-panel]');
                    if (panel) loadPanel(panel);
                }).catch(function (err) {
                    form.querySelector('[data-add-note]').textContent = err.message || '';
                });
            });
            form.querySelector('[name="remote_id"]').addEventListener('change', function () {
                var option = form.querySelector('[name="remote_id"]').selectedOptions[0];
                if (option) form.querySelector('[name="title"]').value = option.getAttribute('data-title') || '';
            });
        });
        var prowlarr = page.querySelector('[data-prowlarr-search]');
        if (prowlarr) {
            prowlarr.addEventListener('submit', function (event) {
                event.preventDefault();
                var query = prowlarr.querySelector('[name="query"]').value;
                getJson('/api/v1/prowlarr/search?query=' + encodeURIComponent(query)).then(function (payload) {
                    var hits = prowlarr.querySelector('[data-prowlarr-hits]');
                    hits.innerHTML = table([
                        { key: 'title', label: 'Title' },
                        { key: 'indexer', label: 'Indexer' },
                        { key: 'seeders', label: 'Seeds', hide: true }
                    ], (payload.items || []).map(function (row) {
                        return { title: esc(row.title), indexer: esc(row.indexer), seeders: esc(row.seeders) };
                    }));
                }).catch(function (err) {
                    prowlarr.querySelector('[data-prowlarr-hits]').textContent = err.message || '';
                });
            });
        }
        var addTorrent = page.querySelector('[data-qbit-add]');
        if (addTorrent) {
            addTorrent.addEventListener('submit', function (event) {
                event.preventDefault();
                postJson('/api/v1/qbittorrent/torrents/add', {
                    urls: addTorrent.querySelector('[name="urls"]').value,
                    category: addTorrent.querySelector('[name="category"]').value
                }).then(function () {
                    addTorrent.querySelector('[data-add-note]').textContent = 'Sent to qBittorrent.';
                    var panel = addTorrent.closest('[data-panel]');
                    if (panel) loadPanel(panel);
                }).catch(function (err) {
                    addTorrent.querySelector('[data-add-note]').textContent = err.message || '';
                });
            });
        }
    }

    function wireCard(card) {
        card.querySelectorAll('[data-homelab-row]').forEach(function (row) {
            var service = row.getAttribute('data-homelab-row');
            var slot = row.querySelector('[data-homelab-version]');
            getJson('/api/v1/' + service + '/status').then(function (payload) {
                if (service === 'qbittorrent') {
                    slot.textContent = (payload.version || 'up') + ' · ' + bytes(payload.download_speed) + '/s';
                    return;
                }
                slot.textContent = (payload.version || 'up');
            }).catch(function (err) {
                slot.textContent = err.message || 'unavailable';
            });
        });
    }

    document.querySelectorAll('[data-homelab="page"]').forEach(wirePage);
    document.querySelectorAll('[data-homelab="card"]').forEach(wireCard);
}(typeof globalThis !== 'undefined' ? globalThis : this));
