import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Excalidraw, exportToBlob } from '@excalidraw/excalidraw';

const AUTOSAVE_MS = 1500;
const POLL_MS = 3000;
const HEARTBEAT_MS = 30000;

function domId(prefix, suffix) {
    return `${prefix}${suffix}`;
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function api(url, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.method && options.method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = csrf();
    }
    const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function parseScene(raw) {
    try {
        const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (!data || typeof data !== 'object') return null;
        return {
            elements: data.elements || [],
            appState: data.appState || {},
            files: data.files || {},
        };
    } catch {
        return null;
    }
}

function serializeScene(elements, appState, files) {
    const bg = document.documentElement.getAttribute('data-theme') === 'light' ? '#ffffff' : '#1e1e1e';
    const cleanState = { ...appState };
    delete cleanState.collaborators;
    delete cleanState.selectedElementIds;
    delete cleanState.selectedGroupIds;
    if (!cleanState.viewBackgroundColor) {
        cleanState.viewBackgroundColor = bg;
    }
    return JSON.stringify({
        type: 'excalidraw',
        version: 2,
        source: 'CloudPad',
        elements,
        appState: cleanState,
        files: files || {},
    });
}

function excalidrawTheme() {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function applyScene(apiRef, scene) {
    if (!apiRef || !scene) return;
    apiRef.updateScene({
        elements: scene.elements,
        appState: scene.appState,
    });
    if (scene.files && Object.keys(scene.files).length) {
        apiRef.addFiles(scene.files);
    }
}

async function loadShares(canvasId, idPrefix) {
    const { res, data } = await api(`/api/cloudpad/${canvasId}/shares`);
    if (!res.ok) return;

    const linksEl = document.getElementById(domId(idPrefix, 'share-links'));
    const usersEl = document.getElementById(domId(idPrefix, 'share-users'));
    if (linksEl) {
        linksEl.innerHTML = (data.links || []).map(l => `
            <li class="surface px-2 py-1 rounded flex flex-wrap gap-2 items-center justify-between">
                <a href="${l.url}" class="link break-all" target="_blank" rel="noopener">${l.url}</a>
                <button type="button" class="cloudpad-revoke-link text-danger" data-id="${l.id}">Revoke</button>
            </li>
        `).join('') || '<li class="text-faint">No links yet.</li>';
        linksEl.querySelectorAll('.cloudpad-revoke-link').forEach(btn => {
            btn.addEventListener('click', async () => {
                await api(`/api/cloudpad/${canvasId}/shares/link/${btn.dataset.id}/revoke`, { method: 'POST', body: '{}' });
                loadShares(canvasId, idPrefix);
            });
        });
    }
    if (usersEl) {
        usersEl.innerHTML = (data.users || []).map(u => `
            <li class="surface px-2 py-1 rounded flex justify-between gap-2">
                <span>${u.username} <span class="text-faint">(${u.permission})</span></span>
                <button type="button" class="cloudpad-remove-share text-danger" data-id="${u.id}">Remove</button>
            </li>
        `).join('') || '<li class="text-faint">No user shares.</li>';
        usersEl.querySelectorAll('.cloudpad-remove-share').forEach(btn => {
            btn.addEventListener('click', async () => {
                await api(`/api/cloudpad/${canvasId}/shares/user/${btn.dataset.id}/remove`, { method: 'POST', body: '{}' });
                loadShares(canvasId, idPrefix);
            });
        });
    }
}

function CloudPadEditor({ canvasId, idPrefix = 'cloudpad-', embedded = false }) {
    const excalidrawRef = useRef(null);
    const saveTimer = useRef(null);
    const dirty = useRef(false);
    const localVersion = useRef(1);
    const canEditRef = useRef(false);
    const isOwnerRef = useRef(false);
    const bootstrappedRef = useRef(false);
    const pendingInitialRef = useRef(null);
    const readyRef = useRef(false);
    const canvasNameRef = useRef('');

    const [ready, setReady] = useState(false);
    const [initialData, setInitialData] = useState(null);
    const [viewMode, setViewMode] = useState(true);
    const [canvasName, setCanvasName] = useState('');
    const [saveLabel, setSaveLabel] = useState('Loading…');
    const [theme, setTheme] = useState(excalidrawTheme);
    const saveNowRef = useRef(() => {});

    useEffect(() => {
        canvasNameRef.current = canvasName;
    }, [canvasName]);

    const refreshControlBanner = useCallback((ctrl, owner) => {
        const banner = document.getElementById(domId(idPrefix, 'control-banner'));
        if (!banner) return;
        banner.replaceChildren();
        banner.className = `${embedded ? 'cloudpad-embed-control-banner' : 'cloudpad-control-banner'} mono text-xs px-4 py-2`;

        if (!ctrl) {
            banner.classList.add('hidden');
            return;
        }

        banner.classList.remove('hidden');

        if (ctrl.viewer_has_control) {
            banner.classList.add('is-ok');
            banner.innerHTML = 'You have edit control. <button type="button" class="underline ml-2" data-act="release">Release control</button>';
        } else if (ctrl.pending_for_viewer) {
            banner.classList.add('is-warn');
            banner.textContent = `Waiting for ${ctrl.holder_username || 'editor'} to grant control…`;
        } else if (ctrl.pending_request && (ctrl.viewer_has_control || owner)) {
            banner.classList.add('is-warn');
            banner.innerHTML = `${ctrl.requester_username} requested control. ` +
                '<button type="button" class="underline" data-act="grant">Grant</button> ' +
                '<button type="button" class="underline ml-1" data-act="deny">Deny</button>';
        } else if (ctrl.holder_id) {
            banner.classList.add('is-warn');
            const acts = [];
            acts.push(`${ctrl.holder_username || 'Someone'} is editing.`);
            acts.push('<button type="button" class="underline ml-2" data-act="request">Request control</button>');
            if (owner) {
                acts.push('<button type="button" class="underline ml-1" data-act="take-force">Take control</button>');
            }
            banner.innerHTML = acts.join(' ');
        } else {
            banner.classList.add('is-warn');
            banner.innerHTML = 'No one is editing. <button type="button" class="underline ml-2" data-act="take">Take control</button>';
        }

        banner.querySelectorAll('button[data-act]').forEach(btn => {
            btn.addEventListener('click', () => {
                banner.dispatchEvent(new CustomEvent('cloudpad-control-act', { detail: btn.dataset.act }));
            });
        });
    }, [idPrefix, embedded]);

    const applyControlState = useCallback((ctrl) => {
        canEditRef.current = !!(ctrl?.viewer_can_edit);
        setViewMode(!canEditRef.current);
        refreshControlBanner(ctrl, isOwnerRef.current);
        setSaveLabel(canEditRef.current ? 'Saved' : 'View only');
    }, [refreshControlBanner]);

    const syncControl = useCallback(async () => {
        const { res, data } = await api(`/api/cloudpad/${canvasId}/control`);
        if (res.ok && data.control) {
            applyControlState(data.control);
        }
    }, [canvasId, applyControlState]);

    const loadCanvas = useCallback(async (sinceVersion = null) => {
        const qs = sinceVersion ? `?since_version=${sinceVersion}` : '';
        const { res, data } = await api(`/api/cloudpad/${canvasId}${qs}`);
        if (!res.ok) {
            setSaveLabel('Unavailable');
            return null;
        }
        const canvas = data.canvas;
        if (canvas.unchanged) {
            await syncControl();
            return canvas;
        }
        localVersion.current = canvas.content_version;
        isOwnerRef.current = !!canvas.is_owner;
        const ctrl = canvas.control || null;
        setCanvasName(canvas.name || '');
        const nameInput = document.getElementById(domId(idPrefix, 'canvas-name'));
        if (nameInput && nameInput !== document.activeElement) {
            nameInput.value = canvas.name || '';
            nameInput.readOnly = !canvas.is_owner;
        }
        applyControlState(ctrl);

        const sharingBtn = document.getElementById(domId(idPrefix, 'sharing-toggle'));
        if (sharingBtn) {
            sharingBtn.classList.toggle('hidden', !canvas.is_owner);
        }

        const sharingJump = document.getElementById(domId(idPrefix, 'sharing-jump'));
        if (sharingJump) {
            sharingJump.classList.toggle('hidden', !canvas.is_owner);
        }

        const fullscreen = document.getElementById(domId(idPrefix, 'fullscreen'));
        if (fullscreen) {
            fullscreen.href = `/cloudpad/canvas/${canvasId}`;
        }

        if (!readyRef.current) {
            const scene = parseScene(canvas.content);
            const initial = scene || { elements: [], appState: {}, files: {} };
            if (bootstrappedRef.current) {
                setInitialData(initial);
                readyRef.current = true;
                setReady(true);
            } else {
                pendingInitialRef.current = initial;
            }
        } else if (!dirty.current && !canEditRef.current) {
            const scene = parseScene(canvas.content);
            applyScene(excalidrawRef.current, scene);
        }

        return canvas;
    }, [canvasId, idPrefix, applyControlState, syncControl]);

    const saveNow = useCallback(async () => {
        if (!canEditRef.current || !excalidrawRef.current) return;
        const elements = excalidrawRef.current.getSceneElements();
        const appState = excalidrawRef.current.getAppState();
        const files = excalidrawRef.current.getFiles();
        const content = serializeScene(elements, appState, files);
        setSaveLabel('Saving…');
        const { res, data } = await api(`/api/cloudpad/${canvasId}/save`, {
            method: 'POST',
            body: JSON.stringify({ content, version: localVersion.current }),
        });
        if (res.status === 409) {
            setSaveLabel('Conflict — reload');
            dirty.current = false;
            return;
        }
        if (!res.ok) {
            setSaveLabel(data.error || 'Save failed');
            return;
        }
        localVersion.current = data.canvas.content_version;
        dirty.current = false;
        setSaveLabel('Saved');
    }, [canvasId]);

    useEffect(() => {
        saveNowRef.current = saveNow;
    }, [saveNow]);

    const scheduleSave = useCallback(() => {
        if (!canEditRef.current) return;
        dirty.current = true;
        setSaveLabel('Unsaved…');
        clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(saveNow, AUTOSAVE_MS);
    }, [saveNow]);

    const controlCall = useCallback(async (action, body = {}) => {
        const { res, data } = await api(`/api/cloudpad/${canvasId}/control/${action}`, {
            method: 'POST',
            body: JSON.stringify(body),
        });
        if (!res.ok) {
            alert(data.error || 'Control action failed.');
            return null;
        }
        const ctrl = data.control;
        canEditRef.current = !!ctrl.viewer_can_edit;
        setViewMode(!ctrl.viewer_can_edit);
        refreshControlBanner(ctrl, isOwnerRef.current);
        if (ctrl.viewer_can_edit) {
            setSaveLabel('Saved');
        }
        return ctrl;
    }, [canvasId, refreshControlBanner]);

    useEffect(() => {
        const statusEl = document.getElementById(domId(idPrefix, 'save-status'));
        if (statusEl) {
            statusEl.textContent = saveLabel;
        }
    }, [saveLabel, idPrefix]);

    useEffect(() => {
        readyRef.current = false;
        setReady(false);
        setInitialData(null);
        bootstrappedRef.current = false;
        pendingInitialRef.current = null;
        dirty.current = false;
        localVersion.current = 1;

        (async () => {
            const canvas = await loadCanvas();
            if (canvas?.is_owner) {
                await controlCall('take', { force: true });
            } else if (canvas?.can_write) {
                await controlCall('take');
            }
            bootstrappedRef.current = true;
            if (pendingInitialRef.current) {
                setInitialData(pendingInitialRef.current);
                readyRef.current = true;
                setReady(true);
            }
        })();

        const banner = document.getElementById(domId(idPrefix, 'control-banner'));
        const onAct = e => {
            const act = e.detail;
            if (act === 'take' || act === 'take-force') controlCall('take', { force: act === 'take-force' });
            if (act === 'request') controlCall('request');
            if (act === 'grant') controlCall('grant');
            if (act === 'deny') controlCall('deny');
            if (act === 'release') controlCall('release');
        };
        banner?.addEventListener('cloudpad-control-act', onAct);

        const poll = setInterval(async () => {
            await loadCanvas(localVersion.current);
            if (canEditRef.current) {
                await api(`/api/cloudpad/${canvasId}/control/heartbeat`, { method: 'POST', body: '{}' });
            }
        }, POLL_MS);

        const heartbeat = setInterval(() => {
            if (canEditRef.current) {
                api(`/api/cloudpad/${canvasId}/control/heartbeat`, { method: 'POST', body: '{}' });
            }
        }, HEARTBEAT_MS);

        if (!embedded && window.matchMedia('(max-width: 768px)').matches) {
            document.getElementById(domId(idPrefix, 'mobile-warn'))?.classList.remove('hidden');
        }

        return () => {
            clearInterval(poll);
            clearInterval(heartbeat);
            banner?.removeEventListener('cloudpad-control-act', onAct);
            clearTimeout(saveTimer.current);
        };
    }, [canvasId, idPrefix, embedded, loadCanvas, controlCall]);

    useEffect(() => {
        const nameInput = document.getElementById(domId(idPrefix, 'canvas-name'));
        if (!nameInput) return;
        const onBlur = async () => {
            if (!isOwnerRef.current) return;
            const name = nameInput.value.trim();
            if (!name || name === canvasName) return;
            const { res, data } = await api(`/api/cloudpad/${canvasId}/update`, {
                method: 'POST',
                body: JSON.stringify({ name }),
            });
            if (res.ok) {
                setCanvasName(data.canvas.name);
                document.dispatchEvent(new CustomEvent('cloudpad:renamed', {
                    detail: { id: canvasId, name: data.canvas.name },
                }));
            }
        };
        nameInput.addEventListener('blur', onBlur);
        return () => nameInput.removeEventListener('blur', onBlur);
    }, [canvasId, idPrefix, canvasName]);

    useEffect(() => {
        const onTheme = e => {
            setTheme(e.detail?.effective === 'light' ? 'light' : 'dark');
        };
        document.addEventListener('dashboard:theme-applied', onTheme);
        return () => document.removeEventListener('dashboard:theme-applied', onTheme);
    }, []);

    useEffect(() => {
        const onFit = () => {
            excalidrawRef.current?.scrollToContent(undefined, { fitToContent: true });
        };

        const onExportPng = async () => {
            if (!excalidrawRef.current) return;
            const blob = await exportToBlob({
                elements: excalidrawRef.current.getSceneElements(),
                appState: excalidrawRef.current.getAppState(),
                files: excalidrawRef.current.getFiles(),
                mimeType: 'image/png',
            });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `${canvasNameRef.current || 'cloudpad'}.png`;
            a.click();
            URL.revokeObjectURL(a.href);
        };

        const onSaveFiles = async () => {
            if (!excalidrawRef.current) return;
            const blob = await exportToBlob({
                elements: excalidrawRef.current.getSceneElements(),
                appState: excalidrawRef.current.getAppState(),
                files: excalidrawRef.current.getFiles(),
                mimeType: 'image/png',
            });
            const fd = new FormData();
            fd.append('file', blob, `${canvasNameRef.current || 'cloudpad'}.png`);
            const res = await fetch('/api/files/upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf() },
                body: fd,
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                alert(data.error || 'Upload failed.');
                return;
            }
            setSaveLabel('Saved to Files');
        };

        const onSharing = () => {
            const panel = document.getElementById(domId(idPrefix, 'sharing'));
            panel?.classList.toggle('hidden');
            if (embedded) {
                document.getElementById('cloudpad-tab-panel')?.classList.toggle(
                    'cloudpad-embed-sharing-open',
                    panel && !panel.classList.contains('hidden'),
                );
            } else {
                document.querySelector('.cloudpad-page')?.classList.toggle(
                    'cloudpad-sharing-open',
                    panel && !panel.classList.contains('hidden'),
                );
            }
            loadShares(canvasId, idPrefix);
        };

        const onSharingJump = () => {
            const panel = document.getElementById(domId(idPrefix, 'sharing'));
            if (panel?.classList.contains('hidden')) {
                onSharing();
            }
            panel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        const onSaveNow = () => {
            clearTimeout(saveTimer.current);
            saveNowRef.current();
        };

        const onKeydown = e => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                onSaveNow();
            }
        };

        const fitBtn = document.getElementById(domId(idPrefix, 'fit-content'));
        const pngBtn = document.getElementById(domId(idPrefix, 'export-png'));
        const filesBtn = document.getElementById(domId(idPrefix, 'save-files'));
        const saveBtn = document.getElementById(domId(idPrefix, 'save-now'));
        const sharingBtn = document.getElementById(domId(idPrefix, 'sharing-toggle'));
        const sharingJump = document.getElementById(domId(idPrefix, 'sharing-jump'));

        fitBtn?.addEventListener('click', onFit);
        pngBtn?.addEventListener('click', onExportPng);
        filesBtn?.addEventListener('click', onSaveFiles);
        saveBtn?.addEventListener('click', onSaveNow);
        sharingBtn?.addEventListener('click', onSharing);
        sharingJump?.addEventListener('click', onSharingJump);
        document.addEventListener('keydown', onKeydown);

        return () => {
            fitBtn?.removeEventListener('click', onFit);
            pngBtn?.removeEventListener('click', onExportPng);
            filesBtn?.removeEventListener('click', onSaveFiles);
            saveBtn?.removeEventListener('click', onSaveNow);
            sharingBtn?.removeEventListener('click', onSharing);
            sharingJump?.removeEventListener('click', onSharingJump);
            document.removeEventListener('keydown', onKeydown);
        };
    }, [canvasId, idPrefix, embedded]);

    useEffect(() => {
        const linkForm = document.getElementById(domId(idPrefix, 'share-link-form'));
        const userForm = document.getElementById(domId(idPrefix, 'share-user-form'));
        if (!linkForm && !userForm) return;

        const onLinkSubmit = async e => {
            e.preventDefault();
            const label = document.getElementById(domId(idPrefix, 'link-label'))?.value.trim() || '';
            const expiresIn = document.getElementById(domId(idPrefix, 'link-expiry'))?.value || '';
            const { res, data } = await api(`/api/cloudpad/${canvasId}/shares/link`, {
                method: 'POST',
                body: JSON.stringify({ label, expires_in: expiresIn || null }),
            });
            if (!res.ok) {
                alert(data.error || 'Could not create link.');
                return;
            }
            loadShares(canvasId, idPrefix);
        };

        const onUserSubmit = async e => {
            e.preventDefault();
            const identifier = document.getElementById(domId(idPrefix, 'share-identifier'))?.value.trim();
            const permission = document.getElementById(domId(idPrefix, 'user-permission'))?.value || 'read';
            const { res, data } = await api(`/api/cloudpad/${canvasId}/shares/user`, {
                method: 'POST',
                body: JSON.stringify({ identifier, permission }),
            });
            if (!res.ok) {
                alert(data.error || 'Could not share.');
                return;
            }
            e.target.reset();
            loadShares(canvasId, idPrefix);
        };

        linkForm?.addEventListener('submit', onLinkSubmit);
        userForm?.addEventListener('submit', onUserSubmit);
        return () => {
            linkForm?.removeEventListener('submit', onLinkSubmit);
            userForm?.removeEventListener('submit', onUserSubmit);
        };
    }, [canvasId, idPrefix]);

    const onChange = useCallback(() => {
        scheduleSave();
    }, [scheduleSave]);

    if (!ready || !initialData) {
        return React.createElement('div', { className: 'empty-state mono p-4' }, 'Loading canvas…');
    }

    return React.createElement(
        'div',
        { style: { height: '100%', width: '100%' } },
        React.createElement(Excalidraw, {
            excalidrawAPI: apiRef => { excalidrawRef.current = apiRef; },
            initialData,
            viewModeEnabled: viewMode,
            theme,
            onChange,
        }),
    );
}

let activeRoot = null;

function mountEditor(canvasId, rootEl, { idPrefix = 'cloudpad-', embedded = false } = {}) {
    unmountEditor();
    activeRoot = createRoot(rootEl);
    activeRoot.render(React.createElement(CloudPadEditor, { canvasId, idPrefix, embedded }));
}

function unmountEditor() {
    if (activeRoot) {
        activeRoot.unmount();
        activeRoot = null;
    }
}

window.CloudPad = { mount: mountEditor, unmount: unmountEditor };

const fullRootEl = document.getElementById('cloudpad-root');
const fullCanvasId = window.CLOUDPAD_CANVAS_ID;
if (fullRootEl && fullCanvasId) {
    mountEditor(fullCanvasId, fullRootEl, { embedded: false });
}
