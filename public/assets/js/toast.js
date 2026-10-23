/**
 * Toast in place of window.alert. Confirm dialogs stay native.
 */
'use strict';

(function () {
    var HOLD_MS = 4500;
    var stack = null;

    function root() {
        if (stack && document.body.contains(stack)) {
            return stack;
        }
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
        return stack;
    }

    function show(message, tone) {
        var text = message == null ? '' : String(message);
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', function () {
                show(text, tone);
            }, { once: true });
            return;
        }
        var item = document.createElement('div');
        item.className = 'toast ' + (tone === 'ok' ? 'toast-ok' : 'toast-error');
        item.setAttribute('role', 'status');
        var body = document.createElement('p');
        body.className = 'toast-text';
        body.textContent = text;
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'toast-dismiss';
        button.setAttribute('aria-label', 'Dismiss');
        button.textContent = 'Dismiss';
        item.appendChild(body);
        item.appendChild(button);
        root().appendChild(item);
        var timer = window.setTimeout(close, HOLD_MS);
        function close() {
            window.clearTimeout(timer);
            if (item.parentNode) {
                item.remove();
            }
        }
        button.addEventListener('click', close);
    }

    window.DashboardToast = { show: show };
    window.alert = function (message) {
        show(message, 'error');
    };
})();
