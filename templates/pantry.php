<?php
/**
 * Pantry inventory, scan, purge, catalog, recipes, and meal builder.
 *
 * @var array<string, mixed> $user
 */

$view = (string) ($_GET['view'] ?? 'inventory');
if (!in_array($view, ['inventory', 'scan', 'purge', 'products', 'recipes', 'meals'], true)) {
    $view = 'inventory';
}
$isMeals = $view === 'meals';
$pageTitle = $isMeals ? 'Meal prep' : 'Pantry';
$extraStyles = ['/assets/css/lifedash.css'];
$lede = match ($view) {
    'meals' => 'A practical plan built around what you already have.',
    'scan' => 'Scan a barcode, or type a UPC.',
    'purge' => 'Lots that need to be used or tossed.',
    'products' => 'The catalog behind what is on the shelf.',
    'recipes' => 'Meals you can make from what is on hand.',
    default => 'Know what you have, what is fresh, and what to use next.',
};
$pantryIcon = static function (string $name): void {
    $path = __DIR__ . '/../public/assets/vendor/lucide/' . $name . '.svg';
    if (is_file($path)) {
        readfile($path);
    }
};
$pantryNav = [
    ['inventory', '/pantry', 'Inventory', 'shopping-basket'],
    ['scan', '/pantry?view=scan', 'Scan', 'scan-barcode'],
    ['purge', '/pantry?view=purge', 'Purge', ''],
    ['products', '/pantry?view=products', 'Products', ''],
    ['recipes', '/pantry?view=recipes', 'Recipes', ''],
    ['meals', '/pantry?view=meals', 'Meals', 'cooking-pot'],
];
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="mx-auto px-4 py-6 space-y-4<?= $isMeals ? ' is-meals' : '' ?>" id="pantry-app">
    <header class="ld-pantry-head">
        <div class="ld-pantry-head-copy">
            <?php $pageEyebrow = 'Kitchen intelligence'; $pageHeading = $isMeals ? 'Meal prep' : 'Pantry'; require __DIR__ . '/partials/page-heading.php'; ?>
            <p class="ld-pantry-lede"><?= e($lede) ?></p>
        </div>
        <?php if ($view === 'inventory'): ?>
        <button type="button" class="btn-primary" id="pantry-add">Add item</button>
        <?php elseif ($isMeals): ?>
        <button type="button" class="btn-primary" id="pantry-add-meal">Add meal</button>
        <?php endif; ?>
    </header>
    <nav class="ld-pantry-nav" id="pantry-nav" aria-label="Pantry">
        <?php foreach ($pantryNav as [$id, $href, $label, $icon]): ?>
        <a href="<?= e($href) ?>" data-view="<?= e($id) ?>"<?= $view === $id ? ' class="is-on" aria-current="page"' : '' ?>>
            <?php if ($icon !== ''): ?><span class="ld-pantry-nav-icon" aria-hidden="true"><?php $pantryIcon($icon); ?></span><?php endif; ?>
            <?= e($label) ?>
        </a>
        <?php endforeach; ?>
        <a href="/settings/pantry">Shelf life</a>
    </nav>
    <p id="pantry-error" class="ld-error hidden"></p>
    <div id="pantry-body"></div>
</main>
<template id="pantry-icon-x"><?php $pantryIcon('x'); ?></template>
<template id="pantry-icon-minus"><?php $pantryIcon('minus'); ?></template>
<template id="pantry-icon-scan"><?php $pantryIcon('scan-barcode'); ?></template>
<template id="pantry-icon-basket"><?php $pantryIcon('shopping-basket'); ?></template>
<template id="pantry-icon-pot"><?php $pantryIcon('cooking-pot'); ?></template>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>

<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/lifedash-realtime.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/pantry.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
