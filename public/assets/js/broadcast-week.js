/**
 * broadcast-week.js — US broadcast calendar (EPOCH) helpers
 */
'use strict';

const BROADCAST_REF_TZ = 'America/New_York';

/** @returns {{ year: number, month: number, day: number }} */
function getEtYmd(now) {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone: BROADCAST_REF_TZ,
            year:     'numeric',
            month:    'numeric',
            day:      'numeric',
        })
            .formatToParts(now)
            .filter((p) => p.type !== 'literal')
            .map((p) => [p.type, parseInt(p.value, 10)]),
    );
    return { year: parts.year, month: parts.month, day: parts.day };
}

/** @param {number} y @param {number} m @param {number} d */
function calendarDateUtc(y, m, d) {
    return new Date(Date.UTC(y, m - 1, d, 12, 0, 0));
}

/**
 * US broadcast calendar (EPOCH): Mon–Sun weeks; week 1 contains Jan 1.
 * @param {number} year @param {number} month @param {number} day
 */
function broadcastWeekForYmd(year, month, day) {
    function weekOneMonday(y) {
        const jan1 = calendarDateUtc(y, 1, 1);
        const dow  = jan1.getUTCDay();
        const mon  = calendarDateUtc(y, 1, 1);
        mon.setUTCDate(jan1.getUTCDate() + (dow === 0 ? -6 : 1 - dow));
        return mon;
    }

    const atNoon = calendarDateUtc(year, month, day);
    let broadcastYear = year;
    let week1         = weekOneMonday(broadcastYear);

    if (atNoon.getTime() < week1.getTime()) {
        broadcastYear -= 1;
        week1 = weekOneMonday(broadcastYear);
    }

    const diffDays = Math.floor((atNoon.getTime() - week1.getTime()) / 86_400_000);
    const weekNum  = Math.floor(diffDays / 7) + 1;
    const weekStart = calendarDateUtc(
        week1.getUTCFullYear(),
        week1.getUTCMonth() + 1,
        week1.getUTCDate(),
    );
    weekStart.setUTCDate(week1.getUTCDate() + (weekNum - 1) * 7);
    const weekEnd = calendarDateUtc(
        weekStart.getUTCFullYear(),
        weekStart.getUTCMonth() + 1,
        weekStart.getUTCDate(),
    );
    weekEnd.setUTCDate(weekStart.getUTCDate() + 6);

    return { broadcastYear, weekNum, weekStart, weekEnd };
}

function formatUtcLongDate(d) {
    return d.toLocaleDateString('en-US', {
        month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC',
    });
}

/**
 * @param {Date} now
 * @returns {string}
 */
function formatBroadcastWeekLine(now) {
    const ymd = getEtYmd(now);
    const { weekNum } = broadcastWeekForYmd(ymd.year, ymd.month, ymd.day);
    const today = calendarDateUtc(ymd.year, ymd.month, ymd.day);
    return `${formatUtcLongDate(today)} — Week ${weekNum}`;
}

window.BroadcastWeek = {
    getEtYmd,
    broadcastWeekForYmd,
    formatBroadcastWeekLine,
    formatUtcLongDate,
    calendarDateUtc,
};
