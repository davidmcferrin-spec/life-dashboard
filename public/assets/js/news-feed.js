/**
 * news-feed.js — RSS news (dashboard pins + /news reader)
 *
 * scope=dashboard — pinned sources only
 * scope=reader    — all sources
 * Category/source filters shared via sessionStorage on both views
 */

'use strict';

const FEED_PAGE_SIZE        = 50;
const FEED_REFRESH_MS       = 5 * 60 * 1000;
const STORAGE_CATEGORY = 'news_category';
const STORAGE_SOURCE   = 'news_source';

let feedScope        = 'reader';
let feedPage         = 0;
let allArticles      = [];
let enabledSources   = [];
let activeCategory   = 'all';
let activeSource     = 'all';

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function timeAgo(dateStr) {
    return window.DashboardTime?.timeAgo(dateStr) ?? '';
}

function sourceColor(slug) {
    let h = 5381;
    for (let i = 0; i < slug.length; i++) {
        h = ((h << 5) + h) ^ slug.charCodeAt(i);
    }
    return 'feed-tag-' + (Math.abs(h) % 11);
}

function detectFeedScope() {
    const el = document.querySelector('[data-feed-scope]');
    feedScope = el?.dataset.feedScope === 'dashboard' ? 'dashboard' : 'reader';
}

function filtersPresent() {
    return document.getElementById('feed-category-filters') !== null;
}

function readFilterState() {
    if (!filtersPresent()) {
        activeCategory = 'all';
        activeSource   = 'all';
        return;
    }
    try {
        activeCategory = sessionStorage.getItem(STORAGE_CATEGORY) || 'all';
        activeSource   = sessionStorage.getItem(STORAGE_SOURCE) || 'all';
    } catch (_) {
        activeCategory = 'all';
        activeSource   = 'all';
    }
}

function saveFilterState() {
    if (!filtersPresent()) {
        return;
    }
    try {
        sessionStorage.setItem(STORAGE_CATEGORY, activeCategory);
        sessionStorage.setItem(STORAGE_SOURCE, activeSource);
    } catch (_) {
        /* ignore */
    }
}

function getFilteredArticles() {
    if (!filtersPresent()) {
        return allArticles;
    }
    return allArticles.filter(article => {
        if (activeCategory !== 'all' && article.category !== activeCategory) {
            return false;
        }
        if (activeSource !== 'all' && article.source_slug !== activeSource) {
            return false;
        }
        return true;
    });
}

function sourcesForFilters() {
    if (enabledSources.length > 0) {
        let list = enabledSources;
        if (activeCategory !== 'all') {
            list = list.filter(s => s.category === activeCategory);
        }
        return list;
    }

    const seen = new Set();
    const fromArticles = [];
    allArticles.forEach(article => {
        if (activeCategory !== 'all' && article.category !== activeCategory) {
            return;
        }
        if (seen.has(article.source_slug)) {
            return;
        }
        seen.add(article.source_slug);
        fromArticles.push({
            slug:     article.source_slug,
            name:     article.source_name,
            category: article.category,
        });
    });
    return fromArticles;
}

function syncCategoryButtons() {
    if (!filtersPresent()) {
        return;
    }
    document.querySelectorAll('#feed-category-filters .seg-btn').forEach(btn => {
        btn.classList.toggle('seg-btn-active', btn.dataset.category === activeCategory);
    });
}

function renderSourceFilters() {
    if (!filtersPresent()) {
        return;
    }

    const chipContainer = document.getElementById('feed-source-filters');
    const selectEl      = document.getElementById('feed-source-select');
    if (!chipContainer) {
        return;
    }

    const sources = sourcesForFilters();

    if (activeSource !== 'all' && !sources.some(s => s.slug === activeSource)) {
        activeSource = 'all';
        saveFilterState();
    }

    chipContainer.innerHTML = '';
    const allBtn = document.createElement('button');
    allBtn.type = 'button';
    allBtn.className = 'seg-btn' + (activeSource === 'all' ? ' seg-btn-active' : '');
    allBtn.dataset.source = 'all';
    allBtn.textContent = 'All';
    chipContainer.appendChild(allBtn);

    sources.forEach(source => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'seg-btn' + (activeSource === source.slug ? ' seg-btn-active' : '');
        btn.dataset.source = source.slug;
        btn.textContent = source.name;
        chipContainer.appendChild(btn);
    });

    if (selectEl) {
        selectEl.innerHTML = '<option value="all">All sources</option>';
        sources.forEach(source => {
            const opt = document.createElement('option');
            opt.value = source.slug;
            opt.textContent = source.name;
            if (activeSource === source.slug) {
                opt.selected = true;
            }
            selectEl.appendChild(opt);
        });
        if (activeSource === 'all') {
            selectEl.value = 'all';
        }
    }
}

function updateFeedCount(filteredCount, totalCount) {
    const countEl = document.getElementById('feed-count');
    if (!countEl) {
        return;
    }
    if (!filtersPresent() || filteredCount === totalCount) {
        countEl.textContent = `${totalCount} article${totalCount === 1 ? '' : 's'}`;
        return;
    }
    countEl.textContent = `${filteredCount} of ${totalCount} articles`;
}

function feedEmptyMessage() {
    const hasFilters = filtersPresent() && (activeCategory !== 'all' || activeSource !== 'all');

    if (allArticles.length === 0) {
        if (feedScope === 'dashboard') {
            return (
                '<div class="empty-state mono text-xs py-4 text-center text-muted">' +
                'No articles from your pinned sources yet. Check <a href="/settings/feeds" class="text-accent hover:underline">News feeds</a> ' +
                'and ensure the RSS cron job (<code class="text-faint">cron/fetch_feeds.php</code>) is running.</div>'
            );
        }
        return (
            '<div class="empty-state mono text-xs py-4 text-center text-muted">' +
            'No articles in the cache yet. Cron refreshes all RSS sources periodically ' +
            '(<code class="text-faint">cron/fetch_feeds.php</code>).</div>'
        );
    }

    if (hasFilters) {
        return (
            '<div class="empty-state mono text-xs py-4 text-center text-muted">' +
            'No articles match these filters. Try another category or source.</div>'
        );
    }

    return (
        '<div class="empty-state mono text-xs py-4 text-center text-muted">' +
        'No articles to show.</div>'
    );
}

function renderFeedPage() {
    const container = document.getElementById('news-feed');
    const loadMore  = document.getElementById('load-more-feed');
    if (!container) {
        return;
    }

    const filtered = getFilteredArticles();
    const start    = feedPage * FEED_PAGE_SIZE;
    const slice    = filtered.slice(start, start + FEED_PAGE_SIZE);

    if (feedPage === 0) {
        container.innerHTML = '';
    }

    slice.forEach(article => {
        const el = document.createElement('a');
        el.href       = article.url;
        el.target     = '_blank';
        el.rel        = 'noopener noreferrer';
        el.className  = 'feed-row';
        el.innerHTML  = `
            <span class="feed-tag ${sourceColor(article.source_slug)}">${escHtml(article.source_name)}</span>
            <span class="feed-title">${escHtml(article.title)}</span>
            <span class="feed-time">${timeAgo(article.published_at)}</span>
        `;
        container.appendChild(el);
    });

    feedPage++;

    if (loadMore) {
        const hasMore = feedPage * FEED_PAGE_SIZE < filtered.length;
        loadMore.classList.toggle('hidden', !hasMore);
    }
}

function refreshFeedView() {
    const container = document.getElementById('news-feed');
    if (!container) {
        return;
    }

    renderSourceFilters();
    syncCategoryButtons();

    const filtered = getFilteredArticles();
    updateFeedCount(filtered.length, allArticles.length);

    feedPage = 0;

    if (filtered.length === 0) {
        container.innerHTML = feedEmptyMessage();
        document.getElementById('load-more-feed')?.classList.add('hidden');
        return;
    }

    renderFeedPage();
}

function setCategory(category) {
    activeCategory = category;
    if (activeSource !== 'all') {
        const sources = sourcesForFilters();
        if (!sources.some(s => s.slug === activeSource)) {
            activeSource = 'all';
        }
    }
    saveFilterState();
    refreshFeedView();
}

function setSource(source) {
    activeSource = source;
    saveFilterState();
    refreshFeedView();
}

async function loadFeed() {
    const container = document.getElementById('news-feed');
    if (!container) {
        return;
    }

    try {
        const res  = await fetch(`/api/feeds?scope=${encodeURIComponent(feedScope)}`, {
            credentials: 'same-origin',
        });
        const text = await res.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch (_) {
            container.innerHTML =
                '<div class="text-danger mono text-xs py-4 text-center">Feed returned an invalid response.</div>';
            return;
        }

        if (!res.ok || !Array.isArray(data.articles)) {
            const msg = data?.error ?? `Could not load feed (${res.status}).`;
            container.innerHTML =
                `<div class="text-danger mono text-xs py-4 text-center">${escHtml(msg)}</div>`;
            return;
        }

        allArticles    = data.articles;
        enabledSources = Array.isArray(data.sources) ? data.sources : [];
        refreshFeedView();
    } catch (_) {
        container.innerHTML =
            '<div class="empty-state mono text-xs py-4 text-center">Feed unavailable. Check your connection and try again.</div>';
    }
}

function initNewsFeed() {
    const container = document.getElementById('news-feed');
    if (!container) {
        return;
    }

    detectFeedScope();
    readFilterState();
    syncCategoryButtons();

    document.getElementById('feed-category-filters')?.addEventListener('click', e => {
        const btn = e.target.closest('[data-category]');
        if (!btn) return;
        setCategory(btn.dataset.category);
    });

    document.getElementById('feed-source-filters')?.addEventListener('click', e => {
        const btn = e.target.closest('[data-source]');
        if (!btn) return;
        setSource(btn.dataset.source);
        const selectEl = document.getElementById('feed-source-select');
        if (selectEl) {
            selectEl.value = btn.dataset.source;
        }
    });

    document.getElementById('feed-source-select')?.addEventListener('change', e => {
        setSource(e.target.value);
    });

    document.getElementById('load-more-feed')?.addEventListener('click', renderFeedPage);

    loadFeed();
    setInterval(loadFeed, FEED_REFRESH_MS);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNewsFeed);
} else {
    initNewsFeed();
}
