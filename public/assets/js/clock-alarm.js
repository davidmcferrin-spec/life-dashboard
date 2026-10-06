/**
 * clock-alarm.js — tab-visible alarm (no catch-up), /clock only
 */
'use strict';

const ALARM_CHANNEL = 'dashboard-alarm';
const ALARM_PREFS_POLL_MS = 30 * 60 * 1000;
const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

let alarmPrefs = null;
let snoozeUntilMs = 0;
let audioCtx = null;
let builtinOsc = null;
let customAudio = null;
let ringing = false;
/** @type {BroadcastChannel|null} */
let alarmChannel = null;

function getUserId() {
    const meta = document.querySelector('meta[name="dashboard-user-id"]');
    return meta?.getAttribute('content') || '0';
}

function firedKey(dateKey, timeKey) {
    return `dashboard_alarm_fired_${getUserId()}_${dateKey}_${timeKey}`;
}

function markFired(dateKey, timeKey) {
    try {
        sessionStorage.setItem(firedKey(dateKey, timeKey), '1');
    } catch (_) {}
}

function alreadyFired(dateKey, timeKey) {
    try {
        return sessionStorage.getItem(firedKey(dateKey, timeKey)) === '1';
    } catch (_) {
        return false;
    }
}

function localDateKey(now, tz) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: tz,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const y = parts.find((p) => p.type === 'year')?.value;
    const m = parts.find((p) => p.type === 'month')?.value;
    const d = parts.find((p) => p.type === 'day')?.value;
    return `${y}-${m}-${d}`;
}

function localTimeKey(now, tz) {
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: tz,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(now);
}

function localWeekday(now, tz) {
    const wd = new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'short' }).format(now);
    const map = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
    return map[wd] ?? new Date(now).getDay();
}

function formatAlarmTitle(prefs) {
    const alarm = prefs?.alarm;
    if (!alarm?.enabled) {
        return 'Alarm off';
    }
    const time = alarm.time || '07:00';
    const days = alarm.days || [];
    const dayStr = days.length
        ? days.map((d) => DAY_LABELS[d] ?? '').filter(Boolean).join(', ')
        : 'every day';
    return `Alarm on at ${time} (${dayStr})`;
}

function updateAlarmIndicator(prefs) {
    const el = document.getElementById('focus-clock-alarm-indicator');
    if (!el) return;

    const enabled = prefs?.alarm?.enabled === true;
    el.classList.toggle('hidden', !enabled);
    el.setAttribute('aria-label', enabled ? 'Alarm enabled' : 'Alarm off');
    el.title = formatAlarmTitle(prefs);
}

function applyAlarmPrefs(prefs) {
    const wasEnabled = alarmPrefs?.alarm?.enabled === true;
    alarmPrefs = prefs;
    const isEnabled = prefs?.alarm?.enabled === true;

    updateAlarmIndicator(prefs);

    if (wasEnabled && !isEnabled && ringing) {
        stopRinging();
    }
}

function ensureOverlay() {
    let el = document.getElementById('focus-clock-alarm-overlay');
    if (el) return el;

    el = document.createElement('div');
    el.id = 'focus-clock-alarm-overlay';
    el.className = 'focus-clock-alarm-banner hidden';
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-labelledby', 'focus-clock-alarm-title');
    el.innerHTML = `
        <div class="focus-clock-alarm-panel">
            <h2 id="focus-clock-alarm-title">Alarm</h2>
            <div class="focus-clock-alarm-actions">
                <button type="button" class="btn-primary" id="focus-clock-alarm-dismiss">Dismiss</button>
                <button type="button" class="btn" id="focus-clock-alarm-snooze">Snooze 5 min</button>
            </div>
        </div>
    `;
    document.body.appendChild(el);

    document.getElementById('focus-clock-alarm-dismiss')?.addEventListener('click', stopRinging);
    document.getElementById('focus-clock-alarm-snooze')?.addEventListener('click', snoozeAlarm);

    return el;
}

function ensureAutoplayHint() {
    let el = document.getElementById('focus-clock-autoplay-hint');
    if (el) return el;

    el = document.createElement('div');
    el.id = 'focus-clock-autoplay-hint';
    el.className = 'focus-clock-autoplay-hint hidden';
    el.innerHTML = '<button type="button" class="btn-primary text-sm" id="focus-clock-enable-sound">Enable alarm sound</button>';
    document.body.appendChild(el);
    document.getElementById('focus-clock-enable-sound')?.addEventListener('click', () => {
        el.classList.add('hidden');
        try {
            const ctx = new AudioContext();
            ctx.resume();
            ctx.close();
        } catch (_) {}
    });
    return el;
}

function startBuiltin() {
    stopBuiltin();
    try {
        audioCtx = audioCtx || new AudioContext();
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        builtinOsc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        builtinOsc.type = 'square';
        builtinOsc.frequency.value = 880;
        gain.gain.value = 0.15;
        builtinOsc.connect(gain);
        gain.connect(audioCtx.destination);
        builtinOsc.start();
    } catch (_) {}
}

function stopBuiltin() {
    try {
        builtinOsc?.stop();
    } catch (_) {}
    builtinOsc = null;
}

function alarmAudioUrl(sound) {
    if (!sound || sound === 'builtin') {
        return null;
    }
    if (sound === 'custom' || sound === 'mine') {
        return '/api/clock/alarm-sound/mine';
    }
    if (sound.startsWith('library:')) {
        return `/api/clock/alarm-sound/${encodeURIComponent(sound.slice(8))}`;
    }
    return null;
}

function playAlarmFile(sound) {
    const url = alarmAudioUrl(sound);
    if (!url) {
        startBuiltin();
        return Promise.resolve();
    }

    const absolute = new URL(url, window.location.origin).href;
    if (!customAudio || customAudio.src !== absolute) {
        customAudio = new Audio(url);
        customAudio.loop = true;
    }
    customAudio.currentTime = 0;
    return customAudio.play();
}

function stopCustom() {
    if (customAudio) {
        customAudio.pause();
        customAudio.currentTime = 0;
    }
}

function showOverlay() {
    ensureOverlay().classList.remove('hidden');
}

function hideOverlay() {
    document.getElementById('focus-clock-alarm-overlay')?.classList.add('hidden');
}

/** Stop audio/UI locally; does not broadcast (avoids BroadcastChannel feedback loop). */
function stopRingingLocal() {
    ringing = false;
    stopBuiltin();
    stopCustom();
    hideOverlay();
    document.getElementById('focus-clock-autoplay-hint')?.classList.add('hidden');
}

function postAlarmMessage(type) {
    try {
        getAlarmChannel()?.postMessage({ type });
    } catch (_) {}
}

/** @returns {BroadcastChannel|null} */
function getAlarmChannel() {
    if (typeof BroadcastChannel === 'undefined') {
        return null;
    }
    return alarmChannel;
}

function handleRemoteAlarmMessage(type) {
    if (type === 'snooze') {
        snoozeUntilMs = Date.now() + 5 * 60 * 1000;
    }
    if ((type === 'stop' || type === 'snooze') && ringing) {
        stopRingingLocal();
    }
}

/** Dismiss alarm in this tab and notify other tabs (no re-entrant stop loop). */
function stopRinging() {
    const wasRinging = ringing;
    stopRingingLocal();
    if (wasRinging) {
        postAlarmMessage('stop');
    }
}

function snoozeAlarm() {
    const wasRinging = ringing;
    snoozeUntilMs = Date.now() + 5 * 60 * 1000;
    stopRingingLocal();
    if (wasRinging) {
        postAlarmMessage('snooze');
    }
}

function startRinging(prefs) {
    if (ringing) return;

    if (typeof BroadcastChannel !== 'undefined') {
        const ch = new BroadcastChannel(ALARM_CHANNEL);
        let leader = true;
        ch.postMessage({ type: 'ping' });
        const t = setTimeout(() => {
            ch.close();
            if (!leader) return;
            doStartRinging(prefs);
        }, 80);
        ch.onmessage = (ev) => {
            const type = ev.data?.type;
            if (type === 'ping') {
                leader = false;
                ch.postMessage({ type: 'pong' });
            }
            if (type === 'pong') leader = false;
            if (type === 'stop' || type === 'snooze') {
                clearTimeout(t);
                if (type === 'snooze') {
                    snoozeUntilMs = Date.now() + 5 * 60 * 1000;
                }
                stopRingingLocal();
            }
        };
        return;
    }

    doStartRinging(prefs);
}

function doStartRinging(prefs) {
    if (ringing) return;
    ringing = true;
    showOverlay();

    const alarm = prefs.alarm || {};
    const play = playAlarmFile(alarm.sound || 'builtin');

    Promise.resolve(play).catch(() => {
        ensureAutoplayHint().classList.remove('hidden');
    });
}

function shouldFire(now, prefs) {
    const alarm = prefs?.alarm;
    if (!alarm?.enabled) return false;
    if (document.visibilityState !== 'visible') return false;
    if (Date.now() < snoozeUntilMs) return false;

    const tz = window.effectiveFocusClockTimezone?.(prefs) ?? prefs.timezone ?? 'America/Chicago';
    const timeKey = localTimeKey(now, tz);
    const alarmTime = alarm.time || '07:00';
    if (timeKey !== alarmTime) return false;

    const days = alarm.days || [1, 2, 3, 4, 5];
    if (!days.includes(localWeekday(now, tz))) return false;

    const dateKey = localDateKey(now, tz);
    if (alreadyFired(dateKey, alarmTime)) return false;

    return true;
}

function tick() {
    if (!alarmPrefs) return;

    const now = window.DashboardTime?.now() ?? new Date();
    if (!shouldFire(now, alarmPrefs)) return;

    const tz = window.effectiveFocusClockTimezone?.(alarmPrefs) ?? alarmPrefs.timezone ?? 'America/Chicago';
    const alarmTime = alarmPrefs.alarm.time || '07:00';
    markFired(localDateKey(now, tz), alarmTime);
    startRinging(alarmPrefs);
}

async function loadPrefs() {
    try {
        const res = await fetch('/api/preferences', { credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        applyAlarmPrefs(data.focus_clock || null);
    } catch (_) {}
}

function initChannel() {
    if (typeof BroadcastChannel === 'undefined') return;
    alarmChannel = new BroadcastChannel(ALARM_CHANNEL);
    alarmChannel.onmessage = (ev) => {
        const type = ev.data?.type;
        if (type === 'stop' || type === 'snooze') {
            handleRemoteAlarmMessage(type);
        }
    };
}

function isClockPage() {
    return document.body?.dataset?.page === 'clock';
}

function init() {
    if (document.documentElement.getAttribute('data-theme-local') === '1') return;
    if (!isClockPage()) return;

    ensureOverlay();
    ensureAutoplayHint();
    initChannel();
    loadPrefs().then(() => {
        setInterval(tick, 1000);
        setInterval(loadPrefs, ALARM_PREFS_POLL_MS);
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            loadPrefs();
        }
    });
}

window.FocusClockAlarm = {
    setPrefs(p) {
        applyAlarmPrefs(p);
    },
    previewSound(sound) {
        const play = playAlarmFile(sound || 'builtin');
        Promise.resolve(play).catch(() => ensureAutoplayHint().classList.remove('hidden'));
        setTimeout(stopRingingLocal, 3000);
    },
    reloadPrefs: loadPrefs,
};

init();
