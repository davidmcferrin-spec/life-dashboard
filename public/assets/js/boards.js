(function () {
    if (!window.LD) return;
    const esc = window.LD.esc;
    const api = window.LD.api;
    const error = document.getElementById('board-error');

    function showError(err) {
        if (!error) return;
        error.textContent = err && err.message ? err.message : 'That did not save.';
        error.classList.remove('hidden');
    }

    function clearError() {
        if (!error) return;
        error.textContent = '';
        error.classList.add('hidden');
    }

    function tags(value) {
        return String(value || '').split(',').map(function (tag) { return tag.trim(); }).filter(Boolean);
    }

    function list(value) {
        return String(value || '').split(',').map(function (part) { return part.trim(); }).filter(Boolean);
    }

    const trips = document.getElementById('trips-app');
    if (trips) {
        const box = document.getElementById('trip-list');
        function draw(rows) {
            box.innerHTML = (rows || []).map(function (row) {
                const when = [row.start_date, row.end_date].filter(Boolean).join(' – ') || 'Dates open';
                const link = row.url ? '<a href="' + esc(row.url) + '">Vault page</a>' : '';
                return '<article class="board-card" id="trip-' + esc(row.id) + '"><h3>' + esc(row.name) + '</h3><p>' + esc(row.destination || 'No destination') + '</p><p>' + esc(when) + ' · ' + esc(row.status) + '</p><p>' + esc(row.owner_bot || '') + '</p>' + link + '</article>';
            }).join('') || '<p class="board-empty">No trips yet.</p>';
        }
        function load() {
            api.get('/trips').then(function (data) {
                clearError();
                draw(data.trips || []);
            }).catch(showError);
        }
        document.getElementById('trip-create').addEventListener('submit', function (event) {
            event.preventDefault();
            const form = event.target;
            const body = {
                name: form.name.value,
                destination: form.destination.value,
                status: form.status.value,
                owner_bot: form.owner_bot.value || null,
                tags: tags(form.tags.value),
                notes: form.notes.value,
                template: form.template.checked,
            };
            if (form.start_date.value) body.start_date = form.start_date.value;
            if (form.end_date.value) body.end_date = form.end_date.value;
            api.post('/trips', body).then(function () {
                form.reset();
                load();
            }).catch(showError);
        });
        load();
    }

    const calls = document.getElementById('calls-app');
    if (calls) {
        const callList = document.getElementById('call-list');
        const contactList = document.getElementById('contact-list');
        function drawCalls(rows) {
            callList.innerHTML = (rows || []).map(function (row) {
                const who = [row.from_number, row.to_number].filter(Boolean).join(' → ') || row.phone_display || '';
                return '<article class="board-card"><h3>' + esc(who || 'Call') + '</h3><p>' + esc(row.purpose || row.reason || '') + '</p><p>' + esc(row.outcome || row.status) + (row.placed_by ? ' · ' + esc(row.placed_by) : '') + '</p><p>' + esc(row.summary || '') + '</p></article>';
            }).join('') || '<p class="board-empty">No calls yet.</p>';
        }
        function drawContacts(rows) {
            contactList.innerHTML = (rows || []).map(function (row) {
                return '<article class="board-card"><h3>' + esc(row.name) + '</h3><p>' + esc((row.phones || []).join(', ')) + '</p><p>' + esc(row.org || '') + '</p></article>';
            }).join('') || '<p class="board-empty">No contacts yet.</p>';
        }
        function loadCalls() {
            api.get('/calls?limit=20').then(function (data) {
                clearError();
                drawCalls(data.calls || []);
            }).catch(showError);
        }
        document.getElementById('call-log').addEventListener('submit', function (event) {
            event.preventDefault();
            const form = event.target;
            const body = {
                direction: 'outbound',
                to_number: form.to_number.value,
                from_number: form.from_number.value,
                purpose: form.purpose.value,
                outcome: form.outcome.value,
                placed_by: form.placed_by.value,
                summary: form.summary.value,
            };
            if (form.duration.value !== '') body.duration = Number(form.duration.value);
            api.post('/calls', body).then(function () {
                form.reset();
                loadCalls();
            }).catch(showError);
        });
        document.getElementById('contact-add').addEventListener('submit', function (event) {
            event.preventDefault();
            const form = event.target;
            api.post('/calls/contacts/add', {
                name: form.name.value,
                phones: list(form.phones.value),
                emails: list(form.emails.value),
                org: form.org.value,
                tags: tags(form.tags.value),
                notes: form.notes.value,
            }).then(function (data) {
                form.reset();
                drawContacts([data.contact]);
            }).catch(showError);
        });
        document.getElementById('contact-find').addEventListener('submit', function (event) {
            event.preventDefault();
            const q = event.target.q.value.trim();
            if (q.length < 2) {
                showError({ message: 'Search needs at least 2 characters.' });
                return;
            }
            api.get('/calls/contacts/find?q=' + encodeURIComponent(q)).then(function (data) {
                clearError();
                drawContacts(data.contacts || []);
            }).catch(showError);
        });
        loadCalls();
    }

    const videos = document.getElementById('videos-app');
    if (videos) {
        const board = document.getElementById('video-board');
        const statuses = ['idea', 'scripted', 'voiced', 'edited', 'scheduled', 'published'];
        function draw(rows) {
            board.innerHTML = statuses.map(function (status) {
                const cards = (rows || []).filter(function (row) { return row.status === status; }).map(function (row) {
                    const link = row.publish_url ? '<a href="' + esc(row.publish_url) + '" rel="noopener">Link</a>' : '';
                    return '<article class="board-card"><h3>' + esc(row.title) + '</h3><p>' + esc(row.channel) + '</p><p>' + esc(row.target_date || '') + '</p>' + link + '</article>';
                }).join('') || '<p class="board-empty">Empty</p>';
                return '<section class="board-column"><h2>' + esc(status) + '</h2>' + cards + '</section>';
            }).join('');
        }
        function load() {
            api.get('/videos').then(function (data) {
                clearError();
                draw(data.videos || []);
            }).catch(showError);
        }
        document.getElementById('video-add').addEventListener('submit', function (event) {
            event.preventDefault();
            const form = event.target;
            const body = {
                channel: form.channel.value,
                title: form.title.value,
                status: form.status.value,
                tags: tags(form.tags.value),
                notes: form.notes.value,
            };
            if (form.target_date.value) body.target_date = form.target_date.value;
            if (form.publish_url.value) body.publish_url = form.publish_url.value;
            api.post('/videos', body).then(function () {
                form.title.value = '';
                form.notes.value = '';
                load();
            }).catch(showError);
        });
        load();
    }
})();
