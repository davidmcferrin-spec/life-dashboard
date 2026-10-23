/**
 * CSS 3D vault mesh. Clouds, camera, and firing all read the live graph.
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
    const copyEl = document.getElementById('brain-node-copy');
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

    const POLL_MS = 60000;
    const PRESENCE_MS = 4000;
    const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    let graph = { nodes: [], edges: [], clusters: [] };
    let camera = cameraApi.meshReset();
    let mode3d = true;
    let paused = false;
    let attachedKey = null;
    let selectedId = null;
    let focusId = null;
    let filter = 'all';
    let query = '';
    let dragging = false;
    let reduceMotion = motionQuery.matches;
    let booted = false;
    let presenceAfter = 0;
    let rateEvents = [];
    let fires = [];
    const seenFire = new Set();
    let placements = new Map();
    let cloudFrames = new Map();
    let resizeTimer = null;
    let pollTimer = null;
    let presenceTimer = null;

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

    function applyCamera() {
        stage.style.setProperty('--world-rx', camera.rx + 'deg');
        stage.style.setProperty('--world-ry', camera.ry + 'deg');
        stage.style.setProperty('--camera-x', camera.x + 'px');
        stage.style.setProperty('--camera-y', camera.y + 'px');
        stage.style.setProperty('--camera-zoom', String(camera.zoom));
        const frame = attachedKey ? cloudFrames.get(attachedKey) : null;
        if (frame) {
            stage.style.setProperty('--focus-x', (50 - frame.x) + '%');
            stage.style.setProperty('--focus-y', (50 - frame.y) + '%');
        } else {
            stage.style.setProperty('--focus-x', '0%');
            stage.style.setProperty('--focus-y', '0%');
        }
        stage.classList.toggle('mode-3d', mode3d);
        stage.classList.toggle('mode-2d', !mode3d);
        stage.classList.toggle('paused', paused);
        stage.classList.toggle('dragging', dragging);
        stage.classList.toggle('camera-free', attachedKey == null);
        stage.classList.toggle('camera-attached', attachedKey != null);
        stage.classList.toggle('is-focused', focusId != null);
    }

    function renderChrome() {
        const nodes = graph.nodes || [];
        const edges = graph.edges || [];
        const clouds = sphere.cloudsFrom(nodes, graph.clusters || []);
        if (countsEl) {
            countsEl.textContent = nodes.length + ' NODES · ' + edges.length + ' LINKS · ' + clouds.length + ' CLOUDS';
        }
        const attached = clouds.find((cloud) => cloud.key === attachedKey) || null;
        if (modeNameEl) modeNameEl.textContent = attached ? 'ATTACHED · ' + attached.label.toUpperCase() : 'FREE ROAM';
        if (modeHintEl) {
            modeHintEl.textContent = attached ? 'Drag to orbit · detach to travel' : 'Drag to travel · scroll to zoom';
        }
        if (freeBtn) {
            freeBtn.classList.toggle('active', attached == null);
            freeBtn.setAttribute('aria-pressed', attached == null ? 'true' : 'false');
        }
        if (tabsEl) {
            tabsEl.innerHTML = clouds.map((cloud) => {
                const on = cloud.key === attachedKey;
                return '<button type="button" data-cloud="' + esc(cloud.key) + '" class="' + (on ? 'active' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '">' + icon('brain-icon-brain') + '<span>' + esc(shortName(cloud.label)) + '</span></button>';
            }).join('');
        }
        stage.querySelectorAll('.neural-cloud').forEach((el) => {
            el.classList.toggle('attached', el.dataset.cloud === attachedKey);
        });
    }

    function renderWorld() {
        if (!world) return;
        const nodes = graph.nodes || [];
        const edges = graph.edges || [];
        const clouds = sphere.cloudsFrom(nodes, graph.clusters || []);
        clouds.forEach((cloud, index) => {
            cloud.frame = sphere.cloudFrame(index, clouds.length);
        });
        cloudFrames = new Map(clouds.map((cloud) => [cloud.key, cloud.frame]));
        const box = stage.getBoundingClientRect();
        const width = box.width || stage.clientWidth || 1;
        const height = box.height || stage.clientHeight || 1;
        placements = new Map();
        const hot = firingIds();
        if (selectedId != null) hot.push(selectedId);
        const parts = ['<div class="neural-links">'];
        const cloudHtml = [];
        clouds.forEach((cloud, cloudIndex) => {
            const visible = filteredNodes(cloud.nodes);
            const ids = sphere.capNodes(visible, edges, {
                cap: sphere.NODE_CAP,
                selectedId: selectedId,
                keepIds: hot,
            });
            const byId = new Map(visible.map((node) => [node.id, node]));
            const shown = ids.map((id) => byId.get(id)).filter(Boolean);
            if (!shown.length) return;
            shown.forEach((node, index) => {
                const local = sphere.placeOnSphere(index, shown.length, 104);
                const frame = cloud.frame;
                const worldPoint = {
                    x: (frame.x / 100) * width + local.x * frame.scale,
                    y: (frame.y / 100) * height + local.y * frame.scale,
                    z: local.z * frame.scale,
                };
                placements.set(node.id, {
                    cloudKey: cloud.key,
                    az: local.az,
                    el: local.el,
                    world: worldPoint,
                });
            });
        });
        const visibleIds = Array.from(placements.keys());
        const links = sphere.capLinks(edges, visibleIds, { cap: sphere.LINK_CAP, hotIds: hot });
        links.forEach((edge) => {
            const a = placements.get(edge.from);
            const b = placements.get(edge.to);
            if (!a || !b) return;
            const pose = sphere.linkPose(a.world, b.world);
            parts.push(
                '<div class="neural-link" data-from="' + edge.from + '" data-to="' + edge.to + '" style="--x:' + pose.x.toFixed(2) + 'px;--y:' + pose.y.toFixed(2) + 'px;--z:' + pose.z.toFixed(2) + 'px;--az:' + pose.az.toFixed(2) + 'deg;--el:' + pose.el.toFixed(2) + 'deg;--len:' + pose.length.toFixed(2) + 'px"><i></i></div>'
            );
        });
        parts.push('</div>');
        clouds.forEach((cloud, cloudIndex) => {
            const visible = filteredNodes(cloud.nodes);
            const ids = sphere.capNodes(visible, edges, {
                cap: sphere.NODE_CAP,
                selectedId: selectedId,
                keepIds: hot,
            });
            const shown = ids.map((id) => visible.find((node) => node.id === id)).filter(Boolean);
            if (!shown.length) return;
            const frame = cloud.frame;
            const attached = cloud.key === attachedKey ? ' attached' : '';
            let html = '<div class="neural-cloud' + attached + '" data-cloud="' + esc(cloud.key) + '" style="--cloud-x:' + frame.x + '%;--cloud-y:' + frame.y + '%;--cloud-scale:' + frame.scale + ';--cloud-delay:' + (cloudIndex * -3.4) + 's">';
            html += '<div class="geodesic-shell"><i></i><i></i><i></i><i></i></div>';
            html += '<button type="button" class="cloud-core" data-cloud="' + esc(cloud.key) + '" aria-label="Attach camera to ' + esc(cloud.label) + '">' + icon('brain-icon-brain') + '<i></i></button>';
            html += '<div class="cloud-name"><strong>' + esc(cloud.label) + '</strong><span>' + cloud.nodes.length + ' nodes</span></div>';
            shown.forEach((node, index) => {
                const place = placements.get(node.id);
                const local = sphere.placeOnSphere(index, shown.length, 104);
                const type = sphere.designType(node.kind);
                const degree = sphere.neighborIds(edges, node.id).length;
                const size = sphere.nodeSize(degree);
                const selected = node.id === selectedId ? ' selected' : '';
                html += '<div class="neural-spoke" style="--az:' + local.az.toFixed(2) + 'deg;--el:' + local.el.toFixed(2) + 'deg;--radius:' + local.radius + 'px;--node-size:' + size + 'px;--pulse-delay:' + (index * -0.38) + 's">';
                html += '<i class="neural-thread"></i>';
                html += '<button type="button" class="neural-object type-' + type + selected + '" data-id="' + node.id + '" aria-label="' + esc(node.title || 'Page') + '">';
                html += '<span>' + esc(sphere.typeLabel(node.kind).slice(0, 1)) + '</span><i></i>';
                html += '<b>' + esc(node.title || 'Untitled') + '<small>' + esc(sphere.typeLabel(node.kind)) + '</small></b>';
                html += '<em class="access-source" hidden><strong></strong><small></small></em>';
                html += '</button></div>';
                if (place) place.az = local.az;
            });
            html += '</div>';
            cloudHtml.push(html);
        });
        world.innerHTML = parts.join('') + cloudHtml.join('');
        markDim();
        updateFires(Date.now());
    }

    function markDim() {
        const keep = new Set();
        if (focusId != null) {
            keep.add(focusId);
            sphere.neighborIds(graph.edges || [], focusId).forEach((id) => keep.add(id));
        }
        stage.querySelectorAll('.neural-object').forEach((el) => {
            const id = Number(el.dataset.id);
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
        stage.querySelectorAll('.neural-object').forEach((el) => {
            const id = Number(el.dataset.id);
            const fire = latestFire(id, now);
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
            source.style.opacity = reduceMotion ? '1' : String(strength);
            const strong = source.querySelector('strong');
            const small = source.querySelector('small');
            if (strong) strong.textContent = String(fire.source || 'Agent').toUpperCase();
            if (small) small.textContent = fire.verb === 'write' ? 'WRITING' : 'READING';
        });
        stage.querySelectorAll('.neural-link').forEach((el) => {
            const from = latestFire(Number(el.dataset.from), now);
            const to = latestFire(Number(el.dataset.to), now);
            const fire = from && to ? (from.born >= to.born ? from : to) : (from || to);
            if (!fire) {
                el.classList.remove('is-firing', 'is-write');
                el.style.removeProperty('opacity');
                return;
            }
            const strength = firing.firingStrength(now - fire.born);
            el.classList.add('is-firing');
            el.classList.toggle('is-write', fire.verb === 'write');
            el.style.opacity = reduceMotion ? '1' : String(Math.max(0.2, strength));
        });
    }

    function aimAt(id, dim) {
        const place = placements.get(id);
        selectedId = id;
        if (dim) focusId = id;
        if (place) {
            attachedKey = place.cloudKey;
            camera = cameraApi.meshFace(camera, place.az, place.el);
        }
        applyCamera();
        renderChrome();
        markDim();
        renderPanel();
    }

    function selectNode(id) {
        selectedId = id;
        markDim();
        renderPanel();
        const missing = !placements.has(id);
        if (missing) renderWorld();
    }

    function attachCloud(key) {
        if (!cloudFrames.has(key)) return;
        attachedKey = key;
        focusId = null;
        camera = { x: 0, y: 0, zoom: camera.zoom, rx: camera.rx, ry: camera.ry };
        applyCamera();
        renderChrome();
        markDim();
    }

    function freeRoam() {
        attachedKey = null;
        focusId = null;
        applyCamera();
        renderChrome();
        markDim();
    }

    function ingestGraph(data) {
        graph = data || graph;
        const clouds = sphere.cloudsFrom(graph.nodes || [], graph.clusters || []);
        if (!booted) {
            booted = true;
            if (clouds[0]) attachedKey = clouds[0].key;
            if ((graph.nodes || [])[0]) selectedId = graph.nodes[0].id;
        }
        if (attachedKey && !clouds.some((cloud) => cloud.key === attachedKey)) {
            attachedKey = clouds[0] ? clouds[0].key : null;
        }
        if (selectedId != null && !nodeById(selectedId)) selectedId = (graph.nodes || [])[0] ? graph.nodes[0].id : null;
        renderWorld();
        renderChrome();
        renderPanel();
        applyCamera();
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

    const pointers = new Map();
    let drag = null;
    let pinch = null;

    stage.addEventListener('pointerdown', (ev) => {
        if (ev.button !== undefined && ev.button !== 0) return;
        if (ev.target.closest('button, input, a')) return;
        stage.setPointerCapture(ev.pointerId);
        pointers.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
        pinch = null;
        if (pointers.size === 1) {
            drag = {
                x: ev.clientX,
                y: ev.clientY,
                camera: { x: camera.x, y: camera.y, zoom: camera.zoom, rx: camera.rx, ry: camera.ry },
            };
            dragging = true;
            applyCamera();
        }
    });
    stage.addEventListener('pointermove', (ev) => {
        if (!pointers.has(ev.pointerId)) return;
        pointers.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
        if (pointers.size >= 2) {
            const pts = Array.from(pointers.values());
            const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
            if (!pinch) pinch = { dist: Math.max(1, dist), zoom: camera.zoom };
            camera = cameraApi.meshPinch(camera, pinch.zoom, pinch.dist, dist);
            drag = null;
            applyCamera();
            return;
        }
        if (!drag) return;
        const dx = ev.clientX - drag.x;
        const dy = ev.clientY - drag.y;
        if (attachedKey == null) camera = cameraApi.meshPan(drag.camera, dx, dy);
        else camera = cameraApi.meshSpin(drag.camera, dx, dy);
        applyCamera();
    });
    function endPointer(ev) {
        pointers.delete(ev.pointerId);
        pinch = null;
        drag = null;
        if (pointers.size === 0 && dragging) {
            dragging = false;
            applyCamera();
        }
    }
    stage.addEventListener('pointerup', endPointer);
    stage.addEventListener('pointercancel', endPointer);
    stage.addEventListener('wheel', (ev) => {
        if (ev.target.closest('input, textarea')) return;
        ev.preventDefault();
        camera = cameraApi.meshWheel(camera, ev.deltaY);
        applyCamera();
    }, { passive: false });
    stage.addEventListener('keydown', (ev) => {
        if (ev.target.closest('input, textarea')) return;
        if (ev.key === '+' || ev.key === '=') camera = cameraApi.meshZoomBy(camera, cameraApi.MESH_ZOOM_STEP);
        else if (ev.key === '-' || ev.key === '_') camera = cameraApi.meshZoomBy(camera, -cameraApi.MESH_ZOOM_STEP);
        else if (ev.key === '0') camera = cameraApi.meshReset();
        else if (ev.key === 'ArrowLeft') camera = cameraApi.meshPan(camera, 28, 0);
        else if (ev.key === 'ArrowRight') camera = cameraApi.meshPan(camera, -28, 0);
        else if (ev.key === 'ArrowUp') camera = cameraApi.meshPan(camera, 0, 28);
        else if (ev.key === 'ArrowDown') camera = cameraApi.meshPan(camera, 0, -28);
        else return;
        ev.preventDefault();
        applyCamera();
    });

    stage.addEventListener('click', (ev) => {
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
        if (node) selectNode(Number(node.dataset.id));
    });

    document.querySelectorAll('.brain-filters [data-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            filter = button.getAttribute('data-filter') || 'all';
            document.querySelectorAll('.brain-filters [data-filter]').forEach((el) => {
                const on = el === button;
                el.classList.toggle('active', on);
                el.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            renderWorld();
            const visible = filteredNodes(graph.nodes || []);
            if (query.trim() && visible[0]) {
                aimAt(visible[0].id, false);
            } else {
                if (selectedId == null || !visible.some((node) => node.id === selectedId)) {
                    selectedId = visible[0] ? visible[0].id : null;
                }
                markDim();
                renderPanel();
            }
            renderChrome();
        });
    });
    if (searchEl) {
        searchEl.addEventListener('input', () => {
            query = searchEl.value;
            renderWorld();
            const visible = filteredNodes(graph.nodes || []);
            if (query.trim() && visible[0]) aimAt(visible[0].id, false);
            else {
                if (selectedId != null && !visible.some((node) => node.id === selectedId)) {
                    selectedId = visible[0] ? visible[0].id : null;
                }
                renderPanel();
            }
        });
    }
    document.getElementById('brain-zoom-in').addEventListener('click', () => {
        camera = cameraApi.meshZoomBy(camera, cameraApi.MESH_ZOOM_STEP);
        applyCamera();
        stage.focus();
    });
    document.getElementById('brain-zoom-out').addEventListener('click', () => {
        camera = cameraApi.meshZoomBy(camera, -cameraApi.MESH_ZOOM_STEP);
        applyCamera();
        stage.focus();
    });
    document.getElementById('brain-zoom-reset').addEventListener('click', () => {
        camera = cameraApi.meshReset();
        applyCamera();
        stage.focus();
    });
    if (modeBtn) {
        modeBtn.addEventListener('click', () => {
            mode3d = !mode3d;
            modeBtn.textContent = mode3d ? '3D' : '2D';
            modeBtn.classList.toggle('active', mode3d);
            modeBtn.setAttribute('aria-pressed', mode3d ? 'true' : 'false');
            applyCamera();
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
    const panel = document.getElementById('brain-node-panel');
    if (panel) {
        panel.addEventListener('click', (ev) => {
            const pick = ev.target.closest('[data-select]');
            if (pick) {
                selectNode(Number(pick.getAttribute('data-select')));
                return;
            }
            if (ev.target.closest('#brain-node-focus')) {
                if (selectedId != null) aimAt(selectedId, true);
            }
        });
    }

    let lastFrame = 0;
    function frame(now) {
        const dt = lastFrame ? now - lastFrame : 0;
        lastFrame = now;
        if (mode3d && !paused && !dragging && !reduceMotion && attachedKey != null) {
            camera = cameraApi.meshIdle(camera, dt, false);
            applyCamera();
        }
        updateFires(now);
        requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);

    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(renderWorld, 120);
    });

    function startTimers() {
        if (!pollTimer) {
            pollTimer = setInterval(() => {
                if (!paused && !document.hidden) {
                    load().catch((err) => {
                        if (copyEl) copyEl.textContent = err.message;
                    });
                }
            }, POLL_MS);
        }
        if (!presenceTimer) {
            presenceTimer = setInterval(pullLive, PRESENCE_MS);
        }
    }

    applyCamera();
    load().then(() => {
        startTimers();
        return pullLive();
    }).catch((err) => {
        if (copyEl) copyEl.textContent = err.message;
        startTimers();
    });
})();
