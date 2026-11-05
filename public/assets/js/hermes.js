/**
 * Hermes chat. The browser talks to /api/v1/hermes, which holds the gateway key.
 * History comes from that API (the gateway session), not from the browser.
 * Enter inserts a newline. Ctrl+Enter or ⌘+Enter sends. A turn in flight disables send.
 */
'use strict';

(function () {
    const root = document.getElementById('hermes-app');
    if (!root || !window.LD) return;

    const log = root.querySelector('[data-hermes-log]');
    const status = root.querySelector('[data-hermes-status]');
    const form = root.querySelector('[data-hermes-form]');
    const field = form.querySelector('textarea');
    const sendBtn = form.querySelector('[data-hermes-send]');
    const newBtn = root.querySelector('[data-hermes-new]');
    const page = Math.max(1, parseInt(log.dataset.page || '40', 10) || 40);
    let timer = 0;
    let posting = false;
    let activeRun = '';
    let hasMore = false;
    let loadingOlder = false;

    function setStatus(text, failed) {
        status.textContent = text || '';
        status.dataset.status = failed ? 'fail' : '';
    }

    function setBusy(on) {
        sendBtn.disabled = !!on;
    }

    function threadPath(before) {
        let path = '/hermes/thread?limit=' + encodeURIComponent(String(page));
        if (before) path += '&before_id=' + encodeURIComponent(before);
        return path;
    }

    function timeLabel(ts) {
        if (!ts) return '';
        const date = new Date(ts);
        if (Number.isNaN(date.getTime())) return '';
        return date.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    }

    function bubble(row) {
        const role = row && row.role === 'user' ? 'user' : 'assistant';
        const item = document.createElement('div');
        item.className = 'hermes-msg hermes-msg-' + role;
        item.dataset.id = row && row.id ? String(row.id) : '';
        const who = document.createElement('p');
        who.className = 'hermes-msg-who';
        who.textContent = role === 'user' ? 'You' : 'Hermes';
        const body = document.createElement('p');
        body.className = 'hermes-msg-body';
        body.textContent = row && row.content ? String(row.content) : '';
        item.appendChild(who);
        item.appendChild(body);
        const label = timeLabel(row && row.ts);
        if (label) {
            const time = document.createElement('time');
            time.className = 'hermes-msg-time';
            time.dateTime = String(row.ts);
            time.textContent = label;
            item.appendChild(time);
        }
        return item;
    }

    function emptyNote(text) {
        const empty = document.createElement('p');
        empty.className = 'hermes-empty';
        empty.textContent = text;
        return empty;
    }

    function knownIds() {
        const ids = new Set();
        log.querySelectorAll('.hermes-msg[data-id]').forEach(function (node) {
            if (node.dataset.id) ids.add(node.dataset.id);
        });
        return ids;
    }

    function oldestId() {
        const node = log.querySelector('.hermes-msg[data-id]');
        return node && node.dataset.id ? node.dataset.id : '';
    }

    function pendingNode() {
        return log.querySelector('[data-hermes-pending]');
    }

    function showPending(on) {
        const existing = pendingNode();
        if (!on) {
            if (existing) existing.remove();
            return;
        }
        if (existing) return;
        const item = document.createElement('div');
        item.className = 'hermes-msg hermes-msg-assistant hermes-msg-pending';
        item.dataset.hermesPending = '1';
        item.setAttribute('aria-live', 'polite');
        const who = document.createElement('p');
        who.className = 'hermes-msg-who';
        who.textContent = 'Hermes';
        const body = document.createElement('p');
        body.className = 'hermes-msg-body';
        body.textContent = 'Working…';
        item.appendChild(who);
        item.appendChild(body);
        log.appendChild(item);
    }

    function clearEmpty() {
        const empty = log.querySelector('.hermes-empty');
        if (empty) empty.remove();
    }

    function replaceMessages(rows) {
        log.replaceChildren();
        if (!rows.length) return;
        rows.forEach(function (row) {
            if (!row || !row.id) return;
            log.appendChild(bubble(row));
        });
    }

    function appendMessages(rows) {
        const known = knownIds();
        const pending = pendingNode();
        let added = 0;
        rows.forEach(function (row) {
            if (!row || !row.id || known.has(String(row.id))) return;
            known.add(String(row.id));
            const node = bubble(row);
            if (pending) log.insertBefore(node, pending);
            else log.appendChild(node);
            added += 1;
        });
        return added;
    }

    function prependMessages(rows) {
        const known = knownIds();
        const fresh = [];
        rows.forEach(function (row) {
            if (!row || !row.id || known.has(String(row.id))) return;
            known.add(String(row.id));
            fresh.push(row);
        });
        if (!fresh.length) return 0;
        clearEmpty();
        const anchor = log.querySelector('.hermes-msg');
        fresh.forEach(function (row) {
            const node = bubble(row);
            if (anchor) log.insertBefore(node, anchor);
            else log.appendChild(node);
        });
        return fresh.length;
    }

    function nearBottom() {
        return log.scrollHeight - log.scrollTop - log.clientHeight < 64;
    }

    function paint(data, mode, force) {
        const messages = Array.isArray(data.messages) ? data.messages : [];
        const stick = !!force || nearBottom();
        if (mode === 'reset') {
            hasMore = !!data.has_more;
            replaceMessages(messages);
            if (!log.querySelector('.hermes-msg')) {
                log.appendChild(emptyNote(data.reachable
                    ? 'No messages yet. Ask Hermes something.'
                    : (data.detail || 'Hermes is not answering.')));
            }
        } else if (!log.querySelector('.hermes-msg[data-id]')) {
            if (!hasMore) hasMore = !!data.has_more;
            replaceMessages(messages);
            clearEmpty();
            if (!log.querySelector('.hermes-msg')) {
                log.appendChild(emptyNote(data.reachable
                    ? 'No messages yet. Ask Hermes something.'
                    : (data.detail || 'Hermes is not answering.')));
            }
        } else {
            clearEmpty();
            appendMessages(messages);
        }
        showPending(!!data.run_id);
        if (!data.reachable) {
            setStatus(data.detail || 'Hermes is not answering.', true);
        } else if (data.run_id) {
            setStatus('Hermes is working…', false);
        } else {
            setStatus('', false);
        }
        if (data.run_id) {
            setBusy(true);
            schedule(String(data.run_id));
        } else {
            activeRun = '';
            window.clearTimeout(timer);
            if (!posting) setBusy(false);
        }
        if (stick) log.scrollTop = log.scrollHeight;
    }

    function fail(error) {
        setStatus(error && error.message ? error.message : 'The message was not sent.', true);
    }

    function schedule(runId) {
        activeRun = runId;
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { poll(runId); }, 2000);
    }

    function poll(runId) {
        if (activeRun !== runId) return;
        window.LD.api.get('/hermes/runs/' + encodeURIComponent(runId)).then(function (data) {
            if (activeRun !== runId) return;
            const done = ['completed', 'failed', 'cancelled', 'interrupted', 'unknown'];
            if (done.indexOf(data.status) === -1) {
                setStatus('Hermes is working…', false);
                schedule(runId);
                return;
            }
            return refresh(false).then(function () {
                if (data.error) {
                    setStatus(String(data.error), true);
                } else if (data.status !== 'completed') {
                    setStatus('Hermes stopped before it finished.', true);
                }
            });
        }).catch(function (error) {
            if (activeRun !== runId) return;
            fail(error);
            setBusy(false);
        });
    }

    function refresh(force) {
        return window.LD.api.get(threadPath('')).then(function (data) {
            paint(data, 'tail', force);
            return data;
        }).catch(fail);
    }

    function loadOlder() {
        if (loadingOlder || !hasMore) return;
        const before = oldestId();
        if (!before) {
            hasMore = false;
            return;
        }
        loadingOlder = true;
        const prevHeight = log.scrollHeight;
        const prevTop = log.scrollTop;
        window.LD.api.get(threadPath(before)).then(function (data) {
            const rows = Array.isArray(data.messages) ? data.messages : [];
            const added = prependMessages(rows);
            if (!added || rows.length === 0) hasMore = false;
            else hasMore = !!data.has_more;
            const grown = log.scrollHeight - prevHeight;
            if (grown <= 0) hasMore = false;
            log.scrollTop = prevTop + grown;
        }).catch(fail).finally(function () {
            loadingOlder = false;
            if (hasMore && log.scrollTop <= 48) loadOlder();
        });
    }

    log.addEventListener('scroll', function () {
        if (log.scrollTop > 48) return;
        loadOlder();
    });

    field.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.isComposing) return;
        if (!event.ctrlKey && !event.metaKey) return;
        event.preventDefault();
        if (posting || sendBtn.disabled || activeRun) return;
        if (!field.value.trim()) return;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (posting || sendBtn.disabled || activeRun) return;
        const message = field.value.trim();
        if (!message) return;
        posting = true;
        setBusy(true);
        setStatus('Sending…', false);
        window.LD.api.post('/hermes/chat', { message: message }).then(function () {
            field.value = '';
            return refresh(true);
        }).catch(function (error) {
            fail(error);
            setBusy(false);
        }).finally(function () {
            posting = false;
            if (!activeRun) setBusy(false);
        });
    });

    newBtn.addEventListener('click', function () {
        const ask = window.AppModal && window.AppModal.confirm
            ? window.AppModal.confirm({
                title: 'New chat',
                message: 'Start a fresh Hermes session? The current transcript stays on the server, and this page shows the new one.',
                confirmLabel: 'New chat',
                cancelLabel: 'Cancel',
            })
            : Promise.resolve(window.confirm('Start a fresh Hermes session?'));
        ask.then(function (yes) {
            if (!yes) return;
            window.clearTimeout(timer);
            activeRun = '';
            hasMore = false;
            return window.LD.api.post('/hermes/reset', {}).then(function (data) {
                paint(data, 'reset', true);
            });
        }).catch(fail);
    });

    window.LD.api.get(threadPath('')).then(function (data) {
        paint(data, 'reset', true);
        if (hasMore && log.scrollTop <= 48) loadOlder();
    }).catch(fail);
})();
