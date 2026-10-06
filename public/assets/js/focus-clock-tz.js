/**
 * focus-clock-tz.js — resolve focus clock IANA timezone from prefs
 */
'use strict';

const FOCUS_CLOCK_TZ_FALLBACK = 'America/Chicago';

/**
 * @param {{ timezone?: string, timezone_auto?: boolean }|null|undefined} prefs
 * @returns {string}
 */
function effectiveFocusClockTimezone(prefs) {
    if (prefs?.timezone_auto) {
        try {
            const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (detected) {
                return detected;
            }
        } catch (_) {}
    }

    return prefs?.timezone || FOCUS_CLOCK_TZ_FALLBACK;
}

/**
 * @returns {string|null}
 */
function detectBrowserTimezone() {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || null;
    } catch (_) {
        return null;
    }
}

window.effectiveFocusClockTimezone = effectiveFocusClockTimezone;
window.detectBrowserTimezone = detectBrowserTimezone;
