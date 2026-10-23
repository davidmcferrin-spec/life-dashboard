/**
 * JARVIS setup wizard. Speech is posted to the assistant route; this file does not implement it.
 */
'use strict';

(function () {
    var root = document.getElementById('jarvis-wizard');
    var stateNode = document.getElementById('jarvis-wizard-state');
    if (!root || !stateNode) {
        return;
    }

    var STEPS = [
        {
            title: 'Define how we work together.',
            lead: 'Set your identity and the way your assistant communicates.'
        },
        {
            title: 'Choose the voice you will trust.',
            lead: 'Choose a voice profile and send a short recording to speech.'
        },
        {
            title: 'Make intelligence proactive.',
            lead: 'Choose when JARVIS should prepare, optimize and assist.'
        },
        {
            title: 'You remain in control.',
            lead: 'Control how information is accessed, remembered and processed.'
        },
        {
            title: 'Ready for initialization.',
            lead: 'Review the protocol and bring your system online.'
        }
    ];

    var PRIVACY = {
        calendar_context: ['off', 'allowed'],
        microphone: ['off', 'while_active'],
        memory: ['off', 'review', 'allowed'],
        proactive: ['ask', 'allowed']
    };

    var PRIVACY_LABELS = {
        off: 'Off',
        allowed: 'Allowed',
        while_active: 'While active',
        review: 'Review first',
        ask: 'Ask permission'
    };

    var state = JSON.parse(stateNode.textContent);
    var step = 0;
    var dirty = !state.identity.saved;
    var recorder = null;
    var recordTimer = null;
    var chunks = [];

    var kicker = document.getElementById('jarvis-step-kicker');
    var title = document.getElementById('jarvis-title');
    var lead = document.getElementById('jarvis-lead');
    var quote = document.getElementById('jarvis-quote');
    var note = document.getElementById('jarvis-save-note');
    var nextLabel = document.getElementById('jarvis-next-label');
    var back = document.getElementById('jarvis-back');
    var next = document.getElementById('jarvis-next');
    var percent = document.getElementById('jarvis-percent');
    var percentBar = document.getElementById('jarvis-percent-bar');
    var progress = root.querySelector('.jarvis-progress-track');

    function csrf() {
        return window.getCsrfToken ? window.getCsrfToken() : '';
    }

    function toast(message, tone) {
        if (window.DashboardToast) {
            window.DashboardToast.show(message, tone || 'error');
        }
    }

    function greeting(hour, name, style) {
        var part = hour < 12 ? 'morning' : (hour < 17 ? 'afternoon' : 'evening');
        var tail = 'I will keep things analytical, clear and focused.';
        if (style === 'concise') {
            tail = 'I will keep it short.';
        } else if (style === 'conversational') {
            tail = 'I will keep things conversational.';
        }
        return 'Good ' + part + ', ' + name + '. ' + tail;
    }

    function selectedStyle() {
        var active = root.querySelector('[data-style].seg-btn-active');
        return active ? active.getAttribute('data-style') : state.identity.style;
    }

    function selectedVoice() {
        var active = root.querySelector('[data-voice].seg-btn-active');
        return active ? active.getAttribute('data-voice') : (state.voice.profile || 'atlas');
    }

    function savedFor(index) {
        if (index === 0) return state.identity.saved;
        if (index === 1) return state.voice.saved;
        if (index === 2) return state.routines_saved;
        if (index === 3) return state.privacy.saved;
        return state.initialize.initialized;
    }

    function showStep(nextStep) {
        step = nextStep;
        var copy = STEPS[step];
        kicker.textContent = 'Step 0' + (step + 1) + ' of 05';
        title.textContent = copy.title;
        lead.textContent = copy.lead;
        root.querySelectorAll('.jarvis-panel').forEach(function (panel) {
            panel.hidden = Number(panel.getAttribute('data-panel')) !== step;
        });
        root.querySelectorAll('.jarvis-step').forEach(function (button) {
            var index = Number(button.getAttribute('data-step'));
            button.classList.toggle('is-active', index === step);
            button.classList.toggle('is-complete', savedFor(index));
        });
        back.disabled = step === 0;
        nextLabel.textContent = step === 4 ? 'Initialize system' : 'Save & continue';
        dirty = !savedFor(step);
        note.textContent = dirty ? 'Unsaved changes' : 'Saved';
        var value = Math.round(((step + 1) / 5) * 100);
        percent.textContent = value + '%';
        percentBar.style.width = value + '%';
        if (progress) {
            progress.setAttribute('aria-valuenow', String(value));
        }
        renderQuote();
        if (step === 4) {
            renderChecks();
        }
        if (step === 2) {
            renderRoutines();
        }
    }

    function renderQuote() {
        var name = document.getElementById('jarvis-name').value.trim() || state.username;
        if (step === 0) {
            quote.textContent = greeting(state.hour, name, selectedStyle());
        } else if (step === 4 && state.initialize.ready) {
            quote.textContent = 'All systems are ready. Shall we begin?';
        } else if (state.identity.saved) {
            quote.textContent = 'Your preferences are being applied to the system.';
        } else {
            quote.textContent = 'Save your name to hear the preview.';
        }
        document.getElementById('jarvis-live-label').textContent = name ? 'Profile adapting' : 'No profile yet';
    }

    function renderChecks() {
        var box = document.getElementById('jarvis-diagnostics');
        var ready = document.getElementById('jarvis-ready');
        box.querySelectorAll('div:not(.jarvis-ready)').forEach(function (row, index) {
            var check = state.initialize.checks[index];
            if (!check) return;
            row.querySelector('span').textContent = check.label;
            row.querySelector('strong').textContent = check.value;
        });
        ready.hidden = !state.initialize.ready;
    }

    function renderRoutines() {
        state.routines.forEach(function (routine) {
            var row = root.querySelector('[data-routine="' + routine.key + '"]');
            if (!row) return;
            var toggle = row.querySelector('.jarvis-toggle');
            var summary = row.querySelector('.jarvis-run');
            var confirm = row.querySelector('.jarvis-confirm');
            toggle.setAttribute('aria-pressed', routine.enabled ? 'true' : 'false');
            summary.hidden = !routine.run_summary;
            summary.textContent = routine.run_summary || '';
            confirm.hidden = routine.run_status !== 'waiting';
        });
    }

    function applyState(nextState) {
        state = nextState;
        renderRoutines();
        renderPrivacy();
        renderChecks();
        dirty = false;
        note.textContent = 'Saved';
        root.querySelectorAll('.jarvis-step').forEach(function (button) {
            var index = Number(button.getAttribute('data-step'));
            button.classList.toggle('is-complete', savedFor(index));
        });
        renderQuote();
    }

    function renderPrivacy() {
        Object.keys(PRIVACY).forEach(function (key) {
            var button = root.querySelector('[data-privacy="' + key + '"] strong');
            if (button) {
                button.textContent = state.privacy[key + '_label'] || PRIVACY_LABELS[state.privacy[key]] || '';
            }
        });
        var listen = document.getElementById('jarvis-listen');
        var micOff = state.privacy.saved && state.privacy.microphone === 'off';
        listen.disabled = micOff;
    }

    function post(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': csrf()
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return { error: 'The wizard could not read the response.' };
            }).then(function (data) {
                if (!response.ok) {
                    var message = data && data.error ? data.error : 'The wizard could not save.';
                    throw new Error(message);
                }
                return data;
            });
        });
    }

    function payloadForStep() {
        if (step === 0) {
            return {
                url: '/api/jarvis/identity',
                body: {
                    display_name: document.getElementById('jarvis-name').value,
                    assistant_name: document.getElementById('jarvis-assistant').value,
                    style: selectedStyle()
                }
            };
        }
        if (step === 1) {
            return {
                url: '/api/jarvis/voice',
                body: { profile: selectedVoice() }
            };
        }
        if (step === 2) {
            var routines = {};
            root.querySelectorAll('[data-routine]').forEach(function (row) {
                routines[row.getAttribute('data-routine')] = row.querySelector('.jarvis-toggle').getAttribute('aria-pressed') === 'true';
            });
            return { url: '/api/jarvis/routines', body: { routines: routines } };
        }
        if (step === 3) {
            var privacy = {};
            root.querySelectorAll('[data-privacy]').forEach(function (button) {
                privacy[button.getAttribute('data-privacy')] = button.getAttribute('data-value') || state.privacy[button.getAttribute('data-privacy')];
            });
            return { url: '/api/jarvis/privacy', body: privacy };
        }
        return { url: '/api/jarvis/initialize', body: {} };
    }

    root.querySelectorAll('[data-style], [data-voice]').forEach(function (button) {
        button.addEventListener('click', function () {
            var group = button.parentElement;
            group.querySelectorAll('.seg-btn').forEach(function (peer) {
                peer.classList.remove('seg-btn-active');
            });
            button.classList.add('seg-btn-active');
            dirty = true;
            note.textContent = 'Unsaved changes';
            renderQuote();
        });
    });

    root.querySelectorAll('.jarvis-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var on = button.getAttribute('aria-pressed') !== 'true';
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            dirty = true;
            note.textContent = 'Unsaved changes';
        });
    });

    root.querySelectorAll('[data-privacy]').forEach(function (button) {
        var key = button.getAttribute('data-privacy');
        button.setAttribute('data-value', state.privacy[key]);
        button.addEventListener('click', function () {
            var options = PRIVACY[key];
            var current = button.getAttribute('data-value');
            var index = options.indexOf(current);
            var value = options[(index + 1) % options.length];
            button.setAttribute('data-value', value);
            button.querySelector('strong').textContent = PRIVACY_LABELS[value];
            dirty = true;
            note.textContent = 'Unsaved changes';
        });
    });

    root.querySelectorAll('.jarvis-step').forEach(function (button) {
        button.addEventListener('click', function () {
            showStep(Number(button.getAttribute('data-step')));
        });
    });

    document.getElementById('jarvis-name').addEventListener('input', function () {
        dirty = true;
        note.textContent = 'Unsaved changes';
        renderQuote();
    });
    document.getElementById('jarvis-assistant').addEventListener('input', function () {
        dirty = true;
        note.textContent = 'Unsaved changes';
    });

    back.addEventListener('click', function () {
        if (step > 0) showStep(step - 1);
    });

    next.addEventListener('click', function () {
        var request = payloadForStep();
        next.disabled = true;
        post(request.url, request.body).then(function (data) {
            applyState(data);
            if (step === 4 && data.initialize && data.initialize.initialized) {
                window.location.assign('/');
                return;
            }
            if (step < 4) showStep(step + 1);
        }).catch(function (error) {
            toast(error.message || 'The wizard could not save.');
        }).finally(function () {
            next.disabled = false;
        });
    });

    root.querySelectorAll('.jarvis-confirm').forEach(function (button) {
        button.addEventListener('click', function () {
            var key = button.closest('[data-routine]').getAttribute('data-routine');
            button.disabled = true;
            post('/api/jarvis/routines/confirm', { routine: key }).then(function (data) {
                applyState(data);
            }).catch(function (error) {
                toast(error.message || 'The routine could not run.');
            }).finally(function () {
                button.disabled = false;
            });
        });
    });

    function stopRecording() {
        if (recordTimer) {
            window.clearTimeout(recordTimer);
            recordTimer = null;
        }
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    }

    function sendClip(blob) {
        var status = document.getElementById('jarvis-voice-status');
        var body = new FormData();
        body.append('_csrf', csrf());
        body.append('file', blob, 'speech.webm');
        status.textContent = 'Sending';
        // Owned by the dashboard assistant. Call it even when that route is not on this branch.
        return fetch('/api/v1/speech/transcribe', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrf()
            },
            body: body
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (data) {
                var transcript = data.text || data.transcript || '';
                if (!response.ok || !transcript) {
                    var message = data.error && String(data.error).length < 180
                        ? data.error
                        : 'Speech service did not return a transcript.';
                    throw new Error(message);
                }
                document.getElementById('jarvis-transcript').textContent = transcript;
                status.textContent = 'Voice channel ready';
            });
        }).catch(function (error) {
            status.textContent = 'Voice channel ready';
            toast(error.message || 'Speech service did not return a transcript.');
        });
    }

    document.getElementById('jarvis-listen').addEventListener('click', function () {
        var micOff = state.privacy.saved && state.privacy.microphone === 'off';
        if (micOff) {
            toast('Microphone is off in Privacy.', 'warn');
            return;
        }
        if (recorder && recorder.state === 'recording') {
            stopRecording();
            return;
        }
        if (!navigator.mediaDevices || typeof MediaRecorder === 'undefined') {
            toast('This browser has no microphone.');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            chunks = [];
            recorder = new MediaRecorder(stream);
            recorder.addEventListener('dataavailable', function (event) {
                if (event.data && event.data.size > 0) chunks.push(event.data);
            });
            recorder.addEventListener('stop', function () {
                stream.getTracks().forEach(function (track) { track.stop(); });
                document.getElementById('jarvis-listen-label').textContent = 'Listen';
                document.getElementById('jarvis-mic').classList.remove('is-live');
                if (chunks.length) sendClip(new Blob(chunks, { type: recorder.mimeType || 'audio/webm' }));
            });
            recorder.start();
            document.getElementById('jarvis-listen-label').textContent = 'Stop';
            document.getElementById('jarvis-voice-status').textContent = 'Listening';
            document.getElementById('jarvis-mic').classList.add('is-live');
            recordTimer = window.setTimeout(stopRecording, 15000);
        }).catch(function (error) {
            var denied = error && error.name === 'NotAllowedError';
            toast(denied ? 'Microphone permission was denied.' : 'The microphone could not start.');
        });
    });

    renderPrivacy();
    showStep(0);
})();
