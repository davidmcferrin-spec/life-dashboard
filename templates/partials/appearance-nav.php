<?php
/**
 * Nav appearance controls (theme switcher + starfield toggle)
 *
 * @var bool $starfieldPref Current starfield preference from server
 */
$starfieldPref = $starfieldPref ?? false;
?>
<div class="appearance-nav flex flex-wrap items-center gap-2">
    <?php require __DIR__ . '/theme-switcher.php'; ?>
    <label class="starfield-toggle flex items-center gap-1.5 cursor-pointer text-muted mono text-xs select-none" title="Starfield in dark mode">
        <input type="checkbox" id="starfield-toggle" <?= $starfieldPref ? 'checked' : '' ?>>
        <span>Stars</span>
    </label>
</div>
