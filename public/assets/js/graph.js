(function () {
    const root = document.getElementById('graph-app');
    if (!root || !window.LD) return;
    const esc = window.LD.esc;
    const error = document.getElementById('graph-error');
    const results = document.getElementById('graph-results');
    const neighbors = document.getElementById('graph-neighbors');

    function fail(err) {
        error.textContent = err && err.message ? err.message : 'The graph did not load.';
        error.classList.remove('hidden');
    }

    function paint(data) {
        const nodes = (data && data.nodes) || [];
        const omitted = (data && data.omitted) || [];
        results.innerHTML = (omitted.length ? '<p class="platform-meta">Omitted without a grant: ' + esc(omitted.join(', ')) + '</p>' : '') +
            (nodes.length ? nodes.map(function (node) {
                return '<button type="button" class="btn" data-kind="' + esc(node.kind) + '" data-ref="' + esc(node.ref_id) + '">' +
                    esc(node.kind) + ' · ' + esc(node.label) + '</button>';
            }).join(' ') : '<p class="platform-meta">No nodes matched.</p>');
    }

    function search(q) {
        const path = q ? '/graph?q=' + encodeURIComponent(q) : '/graph';
        return window.LD.api.get(path).then(function (data) {
            error.classList.add('hidden');
            paint(data);
        }).catch(fail);
    }

    document.getElementById('graph-search').addEventListener('submit', function (event) {
        event.preventDefault();
        search(new FormData(event.target).get('q') || '');
    });
    results.addEventListener('click', function (event) {
        const button = event.target.closest('button[data-kind]');
        if (!button) return;
        window.LD.api.get('/graph/neighbors?kind=' + encodeURIComponent(button.dataset.kind) + '&ref=' + encodeURIComponent(button.dataset.ref))
            .then(function (data) {
                const edges = (data.edges || []).map(function (edge) {
                    return '<div class="platform-row"><span>' + esc(edge.kind) + '</span><span>' + esc(edge.from_label) + ' → ' + esc(edge.to_label) + '</span></div>';
                }).join('');
                neighbors.innerHTML = '<p class="platform-kicker">' + esc(data.node.label) + '</p>' + (edges || '<p class="platform-meta">No links yet.</p>');
            }).catch(fail);
    });
    document.getElementById('graph-link').addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(event.target);
        window.LD.api.post('/graph/edges', {
            from_kind: data.get('from_kind'),
            from_ref: Number(data.get('from_ref')),
            to_kind: data.get('to_kind'),
            to_ref: Number(data.get('to_ref')),
        }).then(function () { return search(''); }).catch(fail);
    });
    search('');
})();
