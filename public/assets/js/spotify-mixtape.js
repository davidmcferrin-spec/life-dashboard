/**
 * Mixtape builder. Plans seed tracks, then creates a playlist and adds them.
 * No locked rows and no regenerate-unlocked call: the plan API has neither.
 */
'use strict';

(function () {
    const STEPS = [
        ['Theme', 'Set the world for this mixtape.', 'Name the theme and describe the moment this mix should create.'],
        ['Prompt & arc', 'Shape the emotional journey.', 'Choose how energy should move from the first track to the last.'],
        ['Seeds', 'Give the plan a starting signal.', 'Add tracks you already know. The plan only reorders these.'],
        ['Rules', 'Set the musical guardrails.', 'Limit tempo and energy, drop explicit tracks, and allow a closed season when you mean to.'],
        ['Review', 'Review the proposed sequence.', 'Generate a plan, then create the playlist and add the ordered tracks.'],
    ];
    const SECTIONS = ['opener', 'build', 'peak', 'breather', 'closer'];
    const PRESETS = [
        ['warmup', 'Warm-up', { opener: 2, build: 4, peak: 6, breather: 5, closer: 4 }],
        ['peak', 'Peak', { opener: 3, build: 6, peak: 9, breather: 5, closer: 4 }],
        ['cooldown', 'Cool-down', { opener: 8, build: 6, peak: 5, breather: 4, closer: 2 }],
        ['steady', 'Steady', { opener: 5, build: 5, peak: 6, breather: 5, closer: 5 }],
        ['roller', 'Roller-coaster', { opener: 3, build: 8, peak: 4, breather: 9, closer: 3 }],
    ];

    function mount(panel, ctx) {
        const draft = {
            theme: '',
            prompt: '',
            moods: '',
            public: false,
            arc: Object.assign({}, PRESETS[1][2]),
            preset: 'peak',
            count: '',
            bpmMin: '',
            bpmMax: '',
            energyMin: '',
            energyMax: '',
            allowExplicit: false,
            exclude: '',
            allow: [],
            seedQuery: '',
        };
        let step = 0;
        let seeds = [];
        let hits = [];
        let plan = null;
        let busy = false;
        const seasons = ctx.seasons || [];
        const active = new Set(((ctx.active && ctx.active.seasons) || []).map((row) => row.season));

        function readForm() {
            const form = panel.querySelector('form');
            if (!form) return;
            const data = new FormData(form);
            if (form.elements.theme) draft.theme = String(data.get('theme') || '');
            if (form.elements.prompt) draft.prompt = String(data.get('prompt') || '');
            if (form.elements.moods) draft.moods = String(data.get('moods') || '');
            if (form.elements.public) draft.public = form.elements.public.checked;
            if (form.elements.count) draft.count = String(data.get('count') || '');
            if (form.elements.bpm_min) draft.bpmMin = String(data.get('bpm_min') || '');
            if (form.elements.bpm_max) draft.bpmMax = String(data.get('bpm_max') || '');
            if (form.elements.energy_min) draft.energyMin = String(data.get('energy_min') || '');
            if (form.elements.energy_max) draft.energyMax = String(data.get('energy_max') || '');
            if (form.elements.allow_explicit) draft.allowExplicit = form.elements.allow_explicit.checked;
            if (form.elements.exclude) draft.exclude = String(data.get('exclude') || '');
            if (form.elements.seed_q) draft.seedQuery = String(data.get('seed_q') || '');
            if (form.querySelector('input[name="allow"]')) {
                draft.allow = Array.from(form.querySelectorAll('input[name="allow"]:checked')).map((el) => el.value);
            }
            if (form.elements.arc_opener) {
                const next = {};
                SECTIONS.forEach((section) => {
                    next[section] = String(data.get('arc_' + section) || '');
                });
                draft.arc = next;
            }
        }

        function arcValues() {
            const out = {};
            for (let i = 0; i < SECTIONS.length; i++) {
                const section = SECTIONS[i];
                const value = Number(draft.arc[section]);
                if (!Number.isInteger(value) || value < 1 || value > 10) return null;
                out[section] = value;
            }
            return out;
        }

        function optionalNumber(value) {
            const text = String(value || '').trim();
            if (text === '') return null;
            const number = Number(text);
            return Number.isFinite(number) ? number : null;
        }

        function eligible() {
            const minBpm = optionalNumber(draft.bpmMin);
            const maxBpm = optionalNumber(draft.bpmMax);
            const minEnergy = optionalNumber(draft.energyMin);
            const maxEnergy = optionalNumber(draft.energyMax);
            const excluded = draft.exclude.toLowerCase().split(',').map((part) => part.trim()).filter(Boolean);
            let rows = seeds.filter((seed) => {
                if (!draft.allowExplicit && seed.explicit) return false;
                const blob = (seed.name + ' ' + (seed.artists || []).join(' ')).toLowerCase();
                if (excluded.some((word) => blob.includes(word))) return false;
                const meta = seed.meta || {};
                if (minBpm !== null || maxBpm !== null) {
                    if (meta.bpm === null || meta.bpm === undefined) return false;
                    if (minBpm !== null && Number(meta.bpm) < minBpm) return false;
                    if (maxBpm !== null && Number(meta.bpm) > maxBpm) return false;
                }
                if (minEnergy !== null || maxEnergy !== null) {
                    if (meta.energy === null || meta.energy === undefined) return false;
                    if (minEnergy !== null && Number(meta.energy) < minEnergy) return false;
                    if (maxEnergy !== null && Number(meta.energy) > maxEnergy) return false;
                }
                return true;
            });
            const cap = optionalNumber(draft.count);
            if (cap !== null && cap > 0) rows = rows.slice(0, cap);
            return rows;
        }

        function presetPath(arc) {
            return 'M' + SECTIONS.map((section, index) => {
                const energy = Number(arc[section]) || 1;
                const x = (index / 4) * 80;
                const y = 26 - ((Math.min(10, Math.max(1, energy)) - 1) / 9) * 22;
                return x.toFixed(1) + ' ' + y.toFixed(1);
            }).join(' L');
        }

        function seasonRange(row) {
            const start = row.start_anchor === 'thanksgiving'
                ? 'Thanksgiving'
                : ((row.start_month || '—') + '/' + (row.start_day || '—'));
            return start + ' – ' + row.end_month + '/' + row.end_day;
        }

        function guardCopy() {
            if (!seasons.length) return 'No season windows yet.';
            const open = seasons.filter((row) => active.has(row.season));
            if (!open.length) {
                return 'No season window is open today. A tagged track outside its window is refused unless you allow that season.';
            }
            return 'Season guard active. Open now: ' + open.map((row) => row.season + ' (' + seasonRange(row) + ')').join(', ') + '.';
        }

        function seedByUri(uri) {
            return seeds.find((seed) => seed.uri === uri) || null;
        }

        function render() {
            const arc = arcValues() || PRESETS[1][2];
            const info = STEPS[step];
            const fields = step === 0 ? themeFields()
                : step === 1 ? arcFields(arc)
                : step === 2 ? seedFields()
                : step === 3 ? ruleFields()
                : reviewFields();
            const primary = step === 4 ? 'Create in Spotify' : (step === 3 ? 'Generate plan' : 'Continue');
            panel.innerHTML = `
                <div class="music-builder">
                    <aside class="music-builder-steps">
                        <p class="music-kicker">Plan mixtape</p>
                        <h2>Mixtape Builder</h2>
                        ${STEPS.map((row, index) => `
                            <button type="button" class="${index === step ? 'is-on' : ''} ${index < step ? 'is-done' : ''}" data-step="${index}">
                                <span>${String(index + 1).padStart(2, '0')}</span>
                                ${ctx.esc(row[0])}
                            </button>`).join('')}
                        <div class="music-guard"><span>${ctx.esc(guardCopy())}</span></div>
                    </aside>
                    <form class="music-builder-form">
                        <p class="music-kicker">Step ${String(step + 1).padStart(2, '0')} of 05</p>
                        <h2>${ctx.esc(info[1])}</h2>
                        <p class="music-muted">${ctx.esc(info[2])}</p>
                        <div class="music-builder-fields">${fields}</div>
                        <footer>
                            <button type="button" class="btn" data-back ${step === 0 || busy ? 'disabled' : ''}>Back</button>
                            <button type="submit" class="btn-primary" ${busy ? 'disabled' : ''}>${busy ? 'Working…' : ctx.esc(primary)}</button>
                        </footer>
                    </form>
                    <aside class="music-builder-preview">
                        <p class="music-kicker">Sequence preview</p>
                        ${ctx.arc(step === 4 && plan ? (plan.order || []).map((row) => row.energy) : SECTIONS.map((section) => arc[section]))}
                        ${previewCopy()}
                    </aside>
                </div>`;
        }

        function themeFields() {
            return `
                <label class="music-field">Theme
                    <input name="theme" class="input" maxlength="100" required value="${ctx.esc(draft.theme)}">
                </label>
                <label class="music-field">Prompt
                    <textarea name="prompt" class="input" rows="5" maxlength="8000" required>${ctx.esc(draft.prompt)}</textarea>
                </label>
                <label class="music-field">Moods
                    <input name="moods" class="input" maxlength="200" value="${ctx.esc(draft.moods)}" placeholder="focused, nocturnal">
                </label>
                <label class="music-check"><input type="checkbox" name="public" ${draft.public ? 'checked' : ''}> Public on Spotify</label>`;
        }

        function arcFields(arc) {
            return `
                <div class="music-field"><span>Arc shape</span>
                    <div class="music-presets">${PRESETS.map((row) => `
                        <button type="button" class="music-preset ${draft.preset === row[0] ? 'is-on' : ''}" data-preset="${row[0]}">
                            <svg viewBox="0 0 80 26" aria-hidden="true"><path d="${presetPath(row[2])}"></path></svg>
                            <span>${ctx.esc(row[1])}</span>
                        </button>`).join('')}
                    </div>
                </div>
                <div class="music-split">${SECTIONS.map((section) => `
                    <label class="music-field">${ctx.esc(section)}
                        <input name="arc_${section}" class="input" inputmode="numeric" min="1" max="10" maxlength="2" value="${ctx.esc(arc[section])}">
                    </label>`).join('')}
                </div>
                <label class="music-field">Track count
                    <input name="count" class="input" inputmode="numeric" maxlength="3" value="${ctx.esc(draft.count)}" placeholder="All seeds">
                </label>`;
        }

        function seedFields() {
            const chips = seeds.map((seed, index) => `
                <span class="music-token">${ctx.esc(seed.name)}
                    <button type="button" data-seed-remove="${index}" aria-label="Remove ${ctx.esc(seed.name)}">${ctx.icon('x')}</button>
                </span>`).join('');
            const results = hits.map((hit) => `
                <li>
                    <span>${ctx.esc(hit.name)} <span class="music-muted">${ctx.esc((hit.artists || []).join(', '))}</span></span>
                    <button type="button" class="btn" data-seed-add="${ctx.esc(hit.uri)}">Add</button>
                </li>`).join('');
            return `
                <label class="music-field">Search tracks
                    <span class="music-search">
                        ${ctx.icon('search')}
                        <input name="seed_q" class="input" maxlength="200" value="${ctx.esc(draft.seedQuery)}" placeholder="Search Spotify">
                    </span>
                </label>
                <button type="button" class="btn" data-seed-search>Search</button>
                <div class="music-tokens">${chips || '<p class="music-muted">No seed tracks yet.</p>'}</div>
                ${results ? `<ul class="music-hit-list">${results}</ul>` : ''}`;
        }

        function ruleFields() {
            const boxes = seasons.map((row) => `
                <label class="music-check">
                    <input type="checkbox" name="allow" value="${ctx.esc(row.season)}" ${draft.allow.indexOf(row.season) !== -1 ? 'checked' : ''}>
                    Allow ${ctx.esc(row.season)} outside ${ctx.esc(seasonRange(row))}
                </label>`).join('');
            return `
                <div class="music-split">
                    <label class="music-field">BPM from
                        <input name="bpm_min" class="input" inputmode="numeric" maxlength="3" value="${ctx.esc(draft.bpmMin)}">
                    </label>
                    <label class="music-field">BPM to
                        <input name="bpm_max" class="input" inputmode="numeric" maxlength="3" value="${ctx.esc(draft.bpmMax)}">
                    </label>
                    <label class="music-field">Energy from
                        <input name="energy_min" class="input" inputmode="numeric" maxlength="2" value="${ctx.esc(draft.energyMin)}" placeholder="1">
                    </label>
                    <label class="music-field">Energy to
                        <input name="energy_max" class="input" inputmode="numeric" maxlength="2" value="${ctx.esc(draft.energyMax)}" placeholder="10">
                    </label>
                </div>
                <p class="music-muted">Key compatibility is always applied. A range drops seeds that have no stored note for that field.</p>
                <label class="music-check"><input type="checkbox" name="allow_explicit" ${draft.allowExplicit ? 'checked' : ''}> Allow explicit tracks</label>
                ${boxes}
                <label class="music-field">Excluded names
                    <input name="exclude" class="input" maxlength="300" value="${ctx.esc(draft.exclude)}" placeholder="artist or track, comma separated">
                </label>`;
        }

        function reviewFields() {
            if (!plan) {
                return '<p class="music-muted">Generate a plan from the rules step. Nothing is sent to Spotify until you create it.</p>';
            }
            const rough = {};
            (plan.rough || []).forEach((row) => { rough[row.to] = row.reasons || []; });
            const rows = (plan.order || []).map((row, index) => {
                const seed = seedByUri(row.uri);
                const name = seed ? seed.name : row.uri;
                const artists = seed ? (seed.artists || []).join(', ') : '';
                const reasons = (rough[row.uri] || []).map(reasonLabel).join(', ');
                return `
                    <div class="music-review-row">
                        <span>${String(index + 1).padStart(2, '0')}</span>
                        <span><strong>${ctx.esc(name)}</strong><small>${ctx.esc(artists)}${row.bpm ? ' · ' + ctx.esc(row.bpm) + ' BPM' : ''}${row.camelot ? ' · ' + ctx.esc(row.camelot) : ''}</small></span>
                        <span class="music-chip">${ctx.esc(row.section || '')}</span>
                        ${reasons ? `<span class="music-warn">${ctx.esc(reasons)}</span>` : ''}
                    </div>`;
            }).join('');
            const missing = (plan.missing || []).map((row) => {
                const seed = seedByUri(row.uri);
                return ctx.esc((seed ? seed.name : row.uri) + ' (' + (row.fields || []).join(', ') + ')');
            }).join(', ');
            return (rows || '<p class="music-muted">The plan has no ordered tracks. Add energy notes, then generate again.</p>')
                + (missing ? `<p class="music-muted">Left out or incomplete: ${missing}</p>` : '');
        }

        function reasonLabel(reason) {
            if (reason === 'bpm') return 'BPM jump';
            if (reason === 'key') return 'Key clash';
            if (reason === 'missing_bpm') return 'BPM missing';
            if (reason === 'missing_key') return 'Key missing';
            return reason;
        }

        function previewCopy() {
            const order = plan && step === 4 ? (plan.order || []) : [];
            if (!order.length) {
                return `<p class="music-muted">${seeds.length ? seeds.length + ' seed' + (seeds.length === 1 ? '' : 's') + ' ready.' : 'Add seeds to preview a sequence.'}</p>`;
            }
            const bpms = order.map((row) => row.bpm).filter((value) => value !== null && value !== undefined);
            const avg = bpms.length ? Math.round(bpms.reduce((sum, value) => sum + Number(value), 0) / bpms.length) : null;
            const gaps = Math.max(0, order.length - 1);
            const bad = (plan.rough || []).length;
            const quality = gaps ? Math.round(((gaps - bad) / gaps) * 100) : null;
            const ms = order.reduce((sum, row) => {
                const seed = seedByUri(row.uri);
                return sum + (seed ? Number(seed.duration_ms) || 0 : 0);
            }, 0);
            return `
                <dl class="music-preview-stats">
                    <div><dt>Tracks</dt><dd>${order.length}</dd></div>
                    <div><dt>Time</dt><dd>${ctx.esc(ctx.duration(ms))}</dd></div>
                    <div><dt>Avg BPM</dt><dd>${avg === null ? '—' : avg}</dd></div>
                    ${quality === null ? '' : `<div><dt>Clean transitions</dt><dd>${quality}%</dd></div>`}
                </dl>`;
        }

        async function addSeed(uri) {
            if (seeds.some((seed) => seed.uri === uri)) return;
            const hit = hits.find((row) => row.uri === uri);
            if (!hit) return;
            let meta = {};
            try {
                const res = await ctx.api.get('/spotify/tracks/meta?uris=' + encodeURIComponent(uri));
                meta = (res.tracks && res.tracks[0]) || {};
            } catch (err) {
                ctx.fail(err);
            }
            seeds.push({
                uri: hit.uri,
                name: hit.name,
                artists: hit.artists || [],
                album: hit.album || '',
                duration_ms: hit.duration_ms || 0,
                explicit: !!hit.explicit,
                image_url: hit.image_url || '',
                meta: meta,
            });
            plan = null;
        }

        async function searchSeeds() {
            const query = draft.seedQuery.trim();
            if (!query) {
                ctx.say('Enter a track or artist to search.');
                return;
            }
            const res = await ctx.api.get('/spotify/search?q=' + encodeURIComponent(query) + '&type=track');
            hits = res.tracks || [];
            if (!hits.length) ctx.say('No tracks matched.');
        }

        async function generate() {
            const arc = arcValues();
            if (!arc) {
                ctx.say('Each arc section needs an energy from 1 to 10.');
                step = 1;
                return;
            }
            const rows = eligible();
            if (!rows.length) {
                ctx.say('Add at least one seed track that passes the rules.');
                return;
            }
            const res = await ctx.api.post('/spotify/mixtape/plan', {
                uris: rows.map((row) => row.uri),
                arc: arc,
            });
            plan = res;
            step = 4;
        }

        async function create() {
            if (!plan || !(plan.order || []).length) {
                ctx.say('Generate a plan with at least one ordered track.');
                return;
            }
            const theme = draft.theme.trim();
            let prompt = draft.prompt.trim();
            if (!theme || !prompt) {
                ctx.say('A mixtape needs a theme and a prompt.');
                step = 0;
                return;
            }
            const moods = draft.moods.trim();
            if (moods) prompt += '\nMoods: ' + moods;
            const arc = arcValues();
            if (!arc) {
                ctx.say('Each arc section needs an energy from 1 to 10.');
                step = 1;
                return;
            }
            const created = await ctx.api.post('/spotify/playlists', {
                name: theme.slice(0, 100),
                description: prompt.slice(0, 300),
                public: draft.public,
                ai: true,
                prompt: prompt.slice(0, 8000),
            });
            const playlist = created.playlist;
            if (playlist.source_prompt_id) {
                await ctx.api.post('/spotify/prompts/' + playlist.source_prompt_id + '/update', {
                    name: theme.slice(0, 120),
                    prompt: prompt.slice(0, 8000),
                    theme: theme,
                    arc: arc,
                    allow_seasons: draft.allow.join(', '),
                    target_playlist_id: playlist.id,
                    active: true,
                });
            }
            const uris = (plan.order || []).map((row) => row.uri);
            const tracks = uris.map((uri) => {
                const seed = seedByUri(uri) || {};
                return {
                    uri: uri,
                    name: seed.name || '',
                    artists: seed.artists || [],
                    album: seed.album || '',
                    duration_ms: seed.duration_ms || 0,
                    explicit: !!seed.explicit,
                    image_url: seed.image_url || '',
                };
            });
            await ctx.api.post('/spotify/playlists/' + playlist.id + '/tracks', { uris: uris, tracks: tracks });
            let queued = false;
            try {
                const pushed = await ctx.api.post('/spotify/push', { playlist_id: playlist.id });
                queued = !!(pushed && pushed.queued);
            } catch (err) {
                ctx.fail(err);
                ctx.say('Playlist staged. Push did not finish, so it is not on Spotify yet.');
                ctx.onCreated(playlist.id);
                return;
            }
            ctx.ok(queued ? 'Playlist created. Push queued.' : 'Playlist created on Spotify.');
            ctx.onCreated(playlist.id);
        }

        panel.addEventListener('click', (event) => {
            const target = event.target.closest('button');
            if (!target || busy) return;
            readForm();
            if (target.dataset.step !== undefined) {
                step = Number(target.dataset.step);
                render();
            } else if (target.dataset.back !== undefined) {
                step = Math.max(0, step - 1);
                render();
            } else if (target.dataset.preset) {
                const chosen = PRESETS.find((row) => row[0] === target.dataset.preset);
                if (!chosen) return;
                draft.preset = chosen[0];
                draft.arc = Object.assign({}, chosen[2]);
                render();
            } else if (target.dataset.seedRemove !== undefined) {
                seeds.splice(Number(target.dataset.seedRemove), 1);
                plan = null;
                render();
            } else if (target.dataset.seedSearch !== undefined) {
                busy = true;
                render();
                searchSeeds().then(() => {
                    busy = false;
                    render();
                }).catch((err) => {
                    busy = false;
                    render();
                    ctx.fail(err);
                });
            } else if (target.dataset.seedAdd) {
                busy = true;
                addSeed(target.dataset.seedAdd).then(() => {
                    busy = false;
                    render();
                });
            }
        });

        panel.addEventListener('submit', (event) => {
            event.preventDefault();
            if (busy) return;
            readForm();
            if (step < 3) {
                if (step === 0 && (!draft.theme.trim() || !draft.prompt.trim())) {
                    ctx.say('Add a theme and a prompt before continuing.');
                    return;
                }
                step += 1;
                render();
                return;
            }
            busy = true;
            render();
            const job = step === 3 ? generate() : create();
            job.then(() => {
                busy = false;
                if (step !== 4 || !plan) render();
                else render();
            }).catch((err) => {
                busy = false;
                render();
                ctx.fail(err);
            });
        });

        panel.addEventListener('input', (event) => {
            if (event.target && event.target.name && String(event.target.name).indexOf('arc_') === 0) {
                draft.preset = 'custom';
                readForm();
            }
        });
        panel.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || step !== 2) return;
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || input.name !== 'seed_q') return;
            event.preventDefault();
            if (busy) return;
            readForm();
            busy = true;
            render();
            searchSeeds().then(() => {
                busy = false;
                render();
            }).catch((err) => {
                busy = false;
                render();
                ctx.fail(err);
            });
        });

        render();
    }

    window.SpotifyMixtape = { mount: mount };
})();
