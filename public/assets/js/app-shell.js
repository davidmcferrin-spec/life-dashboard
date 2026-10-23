(function () {
    var sidebar = document.getElementById('app-sidebar');
    var toggle = document.getElementById('app-nav-toggle');
    var backdrop = document.getElementById('app-nav-backdrop');
    if (!sidebar || !toggle) {
        return;
    }

    var phone = window.matchMedia('(max-width: 699px)');
    var returnFocus = null;

    function collapsed() {
        return phone.matches && !sidebar.classList.contains('is-open');
    }

    function sync() {
        var open = sidebar.classList.contains('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
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

    // Capture so a page that treats Escape as "go home" does not fire while the drawer is open.
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !sidebar.classList.contains('is-open')) {
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

    var dateEl = document.getElementById('live-date');
    if (!dateEl) {
        return;
    }

    function paintDate() {
        var now = window.DashboardTime && typeof window.DashboardTime.now === 'function'
            ? window.DashboardTime.now()
            : new Date();
        dateEl.textContent = now.toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        });
    }

    paintDate();
    setInterval(paintDate, 30000);
})();
