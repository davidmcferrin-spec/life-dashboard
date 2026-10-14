<?php
/**
 * Vault graph. Nodes touched in the audit log during the last week glow.
 *
 * @var array<string, mixed> $user
 */

$prefs         = new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance());
$themePref     = $prefs->getTheme((int) $user['id']);
$starfieldPref = $prefs->getStarfield((int) $user['id']);
$pageTitle     = 'Brain';
$extraStyles   = ['/assets/css/lifedash.css'];
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page brain-page min-h-screen">

<nav class="chrome flex items-center justify-between px-6 py-3">
    <div class="flex items-center gap-3">
        <span class="brand">Brain</span>
        <a href="/brain" class="nav-link mono text-xs">← Vault</a>
    </div>
    <div class="nav-meta flex items-center gap-3">
        <?php require __DIR__ . '/partials/appearance-nav.php'; ?>
        <a href="/" class="nav-link">Dashboard</a>
    </div>
</nav>

<div class="brain-graph-wrap">
    <canvas id="brain-canvas"></canvas>
    <div id="brain-ticker" class="brain-ticker"></div>
    <aside class="brain-panel">
        <p class="brain-kicker">Second brain</p>
        <h1 id="brain-panel-title" class="text-primary text-lg mt-2">Vault</h1>
        <p id="brain-panel-copy" class="text-secondary text-sm mt-2">Pages are nodes. A link is a [[wikilink]]. Nodes touched this week glow.</p>
        <a id="brain-panel-open" class="nav-link inline-block mt-3" href="/brain">Open vault</a>
    </aside>
</div>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/brain-graph.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
