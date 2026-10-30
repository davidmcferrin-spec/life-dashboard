(function () {
    var sidebar = document.getElementById('app-sidebar');
    var toggle = document.getElementById('app-nav-toggle');
    var backdrop = document.getElementById('app-nav-backdrop');
    var railBtn = document.getElementById('app-sidebar-rail');
    var railKey = 'dashboard_sidebar_collapsed';

    if (!sidebar || !toggle) {
        return;
    }

    var phone = window.matchMedia('(max-width: 699px)');
    var returnFocus = null;

    function railOn() {
        return document.documentElement.classList.contains('app-sidebar-rail');
    }

    function syncRail() {
        if (!railBtn) {
            return;
        }
        var on = railOn();
        railBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        railBtn.setAttribute('aria-label', on ? 'Expand sidebar' : 'Collapse sidebar');
    }

    function setRail(on) {
        document.documentElement.classList.toggle('app-sidebar-rail', on);
        try {
            if (on) {
                localStorage.setItem(railKey, '1');
            } else {
                localStorage.removeItem(railKey);
            }
        } catch (e) { /* private mode */ }
        syncRail();
    }

    if (railBtn) {
        railBtn.addEventListener('click', function () {
            setRail(!railOn());
        });
    }
    syncRail();

    function collapsed() {
        return phone.matches && !sidebar.classList.contains('is-open');
    }

    function sync() {
        var open = sidebar.classList.contains('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        if (collapsed()) {
            sidebar.setAttribute('inert', '');
            sidebar.setAttribute('aria-hidden', 'true');
        } else {
            sidebar.removeAttribute('inert');
            sidebar.removeAttribute('aria-hidden');
        }
        if (backdrop) {
            backdrop.hidden = !open;
        }
        document.body.classList.toggle('app-nav-open', open);
    }

    function openNav() {
        if (!phone.matches) {
            return;
        }
        returnFocus = document.activeElement;
        sidebar.classList.add('is-open');
        sync();
        var first = sidebar.querySelector('a, button');
        if (first) {
            first.focus();
        }
    }

    function closeNav(restore) {
        var wasOpen = sidebar.classList.contains('is-open');
        sidebar.classList.remove('is-open');
        sync();
        if (restore && wasOpen) {
            var target = returnFocus && returnFocus.focus ? returnFocus : toggle;
            target.focus();
        }
    }

    toggle.addEventListener('click', function () {
        if (sidebar.classList.contains('is-open')) {
            closeNav(true);
        } else {
            openNav();
        }
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            closeNav(true);
        });
    }

    sidebar.addEventListener('click', function (event) {
        if (phone.matches && event.target.closest('a')) {
            closeNav(false);
        }
    });

    var searchForm = document.querySelector('.app-header-search');
    var searchInput = document.getElementById('app-search');
    var searchResults = document.getElementById('app-search-results');
    var searchClear = searchForm ? searchForm.querySelector('.app-header-search-clear') : null;
    var searchTimer = 0;
    var searchRequest = 0;

    function resultsOpen() {
        return !!(searchResults && !searchResults.hidden);
    }

    function closeResults() {
        if (!searchResults) {
            return;
        }
        searchResults.hidden = true;
        searchResults.innerHTML = '';
        if (searchInput) {
            searchInput.setAttribute('aria-expanded', 'false');
        }
    }

    function escHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function syncClear() {
        if (!searchClear || !searchInput) {
            return;
        }
        searchClear.hidden = searchInput.value === '';
    }

    function paintResults(html) {
        if (!searchResults || !searchInput) {
            return;
        }
        searchResults.innerHTML = html;
        searchResults.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
    }

    function runSearch(query) {
        var request = ++searchRequest;
        paintResults('<p class="app-header-results-note">Searching…</p>');
        var url = '/api/v1/search?' + new URLSearchParams({ q: query });
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                }).catch(function () {
                    return { ok: false, data: { error: 'Search failed.' } };
                });
            })
            .then(function (result) {
                if (request !== searchRequest) {
                    return;
                }
                if (!result.ok) {
                    paintResults('<p class="app-header-results-note">' + escHtml(result.data.error || 'Search failed.') + '</p>');
                    return;
                }
                var hits = (result.data.hits || []).slice(0, 8);
                if (!hits.length) {
                    paintResults('<p class="app-header-results-note">No matches.</p>');
                    return;
                }
                paintResults(hits.map(function (hit) {
                    var rawUrl = String(hit.url || '');
                    var safe = /^(https?:\/\/|\/)/i.test(rawUrl);
                    var extra = /^https?:/i.test(rawUrl) ? ' target="_blank" rel="noopener noreferrer"' : '';
                    var kind = hit.match === 'similar' ? ' · similar' : '';
                    return '<a class="app-header-hit" href="' + escHtml(safe ? rawUrl : '#') + '"' + extra + '>'
                        + '<span class="app-header-hit-kicker">' + escHtml(hit.dataset || '') + escHtml(kind) + '</span>'
                        + '<strong>' + escHtml(hit.title || '') + '</strong>'
                        + '<span class="app-header-hit-snippet">' + escHtml(hit.snippet || '') + '</span>'
                        + '</a>';
                }).join(''));
            })
            .catch(function () {
                if (request !== searchRequest) {
                    return;
                }
                paintResults('<p class="app-header-results-note">Search failed.</p>');
            });
    }

    function scheduleSearch() {
        window.clearTimeout(searchTimer);
        syncClear();
        if (!searchInput) {
            return;
        }
        var query = searchInput.value.trim();
        if (query.length < 2) {
            searchRequest += 1;
            closeResults();
            return;
        }
        searchTimer = window.setTimeout(function () {
            runSearch(query);
        }, 200);
    }

    if (searchForm && searchInput) {
        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var first = searchResults ? searchResults.querySelector('a') : null;
            if (first) {
                first.click();
            }
        });
        searchInput.addEventListener('input', scheduleSearch);
        searchInput.addEventListener('focus', function () {
            if (searchInput.value.trim().length >= 2 && searchResults && searchResults.hidden) {
                scheduleSearch();
            }
        });
        if (searchClear) {
            searchClear.addEventListener('click', function () {
                searchInput.value = '';
                syncClear();
                closeResults();
                searchInput.focus();
            });
        }
        document.addEventListener('click', function (event) {
            if (!searchForm.contains(event.target)) {
                closeResults();
            }
        });
        searchForm.addEventListener('keydown', function (event) {
            if (!searchResults || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')) {
                return;
            }
            var links = Array.prototype.slice.call(searchResults.querySelectorAll('a'));
            if (!links.length) {
                return;
            }
            var index = links.indexOf(document.activeElement);
            event.preventDefault();
            if (event.key === 'ArrowDown') {
                links[(index + 1) % links.length].focus();
            } else {
                links[(index <= 0 ? links.length : index) - 1].focus();
            }
        });
    }

    // Capture so a page that treats Escape as "go home" does not fire while the drawer is open.
    document.addEventListener('keydown', function (event) {
        var shortcut = (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey
            && (event.key === 'k' || event.key === 'K');
        if (shortcut) {
            if (document.body.classList.contains('app-modal-open') || !searchInput) {
                return;
            }
            event.preventDefault();
            searchInput.focus();
            searchInput.select();
            return;
        }
        if (event.key !== 'Escape') {
            return;
        }
        if (menuOpen(userMenu) || menuOpen(notifyPanel)) {
            event.preventDefault();
            event.stopPropagation();
            setUserMenu(false);
            setNotify(false);
            return;
        }
        if (resultsOpen()) {
            event.preventDefault();
            event.stopPropagation();
            closeResults();
            if (searchInput) {
                searchInput.focus();
            }
            return;
        }
        if (!sidebar.classList.contains('is-open')) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        closeNav(true);
    }, true);

    function onPhoneChange() {
        sidebar.classList.remove('is-open');
        sync();
    }

    if (typeof phone.addEventListener === 'function') {
        phone.addEventListener('change', onPhoneChange);
    } else if (typeof phone.addListener === 'function') {
        phone.addListener(onPhoneChange);
    }

    sync();

    var userMenuBtn = document.getElementById('app-user-menu-btn');
    var userMenu = document.getElementById('app-user-menu-list');
    var notifyBtn = document.getElementById('app-notify-btn');
    var notifyPanel = document.getElementById('app-notify-panel');
    var notifyList = document.getElementById('app-notify-list');
    var notifyRead = document.getElementById('app-notify-read');

    function menuOpen(node) {
        return !!(node && !node.hidden);
    }

    function setUserMenu(open) {
        if (!userMenu || !userMenuBtn) {
            return;
        }
        userMenu.hidden = !open;
        userMenuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function setNotify(open) {
        if (!notifyPanel || !notifyBtn) {
            return;
        }
        notifyPanel.hidden = !open;
        notifyBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && notifyList) {
            notifyList.innerHTML = '<p class="app-notify-empty">Loading…</p>';
            loadNotifications();
        }
    }

    function csrf() {
        return window.getCsrfToken ? window.getCsrfToken() : '';
    }

    function countLabel(count) {
        return count > 99 ? '99+' : String(count);
    }

    function paintNotifyBadge(count) {
        if (!notifyBtn) {
            return;
        }
        var badge = notifyBtn.querySelector('.app-notify-badge');
        notifyBtn.setAttribute('aria-label', count > 0 ? 'Notifications, ' + countLabel(count) + ' unread' : 'Notifications');
        if (count <= 0) {
            if (badge) {
                badge.remove();
            }
            if (notifyRead) {
                notifyRead.hidden = true;
            }
            return;
        }
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'app-notify-badge';
            notifyBtn.appendChild(badge);
        }
        badge.textContent = countLabel(count);
        if (notifyRead) {
            notifyRead.hidden = false;
        }
    }

    function whenLabel(value) {
        var date = new Date(value);
        if (isNaN(date.getTime())) {
            return escHtml(value);
        }
        return escHtml(date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        }));
    }

    function safePath(href) {
        return typeof href === 'string'
            && href.charAt(0) === '/'
            && href.charAt(1) !== '/'
            && href.indexOf('\\') === -1
            && href.indexOf('://') === -1;
    }

    function paintNotifications(items) {
        if (!notifyList) {
            return;
        }
        if (!items.length) {
            notifyList.innerHTML = '<p class="app-notify-empty">No notifications.</p>';
            return;
        }
        notifyList.innerHTML = items.map(function (item) {
            var title = escHtml(item.title || '');
            var body = item.body ? '<span>' + escHtml(item.body) + '</span>' : '';
            var when = item.created_at ? '<time datetime="' + escHtml(item.created_at) + '">' + whenLabel(item.created_at) + '</time>' : '';
            var inner = '<strong>' + title + '</strong>' + body + when;
            var unread = item.read ? '' : ' is-unread';
            if (safePath(item.href)) {
                return '<a class="app-notify-item' + unread + '" data-id="' + escHtml(item.id) + '" href="' + escHtml(item.href) + '">' + inner + '</a>';
            }
            return '<button type="button" class="app-notify-item' + unread + '" data-id="' + escHtml(item.id) + '">' + inner + '</button>';
        }).join('');
    }

    function loadNotifications() {
        if (!notifyList) {
            return;
        }
        fetch('/api/notifications', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                }).catch(function () {
                    return { ok: false, data: {} };
                });
            })
            .then(function (result) {
                if (!result.ok) {
                    notifyList.innerHTML = '<p class="app-notify-empty">Could not load notifications.</p>';
                    return;
                }
                var items = result.data.notifications || [];
                paintNotifyBadge(Number(result.data.unread) || 0);
                paintNotifications(items);
            })
            .catch(function () {
                if (notifyList) {
                    notifyList.innerHTML = '<p class="app-notify-empty">Could not load notifications.</p>';
                }
            });
    }

    function postNotification(url) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf()
            },
            body: '{}'
        }).then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, data: data };
            }).catch(function () {
                return { ok: false, data: {} };
            });
        });
    }

    if (userMenuBtn) {
        userMenuBtn.addEventListener('click', function () {
            var open = !menuOpen(userMenu);
            setNotify(false);
            setUserMenu(open);
        });
    }

    if (notifyBtn) {
        notifyBtn.addEventListener('click', function () {
            var open = !menuOpen(notifyPanel);
            setUserMenu(false);
            setNotify(open);
        });
    }

    if (notifyRead) {
        notifyRead.addEventListener('click', function () {
            postNotification('/api/notifications/read').then(function (result) {
                if (!result.ok) {
                    return;
                }
                paintNotifyBadge(0);
                loadNotifications();
            });
        });
    }

    if (notifyList) {
        notifyList.addEventListener('click', function (event) {
            var item = event.target.closest('[data-id]');
            if (!item) {
                return;
            }
            var id = item.getAttribute('data-id');
            if (!id) {
                return;
            }
            postNotification('/api/notifications/' + encodeURIComponent(id) + '/read');
        });
    }

    document.addEventListener('click', function (event) {
        if (userMenu && userMenuBtn && !userMenuBtn.contains(event.target) && !userMenu.contains(event.target)) {
            setUserMenu(false);
        }
        if (notifyPanel && notifyBtn && !notifyBtn.contains(event.target) && !notifyPanel.contains(event.target)) {
            setNotify(false);
        }
    });

    var themeBtn = document.getElementById('app-theme-cycle');
    var themeOrder = ['jarvis', 'light', 'dark'];
    var themeNames = { jarvis: 'JARVIS', light: 'Light', dark: 'Dark', system: 'System' };

    function themeLabel(pref) {
        return themeNames[pref] || 'JARVIS';
    }

    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var pref = document.documentElement.getAttribute('data-theme-pref') || 'jarvis';
            var index = themeOrder.indexOf(pref);
            var next = themeOrder[(index + 1) % themeOrder.length];
            var pick = document.querySelector('.app-theme-picks [data-theme-pick="' + next + '"]');
            if (pick) {
                pick.click();
            }
        });
        document.addEventListener('dashboard:theme-applied', function (event) {
            var pref = event.detail && event.detail.pref;
            if (!pref) {
                return;
            }
            themeBtn.setAttribute('aria-label', 'Theme: ' + themeLabel(pref) + '. Switch theme.');
        });
    }

    var dateEl = document.getElementById('live-date');
    var clockEl = document.getElementById('live-clock');
    var clockWrap = document.querySelector('.app-header-clock');
    if (!dateEl && !clockEl) {
        return;
    }

    function paintDate() {
        var now = window.DashboardTime && typeof window.DashboardTime.now === 'function'
            ? window.DashboardTime.now()
            : new Date();
        var hour12 = !(clockWrap && clockWrap.getAttribute('data-hour12') === '0');
        if (clockEl) {
            clockEl.textContent = now.toLocaleTimeString('en-US', {
                hour: hour12 ? 'numeric' : '2-digit',
                minute: '2-digit',
                hour12: hour12
            });
        }
        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString('en-US', {
                weekday: 'short',
                month: 'short',
                day: 'numeric'
            });
        }
    }

    paintDate();
    setInterval(paintDate, 1000);
})();
