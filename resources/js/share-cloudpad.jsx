import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Excalidraw, exportToBlob } from '@excalidraw/excalidraw';

const TOKEN = window.SHARE_CLOUDPAD_TOKEN;
const POLL_MS = 5000;

async function api(url) {
    const res = await fetch(url, { credentials: 'same-origin' });
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

function excalidrawTheme() {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function applyScene(api, scene) {
    if (!api || !scene) return;
    api.updateScene({
        elements: scene.elements,
        appState: scene.appState,
    });
    if (scene.files && Object.keys(scene.files).length) {
        api.addFiles(scene.files);
    }
}

function ShareViewer() {
    const excalidrawRef = useRef(null);
    const localVersion = useRef(0);
    const hasLoadedRef = useRef(false);
    const [initialData, setInitialData] = useState(null);
    const [name, setName] = useState('');
    const [theme, setTheme] = useState(excalidrawTheme);

    const load = useCallback(async (sinceVersion = null) => {
        const qs = sinceVersion ? `?since_version=${sinceVersion}` : '';
        const { res, data } = await api(`/api/share/cloudpad/${TOKEN}${qs}`);
        if (!res.ok) {
            document.getElementById('share-cloudpad-status').textContent = 'Unavailable';
            return;
        }
        const canvas = data.canvas;
        if (canvas.unchanged) return;
        localVersion.current = canvas.content_version;
        setName(canvas.name || 'Shared canvas');
        document.getElementById('share-cloudpad-name').textContent = canvas.name || 'Shared canvas';
        document.getElementById('share-cloudpad-status').textContent = 'Read only';
        const scene = parseScene(canvas.content) || { elements: [], appState: {}, files: {} };
        if (!hasLoadedRef.current) {
            hasLoadedRef.current = true;
            setInitialData(scene);
        } else {
            applyScene(excalidrawRef.current, scene);
        }
    }, []);

    useEffect(() => {
        load();
        const poll = setInterval(() => load(localVersion.current), POLL_MS);
        return () => clearInterval(poll);
    }, [load]);

    useEffect(() => {
        const onTheme = e => {
            setTheme(e.detail?.effective === 'light' ? 'light' : 'dark');
        };
        document.addEventListener('dashboard:theme-applied', onTheme);
        return () => document.removeEventListener('dashboard:theme-applied', onTheme);
    }, []);

    useEffect(() => {
        document.getElementById('share-cloudpad-fit')?.addEventListener('click', () => {
            excalidrawRef.current?.scrollToContent(undefined, { fitToContent: true });
        });
        document.getElementById('share-cloudpad-png')?.addEventListener('click', async () => {
            if (!excalidrawRef.current) return;
            const blob = await exportToBlob({
                elements: excalidrawRef.current.getSceneElements(),
                appState: excalidrawRef.current.getAppState(),
                files: excalidrawRef.current.getFiles(),
                mimeType: 'image/png',
            });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `${name || 'cloudpad'}.png`;
            a.click();
            URL.revokeObjectURL(a.href);
        });
    }, [name]);

    if (!initialData) {
        return React.createElement('div', { className: 'empty-state mono p-4' }, 'Loading…');
    }

    return React.createElement(
        'div',
        { style: { height: '100%', width: '100%' } },
        React.createElement(Excalidraw, {
            excalidrawAPI: apiRef => { excalidrawRef.current = apiRef; },
            initialData,
            viewModeEnabled: true,
            theme,
        }),
    );
}

const rootEl = document.getElementById('share-cloudpad-root');
if (rootEl && TOKEN) {
    createRoot(rootEl).render(React.createElement(ShareViewer));
}
