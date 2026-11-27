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

    function renderUnraid(data) {
        var disks = ((data.disks && data.disks.disks) || []).concat((data.disks && data.disks.parities) || [], (data.disks && data.disks.caches) || []);
        var parity = (data.disks && data.disks.parity) || {};
        var containers = (data.docker && data.docker.items) || [];
        var vms = (data.vms && data.vms.items) || [];
        var status = data.status || {};
        var mem = status.memory_percent == null ? '' : Math.round(status.memory_percent) + '%';
        var cpu = status.cpu_percent == null ? '' : Math.round(status.cpu_percent) + '%';
        return block('Host', '<p class="text-sm">' + esc(status.cpu || '') + ' · CPU ' + esc(cpu) + ' · memory ' + esc(mem) + '</p>')
            + block('Disks', '<p class="text-xs text-muted">Parity ' + esc(parity.status || '') + '</p>' + table([
                { key: 'name', label: 'Disk' },
                { key: 'temp', label: 'Temp', hide: true },
                { key: 'status', label: 'Status' }
            ], disks.map(function (row) {
                return { name: esc(row.name || row.device), temp: row.temp_c == null ? '' : esc(row.temp_c + ' C'), status: esc(row.status) };
            })))
            + block('Containers', table([
                { key: 'name', label: 'Name' },
                { key: 'state', label: 'State' },
                { key: 'actions', label: '' }
            ], containers.map(function (row) {
                var buttons = '<span class="homelab-actions">'
                    + '<button type="button" class="btn text-xs" data-unraid="start" data-kind="docker" data-id="' + esc(row.id) + '">Start</button>'
                    + '<button type="button" class="btn text-xs" data-unraid="stop" data-kind="docker" data-id="' + esc(row.id) + '" data-label="' + esc(row.name) + '">Stop</button>'
                    + '<button type="button" class="btn text-xs" data-unraid="restart" data-kind="docker" data-id="' + esc(row.id) + '" data-label="' + esc(row.name) + '">Restart</button>'
                    + '</span>';
                return { name: esc(row.name), state: esc(row.state), actions: buttons };
            })))
            + block('VMs', table([
                { key: 'name', label: 'Name' },
                { key: 'state', label: 'State' },
                { key: 'actions', label: '' }
            ], vms.map(function (row) {
                var buttons = '<span class="homelab-actions">'
                    + '<button type="button" class="btn text-xs" data-unraid="start" data-kind="vm" data-id="' + esc(row.id) + '">Start</button>'
                    + '<button type="button" class="btn text-xs" data-unraid="stop" data-kind="vm" data-id="' + esc(row.id) + '" data-label="' + esc(row.name) + '">Stop</button>'
                    + '</span>';
                return { name: esc(row.name), state: esc(row.state), actions: buttons };
            })));
    }

    function renderJellyfin(data) {
        var sessions = (data.sessions && data.sessions.items) || [];
        var recent = (data.recent && data.recent.items) || [];
        var counts = data.counts || {};
        return block('Now playing', table([
            { key: 'user', label: 'User' },
            { key: 'title', label: 'Title' },
            { key: 'device', label: 'Device', hide: true }
        ], sessions.filter(function (row) { return row.playing; }).map(function (row) {
            return { user: esc(row.user), title: esc(row.now_playing), device: esc(row.device) };
        }))) + block('Library', '<p class="text-sm">Movies ' + esc(counts.movie || 0) + ' · Series ' + esc(counts.series || 0) + ' · Episodes ' + esc(counts.episode || 0) + '</p>')
            + block('Recently added', table([{ key: 'name', label: 'Title' }, { key: 'type', label: 'Type' }], recent.map(function (row) {
                return { name: esc(row.name), type: esc(row.type) };
            })));
    }

    function renderPlex(data) {
        var sessions = (data.sessions && data.sessions.items) || [];
        var recent = (data.recent && data.recent.items) || [];
        var libraries = (data.libraries && data.libraries.items) || [];
        return block('Sessions', table([
            { key: 'title', label: 'Title' },
            { key: 'user', label: 'User' }
        ], sessions.map(function (row) {
            return { title: esc(row.title), user: esc(row.user) };
        }))) + block('Libraries', table([
            { key: 'title', label: 'Section' },
            { key: 'count', label: 'Count', hide: true },
            { key: 'actions', label: '' }
        ], libraries.map(function (row) {
            return {
                title: esc(row.title),
                count: esc(row.count == null ? '' : row.count),
                actions: '<button type="button" class="btn text-xs" data-plex-refresh="' + esc(row.key) + '">Refresh</button>'
            };
        }))) + block('Recently added', table([{ key: 'title', label: 'Title' }, { key: 'type', label: 'Type' }], recent.map(function (row) {
            return { title: esc(row.title), type: esc(row.type) };
        })));
    }

    var reads = {
        sonarr: ['status', 'queue', 'calendar', 'missing', 'library', 'health'],
        radarr: ['status', 'queue', 'calendar', 'missing', 'library', 'health'],
        prowlarr: ['status', 'indexers', 'health', 'apps'],
        qbittorrent: ['status', 'torrents'],
        unraid: ['status', 'disks', 'docker', 'vms'],
        jellyfin: ['status', 'sessions', 'counts', 'recent'],
        plex: ['status', 'sessions', 'libraries', 'recent']
    };

    function pathFor(service, part) {
        if (part === 'library') {
            return '/api/v1/' + service + '/' + (service === 'sonarr' ? 'series' : 'movies');
        }
        if (part === 'torrents') return '/api/v1/qbittorrent/torrents';
        if (part === 'docker') return '/api/v1/unraid/docker';
        if (part === 'vms') return '/api/v1/unraid/vms';
        if (part === 'disks') return '/api/v1/unraid/disks';
        if (part === 'sessions') return '/api/v1/' + service + '/sessions';
        if (part === 'counts') return '/api/v1/jellyfin/counts';
        if (part === 'recent') return '/api/v1/' + service + '/recent';
        if (part === 'libraries') return '/api/v1/plex/libraries';
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
        } else if (service === 'unraid') {
            statusEl.textContent = (status.hostname || 'Unraid') + ' ' + (status.version || '') + (status.array ? ' · array ' + status.array : '');
            body.innerHTML = renderUnraid(data);
        } else if (service === 'jellyfin') {
            statusEl.textContent = (status.name || 'Jellyfin') + ' ' + (status.version || '');
            body.innerHTML = renderJellyfin(data);
        } else if (service === 'plex') {
            statusEl.textContent = (status.name || 'Plex') + ' ' + (status.version || '');
            body.innerHTML = renderPlex(data);
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

    async function runUnraid(button) {
        var action = button.getAttribute('data-unraid');
        var kind = button.getAttribute('data-kind');
        var id = button.getAttribute('data-id');
        var panel = button.closest('[data-panel]');
        var body = {};
        try {
            if (action === 'stop' || action === 'restart') {
                var noun = kind === 'vm' ? 'VM' : 'container';
                var ok = await ask(action === 'restart' ? 'Restart' : 'Stop', (action === 'restart' ? 'Restart ' : 'Stop ') + (button.getAttribute('data-label') || noun) + '?', action === 'restart' ? 'Restart' : 'Stop');
                if (ok === null) {
                    noteOn(button, 'Confirmation is not available.');
                    return;
                }
                if (ok !== true) {
                    noteOn(button, 'Not sent.');
                    return;
                }
                body = { confirm: true };
            }
            var path = kind === 'vm' ? '/api/v1/unraid/vms/' : '/api/v1/unraid/docker/';
            await postJson(path + encodeURIComponent(id) + '/' + action, body);
            if (panel) loadPanel(panel);
        } catch (err) {
            noteOn(button, err.message || 'Unraid did not answer.');
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
            if (qbit) {
                event.preventDefault();
                runQbit(qbit);
                return;
            }
            var unraid = event.target.closest('[data-unraid]');
            if (unraid) {
                event.preventDefault();
                runUnraid(unraid);
                return;
            }
            var refresh = event.target.closest('[data-plex-refresh]');
            if (!refresh) return;
            event.preventDefault();
            postJson('/api/v1/plex/libraries/' + encodeURIComponent(refresh.getAttribute('data-plex-refresh')) + '/refresh', {}).then(function () {
                noteOn(refresh, 'Library refresh started.');
            }).catch(function (err) {
                noteOn(refresh, err.message || 'Plex did not answer.');
            });
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
        function wireSearch(root, formSel, url, field, hitsSel) {
            var form = root.querySelector(formSel);
            if (!form) return;
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var value = form.querySelector('[name="' + field + '"]').value;
                getJson(url + encodeURIComponent(value)).then(function (payload) {
                    form.querySelector(hitsSel).innerHTML = table([
                        { key: 'title', label: 'Title' },
                        { key: 'type', label: 'Type' }
                    ], (payload.items || []).map(function (row) {
                        return { title: esc(row.name || row.title), type: esc(row.type) };
                    }));
                }).catch(function (err) {
                    form.querySelector(hitsSel).textContent = err.message || '';
                });
            });
        }
        wireSearch(page, '[data-jellyfin-search]', '/api/v1/jellyfin/search?term=', 'term', '[data-jellyfin-hits]');
        wireSearch(page, '[data-plex-search]', '/api/v1/plex/search?query=', 'query', '[data-plex-hits]');
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
