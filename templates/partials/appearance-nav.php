<?php
/**
 * Header appearance controls. The cycle is JARVIS, light, then dark.
 * System stays on the Settings page.
 *
 * @var bool $starfieldPref
 * @var string $themePref
 */
$starfieldPref = $starfieldPref ?? false;
$themePref = $themePref ?? 'jarvis';
if (!in_array($themePref, ['jarvis', 'light', 'dark', 'system'], true)) {
    $themePref = 'jarvis';
}
$shellThemeNames = [
    'jarvis' => 'JARVIS',
    'light' => 'Light',
    'dark' => 'Dark',
    'system' => 'System',
];
$shellThemeName = $shellThemeNames[$themePref];

$shellIcon = $shellIcon ?? static function (string $name): void {
    $path = __DIR__ . '/../../public/assets/vendor/lucide/' . $name . '.svg';
    if (is_file($path)) {
        readfile($path);
    }
};
?>
<div class="appearance-nav app-header-appearance">
    <div class="app-theme-picks" hidden>
        <button type="button" data-theme-pick="jarvis">JARVIS</button>
        <button type="button" data-theme-pick="light">Light</button>
        <button type="button" data-theme-pick="dark">Dark</button>
    </div>
    <button type="button" id="app-theme-cycle" class="app-header-theme" aria-label="Theme: <?= e($shellThemeName) ?>. Switch theme.">
        <span class="app-header-theme-icon" data-theme-icon="jarvis" aria-hidden="true"><?php $shellIcon('sparkles'); ?></span>
        <span class="app-header-theme-icon" data-theme-icon="light" aria-hidden="true"><?php $shellIcon('sun'); ?></span>
        <span class="app-header-theme-icon" data-theme-icon="dark" aria-hidden="true"><?php $shellIcon('moon'); ?></span>
        <span class="app-header-theme-icon" data-theme-icon="system" aria-hidden="true"><?php $shellIcon('monitor'); ?></span>
        <span class="app-header-theme-label" data-theme-label="jarvis">JARVIS</span>
        <span class="app-header-theme-label" data-theme-label="light">Light</span>
        <span class="app-header-theme-label" data-theme-label="dark">Dark</span>
        <span class="app-header-theme-label" data-theme-label="system">System</span>
    </button>
    <label class="starfield-toggle" title="Starfield in dark mode">
        <input type="checkbox" id="starfield-toggle" <?= $starfieldPref ? 'checked' : '' ?>>
        <span>Stars</span>
    </label>
</div>
