<?php
/**
 * Category shelf-life defaults. Products inherit a day count when their own is empty.
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Pantry shelf life';
$active = 'pantry';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Shelf life'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Reasonable defaults in the style of USDA FoodKeeper. Verify them and edit.
                    A product uses its own days when those are set, and these when they are empty.
                    <a class="link" href="/pantry">Open pantry</a>
                </p>
            </header>
            <p id="pantry-shelf-error" class="ld-error hidden"></p>
            <div id="pantry-shelf" class="space-y-3"></div>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-pantry.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
