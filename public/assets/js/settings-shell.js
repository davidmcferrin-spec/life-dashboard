'use strict';

(function () {
    var rail = document.getElementById('settings-rail');
    var toggle = document.getElementById('settings-rail-toggle');
    var backdrop = document.getElementById('settings-rail-backdrop');
    if (!rail || !toggle) {
        return;
    }

    var phone = window.matchMedia('(max-width: 699px)');

    function collapsed() {
        return phone.matches && !rail.classList.contains('is-open');
    }

    function sync() {
        var open = rail.classList.contains('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (collapsed()) {
            rail.setAttribute('inert', '');
            rail.setAttribute('aria-hidden', 'true');
        } else {
            rail.removeAttribute('inert');
            rail.removeAttribute('aria-hidden');
        }
        if (backdrop) {
            backdrop.hidden = !open;
        }
        document.body.classList.toggle('settings-rail-open', open && phone.matches);
    }

    function openRail() {
        if (!phone.matches) {
            return;
        }
        rail.classList.add('is-open');
        sync();
        var current = rail.querySelector('[aria-current="page"]') || rail.querySelector('a');
        if (current) {
            current.focus();
        }
    }

    function closeRail(restore) {
        rail.classList.remove('is-open');
        sync();
        if (restore) {
            toggle.focus();
        }
    }

    toggle.addEventListener('click', function () {
        if (rail.classList.contains('is-open')) {
            closeRail(true);
        } else {
            openRail();
        }
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            closeRail(true);
        });
    }

    rail.addEventListener('click', function (event) {
        if (phone.matches && event.target.closest('a')) {
            closeRail(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !rail.classList.contains('is-open') || !phone.matches) {
            return;
        }
        event.preventDefault();
        closeRail(true);
    });

    function onPhoneChange() {
        rail.classList.remove('is-open');
        sync();
    }

    if (typeof phone.addEventListener === 'function') {
        phone.addEventListener('change', onPhoneChange);
    } else if (typeof phone.addListener === 'function') {
        phone.addListener(onPhoneChange);
    }

    sync();
})();
