/**
 * Shared behavior for every .app-modal.
 *
 * Inert is set on siblings of the dialog and of each ancestor, never on
 * those ancestors. An inert parent would freeze the dialog itself.
 * Escape clicks the dialog's own close control so its existing handler
 * still runs (scroll lock, remove-on-close, and the rest).
 *
 * AppModal.confirm is for a yes/no choice that has no markup of its own.
 * Cancel, Escape, and the backdrop resolve false. The confirm button
 * resolves true.
 */
'use strict';

(function () {
    const openers = new WeakMap();
    let lastOutside = null;

    function isOpen(modal) {
        return !!modal
            && modal.isConnected
            && modal.classList.contains('app-modal')
            && !modal.classList.contains('hidden')
            && !modal.hasAttribute('hidden');
    }

    function openModals() {
        return Array.from(document.querySelectorAll('.app-modal')).filter(isOpen);
    }

    function closer(modal) {
        return modal.querySelector(
            '.app-modal-close, [data-close], [data-close-modal], [data-close-meta], [data-setup-close]'
        );
    }

    function buried(el, modal) {
        let node = el.parentElement;
        while (node && node !== modal) {
            if (node.classList.contains('hidden') || node.hasAttribute('hidden')) return true;
            node = node.parentElement;
        }
        return false;
    }

    function tabbables(modal) {
        const sel = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled]):not([type="hidden"])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
        ].join(', ');
        return Array.from(modal.querySelectorAll(sel)).filter((el) => !buried(el, modal));
    }

    function firstField(modal) {
        const sel = [
            'input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
        ].join(', ');
        return Array.from(modal.querySelectorAll(sel)).find((el) => !buried(el, modal)) || null;
    }

    function titleEl(modal) {
        const id = modal.getAttribute('aria-labelledby');
        if (id) {
            const named = document.getElementById(id);
            if (named && modal.contains(named)) return named;
        }
        return modal.querySelector('h1, h2, h3, h4');
    }

    function initialFocus(modal) {
        const field = firstField(modal);
        if (field) return field;
        const title = titleEl(modal);
        if (title) {
            if (!title.hasAttribute('tabindex')) title.setAttribute('tabindex', '-1');
            return title;
        }
        return closer(modal);
    }

    function releaseInert() {
        document.querySelectorAll('[data-modal-inert]').forEach((el) => {
            if (el.getAttribute('data-modal-inert') === '1') el.removeAttribute('inert');
            el.removeAttribute('data-modal-inert');
        });
    }

    function paintInert(modal) {
        let node = modal;
        while (node.parentElement && node.parentElement !== document.documentElement) {
            const parent = node.parentElement;
            Array.from(parent.children).forEach((sib) => {
                if (sib === node || sib.hasAttribute('inert') || sib.hasAttribute('data-modal-inert')) return;
                sib.setAttribute('inert', '');
                sib.setAttribute('data-modal-inert', '1');
            });
            if (parent === document.body) break;
            node = parent;
        }
    }

    function remember(modal) {
        if (openers.has(modal)) return;
        const active = document.activeElement;
        let opener = null;
        if (active && active !== document.body && active !== document.documentElement && !modal.contains(active)) {
            opener = active;
        } else if (lastOutside && lastOutside.isConnected && !modal.contains(lastOutside)) {
            opener = lastOutside;
        }
        openers.set(modal, opener);
    }

    function focusInside(modal) {
        const active = document.activeElement;
        if (active && modal.contains(active)) return;
        const target = initialFocus(modal);
        if (target && typeof target.focus === 'function') target.focus();
    }

    function settle(closed) {
        const open = openModals();
        open.forEach(remember);
        const top = open.length ? open[open.length - 1] : null;
        releaseInert();
        if (top) paintInert(top);
        closed.forEach((modal) => {
            const opener = openers.get(modal) || null;
            openers.delete(modal);
            if (!opener || !opener.isConnected || typeof opener.focus !== 'function') return;
            if (top && !top.contains(opener)) return;
            opener.focus();
        });
        if (top) focusInside(top);
    }

    function collect(node, bag) {
        if (!node || node.nodeType !== 1) return;
        if (node.classList && node.classList.contains('app-modal')) bag.add(node);
        if (typeof node.querySelectorAll === 'function') {
            node.querySelectorAll('.app-modal').forEach((modal) => bag.add(modal));
        }
    }

    const observer = new MutationObserver((records) => {
        const bag = new Set();
        records.forEach((rec) => {
            if (rec.type === 'attributes') {
                collect(rec.target, bag);
                return;
            }
            Array.from(rec.addedNodes || []).forEach((node) => collect(node, bag));
            Array.from(rec.removedNodes || []).forEach((node) => collect(node, bag));
        });
        if (!bag.size) return;
        const closed = Array.from(bag).filter((modal) => openers.has(modal) && !isOpen(modal));
        const opened = Array.from(bag).filter((modal) => isOpen(modal));
        if (!closed.length && !opened.length) return;
        settle(closed);
    });

    function boot() {
        observer.observe(document.documentElement, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class', 'hidden'],
        });
        if (openModals().length) settle([]);
    }

    document.addEventListener('focusin', (event) => {
        const target = event.target;
        if (!target || target === document.body || target === document.documentElement) return;
        if (typeof target.closest !== 'function') return;
        const host = target.closest('.app-modal');
        if (host && isOpen(host)) return;
        lastOutside = target;
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' && event.key !== 'Tab') return;
        if (event.key === 'Tab' && (event.altKey || event.ctrlKey || event.metaKey)) return;
        const open = openModals();
        const modal = open.length ? open[open.length - 1] : null;
        if (!modal) return;

        if (event.key === 'Escape') {
            const btn = closer(modal);
            if (!btn) return;
            event.preventDefault();
            btn.click();
            return;
        }

        const items = tabbables(modal);
        if (!items.length) {
            event.preventDefault();
            const fallback = initialFocus(modal);
            if (fallback) fallback.focus();
            return;
        }
        const active = document.activeElement;
        const inside = !!(active && modal.contains(active));
        const index = active ? items.indexOf(active) : -1;
        if (event.shiftKey) {
            if (!inside || index <= 0) {
                event.preventDefault();
                items[items.length - 1].focus();
            }
            return;
        }
        if (!inside || index === items.length - 1) {
            event.preventDefault();
            items[0].focus();
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    let confirmSeq = 0;

    function confirmDialog(options) {
        const opts = options || {};
        const title = opts.title ? String(opts.title) : 'Confirm';
        const message = opts.message ? String(opts.message) : '';
        const confirmLabel = opts.confirmLabel ? String(opts.confirmLabel) : 'Confirm';
        const cancelLabel = opts.cancelLabel ? String(opts.cancelLabel) : 'Cancel';
        const danger = !!opts.danger;
        const titleId = 'app-modal-confirm-title-' + (++confirmSeq);

        return new Promise((resolve) => {
            let settled = false;
            const modal = document.createElement('div');
            modal.className = 'app-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-labelledby', titleId);

            const backdrop = document.createElement('div');
            backdrop.className = 'app-modal-backdrop';
            backdrop.setAttribute('data-close', '');
            backdrop.setAttribute('aria-hidden', 'true');

            const panel = document.createElement('div');
            panel.className = 'app-modal-panel surface';

            const head = document.createElement('div');
            head.className = 'flex items-center justify-between gap-2 mb-3';

            const heading = document.createElement('h3');
            heading.id = titleId;
            heading.className = 'section-title text-sm';
            heading.textContent = title;

            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'app-modal-close';
            close.setAttribute('data-close', '');
            close.setAttribute('aria-label', 'Close');
            close.textContent = '×';

            const body = document.createElement('p');
            body.className = 'text-sm mb-4';
            body.textContent = message;

            const actions = document.createElement('div');
            actions.className = 'flex flex-wrap gap-2';

            const ok = document.createElement('button');
            ok.type = 'button';
            ok.className = (danger ? 'btn-danger' : 'btn-primary') + ' text-sm';
            ok.textContent = confirmLabel;

            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn text-sm';
            cancel.setAttribute('data-close', '');
            cancel.textContent = cancelLabel;

            function finish(value) {
                if (settled) return;
                settled = true;
                modal.classList.add('hidden');
                modal.remove();
                if (!document.querySelector('.app-modal:not(.hidden)')) {
                    document.body.classList.remove('app-modal-open');
                }
                resolve(value);
            }

            ok.addEventListener('click', () => finish(true));
            modal.addEventListener('click', (event) => {
                const target = event.target;
                if (target && typeof target.closest === 'function' && target.closest('[data-close]')) {
                    finish(false);
                }
            });

            head.appendChild(heading);
            head.appendChild(close);
            actions.appendChild(ok);
            actions.appendChild(cancel);
            panel.appendChild(head);
            panel.appendChild(body);
            panel.appendChild(actions);
            modal.appendChild(backdrop);
            modal.appendChild(panel);

            document.body.classList.add('app-modal-open');
            document.body.appendChild(modal);
        });
    }

    globalThis.AppModal = { confirm: confirmDialog };
})();
