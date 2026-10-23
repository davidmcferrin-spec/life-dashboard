/**
 * Show reveals only a value the user just typed or pasted.
 * A stored secret is never in the field, so it cannot be revealed.
 */
'use strict';

(function () {
    function fieldOf(node) {
        if (!node || typeof node.closest !== 'function') return null;
        return node.closest('[data-secret-field]');
    }

    function inputOf(field) {
        return field ? field.querySelector('input') : null;
    }

    function showOf(field) {
        return field ? field.querySelector('[data-secret-show]') : null;
    }

    function canReveal(input) {
        return !!input
            && !input.readOnly
            && input.getAttribute('data-typed') === '1'
            && input.value !== '';
    }

    function hide(field) {
        var input = inputOf(field);
        var show = showOf(field);
        if (input) input.type = 'password';
        if (!show) return;
        show.setAttribute('aria-pressed', 'false');
        show.setAttribute('aria-label', 'Show secret');
        show.disabled = !canReveal(input);
    }

    function markTyped(input) {
        if (!input || input.readOnly) return;
        input.setAttribute('data-typed', '1');
        var show = showOf(fieldOf(input));
        if (show) show.disabled = !canReveal(input);
    }

    document.addEventListener('keydown', function (event) {
        var input = event.target;
        if (!input || input.tagName !== 'INPUT') return;
        if (!fieldOf(input) || inputOf(fieldOf(input)) !== input) return;
        if (event.key === 'Tab' || event.key === 'Shift') return;
        markTyped(input);
    });

    document.addEventListener('paste', function (event) {
        var input = event.target;
        if (!input || input.tagName !== 'INPUT') return;
        if (!fieldOf(input) || inputOf(fieldOf(input)) !== input) return;
        markTyped(input);
    });

    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input || input.tagName !== 'INPUT') return;
        var field = fieldOf(input);
        if (!field || inputOf(field) !== input) return;
        if (input.value === '') {
            input.removeAttribute('data-typed');
            hide(field);
            return;
        }
        var show = showOf(field);
        if (show) show.disabled = !canReveal(input);
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || typeof target.closest !== 'function') return;

        var show = target.closest('[data-secret-show]');
        if (show) {
            var field = fieldOf(show);
            var input = inputOf(field);
            if (!canReveal(input)) return;
            var open = show.getAttribute('aria-pressed') === 'true';
            input.type = open ? 'password' : 'text';
            show.setAttribute('aria-pressed', open ? 'false' : 'true');
            show.setAttribute('aria-label', open ? 'Show secret' : 'Hide secret');
            return;
        }

        var replace = target.closest('[data-secret-replace]');
        if (!replace) return;
        var replaced = fieldOf(replace);
        var next = inputOf(replaced);
        if (!next) return;
        next.readOnly = false;
        next.classList.remove('hidden');
        if (next.getAttribute('data-mask') === '1') {
            next.placeholder = '';
            next.removeAttribute('data-mask');
        }
        next.value = '';
        next.removeAttribute('data-typed');
        hide(replaced);
        if (typeof next.focus === 'function') next.focus();
    });
})();
