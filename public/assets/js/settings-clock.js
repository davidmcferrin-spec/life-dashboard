/**
 * settings-clock.js — focus clock settings helpers
 */
'use strict';

function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function initScaleSlider() {
    const slider = document.getElementById('focus-clock-scale');
    const label  = document.getElementById('focus-clock-scale-value');
    if (!slider || !label) return;
    slider.addEventListener('input', () => {
        label.textContent = slider.value + '%';
    });
}

function initFontColonToggle() {
    const colonWrap  = document.getElementById('focus-clock-colon-wrap');
    if (!colonWrap) return;

    function update() {
        const font = document.querySelector('input[name="focus_clock_font"]:checked')?.value
            || document.querySelector('select[name="focus_clock_font"]')?.value;
        colonWrap.classList.toggle('hidden', font !== 'dseg7');
    }

    document.querySelectorAll('input[name="focus_clock_font"]').forEach((el) => {
        el.addEventListener('change', update);
    });
    update();
}

let alarmSoundCatalog = [];

function getSelectedAlarmSound() {
    return document.getElementById('focus-clock-alarm-sound')?.value || 'builtin';
}

function updateAlarmDeleteButton() {
    const btn = document.getElementById('focus-clock-alarm-delete');
    if (!btn) return;

    const sound = getSelectedAlarmSound();
    const show = sound === 'mine' || sound.startsWith('library:');
    btn.classList.toggle('hidden', !show);
    btn.textContent = sound === 'mine' ? 'Remove my personal sound' : 'Remove from shared library';
}

function populateAlarmSoundSelect(sounds, selected) {
    const select = document.getElementById('focus-clock-alarm-sound');
    if (!select) return;

    select.innerHTML = '';
    sounds.forEach((s) => {
        const opt = document.createElement('option');
        opt.value = s.id;
        let label = s.label;
        if (s.scope === 'shared' && s.uploaded_by) {
            label += ` (${s.uploaded_by})`;
        }
        opt.textContent = label;
        select.appendChild(opt);
    });

    const hasSelected = sounds.some((s) => s.id === selected);
    select.value = hasSelected ? selected : 'builtin';
    updateAlarmDeleteButton();
}

async function loadAlarmSounds() {
    const select = document.getElementById('focus-clock-alarm-sound');
    if (!select) return;

    const initial = select.dataset.initial || 'builtin';

    try {
        const res = await fetch('/api/clock/alarm-sounds', { credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        alarmSoundCatalog = data.sounds || [];
        populateAlarmSoundSelect(alarmSoundCatalog, initial);
    } catch (_) {}
}

async function uploadAlarmSound() {
    const fileInput = document.getElementById('focus-clock-alarm-file');
    const status    = document.getElementById('focus-clock-alarm-upload-status');
    if (!fileInput?.files?.length) {
        if (status) status.textContent = 'Choose a file first.';
        return;
    }

    const scope = document.querySelector('input[name="focus_clock_alarm_upload_scope"]:checked')?.value || 'shared';
    const fd = new FormData();
    fd.append('alarm_sound', fileInput.files[0]);
    fd.append('scope', scope);
    fd.append('_csrf', getCsrf());

    if (status) status.textContent = 'Uploading…';

    try {
        const res = await fetch('/api/clock/alarm-sound', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': getCsrf() },
        });
        const data = await res.json();
        if (!res.ok) {
            if (status) status.textContent = data.error || 'Upload failed.';
            return;
        }
        if (status) {
            status.textContent = scope === 'mine'
                ? 'Personal sound saved.'
                : `Added to shared library: ${data.label || 'sound'}`;
        }
        fileInput.value = '';
        await loadAlarmSounds();
        if (data.id) {
            const select = document.getElementById('focus-clock-alarm-sound');
            if (select) select.value = data.id;
        }
        updateAlarmDeleteButton();
        if (window.FocusClockAlarm?.reloadPrefs) {
            window.FocusClockAlarm.reloadPrefs();
        }
    } catch (_) {
        if (status) status.textContent = 'Upload failed.';
    }
}

function initAlarmUpload() {
    document.getElementById('focus-clock-alarm-upload-btn')?.addEventListener('click', uploadAlarmSound);
}

async function deleteSelectedAlarmSound() {
    const status = document.getElementById('focus-clock-alarm-upload-status');
    const sound = getSelectedAlarmSound();

    if (sound === 'builtin') {
        if (status) status.textContent = 'Built-in tone cannot be deleted.';
        return;
    }

    const scope = sound === 'mine' ? 'mine' : 'shared';
    const body = new URLSearchParams();
    body.set('scope', scope);
    body.set('sound_id', sound);
    body.set('_csrf', getCsrf());

    try {
        const res = await fetch('/api/clock/alarm-sound/delete', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': getCsrf(),
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body.toString(),
        });
        const data = await res.json();
        if (!res.ok) {
            if (status) status.textContent = data.error || 'Delete failed.';
            return;
        }
        if (status) status.textContent = 'Sound removed.';
        await loadAlarmSounds();
        const select = document.getElementById('focus-clock-alarm-sound');
        if (select) select.value = 'builtin';
        updateAlarmDeleteButton();
        if (window.FocusClockAlarm?.reloadPrefs) {
            window.FocusClockAlarm.reloadPrefs();
        }
    } catch (_) {
        if (status) status.textContent = 'Delete failed.';
    }
}

function initAlarmSounds() {
    const select = document.getElementById('focus-clock-alarm-sound');
    if (!select) return;

    loadAlarmSounds();

    select.addEventListener('change', updateAlarmDeleteButton);

    document.getElementById('focus-clock-alarm-preview')?.addEventListener('click', () => {
        window.FocusClockAlarm?.previewSound(getSelectedAlarmSound());
    });

    document.getElementById('focus-clock-alarm-delete')?.addEventListener('click', async (e) => {
        e.preventDefault();
        const btn = e.currentTarget;
        if (btn.dataset.confirming === '1') return;
        btn.dataset.confirming = '1';
        try {
            const ok = await globalThis.AppModal.confirm({
                title: 'Remove alarm sound',
                message: 'Remove this alarm sound?',
                confirmLabel: 'Remove',
                danger: true,
            });
            if (!ok) return;
            await deleteSelectedAlarmSound();
        } finally {
            if (btn.isConnected) btn.dataset.confirming = '';
        }
    });
}

function initTimezoneAuto() {
    const autoCb   = document.getElementById('focus-clock-timezone-auto');
    const select   = document.getElementById('focus-clock-timezone');
    const detected = document.getElementById('focus-clock-timezone-detected');
    if (!autoCb || !select) return;

    function update() {
        const auto = autoCb.checked;
        select.disabled = auto;
        select.classList.toggle('opacity-50', auto);

        if (!detected) return;

        if (auto) {
            const tz = window.detectBrowserTimezone?.();
            if (tz) {
                detected.textContent = `Detected: ${tz}`;
                detected.classList.remove('hidden');
            } else {
                detected.textContent = 'Could not detect timezone; fallback list will be used.';
                detected.classList.remove('hidden');
            }
        } else {
            detected.classList.add('hidden');
        }
    }

    autoCb.addEventListener('change', update);
    update();
}

function initSettingsFormReload() {
    const form = document.querySelector('form[action="/settings/clock"]');
    if (!form) return;
    form.addEventListener('submit', () => {
        const select = document.getElementById('focus-clock-timezone');
        if (select?.disabled) {
            select.disabled = false;
        }
        if (window.FocusClockAlarm?.reloadPrefs) {
            window.FocusClockAlarm.reloadPrefs();
        }
    });
}

initScaleSlider();
initFontColonToggle();
initTimezoneAuto();
initAlarmSounds();
initAlarmUpload();
initSettingsFormReload();
