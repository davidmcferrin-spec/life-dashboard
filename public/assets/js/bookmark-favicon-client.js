'use strict';

/**
 * Client-side bookmark favicon resolution (browser fetch, CDN fallback).
 * Server fetch is invoked separately from bookmarks-manage.js.
 */
window.BookmarkFaviconClient = (function () {
    const FAVICON_MAX_BYTES = 1048576;
    const MAX_HTML_BYTES    = 262144;

    function normalizePageUrl(pageUrl) {
        const trimmed = String(pageUrl || '').trim();
        if (trimmed === '') {
            return '';
        }

        return /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
    }

    function isPrivateHost(pageUrl) {
        try {
            const host = new URL(normalizePageUrl(pageUrl)).hostname.toLowerCase();
            if (host === 'localhost' || host.endsWith('.local')) {
                return true;
            }
            if (/^127\./.test(host) || /^10\./.test(host) || /^192\.168\./.test(host)) {
                return true;
            }
            if (/^172\.(1[6-9]|2\d|3[0-1])\./.test(host)) {
                return true;
            }

            return false;
        } catch (_) {
            return false;
        }
    }

    function decodeHtmlEntities(value) {
        return String(value)
            .replace(/&amp;/g, '&')
            .replace(/&quot;/g, '"')
            .replace(/&#39;/g, "'")
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>');
    }

    function resolveRelativeUrl(base, relative) {
        const rel = String(relative || '').trim();
        if (rel === '' || rel.startsWith('data:')) {
            return null;
        }

        try {
            return new URL(rel, normalizePageUrl(base)).href;
        } catch (_) {
            return null;
        }
    }

    function discoverIconUrlsFromHtml(html, pageUrl) {
        const out  = [];
        const tags = html.match(/<link\b[^>]*>/gi) || [];

        tags.forEach(tag => {
            const relMatch = tag.match(/\brel=["']([^"']+)["']/i);
            if (!relMatch) {
                return;
            }

            const rels = relMatch[1].toLowerCase().split(/\s+/);
            const ok   = rels.some(rel => rel === 'icon' || rel === 'shortcut' || rel === 'apple-touch-icon');
            if (!ok) {
                return;
            }

            const hrefMatch = tag.match(/\bhref=["']([^"']+)["']/i);
            if (!hrefMatch) {
                return;
            }

            const resolved = resolveRelativeUrl(pageUrl, decodeHtmlEntities(hrefMatch[1]));
            if (resolved) {
                out.push(resolved);
            }
        });

        return out;
    }

    function defaultIconCandidates(pageUrl) {
        const normalized = normalizePageUrl(pageUrl);
        if (normalized === '') {
            return [];
        }

        try {
            const origin = new URL(normalized).origin;

            return [
                `${origin}/favicon.ico`,
                `${origin}/favicon.png`,
                `${origin}/apple-touch-icon.png`,
            ];
        } catch (_) {
            return [];
        }
    }

    async function tryFetchIconBlob(url) {
        try {
            const res = await fetch(url, { credentials: 'omit', mode: 'cors' });
            if (!res.ok) {
                return null;
            }

            const blob = await res.blob();
            if (!blob || blob.size <= 0 || blob.size > FAVICON_MAX_BYTES) {
                return null;
            }

            const type = (blob.type || '').toLowerCase();
            if (!type.startsWith('image/') && !url.toLowerCase().endsWith('.ico')) {
                return null;
            }

            const filename = url.split('/').pop()?.split('?')[0] || 'favicon.ico';

            return { blob, filename };
        } catch (_) {
            return null;
        }
    }

    async function buildIconCandidates(pageUrl) {
        const candidates = defaultIconCandidates(pageUrl);
        const normalized = normalizePageUrl(pageUrl);
        if (normalized === '') {
            return [];
        }

        try {
            const res = await fetch(normalized, {
                credentials: 'omit',
                mode: 'cors',
                headers: { Accept: 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8' },
            });
            if (res.ok) {
                const html = (await res.text()).slice(0, MAX_HTML_BYTES);
                discoverIconUrlsFromHtml(html, normalized).forEach(url => candidates.push(url));
            }
        } catch (_) {
            /* CORS or network */
        }

        return [...new Set(candidates)];
    }

    async function fetchIconCandidates(bookmarkId, pageUrl) {
        const merged = [...defaultIconCandidates(pageUrl)];

        if (bookmarkId) {
            try {
                const res = await fetch(`/api/bookmarks/${bookmarkId}/favicon/candidates`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                if (res.ok) {
                    const data = await res.json().catch(() => ({}));
                    (data.candidates || []).forEach(url => merged.push(url));
                }
            } catch (_) {
                /* keep defaults */
            }
        }

        return [...new Set(merged.filter(Boolean))];
    }

    function openFaviconInNewTab(candidates, index = 0) {
        if (!candidates.length) {
            return null;
        }

        const url = candidates[Math.min(Math.max(index, 0), candidates.length - 1)];
        window.open(url, '_blank', 'noopener,noreferrer');

        return url;
    }

    /**
     * @param {HTMLElement|null} linksEl container for alternate candidate links
     * @param {HTMLElement|null} previewEl optional external img preview
     */
    async function offerManualIconCapture(bookmarkId, pageUrl, statusEl, options = {}) {
        const { autoOpen = false, linksEl = null, previewEl = null } = options;
        const setStatus = text => {
            if (statusEl) {
                statusEl.textContent = text;
            }
        };

        const candidates = await fetchIconCandidates(bookmarkId, pageUrl);

        if (linksEl) {
            linksEl.replaceChildren();

            if (candidates.length === 0) {
                linksEl.classList.add('hidden');
            } else {
                linksEl.classList.remove('hidden');
                linksEl.append('Also try: ');

                candidates.forEach((url, i) => {
                    if (i > 0) {
                        linksEl.append(' · ');
                    }

                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'link mono text-xs bm-candidate-link';
                    btn.textContent = url.split('/').pop()?.split('?')[0] || `link ${i + 1}`;
                    btn.addEventListener('click', () => {
                        window.open(url, '_blank', 'noopener,noreferrer');
                        setStatus(`Opened ${url} — save the image, then click Upload icon.`);
                        if (previewEl) {
                            previewEl.src = url;
                            previewEl.classList.remove('hidden');
                        }
                    });
                    linksEl.appendChild(btn);
                });
            }
        }

        if (previewEl && candidates.length > 0) {
            previewEl.src = candidates[0];
            previewEl.classList.remove('hidden');
        }

        if (autoOpen && candidates.length > 0) {
            const opened = openFaviconInNewTab(candidates, 0);
            setStatus(`Opened ${opened} — right-click → Save image, then click Upload icon.`);
        } else if (candidates.length > 0) {
            setStatus('Open an icon URL in a new tab, save the image, then click Upload icon.');
        }

        return candidates;
    }

    async function fetchIconViaBrowser(pageUrl) {
        const candidates = await buildIconCandidates(pageUrl);

        for (const url of candidates) {
            const result = await tryFetchIconBlob(url);
            if (result) {
                return result;
            }
        }

        return null;
    }

    async function fetchIconViaCdn(pageUrl) {
        if (isPrivateHost(pageUrl)) {
            return null;
        }

        let hostname;
        try {
            hostname = new URL(normalizePageUrl(pageUrl)).hostname;
        } catch (_) {
            return null;
        }

        const urls = [
            `https://icons.duckduckgo.com/ip3/${encodeURIComponent(hostname)}.ico`,
            `https://www.google.com/s2/favicons?domain=${encodeURIComponent(hostname)}&sz=64`,
        ];

        for (const url of urls) {
            const result = await tryFetchIconBlob(url);
            if (result) {
                return result;
            }
        }

        return null;
    }

    async function uploadFaviconBlob(bookmarkId, blob, filename) {
        const fd = new FormData();
        fd.append('favicon', blob, filename || 'favicon.ico');
        const res = await fetch(`/api/bookmarks/${bookmarkId}/favicon/upload`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': getCsrfToken(), Accept: 'application/json' },
            body: fd,
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));

        return { res, data };
    }

    async function fetchFaviconFromServer(bookmarkId) {
        const res = await fetch(`/api/bookmarks/${bookmarkId}/favicon/fetch`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken(),
            },
            body: '{}',
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));

        return { res, data };
    }

    async function resolveBookmarkIcon(bookmarkId, pageUrl, statusEl, options = {}) {
        const { autoOpenOnFail = false, linksEl = null, previewEl = null } = options;
        const setStatus = text => {
            if (statusEl) {
                statusEl.textContent = text;
            }
        };

        const privateHost = isPrivateHost(pageUrl);
        let serverMsg     = '';

        if (!privateHost) {
            setStatus('Fetching from server…');
            const server = await fetchFaviconFromServer(bookmarkId);
            serverMsg = server.data.error || '';

            if (server.res.ok && server.data.bookmark?.has_favicon) {
                setStatus('Icon saved.');
                return server.data.bookmark;
            }
        }

        setStatus(privateHost ? 'Fetching from your browser…' : 'Trying from your browser…');
        const client = await fetchIconViaBrowser(pageUrl);
        if (client) {
            setStatus('Uploading…');
            const up = await uploadFaviconBlob(bookmarkId, client.blob, client.filename);
            if (up.res.ok && up.data.bookmark?.has_favicon) {
                setStatus('Icon saved (browser fetch).');
                return up.data.bookmark;
            }
            if (up.data.error) {
                setStatus(up.data.error);
                await offerManualIconCapture(bookmarkId, pageUrl, statusEl, {
                    autoOpen: autoOpenOnFail,
                    linksEl,
                    previewEl,
                });

                return null;
            }
        }

        if (!privateHost) {
            setStatus('Trying icon lookup service…');
            const cdn = await fetchIconViaCdn(pageUrl);
            if (cdn) {
                setStatus('Uploading…');
                const up = await uploadFaviconBlob(bookmarkId, cdn.blob, cdn.filename);
                if (up.res.ok && up.data.bookmark?.has_favicon) {
                    setStatus('Icon saved (lookup service).');
                    return up.data.bookmark;
                }
            }
        }

        if (serverMsg && !privateHost) {
            setStatus(`${serverMsg} Opening icon location…`);
        } else {
            setStatus('Could not fetch automatically. Opening icon location…');
        }

        await offerManualIconCapture(bookmarkId, pageUrl, statusEl, {
            autoOpen: autoOpenOnFail,
            linksEl,
            previewEl,
        });

        return null;
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    async function backfillMissingIcons(bookmarks, onProgress) {
        const missing = bookmarks.filter(bm => !bm.has_favicon);
        if (missing.length === 0) {
            return 0;
        }

        let saved = 0;
        for (let i = 0; i < missing.length; i++) {
            const bm = missing[i];
            if (onProgress) {
                onProgress(i + 1, missing.length, bm);
            }

            const result = await resolveBookmarkIcon(bm.id, bm.url, null);
            if (result) {
                saved++;
            }

            await sleep(400);
        }

        return saved;
    }

    return {
        FAVICON_MAX_BYTES,
        normalizePageUrl,
        isPrivateHost,
        defaultIconCandidates,
        fetchIconCandidates,
        openFaviconInNewTab,
        offerManualIconCapture,
        fetchIconViaBrowser,
        fetchIconViaCdn,
        uploadFaviconBlob,
        resolveBookmarkIcon,
        backfillMissingIcons,
    };
})();
