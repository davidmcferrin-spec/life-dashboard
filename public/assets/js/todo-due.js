'use strict';

/**
 * Due-date urgency helpers for todo widget and /todos page.
 * Uses browser local calendar date for "today" comparisons.
 */
(function () {
    function parseLocalDate(isoDate) {
        if (!isoDate || typeof isoDate !== 'string') {
            return null;
        }
        const parts = isoDate.split('-').map(Number);
        if (parts.length !== 3 || parts.some(n => Number.isNaN(n))) {
            return null;
        }
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function todayLocal() {
        const d = new Date();
        return new Date(d.getFullYear(), d.getMonth(), d.getDate());
    }

    function dayDiff(from, to) {
        return Math.round((to.getTime() - from.getTime()) / 86400000);
    }

    /** @returns {null|'soon'|'today'|'overdue'} */
    function urgency(dueDate, status) {
        if (!dueDate || status !== 'open') {
            return null;
        }
        const due = parseLocalDate(dueDate);
        if (!due) {
            return null;
        }
        const diff = dayDiff(todayLocal(), due);
        if (diff < 0) {
            return 'overdue';
        }
        if (diff === 0) {
            return 'today';
        }
        if (diff <= 2) {
            return 'soon';
        }
        return null;
    }

    function rowClass(level) {
        if (!level) {
            return '';
        }
        return ` todo-due-${level}`;
    }

    function flagHtml(level) {
        if (level === 'today' || level === 'overdue') {
            return '<span class="todo-due-flag" aria-hidden="true" title="Due today">🚩</span>';
        }
        return '';
    }

    function formatLabel(isoDate) {
        const due = parseLocalDate(isoDate);
        if (!due) {
            return '';
        }
        return due.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    window.TodoDue = {
        urgency,
        rowClass,
        flagHtml,
        formatLabel,
        todayLocal,
    };
})();
