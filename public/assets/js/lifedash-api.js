/**
 * lifedash-api.js — /api/v1 client + shared formatting helpers.
 *
 * Exposes window.LD = { api, esc, fmt, status, debounce }.
 * All mutating calls send the CSRF header (csrf.js). Errors are thrown as
 * LD.ApiError with .status and .fields (422 field map) so callers can show them.
 */
'use strict';

(function () {
    class ApiError extends Error {
        constructor(message, status, fields) {
            super(message);
            this.name = 'ApiError';
            this.status = status;
            this.fields = fields || {};
        }
    }

    async function request(method, path, body) {
        const opts = {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = window.getCsrfToken ? window.getCsrfToken() : '';
            opts.body = JSON.stringify(body ?? {});
        }

        let res;
        try {
            res = await fetch('/api/v1' + path, opts);
        } catch (e) {
            throw new ApiError('Network error — check your connection.', 0);
        }

        let data = null;
        try {
            data = await res.json();
        } catch (e) {
            data = null;
        }

        if (res.status === 401) {
            // Session expired: go back through login and return here.
            window.location.href = '/login';
            throw new ApiError('Signed out.', 401);
        }
        if (!res.ok) {
            throw new ApiError((data && data.error) || `Request failed (${res.status}).`, res.status, data && data.fields);
        }
        return data ?? {};
    }

    const api = {
        get: (path) => request('GET', path),
        post: (path, body) => request('POST', path, body),
    };

    function esc(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    const MOOD_FACES = ['😞', '😕', '😐', '🙂', '😄'];

    const fmt = {
        number(v, decimals) {
            if (v === null || v === undefined || Number.isNaN(Number(v))) return '—';
            const d = Number.isInteger(decimals) ? decimals : 1;
            return new Intl.NumberFormat(undefined, { maximumFractionDigits: d, minimumFractionDigits: 0 }).format(Number(v));
        },

        money(v) {
            if (v === null || v === undefined || Number.isNaN(Number(v))) return '—';
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(Number(v));
        },

        duration(seconds) {
            if (seconds === null || seconds === undefined) return '—';
            const s = Math.max(0, Math.round(Number(seconds)));
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            if (h === 0 && m === 0) return `${s}s`;
            return h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
        },

        /** Display a value in the metric's own terms. */
        value(metric, v, decimals) {
            if (v === null || v === undefined) return '—';
            const cfg = (metric && metric.config) || {};
            switch (metric && metric.kind) {
                case 'habit':
                    return Number(v) > 0 ? 'Done' : 'Skipped';
                case 'duration':
                    return fmt.duration(v);
                case 'scale': {
                    const label = cfg.labels && cfg.labels[String(Math.round(v))];
                    const n = fmt.number(v, Number.isInteger(decimals) ? decimals : 1);
                    return label && Number.isInteger(Number(v)) ? `${n} · ${label}` : n;
                }
                default: {
                    const d = Number.isInteger(decimals) ? decimals : (Number.isInteger(cfg.decimals) ? cfg.decimals : 1);
                    const n = fmt.number(v, d);
                    return metric && metric.unit ? `${n} ${metric.unit}` : n;
                }
            }
        },

        /** Emoji for a 1–5 style scale position; null for other ranges. */
        face(metric, v) {
            const cfg = (metric && metric.config) || {};
            const min = cfg.min ?? 1;
            const max = cfg.max ?? 5;
            if (max - min !== 4) return null;
            return MOOD_FACES[Math.round(v) - min] ?? null;
        },

        /** "Mon 21" style short date from Y-m-d (no timezone shift). */
        shortDate(ymd) {
            const [y, m, d] = String(ymd).split('-').map(Number);
            const dt = new Date(Date.UTC(y, m - 1, d, 12));
            return dt.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', timeZone: 'UTC' });
        },

        monthDay(ymd) {
            const [y, m, d] = String(ymd).split('-').map(Number);
            const dt = new Date(Date.UTC(y, m - 1, d, 12));
            return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' });
        },

        /** Local time of a UTC "Y-m-d H:i:s" string. */
        time(utc) {
            const dt = new Date(String(utc).replace(' ', 'T') + 'Z');
            return Number.isNaN(dt.getTime()) ? '' : dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
        },
    };

    let statusTimer = null;
    function status(text, kind) {
        const el = document.getElementById('ld-status');
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('text-danger', kind === 'error');
        clearTimeout(statusTimer);
        if (text && kind !== 'sticky' && kind !== 'error') {
            statusTimer = setTimeout(() => { el.textContent = ''; }, 2500);
        }
    }

    function debounce(fn, ms) {
        let t = null;
        const wrapped = (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), ms);
        };
        wrapped.flush = (...args) => {
            clearTimeout(t);
            fn(...args);
        };
        return wrapped;
    }

    window.LD = Object.assign(window.LD || {}, { api, ApiError, esc, fmt, status, debounce });
})();
