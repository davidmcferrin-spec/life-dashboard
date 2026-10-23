/**
 * Mixtape builder. Plans seed tracks and artists, then creates a playlist.
 * Locks and swaps go through Mixtape::plan so a pinned row stays put.
 */
'use strict';

(function () {
    const STEPS = [
        ['Theme', 'Set the world for this mixtape.', 'Name the theme and describe the moment this mix should create.'],
        ['Prompt & arc', 'Shape the emotional journey.', 'Choose how energy should move from the first track to the last.'],
        ['Seeds', 'Give the plan a starting signal.', 'Add tracks or artists you already know. The plan orders tracks from those seeds.'],
        ['Rules', 'Set the musical guardrails.', 'Limit tempo and energy, drop explicit tracks, and allow a closed season when you mean to.'],
        ['Review', 'Review the proposed sequence.', 'Lock the tracks you want to keep, swap what misses, then create the playlist.'],
    ];
    const SECTIONS = ['opener', 'build', 'peak', 'breather', 'closer'];
    const MOODS = ['focused', 'nocturnal', 'expansive', 'kinetic', 'warm'];
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
            moods: [],
            public: false,
            arc: Object.assign({}, PRESETS[1][2]),
            preset: 'peak',
            count: '',
            length: '',
            bpmMin: '',
            bpmMax: '',
            energyMin: '',
            energyMax: '',
            allowExplicit: false,
            keyCompat: true,
            exclude: '',
            allow: [],
            seedQuery: '',
            artistQuery: '',
        };
        let step = 0;
        let seeds = [];
        let artists = [];
        let artistTracks = [];
        let hits = [];
        let artistHits = [];
        let plan = null;
        let busy = false;
        let saved = false;
        let locks = {};
        let lockPositions = {};
        let suggestion = null;
        let suggestionState = 'idle';
        const seasons = ctx.seasons || [];
        const active = new Set(((ctx.active && ctx.active.seasons) || []).map((row) => row.season));

        function readForm() {
            const form = panel.querySelector('form');
            if (!form) return;
            const data = new FormData(form);
            if (form.elements.theme) draft.theme = String(data.get('theme') || '');
            if (form.elements.prompt) draft.prompt = String(data.get('prompt') || '');
            if (form.elements.public) draft.public = form.elements.public.checked;
            if (form.elements.count) draft.count = String(data.get('count') || '');
            if (form.elements.length) draft.length = String(data.get('length') || '');
            if (form.elements.bpm_min) draft.bpmMin = String(data.get('bpm_min') || '');
            if (form.elements.bpm_max) draft.bpmMax = String(data.get('bpm_max') || '');
            if (form.elements.energy_min) draft.energyMin = String(data.get('energy_min') || '');
            if (form.elements.energy_max) draft.energyMax = String(data.get('energy_max') || '');
            if (form.elements.allow_explicit) draft.allowExplicit = form.elements.allow_explicit.checked;
            if (form.elements.exclude) draft.exclude = String(data.get('exclude') || '');
            if (form.elements.seed_q) draft.seedQuery = String(data.get('seed_q') || '');
            if (form.elements.artist_q) draft.artistQuery = String(data.get('artist_q') || '');
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

        function targetMs() {
            const text = draft.length.trim().toLowerCase();
            if (text === '') return null;
            const hours = text.match(/(\d+)\s*h/);
            const minutes = text.match(/(\d+)\s*m/);
            let total = 0;
            if (hours || minutes) {
                total = ((hours ? Number(hours[1]) : 0) * 60) + (minutes ? Number(minutes[1]) : 0);
            } else if (/^\d+$/.test(text)) {
                total = Number(text);
            }
            return total > 0 ? total * 60000 : null;
        }

        function pool() {
            return seeds.concat(artistTracks);
        }

        function eligible() {
            const minBpm = optionalNumber(draft.bpmMin);
            const maxBpm = optionalNumber(draft.bpmMax);
            const minEnergy = optionalNumber(draft.energyMin);
            const maxEnergy = optionalNumber(draft.energyMax);
            const excluded = draft.exclude.toLowerCase().split(',').map((part) => part.trim()).filter(Boolean);
            let rows = pool().filter((seed) => {
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
            return pool().find((seed) => seed.uri === uri) || null;
        }

        function lockPayload() {
            return Object.keys(locks).map((uri) => ({
                uri: uri,
                position: lockPositions[uri],
            })).filter((row) => Number.isInteger(row.position) && row.position >= 0);
        }

        function moodLabel(tag) {
            const text = String(tag || '');
            return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
        }

        function moodChoices() {
            const set = MOODS.slice();
            pool().forEach((seed) => {
                ((seed.meta && seed.meta.mood_tags) || []).forEach((tag) => {
                    if (set.indexOf(tag) === -1) set.push(tag);
                });
            });
            return set;
        }

        function render() {
            const startSuggest = step === 2 && suggestionState === 'idle';
            if (startSuggest) suggestionState = 'loading';
            const arc = arcValues() || PRESETS[1][2];
            const info = STEPS[step];
            const fields = step === 0 ? themeFields()
                : step === 1 ? arcFields(arc)
                : step === 2 ? seedFields()
                : step === 3 ? ruleFields()
                : reviewFields();
            const primary = step === 4 ? 'Create in Spotify' : (step === 3 ? 'Generate plan' : 'Continue');
            const regenerate = step === 4
                ? `<button type="button" class="btn" data-regenerate ${!plan || busy ? 'disabled' : ''}>${ctx.icon('refresh-cw')} Regenerate unlocked</button>`
                : '';
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
                            <span class="music-saved">${saved ? 'Saved automatically' : ''}</span>
                            <span class="music-footer-actions">
                                ${regenerate}
                                <button type="submit" class="btn-primary" ${busy ? 'disabled' : ''}>${busy ? 'Working…' : ctx.esc(primary)}</button>
                            </span>
                        </footer>
                    </form>
                    <aside class="music-builder-preview">
                        <p class="music-kicker">Sequence preview</p>
                        ${ctx.arc(step === 4 && plan ? (plan.order || []).map((row) => row.energy) : SECTIONS.map((section) => arc[section]))}
                        ${previewCopy()}
                    </aside>
                </div>`;
            if (startSuggest) {
                loadSuggestion().then(() => {
                    suggestionState = 'done';
                    if (step === 2) render();
                });
            }
        }

        function themeFields() {
            const chips = moodChoices().map((mood) => `
                <button type="button" class="music-mood${draft.moods.indexOf(mood) !== -1 ? ' is-on' : ''}" data-mood="${ctx.esc(mood)}">${ctx.esc(moodLabel(mood))}</button>`).join('');
            return `
                <label class="music-field">Theme
                    <input name="theme" class="input" maxlength="100" required value="${ctx.esc(draft.theme)}">
                </label>
                <label class="music-field">Prompt
                    <textarea name="prompt" class="input" rows="5" maxlength="8000" required>${ctx.esc(draft.prompt)}</textarea>
                </label>
                <div class="music-field"><span>Moods</span>
                    <div class="music-moods">${chips}</div>
                </div>
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
                <div class="music-split">
                    <label class="music-field">Track count
                        <input name="count" class="input" inputmode="numeric" maxlength="3" value="${ctx.esc(draft.count)}" placeholder="All seeds">
                    </label>
                    <label class="music-field">Target length
                        <input name="length" class="input" maxlength="16" value="${ctx.esc(draft.length)}" placeholder="1h 30m">
                    </label>
                </div>`;
        }

        function seedFields() {
            const chips = seeds.map((seed, index) => `
                <span class="music-token">${ctx.esc(seed.name)}
                    <button type="button" data-seed-remove="${index}" aria-label="Remove ${ctx.esc(seed.name)}">${ctx.icon('x')}</button>
                </span>`).join('');
            const artistChips = artists.map((artist, index) => `
                <span class="music-token">${ctx.esc(artist.name)}
                    <button type="button" data-artist-remove="${index}" aria-label="Remove ${ctx.esc(artist.name)}">${ctx.icon('x')}</button>
                </span>`).join('');
            const results = hits.map((hit) => `
                <li>
                    <span>${ctx.esc(hit.name)} <span class="music-muted">${ctx.esc((hit.artists || []).join(', '))}</span></span>
                    <button type="button" class="btn" data-seed-add="${ctx.esc(hit.uri)}">Add</button>
                </li>`).join('');
            const artistResults = artistHits.map((hit) => `
                <li>
                    <span>${ctx.esc(hit.name)}</span>
                    <button type="button" class="btn" data-artist-add="${ctx.esc(hit.id)}">Add</button>
                </li>`).join('');
            const suggest = suggestionVisible()
                ? `<div class="music-suggest">${ctx.icon('sparkles')}<span><strong>Music Director suggestion</strong><p>${ctx.esc(suggestion.text)}</p></span><button type="button" class="btn" data-suggest>Add suggestion</button></div>`
                : (suggestionState === 'loading' ? '<p class="music-muted">Checking your artists and Liked Songs.</p>' : '');
            return `
                <label class="music-field">Seed tracks
                    <span class="music-search">
                        ${ctx.icon('search')}
                        <input name="seed_q" class="input" maxlength="200" value="${ctx.esc(draft.seedQuery)}" placeholder="Search tracks">
                    </span>
                </label>
                <button type="button" class="btn" data-seed-search>Search</button>
                <div class="music-tokens">${chips || '<p class="music-muted">No seed tracks yet.</p>'}</div>
                ${results ? `<ul class="music-hit-list">${results}</ul>` : ''}
                <label class="music-field">Seed artists
                    <span class="music-search">
                        ${ctx.icon('search')}
                        <input name="artist_q" class="input" maxlength="200" value="${ctx.esc(draft.artistQuery)}" placeholder="Search artists">
                    </span>
                </label>
                <button type="button" class="btn" data-artist-search>Search</button>
                <div class="music-tokens">${artistChips || '<p class="music-muted">No seed artists yet.</p>'}</div>
                ${artistResults ? `<ul class="music-hit-list">${artistResults}</ul>` : ''}
                ${suggest}`;
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
                <div class="music-setting">
                    <span><strong>Key compatibility</strong><small>Avoid harsh Camelot wheel transitions.</small></span>
                    <button type="button" class="music-toggle${draft.keyCompat ? ' is-on' : ''}" data-key-toggle aria-pressed="${draft.keyCompat ? 'true' : 'false'}" aria-label="Key compatibility"><i></i></button>
                </div>
                <p class="music-muted">A range drops seeds that have no stored note for that field.</p>
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
            const rows = (plan.order || []).map((row, index) => {
                const seed = seedByUri(row.uri);
                const name = seed ? seed.name : row.uri;
                const artistsText = seed ? (seed.artists || []).join(', ') : '';
                const mood = seed && seed.meta && seed.meta.mood_tags && seed.meta.mood_tags[0];
                const locked = !!locks[row.uri];
                return `
                    <div class="music-review-row">
                        <span>${String(index + 1).padStart(2, '0')}</span>
                        <span><strong>${ctx.esc(name)}</strong><small>${ctx.esc(artistsText)}${row.bpm ? ' · ' + ctx.esc(row.bpm) + ' BPM' : ''}${row.camelot ? ' · ' + ctx.esc(row.camelot) : ''}</small></span>
                        ${mood ? `<span class="music-chip music-review-mood">${ctx.esc(moodLabel(mood))}</span>` : '<span class="music-review-mood"></span>'}
                        <button type="button" class="music-icon-btn" data-swap="${ctx.esc(row.uri)}" ${locked || busy ? 'disabled' : ''} aria-label="Swap track">${ctx.icon('refresh-cw')}</button>
                        <button type="button" class="music-icon-btn${locked ? ' is-on' : ''}" data-lock="${ctx.esc(row.uri)}" data-pos="${index}" aria-pressed="${locked ? 'true' : 'false'}" aria-label="${locked ? 'Unlock track' : 'Lock track'}">${ctx.icon(locked ? 'lock-keyhole' : 'archive')}</button>
                    </div>`;
            }).join('');
            const missing = (plan.missing || []).map((row) => {
                const seed = seedByUri(row.uri);
                return ctx.esc((seed ? seed.name : row.uri) + ' (' + (row.fields || []).join(', ') + ')');
            }).join(', ');
            return (rows || '<p class="music-muted">The plan has no ordered tracks. Add energy notes, then generate again.</p>')
                + (missing ? `<p class="music-muted">Left out or incomplete: ${missing}</p>` : '');
        }

        function transitionChip(prev, row) {
            if (!prev) return '';
            const rough = {};
            (plan.rough || []).forEach((item) => { rough[item.to] = item.reasons || []; });
            const reasons = rough[row.uri] || [];
            let text = '';
            let warn = false;
            if (reasons.indexOf('key') !== -1) {
                text = 'Key clash';
                warn = true;
            } else if (reasons.indexOf('missing_key') !== -1) {
                text = 'Key missing';
                warn = true;
            } else if (prev.bpm !== null && prev.bpm !== undefined && row.bpm !== null && row.bpm !== undefined) {
                const delta = Math.round(Number(row.bpm) - Number(prev.bpm));
                text = (delta > 0 ? '+' : '') + delta + ' BPM';
                warn = reasons.indexOf('bpm') !== -1;
            } else if (reasons.indexOf('missing_bpm') !== -1 || reasons.indexOf('bpm') !== -1) {
                text = 'BPM missing';
                warn = true;
            }
            if (!text) return '';
            return `<i class="music-trans${warn ? ' is-warn' : ''}">${ctx.esc(text)}</i>`;
        }

        function previewCopy() {
            const order = plan && step === 4 ? (plan.order || []) : [];
            if (!order.length) {
                const count = pool().length;
                return `<p class="music-muted">${count ? count + ' seed' + (count === 1 ? '' : 's') + ' ready.' : 'Add seeds to preview a sequence.'}</p>`;
            }
            const bpms = order.map((row) => row.bpm).filter((value) => value !== null && value !== undefined);
            const avg = bpms.length ? Math.round(bpms.reduce((sum, value) => sum + Number(value), 0) / bpms.length) : null;
            const gaps = Math.max(0, order.length - 1);
            const bad = (plan.rough || []).length;
            const quality = gaps ? Math.round(((gaps - bad) / gaps) * 100) : null;
            const ms = order.reduce((sum, row) => {
                const seed = seedByUri(row.uri);
                const fromSeed = seed ? Number(seed.duration_ms) || 0 : 0;
                return sum + (fromSeed || Number(row.duration_ms) || 0);
            }, 0);
            const rows = order.map((row, index) => {
                const seed = seedByUri(row.uri);
                const name = seed ? seed.name : row.uri;
                return `
                    <div class="music-preview-row">
                        <span>${String(index + 1).padStart(2, '0')}</span>
                        <span><strong>${ctx.esc(name)}</strong><small>${row.bpm ? ctx.esc(row.bpm) + ' BPM' : 'BPM unknown'}${row.camelot ? ' · ' + ctx.esc(row.camelot) : ''}</small></span>
                        ${transitionChip(index ? order[index - 1] : null, row)}
                    </div>`;
            }).join('');
            const bar = quality === null ? '' : `
                <div class="music-quality">
                    <span>${ctx.icon('activity')} Transition quality <strong>${quality}%</strong></span>
                    <div class="music-quality-bar"><i style="width: ${quality}%"></i></div>
                </div>`;
            return `
                <dl class="music-preview-stats">
                    <div><dt>Tracks</dt><dd>${order.length}</dd></div>
                    <div><dt>Time</dt><dd>${ctx.esc(ctx.duration(ms))}</dd></div>
                    <div><dt>Avg BPM</dt><dd>${avg === null ? '—' : avg}</dd></div>
                </dl>
                <div class="music-preview-list">${rows}</div>
                ${bar}`;
        }

        function suggestionVisible() {
            if (!suggestion) return false;
            const name = suggestion.name.toLowerCase();
            if (artists.some((artist) => artist.name.toLowerCase() === name)) return false;
            return !seeds.some((seed) => (seed.artists || []).some((item) => String(item).toLowerCase() === name));
        }

        function planBody(rows, overrides) {
            const durations = {};
            rows.forEach((row) => { durations[row.uri] = Number(row.duration_ms) || 0; });
            const body = {
                uris: rows.map((row) => row.uri),
                arc: arcValues(),
                key_compat: !!draft.keyCompat,
                durations: durations,
                locks: lockPayload(),
            };
            const target = targetMs();
            if (target) body.target_ms = target;
            return Object.assign(body, overrides || {});
        }

        function rememberLockPositions() {
            const order = (plan && plan.order) || [];
            order.forEach((row, index) => {
                if (locks[row.uri]) lockPositions[row.uri] = index;
            });
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
                ctx.say('Enter a track to search.');
                return;
            }
            const res = await ctx.api.get('/spotify/search?q=' + encodeURIComponent(query) + '&type=track');
            hits = res.tracks || [];
            if (!hits.length) ctx.say('No tracks matched.');
        }

        async function searchArtists() {
            const query = draft.artistQuery.trim();
            if (!query) {
                ctx.say('Enter an artist to search.');
                return;
            }
            const res = await ctx.api.get('/spotify/search?q=' + encodeURIComponent(query) + '&type=artist');
            artistHits = res.artists || [];
            if (!artistHits.length) ctx.say('No artists matched.');
        }

        function addArtist(hit) {
            if (!hit || artists.some((artist) => artist.id === hit.id)) return;
            artists.push({ id: hit.id, uri: hit.uri || ('spotify:artist:' + hit.id), name: hit.name });
            plan = null;
        }

        async function expandArtists() {
            const found = [];
            const seen = {};
            seeds.forEach((seed) => { seen[seed.uri] = true; });
            for (let i = 0; i < artists.length; i++) {
                const name = String(artists[i].name || '').replace(/"/g, '');
                if (!name) continue;
                const res = await ctx.api.get('/spotify/search?q=' + encodeURIComponent('artist:"' + name + '"') + '&type=track');
                // Search returns at most 10. Five keeps one artist from filling the plan.
                const tracks = (res.tracks || []).slice(0, 5);
                for (let j = 0; j < tracks.length; j++) {
                    const hit = tracks[j];
                    if (!hit.uri || seen[hit.uri]) continue;
                    seen[hit.uri] = true;
                    let meta = {};
                    try {
                        const metaRes = await ctx.api.get('/spotify/tracks/meta?uris=' + encodeURIComponent(hit.uri));
                        meta = (metaRes.tracks && metaRes.tracks[0]) || {};
                    } catch (err) {
                        ctx.fail(err);
                    }
                    found.push({
                        uri: hit.uri,
                        name: hit.name,
                        artists: hit.artists || [],
                        album: hit.album || '',
                        duration_ms: hit.duration_ms || 0,
                        explicit: !!hit.explicit,
                        image_url: hit.image_url || '',
                        meta: meta,
                    });
                }
            }
            artistTracks = found;
        }

        async function resolveArtist(name, tops) {
            const top = tops.find((row) => String(row.name).toLowerCase() === name.toLowerCase());
            if (top) return { id: top.spotify_id, uri: 'spotify:artist:' + top.spotify_id, name: top.name };
            try {
                const res = await ctx.api.get('/spotify/search?q=' + encodeURIComponent(name) + '&type=artist');
                const hit = (res.artists || []).find((artist) => String(artist.name).toLowerCase() === name.toLowerCase());
                if (!hit) return null;
                return { id: hit.id, uri: hit.uri || ('spotify:artist:' + hit.id), name: hit.name };
            } catch (err) {
                ctx.fail(err);
                return null;
            }
        }

        async function loadSuggestion() {
            let tops = [];
            let saved = [];
            try {
                const topRes = await ctx.api.get('/spotify/top?kind=artist&time_range=medium_term');
                tops = (topRes.items || []).filter((row) => row.kind === 'artist' && row.name && row.spotify_id);
            } catch (err) {
                ctx.fail(err);
            }
            try {
                const savedRes = await ctx.api.get('/spotify/saved?limit=50');
                saved = (savedRes.saved || []).map((row) => row.track).filter((track) => track && track.uri);
            } catch (err) {
                ctx.fail(err);
            }
            const metaByUri = {};
            if (saved.length) {
                try {
                    const metaRes = await ctx.api.get('/spotify/tracks/meta?uris=' + encodeURIComponent(saved.map((track) => track.uri).join(',')));
                    (metaRes.tracks || []).forEach((meta) => { metaByUri[meta.uri] = meta; });
                } catch (err) {
                    ctx.fail(err);
                }
            }
            const used = {};
            artists.forEach((artist) => { used[artist.name.toLowerCase()] = true; });
            seeds.forEach((seed) => {
                (seed.artists || []).forEach((name) => { used[String(name).toLowerCase()] = true; });
            });
            let best = null;
            saved.forEach((track) => {
                const meta = metaByUri[track.uri];
                const energy = meta && meta.energy !== null && meta.energy !== undefined ? Number(meta.energy) : null;
                const name = (track.artists && track.artists[0]) || '';
                if (!name || energy === null || !Number.isFinite(energy) || used[name.toLowerCase()]) return;
                if (!best || energy < best.energy) best = { name: name, energy: energy, title: track.name };
            });
            if (best) {
                const resolved = await resolveArtist(best.name, tops);
                if (resolved) {
                    suggestion = {
                        id: resolved.id,
                        uri: resolved.uri,
                        name: resolved.name,
                        text: 'Add ' + resolved.name + '. ' + best.title + ' is the lowest stored energy in Liked Songs (' + best.energy + ').',
                    };
                    return;
                }
            }
            const ranked = tops.slice().sort((a, b) => Number(a.rank) - Number(b.rank));
            for (let i = 0; i < ranked.length; i++) {
                const row = ranked[i];
                if (used[String(row.name).toLowerCase()]) continue;
                suggestion = {
                    id: row.spotify_id,
                    uri: 'spotify:artist:' + row.spotify_id,
                    name: row.name,
                    text: 'Add ' + row.name + '. They rank ' + row.rank + ' among your artists and are not a seed yet.',
                };
                return;
            }
            suggestion = null;
        }

        async function persistLocks() {
            try {
                const res = await ctx.api.post('/spotify/mixtape/locks', { locks: lockPayload() });
                saved = !!(res && res.saved);
            } catch (err) {
                saved = false;
                ctx.fail(err);
            }
        }

        async function loadLocks() {
            try {
                const res = await ctx.api.get('/spotify/mixtape/locks');
                locks = {};
                lockPositions = {};
                (res.locks || []).forEach((row) => {
                    if (!row.uri) return;
                    locks[row.uri] = true;
                    lockPositions[row.uri] = Number(row.position);
                });
                saved = true;
            } catch (err) {
                saved = false;
                ctx.fail(err);
            }
        }

        async function generate(overrides) {
            const arc = arcValues();
            if (!arc) {
                ctx.say('Each arc section needs an energy from 1 to 10.');
                step = 1;
                return;
            }
            await expandArtists();
            const rows = eligible();
            if (!rows.length) {
                ctx.say('Add at least one seed track that passes the rules.');
                return;
            }
            const res = await ctx.api.post('/spotify/mixtape/plan', planBody(rows, overrides));
            plan = res;
            rememberLockPositions();
            await persistLocks();
            step = 4;
        }

        async function swapTrack(uri) {
            const order = (plan && plan.order) || [];
            const index = order.findIndex((row) => row.uri === uri);
            if (index < 0 || locks[uri]) return;
            const arc = arcValues();
            if (!arc) {
                ctx.say('Each arc section needs an energy from 1 to 10.');
                step = 1;
                return;
            }
            await expandArtists();
            const rows = eligible();
            const temporary = order.map((row, position) => ({ uri: row.uri, position: position }))
                .filter((row) => row.uri !== uri);
            const res = await ctx.api.post('/spotify/mixtape/plan', planBody(rows, {
                locks: temporary,
                avoid: [uri],
                slots: order.length,
            }));
            const next = (res.order || [])[index];
            plan = res;
            if (!next || next.uri === uri) ctx.say('No other seed fits this slot.');
            rememberLockPositions();
            await persistLocks();
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
            if (draft.moods.length) prompt += '\nMoods: ' + draft.moods.join(', ');
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
            } else if (target.dataset.mood) {
                const mood = target.dataset.mood;
                const index = draft.moods.indexOf(mood);
                if (index === -1) draft.moods.push(mood);
                else draft.moods.splice(index, 1);
                render();
            } else if (target.dataset.keyToggle !== undefined) {
                draft.keyCompat = !draft.keyCompat;
                render();
            } else if (target.dataset.seedRemove !== undefined) {
                seeds.splice(Number(target.dataset.seedRemove), 1);
                plan = null;
                render();
            } else if (target.dataset.artistRemove !== undefined) {
                artists.splice(Number(target.dataset.artistRemove), 1);
                artistTracks = [];
                plan = null;
                render();
            } else if (target.dataset.suggest !== undefined && suggestion) {
                addArtist(suggestion);
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
            } else if (target.dataset.artistSearch !== undefined) {
                busy = true;
                render();
                searchArtists().then(() => {
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
            } else if (target.dataset.artistAdd) {
                const hit = artistHits.find((row) => row.id === target.dataset.artistAdd);
                addArtist(hit);
                render();
            } else if (target.dataset.lock) {
                const uri = target.dataset.lock;
                if (locks[uri]) {
                    delete locks[uri];
                    delete lockPositions[uri];
                } else {
                    locks[uri] = true;
                    lockPositions[uri] = Number(target.dataset.pos);
                }
                busy = true;
                render();
                persistLocks().then(() => {
                    busy = false;
                    render();
                });
            } else if (target.dataset.swap) {
                busy = true;
                render();
                swapTrack(target.dataset.swap).then(() => {
                    busy = false;
                    render();
                }).catch((err) => {
                    busy = false;
                    render();
                    ctx.fail(err);
                });
            } else if (target.dataset.regenerate !== undefined) {
                busy = true;
                render();
                generate().then(() => {
                    busy = false;
                    render();
                }).catch((err) => {
                    busy = false;
                    render();
                    ctx.fail(err);
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
                render();
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
            if (!(input instanceof HTMLInputElement)) return;
            if (input.name !== 'seed_q' && input.name !== 'artist_q') return;
            event.preventDefault();
            if (busy) return;
            readForm();
            busy = true;
            render();
            const job = input.name === 'artist_q' ? searchArtists() : searchSeeds();
            job.then(() => {
                busy = false;
                render();
            }).catch((err) => {
                busy = false;
                render();
                ctx.fail(err);
            });
        });

        render();
        loadLocks().then(() => {
            readForm();
            if (!busy) render();
        });
    }

    window.SpotifyMixtape = { mount: mount };
})();
