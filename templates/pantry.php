<?php
/**
 * Pantry inventory, scan, purge, catalog, recipes, and meal builder.
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Pantry';
$extraStyles = ['/assets/css/lifedash.css'];
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="mx-auto px-4 py-6 space-y-4" id="pantry-app">
    <header>
        <?php $pageEyebrow = 'Dashboard'; $pageHeading = 'Pantry'; require __DIR__ . '/partials/page-heading.php'; ?>
        <p class="text-muted text-sm mt-1">What is on hand, and when to use it.</p>
    </header>
    <nav class="ld-pantry-nav" id="pantry-nav">
        <a href="/pantry" data-view="inventory" class="is-on">Inventory</a>
        <a href="/pantry?view=scan" data-view="scan">Scan</a>
        <a href="/pantry?view=purge" data-view="purge">Purge</a>
        <a href="/pantry?view=products" data-view="products">Products</a>
        <a href="/pantry?view=recipes" data-view="recipes">Recipes</a>
        <a href="/pantry?view=meals" data-view="meals">Meals</a>
        <a href="/settings/pantry">Shelf life</a>
    </nav>
    <p id="pantry-error" class="ld-error hidden"></p>
    <div id="pantry-body"></div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/pantry.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
