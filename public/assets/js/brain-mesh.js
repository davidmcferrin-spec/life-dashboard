/**
 * CSS 3D vault mesh. Clouds, camera, and firing all read the live graph.
 * The frame loop runs only while the camera is moving, a key is held, or a
 * pulse is fading, and it stops when the tab is hidden.
 */
'use strict';

(function () {
    const stage = document.getElementById('brain-mesh');
    if (!stage || !window.LD || !window.BrainCamera || !window.BrainSphere || !window.BrainFiring) return;

    const { api, esc } = window.LD;
    const sphere = window.BrainSphere;
    const firing = window.BrainFiring;
    const cameraApi = window.BrainCamera;

    const world = document.getElementById('brain-world-inner');
    const countsEl = document.getElementById('brain-readout-counts');
    const tabsEl = document.getElementById('brain-cloud-tabs');
    const modeNameEl = document.getElementById('brain-mode-name');
    const modeHintEl = document.getElementById('brain-mode-hint');
    const freeBtn = document.getElementById('brain-free');
    const searchEl = document.getElementById('brain-search');
    const typeEl = document.getElementById('brain-node-type');
    const signalEl = document.getElementById('brain-node-signal');
    const markEl = document.getElementById('brain-node-mark');
    const titleEl = document.getElementById('brain-node-title');
    const readsEl = document.getElementById('brain-node-reads');
    const writesEl = document.getElementById('brain-node-writes');
    const activityEl = document.getElementById('brain-node-activity');
    const meterEl = document.getElementById('brain-node-meter');
    const updatedEl = document.getElementById('brain-node-updated');
    const linksEl = document.getElementById('brain-node-links');
    const firingEl = document.getElementById('brain-node-firing');
    const openEl = document.getElementById('brain-node-open');
    const pauseBtn = document.getElementById('brain-pause');
    const modeBtn = document.getElementById('brain-mode');
    const helpEl = document.getElementById('brain-help');
    const helpBtn = document.getElementById('brain-help-open');

    const POLL_MS = 60000;
    const PRESENCE_MS = 4000;
    const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    let graph = { nodes: [], edges: [], clusters: [] };
    let graphSig = '';
    let display = cameraApi.meshReset();
    let target = cameraApi.meshClone(display);
    let homeDist = display.dist;
    let mode3d = true;
    let fly = false;
    let paused = false;
    let attachedKey = null;
    let pivotLock = null;
    let selectedId = null;
    let focusId = null;
    let filter = 'all';
    let query = '';
    let dragging = false;
    let helpOpen = false;
    let reduceMotion = motionQuery.matches;
    let booted = false;
    let framed = false;
    let userMoved = false;
    let presenceAfter = 0;
    let rateEvents = [];
    let fires = [];
    let firesMarked = false;
    const seenFire = new Set();
    let placements = new Map();
    let cloudCenters = new Map();
    let nodeEls = new Map();
    let cloudEls = new Map();
    let resizeTimer = null;
    let pollTimer = null;
    let presenceTimer = null;
    let saveTimer = 0;
    let raf = 0;
    let loopOn = false;
    let lastFrame = 0;
    let lastPerspective = 0;
    let suppressClick = false;
    const held = new Set();
    const pointers = new Map();
    let gesture = null;

    function onMotion() {
        reduceMotion = motionQuery.matches;
    }
    if (motionQuery.addEventListener) motionQuery.addEventListener('change', onMotion);
    else if (motionQuery.addListener) motionQuery.addListener(onMotion);

    function icon(id) {
        const tpl = document.getElementById(id);
        return tpl ? tpl.innerHTML : '';
    }

    function nodeById(id) {
        return (graph.nodes || []).find((node) => node.id === id) || null;
    }

    function safeStorage() {
        try {
            return window.localStorage;
        } catch (err) {
            return null;
        }
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(savePrefs, 280);
    }

    function savePrefs() {
        cameraApi.meshSavePrefs(safeStorage(), cameraApi.meshExportPrefs(target, {
            homeDist: homeDist || target.dist,
            mode3d: mode3d,
            fly: fly,
        }));
    }

    function viewport() {
        const rect = stage.getBoundingClientRect();
        return {
            width: rect.width || stage.clientWidth || 1,
            height: rect.height || stage.clientHeight || 1,
            left: rect.left,
            top: rect.top,
        };
    }

    function perspective() {
        return cameraApi.meshPerspective(viewport().height, cameraApi.MESH_FOV_DEG);
    }

    function pointerLocal(clientX, clientY) {
        const box = viewport();
        return {
            x: clientX - box.left - box.width / 2,
            y: clientY - box.top - box.height / 2,
        };
    }

    function present(cam) {
        const copy = cameraApi.meshClone(cam);
        if (mode3d) return copy;
        copy.yaw = 0;
        copy.pitch = 0;
        copy.pz = 0;
        return copy;
    }

    function commitControl(base, next) {
        if (mode3d) return cameraApi.meshClone(next);
        return {
            px: next.px,
            py: next.py,
            pz: base.pz,
            ax: next.ax,
            ay: next.ay,
            yaw: base.yaw,
            pitch: base.pitch,
            dist: next.dist,
        };
    }

    function releaseLock() {
        if (!pivotLock && attachedKey == null) return;
        pivotLock = null;
        attachedKey = null;
        renderChrome();
    }

    function parseUtc(value) {
        return sphere.updatedMs(value);
    }

    function shortName(label) {
        return String(label || 'Cloud').replace(/ Intelligence$/, '').replace(/ Core$/, '');
    }

    function updatedLabel(value, now) {
        const t = parseUtc(value);
        if (!Number.isFinite(t)) return '\u2014';
        const sec = Math.max(0, Math.round((now - t) / 1000));
        if (sec < 60) return 'Just now';
        const min = Math.round(sec / 60);
        if (min < 60) return min === 1 ? '1 minute ago' : min + ' minutes ago';
        const hr = Math.round(min / 60);
        if (hr < 36) return hr === 1 ? '1 hour ago' : hr + ' hours ago';
        const day = Math.round(hr / 24);
        return day === 1 ? '1 day ago' : day + ' days ago';
    }

    function filteredNodes(nodes) {
        const now = Date.now();
        const q = query.trim().toLowerCase();
        return (nodes || []).filter((node) => {
            if (!sphere.passesFilter(node, filter, now)) return false;
            if (!q) return true;
            return String(node.title || '').toLowerCase().includes(q);
        });
    }

    function firingIds() {
        const now = Date.now();
        const ids = [];
        fires.forEach((fire) => {
            if (firing.firingStrength(now - fire.born) > 0) ids.push(fire.pageId);
        });
        return ids;
    }

    function dataSig(data) {
        const nodes = (data && data.nodes) || [];
        const edges = (data && data.edges) || [];
        const clusters = (data && data.clusters) || [];
        let sig = nodes.length + '|' + edges.length + '|' + clusters.length + '|';
        for (let i = 0; i < nodes.length; i++) {
            const node = nodes[i];
            sig += [node.id, node.folder_id, node.cluster, node.kind, node.title, node.folder, node.updated_at].join(',') + ';';
        }
        for (let i = 0; i < edges.length; i++) sig += edges[i].from + '>' + edges[i].to + ';';
        for (let i = 0; i < clusters.length; i++) sig += clusters[i].key + '=' + clusters[i].label + ';';
        return sig;
    }

    function syncModeButton() {
        if (!modeBtn) return;
        modeBtn.textContent = mode3d ? '3D' : '2D';
        modeBtn.classList.toggle('active', mode3d);
        modeBtn.setAttribute('aria-pressed', mode3d ? 'true' : 'false');
    }

    function applyCamera() {
        const cam = present(display);
        const p = perspective();
        lastPerspective = p;
        stage.style.setProperty('--scene-perspective', p.toFixed(1) + 'px');
        stage.style.setProperty('--cam-ax', cam.ax.toFixed(2) + 'px');
        stage.style.setProperty('--cam-ay', cam.ay.toFixed(2) + 'px');
        stage.style.setProperty('--cam-px', cam.px.toFixed(2));
        stage.style.setProperty('--cam-py', cam.py.toFixed(2));
        stage.style.setProperty('--cam-pz', cam.pz.toFixed(2));
        stage.style.setProperty('--cam-yaw', cam.yaw.toFixed(3) + 'deg');
        stage.style.setProperty('--cam-pitch', cam.pitch.toFixed(3) + 'deg');
        stage.style.setProperty('--cam-dist', cam.dist.toFixed(2));
        stage.classList.toggle('mode-3d', mode3d);
        stage.classList.toggle('mode-2d', !mode3d);
        stage.classList.toggle('paused', paused);
        stage.classList.toggle('dragging', dragging);
        stage.classList.toggle('camera-fly', fly && mode3d);
        stage.classList.toggle('camera-attached', attachedKey != null);
        stage.classList.toggle('is-focused', focusId != null);
    }

    function updateLod() {
        const cam = present(display);
        const eye = cameraApi.meshCameraPosition(cam);
        nodeEls.forEach((el, id) => {
            const place = placements.get(id);
            if (!place) return;
            let bucket = 'near';
            if (id !== selectedId) {
                const worldPoint = place.world;
                const distance = Math.hypot(worldPoint.x - eye.x, worldPoint.y - eye.y, worldPoint.z - eye.z);
                bucket = cameraApi.meshLod(distance, homeDist, 'node');
            }
            if (el.dataset.lod === bucket) return;
            el.dataset.lod = bucket;
            el.classList.remove('lod-near', 'lod-mid', 'lod-far');
            el.classList.add('lod-' + bucket);
        });
        cloudEls.forEach((el, key) => {
            const cloud = cloudCenters.get(key);
            if (!cloud) return;
            const distance = Math.hypot(cloud.x - eye.x, cloud.y - eye.y, cloud.z - eye.z);
            const bucket = cameraApi.meshLod(distance, homeDist, 'cloud');
            if (el.dataset.lod === bucket) return;
            el.dataset.lod = bucket;
            el.classList.remove('lod-near', 'lod-mid', 'lod-far');
            el.classList.add('lod-' + bucket);
        });
        cullLabels();
    }

    function cullLabels() {
        const cam = present(display);
        const p = perspective();
        const items = [];
        nodeEls.forEach((el, id) => {
            const place = placements.get(id);
            if (!place) return;
            const screen = cameraApi.meshProject(cam, place.world, p);
            const dx = place.world.x - cam.px;
            const dy = place.world.y - cam.py;
            const dz = place.world.z - cam.pz;
            items.push({
                el: el,
                id: id,
                x: screen.x,
                y: screen.y,
                behind: !!screen.behind,
                priority: id === selectedId ? 0 : 1,
                dist: dx * dx + dy * dy + dz * dz,
            });
        });
        items.sort((a, b) => a.priority - b.priority || a.dist - b.dist || a.id - b.id);
        const overview = cam.dist > homeDist * 0.72;
        const kept = [];
        items.forEach((item) => {
            const rect = { x: item.x + 8, y: item.y - 18, w: 148, h: 36 };
            let hide = item.behind || (overview && item.priority !== 0);
            if (!hide && item.priority !== 0) {
                for (let i = 0; i < kept.length; i++) {
                    const other = kept[i];
                    if (rect.x < other.x + other.w && rect.x + rect.w > other.x && rect.y < other.y + other.h && rect.y + rect.h > other.y) {
                        hide = true;
                        break;
                    }
                }
            }
            item.el.classList.toggle('label-off', hide);
            if (!hide) kept.push(rect);
        });
        const names = [];
        cloudEls.forEach((el, key) => {
            const cloud = cloudCenters.get(key);
            if (!cloud) return;
            const screen = cameraApi.meshProject(cam, cloud, p);
            names.push({ el: el, x: screen.x, y: screen.y, behind: !!screen.behind, key: key });
        });
        names.sort((a, b) => a.key < b.key ? -1 : a.key > b.key ? 1 : 0);
        const keptNames = [];
        names.forEach((item) => {
            const rect = { x: item.x - 54, y: item.y, w: 108, h: 32 };
            let hide = item.behind;
            if (!hide) {
                for (let i = 0; i < keptNames.length; i++) {
                    const other = keptNames[i];
                    if (rect.x < other.x + other.w && rect.x + rect.w > other.x && rect.y < other.y + other.h && rect.y + rect.h > other.y) {
                        hide = true;
                        break;
                    }
                }
            }
            item.el.classList.toggle('name-off', hide);
            if (!hide) keptNames.push(rect);
        });
    }

    function renderChrome() {
        const nodes = graph.nodes || [];
        const edges = graph.edges || [];
        const clouds = sphere.cloudsFrom(nodes, graph.clusters || []);
        if (countsEl) {
            countsEl.textContent = nodes.length + ' NODES · ' + edges.length + ' LINKS · ' + clouds.length + ' CLOUDS';
        }
        const attached = clouds.find((cloud) => cloud.key === attachedKey) || null;
        if (modeNameEl) {
            if (attached) modeNameEl.textContent = 'ATTACHED · ' + attached.label.toUpperCase();
            else modeNameEl.textContent = fly ? 'FREE FLY' : 'ORBIT';
        }
        if (modeHintEl) {
            if (!mode3d) modeHintEl.textContent = 'Drag to pan · scroll to zoom';
            else if (fly) modeHintEl.textContent = 'Drag to look · WASD to fly';
            else if (attached) modeHintEl.textContent = 'Drag to orbit · two fingers pan';
            else modeHintEl.textContent = 'Drag to orbit · scroll to zoom';
        }
        if (freeBtn) {
            freeBtn.classList.toggle('active', attached == null);
            freeBtn.setAttribute('aria-pressed', attached == null ? 'true' : 'false');
        }
        if (tabsEl) {
            const sig = clouds.map((cloud) => cloud.key + (cloud.key === attachedKey ? '*' : '') + cloud.label).join('|');
            if (tabsEl.dataset.sig !== sig) {
                tabsEl.dataset.sig = sig;
                tabsEl.innerHTML = clouds.map((cloud) => {
                    const on = cloud.key === attachedKey;
                    return '<button type="button" data-cloud="' + esc(cloud.key) + '" class="' + (on ? 'active' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '">' + icon('brain-icon-brain') + '<span>' + esc(shortName(cloud.label)) + '</span></button>';
                }).join('');
            } else {
                tabsEl.querySelectorAll('[data-cloud]').forEach((button) => {
                    const on = button.getAttribute('data-cloud') === attachedKey;
                    button.classList.toggle('active', on);
                    button.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
            }
        }
        stage.querySelectorAll('.neural-cloud').forEach((el) => {
            el.classList.toggle('attached', el.dataset.cloud === attachedKey);
        });
    }

    function renderWorld() {
        if (!world) return;
        const nodes = graph.nodes || [];
        const edges = graph.edges || [];
        const full = sphere.cloudsFrom(nodes, graph.clusters || []);
        const fullCount = new Map(full.map((cloud) => [cloud.key, cloud.nodes.length]));
        const hot = firingIds();
        if (selectedId != null) hot.push(selectedId);
        const visibleNodes = [];
        const visibleIds = [];
        full.forEach((cloud) => {
            const visible = filteredNodes(cloud.nodes);
            const ids = sphere.capNodes(visible, edges, {
                cap: sphere.NODE_CAP,
                selectedId: selectedId,
                keepIds: hot,
            });
            const byId = new Map(visible.map((node) => [node.id, node]));
            ids.forEach((id) => {
                const node = byId.get(id);
                if (!node) return;
                visibleNodes.push(node);
                visibleIds.push(id);
            });
        });
        const layout = sphere.layoutWorld(visibleNodes, edges, graph.clusters || []);
        cloudCenters = new Map(layout.clouds.map((cloud) => [cloud.key, cloud]));
        placements = new Map();
        layout.positions.forEach((point, id) => {
            placements.set(id, {
                cloudKey: point.cloudKey,
                world: { x: point.x, y: point.y, z: point.z },
                clearance: point.clearance,
            });
        });
        const links = sphere.capLinks(edges, visibleIds, { cap: sphere.LINK_CAP, hotIds: hot });
        const parts = ['<div class="neural-links">'];
        links.forEach((edge) => {
            const a = placements.get(edge.from);
            const b = placements.get(edge.to);
            if (!a || !b) return;
            const pose = sphere.linkPose(a.world, b.world);
            const flat = Math.atan2(b.world.y - a.world.y, b.world.x - a.world.x) * 180 / Math.PI;
            parts.push(
                '<div class="neural-link" data-from="' + edge.from + '" data-to="' + edge.to + '" style="--x:' + pose.x.toFixed(1) + 'px;--y:' + pose.y.toFixed(1) + 'px;--z:' + pose.z.toFixed(1) + 'px;--az:' + pose.az.toFixed(2) + 'deg;--el:' + pose.el.toFixed(2) + 'deg;--len:' + Math.max(0, pose.length).toFixed(1) + 'px;--flat:' + flat.toFixed(2) + 'deg"><i></i></div>'
            );
        });
        parts.push('</div>');
        const byId = new Map(visibleNodes.map((node) => [node.id, node]));
        layout.clouds.forEach((cloud) => {
            const members = [];
            layout.positions.forEach((point, id) => {
                if (point.cloudKey === cloud.key) members.push(id);
            });
            if (!members.length) return;
            const attached = cloud.key === attachedKey ? ' attached' : '';
            let html = '<div class="neural-cloud' + attached + '" data-cloud="' + esc(cloud.key) + '" style="--cx:' + cloud.x.toFixed(1) + 'px;--cy:' + cloud.y.toFixed(1) + 'px;--cz:' + cloud.z.toFixed(1) + 'px;--shell:' + (cloud.radius * 1.65).toFixed(1) + 'px">';
            html += '<div class="geodesic-shell"><i></i><i></i><i></i><i></i></div>';
            html += '<button type="button" class="cloud-core" data-cloud="' + esc(cloud.key) + '" aria-label="Attach camera to ' + esc(cloud.label) + '">' + icon('brain-icon-brain') + '<i></i></button>';
            html += '<div class="cloud-name"><strong>' + esc(cloud.label) + '</strong><span>' + (fullCount.get(cloud.key) || cloud.count) + ' nodes</span></div>';
            members.forEach((id) => {
                const node = byId.get(id);
                const point = layout.positions.get(id);
                if (!node || !point) return;
                const type = sphere.designType(node.kind);
                const degree = sphere.neighborIds(edges, node.id).length;
                const size = sphere.nodeSize(degree);
                const selected = node.id === selectedId ? ' selected' : '';
                html += '<button type="button" class="neural-object type-' + type + selected + '" data-id="' + node.id + '" aria-label="' + esc(node.title || 'Page') + '" style="--nx:' + (point.x - cloud.x).toFixed(1) + 'px;--ny:' + (point.y - cloud.y).toFixed(1) + 'px;--nz:' + (point.z - cloud.z).toFixed(1) + 'px;--node-size:' + size + 'px">';
                html += '<span>' + esc(sphere.typeLabel(node.kind).slice(0, 1)) + '</span><i></i>';
                html += '<b>' + esc(node.title || 'Untitled') + '<small>' + esc(sphere.typeLabel(node.kind)) + '</small></b>';
                html += '<em class="access-source" hidden><strong></strong><small></small></em>';
                html += '</button>';
            });
            html += '</div>';
            parts.push(html);
        });
        world.innerHTML = parts.join('');
        nodeEls = new Map();
        world.querySelectorAll('.neural-object').forEach((el) => nodeEls.set(Number(el.dataset.id), el));
        cloudEls = new Map();
        world.querySelectorAll('.neural-cloud').forEach((el) => cloudEls.set(el.dataset.cloud, el));
        firesMarked = fires.length > 0;
        markDim();
        updateFires(Date.now());
        updateLod();
    }

    function markDim() {
        const keep = new Set();
        if (focusId != null) {
            keep.add(focusId);
            sphere.neighborIds(graph.edges || [], focusId).forEach((id) => keep.add(id));
        }
        nodeEls.forEach((el, id) => {
            el.classList.toggle('selected', id === selectedId);
            el.classList.toggle('is-keep', focusId != null && keep.has(id));
        });
        stage.querySelectorAll('.neural-link').forEach((el) => {
            const from = Number(el.dataset.from);
            const to = Number(el.dataset.to);
            el.classList.toggle('is-keep', focusId != null && (from === focusId || to === focusId));
        });
        stage.classList.toggle('is-focused', focusId != null);
    }

    function renderPanel() {
        const now = Date.now();
        const node = selectedId == null ? null : nodeById(selectedId);
        if (!node) {
            if (typeEl) typeEl.textContent = '\u2014';
            if (titleEl) titleEl.textContent = 'Select a page';
            if (readsEl) readsEl.textContent = '\u2014';
            if (writesEl) writesEl.textContent = '\u2014';
            if (activityEl) activityEl.textContent = '\u2014';
            if (meterEl) meterEl.style.width = '0';
            if (updatedEl) updatedEl.textContent = '\u2014';
            if (linksEl) linksEl.textContent = '\u2014';
            if (firingEl) firingEl.innerHTML = '';
            if (openEl) openEl.href = '/brain';
            return;
        }
        const type = sphere.designType(node.kind);
        if (typeEl) {
            typeEl.textContent = sphere.typeLabel(node.kind);
            typeEl.className = 'node-type type-' + type;
        }
        if (titleEl) titleEl.textContent = node.title || 'Untitled';
        const rates = firing.pageRates(rateEvents, now);
        const row = rates.get(node.id);
        if (readsEl) readsEl.textContent = row ? firing.formatRate(row.reads) : '\u2014';
        if (writesEl) writesEl.textContent = row ? firing.formatRate(row.writes) : '\u2014';
        if (activityEl) activityEl.textContent = row ? row.activity + '%' : '\u2014';
        if (meterEl) meterEl.style.width = row ? row.activity + '%' : '0';
        if (updatedEl) updatedEl.textContent = updatedLabel(node.updated_at, now);
        const neighbors = sphere.neighborIds(graph.edges || [], node.id);
        if (linksEl) linksEl.textContent = neighbors.length + ' items';
        if (openEl) openEl.href = '/brain?page=' + encodeURIComponent(node.id);
        const latest = latestFire(node.id, now);
        const strength = latest ? firing.firingStrength(now - latest.born) : 0;
        if (signalEl) {
            const text = signalEl.querySelector('span');
            if (text) text.textContent = firing.signalLabel(latest && latest.verb, strength);
            signalEl.classList.toggle('is-read', strength > 0 && latest.verb === 'read');
            signalEl.classList.toggle('is-write', strength > 0 && latest.verb === 'write');
        }
        if (markEl) markEl.className = 'node-mark' + (strength > 0 ? ' access-' + latest.verb : '');
        if (firingEl) {
            const sig = neighbors.slice().sort((a, b) => a - b).join(',');
            if (firingEl.dataset.sig !== sig) {
                firingEl.dataset.sig = sig;
                const byId = new Map((graph.nodes || []).map((item) => [item.id, item]));
                firingEl.innerHTML = neighbors.map((id) => {
                    const linked = byId.get(id);
                    if (!linked) return '';
                    const linkedType = sphere.designType(linked.kind);
                    const live = latestFire(id, now);
                    const state = live && firing.firingStrength(now - live.born) > 0 ? live.verb : linkedType;
                    return '<button type="button" data-select="' + id + '"><i class="type-' + linkedType + '"></i><span>' + esc(linked.title || 'Untitled') + '<small>' + esc(sphere.typeLabel(linked.kind)) + ' · ' + esc(String(state)) + '</small></span>' + icon('brain-icon-chevron') + '</button>';
                }).join('');
            }
        }
    }

    function latestFire(pageId, now) {
        let best = null;
        fires.forEach((fire) => {
            if (fire.pageId !== pageId) return;
            if (firing.firingStrength(now - fire.born) <= 0) return;
            if (!best || fire.born > best.born) best = fire;
        });
        return best;
    }

    function updateFires(now) {
        fires = fires.filter((fire) => firing.firingStrength(now - fire.born) > 0);
        if (!fires.length) {
            if (!firesMarked) return;
            firesMarked = false;
            nodeEls.forEach((el) => {
                el.classList.remove('is-firing-read', 'is-firing-write');
                const source = el.querySelector('.access-source');
                if (source) source.hidden = true;
            });
            stage.querySelectorAll('.neural-link.is-firing').forEach((el) => {
                el.classList.remove('is-firing', 'is-write');
                el.style.removeProperty('opacity');
            });
            return;
        }
        firesMarked = true;
        const latest = new Map();
        fires.forEach((fire) => {
            const prev = latest.get(fire.pageId);
            if (!prev || fire.born > prev.born) latest.set(fire.pageId, fire);
        });
        nodeEls.forEach((el, id) => {
            const fire = latest.get(id) || null;
            el.classList.toggle('is-firing-read', !!fire && fire.verb === 'read');
            el.classList.toggle('is-firing-write', !!fire && fire.verb === 'write');
            const source = el.querySelector('.access-source');
            if (!source) return;
            if (!fire) {
                source.hidden = true;
                return;
            }
            source.hidden = false;
            source.classList.toggle('is-write', fire.verb === 'write');
            const strength = firing.firingStrength(now - fire.born);
            const opacity = reduceMotion ? '1' : strength.toFixed(2);
            if (source.style.opacity !== opacity) source.style.opacity = opacity;
            const strong = source.querySelector('strong');
            const small = source.querySelector('small');
            const sourceName = String(fire.source || 'Agent').toUpperCase();
            if (strong && strong.textContent !== sourceName) strong.textContent = sourceName;
            const verb = fire.verb === 'write' ? 'WRITING' : 'READING';
            if (small && small.textContent !== verb) small.textContent = verb;
        });
        stage.querySelectorAll('.neural-link').forEach((el) => {
            const from = latest.get(Number(el.dataset.from));
            const to = latest.get(Number(el.dataset.to));
            const fire = from && to ? (from.born >= to.born ? from : to) : (from || to);
            if (!fire) {
                if (el.classList.contains('is-firing')) {
                    el.classList.remove('is-firing', 'is-write');
                    el.style.removeProperty('opacity');
                }
                return;
            }
            const strength = firing.firingStrength(now - fire.born);
            el.classList.add('is-firing');
            el.classList.toggle('is-write', fire.verb === 'write');
            const opacity = reduceMotion ? '1' : Math.max(0.2, strength).toFixed(2);
            if (el.style.opacity !== opacity) el.style.opacity = opacity;
        });
    }

    function ensurePlaced(id) {
        if (placements.has(id)) return true;
        selectedId = id;
        renderWorld();
        return placements.has(id);
    }

    function flyTo(id, opts) {
        const options = opts || {};
        if (!ensurePlaced(id)) {
            selectedId = id;
            markDim();
            renderPanel();
            return;
        }
        const place = placements.get(id);
        selectedId = id;
        if (options.dim) focusId = id;
        pivotLock = { type: 'node', id: id };
        attachedKey = place.cloudKey;
        const dist = options.close ? cameraApi.MESH_CLOSE_DIST : cameraApi.MESH_FRAME_DIST;
        const framed = cameraApi.meshFrame(present(target), place.world, dist);
        target = commitControl(target, framed);
        userMoved = true;
        applyCamera();
        renderChrome();
        markDim();
        renderPanel();
        updateLod();
        kick();
        scheduleSave();
    }

    function cycle(step) {
        const ids = Array.from(placements.keys()).sort((a, b) => {
            const left = nodeById(a);
            const right = nodeById(b);
            const ta = (left && left.title) || '';
            const tb = (right && right.title) || '';
            if (ta !== tb) return ta < tb ? -1 : 1;
            return a - b;
        });
        if (!ids.length) return;
        let index = ids.indexOf(selectedId);
        if (index < 0) index = step > 0 ? -1 : 0;
        flyTo(ids[(index + step + ids.length) % ids.length], { dim: false });
    }

    function applyHome(animate, save) {
        const points = [];
        placements.forEach((place) => points.push(place.world));
        const box = viewport();
        const fit = cameraApi.meshFit(points, box.width, box.height, cameraApi.MESH_FOV_DEG);
        homeDist = fit.dist;
        pivotLock = null;
        attachedKey = null;
        focusId = null;
        userMoved = false;
        target = fit;
        if (!animate || reduceMotion) display = cameraApi.meshClone(fit);
        applyCamera();
        renderChrome();
        markDim();
        updateLod();
        kick();
        if (save) scheduleSave();
    }

    function attachCloud(key) {
        const center = cloudCenters.get(key);
        if (!center) return;
        const points = [];
        placements.forEach((place) => {
            if (place.cloudKey === key) points.push(place.world);
        });
        const box = viewport();
        const fit = cameraApi.meshFit(points.length ? points : [center], box.width, box.height, cameraApi.MESH_FOV_DEG);
        const base = present(target);
        attachedKey = key;
        focusId = null;
        pivotLock = { type: 'cloud', key: key };
        target = commitControl(target, {
            px: fit.px,
            py: fit.py,
            pz: fit.pz,
            ax: 0,
            ay: 0,
            yaw: base.yaw,
            pitch: base.pitch,
            dist: fit.dist,
        });
        userMoved = true;
        applyCamera();
        renderChrome();
        markDim();
        kick();
        scheduleSave();
    }

    function freeRoam() {
        attachedKey = null;
        pivotLock = null;
        focusId = null;
        applyCamera();
        renderChrome();
        markDim();
        updateLod();
    }

    function setHelp(open) {
        helpOpen = open;
        if (open) held.clear();
        if (helpEl) helpEl.hidden = !open;
        if (helpBtn) helpBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            const close = helpEl && helpEl.querySelector('.brain-help-close');
            if (close) close.focus();
        } else if (helpBtn) {
            helpBtn.focus();
        }
    }

    function runAction(name) {
        if (name === 'help') setHelp(!helpOpen);
        else if (name === 'close-help') setHelp(false);
        else if (name === 'home') applyHome(true, true);
        else if (name === 'focus') {
            if (selectedId != null) flyTo(selectedId, { dim: true });
        } else if (name === 'level') {
            target = cameraApi.meshLevel(target);
            userMoved = true;
            kick();
            scheduleSave();
        } else if (name === 'toggle-fly') {
            fly = !fly;
            renderChrome();
            applyCamera();
            scheduleSave();
        } else if (name === 'mode-2d' || name === 'mode-3d') {
            mode3d = name === 'mode-3d';
            syncModeButton();
            applyCamera();
            updateLod();
            renderChrome();
            scheduleSave();
        } else if (name === 'search') {
            if (searchEl) searchEl.focus();
        } else if (name === 'clear') {
            selectedId = null;
            focusId = null;
            pivotLock = null;
            markDim();
            renderPanel();
            updateLod();
        } else if (name === 'cycle-next') cycle(1);
        else if (name === 'cycle-prev') cycle(-1);
    }

    function typingTarget(targetNode) {
        if (!targetNode || !targetNode.closest) return false;
        return !!targetNode.closest('input, textarea, select, [contenteditable="true"]');
    }

    function ingestGraph(data) {
        const next = data || graph;
        const sig = dataSig(next);
        const same = booted && sig === graphSig;
        graph = next;
        graphSig = sig;
        const clouds = sphere.cloudsFrom(graph.nodes || [], graph.clusters || []);
        if (!booted) {
            booted = true;
            if ((graph.nodes || [])[0]) selectedId = graph.nodes[0].id;
        }
        if (attachedKey && !clouds.some((cloud) => cloud.key === attachedKey)) {
            attachedKey = null;
            pivotLock = null;
        }
        if (selectedId != null && !nodeById(selectedId)) {
            selectedId = (graph.nodes || [])[0] ? graph.nodes[0].id : null;
            if (pivotLock && pivotLock.type === 'node') pivotLock = null;
        }
        if (!same) renderWorld();
        renderChrome();
        renderPanel();
        if (!framed) {
            framed = true;
            const prefs = cameraApi.meshLoadPrefs(safeStorage());
            applyHome(false, false);
            if (prefs) {
                mode3d = prefs.mode3d !== false;
                fly = prefs.fly === true;
                target = cameraApi.meshApplyPrefs(target, prefs);
                display = cameraApi.meshClone(target);
                userMoved = true;
            }
            syncModeButton();
            applyCamera();
            updateLod();
            renderChrome();
        }
    }

    function rememberFire(id, pageId, verb, source, at) {
        const now = Date.now();
        if (!id || seenFire.has(id)) return;
        if (!firing.isFresh(at, now) || !pageId) {
            if (id) seenFire.add(id);
            return;
        }
        seenFire.add(id);
        fires.push({
            id: id,
            pageId: pageId,
            verb: verb === 'write' ? 'write' : 'read',
            source: source || 'Agent',
            born: now,
        });
    }

    function ingestLive(presenceEvents, accessEvents) {
        const nextRates = [];
        (accessEvents || []).forEach((event) => {
            const pageId = Number(event.page_id) || 0;
            if (!pageId) return;
            nextRates.push({
                pageId: pageId,
                verb: event.verb === 'write' ? 'write' : 'read',
                atMs: parseUtc(event.at),
            });
        });
        rateEvents = nextRates;
        const presenceById = new Map();
        (presenceEvents || []).forEach((event) => {
            const id = Number(event.id) || 0;
            if (id > presenceAfter) presenceAfter = id;
            if (id) presenceById.set(id, event);
        });
        (accessEvents || []).forEach((event) => {
            if (event.actor !== 'user' && event.actor !== 'ai') return;
            const id = Number(event.id) || 0;
            const hit = presenceById.get(id);
            const source = (hit && hit.agent) || event.source || (event.actor === 'user' ? 'You' : 'Agent');
            rememberFire(id, Number(event.page_id) || 0, event.verb, source, parseUtc(event.at));
        });
        const alive = firingIds();
        const needs = alive.some((id) => !placements.has(id));
        if (needs) renderWorld();
        else updateFires(Date.now());
        if (fires.length) kick();
        renderPanel();
    }

    async function pullLive() {
        if (paused || document.hidden) return;
        try {
            const presence = await api.get('/audit/presence?after=' + presenceAfter + '&limit=20');
            const access = await api.get('/pages/graph/access?limit=80');
            ingestLive(presence.events || [], access.events || []);
        } catch (err) {
            /* the mesh stays up when the feed is quiet */
        }
    }

    async function load() {
        ingestGraph(await api.get('/pages/graph'));
    }

    function stepKeys(dt) {
        const move = cameraApi.meshHeldMove(held);
        if (!move || helpOpen) return false;
        const speed = (dt / 1000) * (360 + display.dist * 0.45) * (move.fast ? 3.2 : 1) * (move.precise ? 0.28 : 1);
        const mode = fly && mode3d ? 'fly' : 'pan';
        const next = cameraApi.meshNudge(present(target), mode, move, speed, perspective());
        target = commitControl(target, next);
        userMoved = true;
        releaseLock();
        return true;
    }

    function needsMotion() {
        if (document.hidden) return false;
        if (dragging) return true;
        if (cameraApi.meshHeldMove(held) && !helpOpen) return true;
        if (!cameraApi.meshSettled(display, target)) return true;
        if (!paused && !reduceMotion && fires.length) return true;
        return false;
    }

    function settleClasses(moving) {
        stage.classList.toggle('is-moving', moving);
        stage.classList.toggle('is-idle', !moving);
    }

    function kick() {
        if (document.hidden) {
            stage.classList.add('is-hidden', 'is-idle');
            stage.classList.remove('is-moving');
            return;
        }
        stage.classList.remove('is-hidden');
        if (!needsMotion()) {
            settleClasses(false);
            return;
        }
        if (loopOn) return;
        loopOn = true;
        lastFrame = 0;
        raf = requestAnimationFrame(tick);
    }

    function tick(now) {
        const dt = lastFrame ? Math.min(64, now - lastFrame) : 16;
        lastFrame = now;
        const keyed = stepKeys(dt);
        const step = cameraApi.meshStep(
            display,
            target,
            reduceMotion ? 1000 : dt,
            reduceMotion ? 80 : (dragging ? 18 : cameraApi.MESH_DAMP)
        );
        display = step.camera;
        if (!step.settled || dragging || keyed) {
            applyCamera();
            updateLod();
        }
        if (fires.length) updateFires(now);
        const live = !paused && !reduceMotion && fires.length > 0;
        const again = (!step.settled || dragging || keyed || live) && !document.hidden;
        settleClasses(again && (!step.settled || dragging || keyed));
        if (again) raf = requestAnimationFrame(tick);
        else {
            loopOn = false;
            applyCamera();
            updateLod();
            settleClasses(false);
        }
    }

    function pinchInfo() {
        const pts = Array.from(pointers.values());
        return {
            dist: Math.max(1, Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y)),
            cx: (pts[0].x + pts[1].x) / 2,
            cy: (pts[0].y + pts[1].y) / 2,
        };
    }

    function beginPinch() {
        const info = pinchInfo();
        releaseLock();
        const base = cameraApi.meshClone(display);
        gesture = {
            type: 'pinch',
            dist: info.dist,
            cx: info.cx,
            cy: info.cy,
            cam: present(base),
            base: base,
        };
        target = cameraApi.meshClone(base);
        dragging = true;
        suppressClick = true;
        applyCamera();
        kick();
    }

    stage.addEventListener('pointerdown', (ev) => {
        if (helpOpen) return;
        if (ev.button !== undefined && ev.button > 2) return;
        const onNode = ev.target.closest('.neural-object');
        const onUi = ev.target.closest('button, input, a, .brain-help, .brain-toolbar, .camera-dock, .brain-legend, .node-panel');
        if (onUi && !onNode) return;
        if (ev.button === undefined || ev.button === 0 || ev.button === 1 || ev.button === 2) {
            try { stage.setPointerCapture(ev.pointerId); } catch (err) { /* already released */ }
        }
        pointers.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
        suppressClick = false;
        if (!onNode && ev.target !== searchEl) stage.focus();
        if (pointers.size >= 2) {
            beginPinch();
            return;
        }
        const local = pointerLocal(ev.clientX, ev.clientY);
        const pan = !mode3d || ev.button === 1 || ev.button === 2 || ev.shiftKey;
        const base = cameraApi.meshClone(display);
        if (pan) {
            releaseLock();
            gesture = { type: 'pan', x: ev.clientX, y: ev.clientY, cam: present(base), base: base, moved: false };
        } else if (fly) {
            releaseLock();
            gesture = { type: 'look', x: ev.clientX, y: ev.clientY, cam: present(base), base: base, moved: false };
        } else {
            let grabbed = present(base);
            if (!pivotLock) grabbed = cameraApi.meshGrabPivot(grabbed, local.x, local.y, perspective());
            display = commitControl(base, grabbed);
            target = cameraApi.meshClone(display);
            gesture = { type: 'orbit', x: ev.clientX, y: ev.clientY, cam: present(display), base: cameraApi.meshClone(display), moved: false };
        }
        dragging = true;
        applyCamera();
        kick();
    });

    stage.addEventListener('pointermove', (ev) => {
        if (!pointers.has(ev.pointerId)) return;
        const prev = pointers.get(ev.pointerId);
        pointers.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
        if (pointers.size >= 2) {
            if (!gesture || gesture.type !== 'pinch') beginPinch();
            const info = pinchInfo();
            const local = pointerLocal(info.cx, info.cy);
            const next = cameraApi.meshPinchGesture(
                gesture.cam,
                info.cx - gesture.cx,
                info.cy - gesture.cy,
                info.dist / gesture.dist,
                local.x,
                local.y,
                perspective()
            );
            target = commitControl(gesture.base, next);
            userMoved = true;
            suppressClick = true;
            kick();
            return;
        }
        if (!gesture || gesture.pointerOnly) return;
        const dx = ev.clientX - gesture.x;
        const dy = ev.clientY - gesture.y;
        if (!gesture.moved && Math.hypot(dx, dy) < 6 && Math.hypot(ev.clientX - prev.x, ev.clientY - prev.y) < 6) return;
        gesture.moved = true;
        suppressClick = true;
        let next = gesture.cam;
        if (gesture.type === 'pan') next = cameraApi.meshPanBy(gesture.cam, dx, dy, perspective());
        else if (gesture.type === 'look') next = cameraApi.meshLook(gesture.cam, dx * 0.22, dy * 0.16);
        else next = cameraApi.meshOrbit(gesture.cam, dx, dy);
        target = commitControl(gesture.base, next);
        userMoved = true;
        kick();
    });

    function endPointer(ev) {
        pointers.delete(ev.pointerId);
        if (pointers.size >= 2) return;
        if (pointers.size === 1) {
            gesture = null;
            dragging = false;
            applyCamera();
            scheduleSave();
            return;
        }
        gesture = null;
        dragging = false;
        applyCamera();
        scheduleSave();
        kick();
    }
    stage.addEventListener('pointerup', endPointer);
    stage.addEventListener('pointercancel', endPointer);
    stage.addEventListener('contextmenu', (ev) => {
        if (ev.target.closest('input, textarea')) return;
        ev.preventDefault();
    });

    stage.addEventListener('wheel', (ev) => {
        if (ev.target.closest('input, textarea, .brain-help')) return;
        ev.preventDefault();
        const local = pointerLocal(ev.clientX, ev.clientY);
        const pixels = ev.deltaMode === 1 ? ev.deltaY * 16 : ev.deltaMode === 2 ? ev.deltaY * viewport().height : ev.deltaY;
        const factor = Math.max(0.82, Math.min(1.22, Math.exp(-pixels * 0.0015)));
        const next = cameraApi.meshZoomAt(present(target), local.x, local.y, factor, perspective());
        target = commitControl(target, next);
        userMoved = true;
        kick();
        scheduleSave();
    }, { passive: false });

    document.addEventListener('keydown', (ev) => {
        if (typingTarget(ev.target)) {
            if (ev.key === 'Escape' && ev.target.blur) ev.target.blur();
            return;
        }
        if (ev.code === 'ShiftLeft' || ev.code === 'ShiftRight') {
            held.add('ShiftLeft');
            return;
        }
        if (ev.code === 'AltLeft' || ev.code === 'AltRight') {
            held.add('AltLeft');
            return;
        }
        const action = cameraApi.meshShortcut({
            code: ev.code,
            key: ev.key,
            shift: ev.shiftKey,
            alt: ev.altKey,
            meta: ev.metaKey,
            ctrl: ev.ctrlKey,
            typing: false,
            helpOpen: helpOpen,
            repeat: ev.repeat,
            stageFocused: ev.target === stage,
            onControl: !!(ev.target && ev.target.closest && ev.target.closest('button, a')),
        });
        if (!action) return;
        if (action.kind === 'move') {
            held.add(ev.code);
            if (ev.shiftKey) held.add('ShiftLeft');
            if (ev.altKey) held.add('AltLeft');
            ev.preventDefault();
            kick();
            return;
        }
        ev.preventDefault();
        if (action.kind === 'zoom') {
            const next = cameraApi.meshZoomAt(present(target), 0, 0, action.factor, perspective());
            target = commitControl(target, next);
            userMoved = true;
            kick();
            scheduleSave();
            return;
        }
        if (action.name === 'close-help') setHelp(false);
        else runAction(action.name);
    });

    document.addEventListener('keyup', (ev) => {
        held.delete(ev.code);
        if (ev.code === 'ShiftLeft' || ev.code === 'ShiftRight' || !ev.shiftKey) held.delete('ShiftLeft');
        if (ev.code === 'AltLeft' || ev.code === 'AltRight' || !ev.altKey) held.delete('AltLeft');
        if (held.size) scheduleSave();
    });
    window.addEventListener('blur', () => held.clear());

    stage.addEventListener('click', (ev) => {
        if (suppressClick) {
            suppressClick = false;
            return;
        }
        const tab = ev.target.closest('#brain-cloud-tabs [data-cloud]');
        if (tab) {
            attachCloud(tab.getAttribute('data-cloud'));
            return;
        }
        const core = ev.target.closest('.cloud-core');
        if (core) {
            attachCloud(core.getAttribute('data-cloud'));
            return;
        }
        const node = ev.target.closest('.neural-object');
        if (node) flyTo(Number(node.dataset.id), { dim: false, close: false });
    });
    stage.addEventListener('dblclick', (ev) => {
        const node = ev.target.closest('.neural-object');
        if (!node) return;
        flyTo(Number(node.dataset.id), { dim: false, close: true });
    });

    document.querySelectorAll('.brain-filters [data-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            filter = button.getAttribute('data-filter') || 'all';
            document.querySelectorAll('.brain-filters [data-filter]').forEach((el) => {
                const on = el === button;
                el.classList.toggle('active', on);
                el.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            const visible = filteredNodes(graph.nodes || []);
            if (query.trim() && visible[0]) selectedId = visible[0].id;
            else if (selectedId == null || !visible.some((node) => node.id === selectedId)) {
                selectedId = visible[0] ? visible[0].id : null;
            }
            renderWorld();
            if (query.trim() && visible[0]) flyTo(visible[0].id, { dim: false });
            else {
                markDim();
                renderPanel();
            }
            renderChrome();
        });
    });
    if (searchEl) {
        searchEl.addEventListener('input', () => {
            query = searchEl.value;
            const visible = filteredNodes(graph.nodes || []);
            if (query.trim() && visible[0]) selectedId = visible[0].id;
            else if (selectedId != null && !visible.some((node) => node.id === selectedId)) {
                selectedId = visible[0] ? visible[0].id : null;
            }
            renderWorld();
            if (query.trim() && visible[0]) flyTo(visible[0].id, { dim: false });
            else renderPanel();
        });
    }
    document.getElementById('brain-zoom-in').addEventListener('click', () => {
        const next = cameraApi.meshZoomAt(present(target), 0, 0, cameraApi.MESH_ZOOM_STEP, perspective());
        target = commitControl(target, next);
        userMoved = true;
        kick();
        scheduleSave();
        stage.focus();
    });
    document.getElementById('brain-zoom-out').addEventListener('click', () => {
        const next = cameraApi.meshZoomAt(present(target), 0, 0, 1 / cameraApi.MESH_ZOOM_STEP, perspective());
        target = commitControl(target, next);
        userMoved = true;
        kick();
        scheduleSave();
        stage.focus();
    });
    function homeClick() {
        applyHome(!reduceMotion, true);
        stage.focus();
    }
    document.getElementById('brain-zoom-reset').addEventListener('click', homeClick);
    document.getElementById('brain-home').addEventListener('click', homeClick);
    if (modeBtn) {
        modeBtn.addEventListener('click', () => {
            mode3d = !mode3d;
            syncModeButton();
            applyCamera();
            updateLod();
            renderChrome();
            scheduleSave();
        });
    }
    if (pauseBtn) {
        pauseBtn.addEventListener('click', () => {
            paused = !paused;
            pauseBtn.setAttribute('aria-pressed', paused ? 'true' : 'false');
            pauseBtn.setAttribute('aria-label', paused ? 'Resume live updates' : 'Pause live updates');
            const play = pauseBtn.querySelector('.icon-play');
            const hold = pauseBtn.querySelector('.icon-pause');
            if (play) play.hidden = !paused;
            if (hold) hold.hidden = paused;
            applyCamera();
        });
    }
    if (freeBtn) freeBtn.addEventListener('click', freeRoam);
    if (helpBtn) helpBtn.addEventListener('click', () => setHelp(!helpOpen));
    if (helpEl) {
        helpEl.addEventListener('click', (ev) => {
            if (ev.target.closest('[data-close-help]')) setHelp(false);
        });
    }
    const panel = document.getElementById('brain-node-panel');
    if (panel) {
        panel.addEventListener('click', (ev) => {
            const pick = ev.target.closest('[data-select]');
            if (pick) {
                flyTo(Number(pick.getAttribute('data-select')), { dim: focusId != null });
                return;
            }
            if (ev.target.closest('#brain-node-focus')) {
                if (selectedId != null) flyTo(selectedId, { dim: true });
            }
        });
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            cancelAnimationFrame(raf);
            loopOn = false;
            stage.classList.add('is-hidden', 'is-idle');
            stage.classList.remove('is-moving');
        } else {
            stage.classList.remove('is-hidden');
            kick();
        }
    });

    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            const nextP = perspective();
            if (!userMoved) {
                applyHome(false, false);
                return;
            }
            if (lastPerspective > 0 && Math.abs(nextP - lastPerspective) > 1) {
                const scale = nextP / lastPerspective;
                target = cameraApi.meshClone(target);
                display = cameraApi.meshClone(display);
                target.dist = Math.max(cameraApi.MESH_MIN_DIST, Math.min(cameraApi.MESH_MAX_DIST, target.dist * scale));
                display.dist = Math.max(cameraApi.MESH_MIN_DIST, Math.min(cameraApi.MESH_MAX_DIST, display.dist * scale));
            }
            applyCamera();
            updateLod();
        }, 80);
    });

    function startTimers() {
        if (!pollTimer) {
            pollTimer = setInterval(() => {
                if (!paused && !document.hidden) {
                    load().catch(() => { /* keep the mesh on screen */ });
                }
            }, POLL_MS);
        }
        if (!presenceTimer) presenceTimer = setInterval(pullLive, PRESENCE_MS);
    }

    applyCamera();
    settleClasses(false);
    load().then(() => {
        startTimers();
        return pullLive();
    }).catch(() => {
        startTimers();
    });
})();
