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
