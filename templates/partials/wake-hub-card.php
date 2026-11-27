<?php
/**
 * Device list for the Wake page and the dashboard card.
 */
if (empty($wakeIconsReady)) {
    $wakeIconsReady = true;
    $wakeIcon = static function (string $name): void {
        $path = __DIR__ . '/../../public/assets/vendor/lucide/' . $name . '.svg';
        if (is_file($path)) {
            readfile($path);
        }
    };
    foreach (['play' => 'play', 'on' => 'activity', 'off' => 'wifi-off', 'cycle' => 'refresh-cw', 'reboot' => 'rotate-ccw', 'shutdown' => 'circle-alert', 'server' => 'server'] as $key => $file) {
        echo '<template id="wake-ico-' . e($key) . '">';
        $wakeIcon($file);
        echo '</template>';
    }
}
?>
<div class="wake-hub" data-wake-hub>
    <div class="wake-hub-head">
        <h2 class="section-title">
            <span class="ld-w-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/server.svg'); ?></span>
            Wake Hub
        </h2>
    </div>
    <p class="wake-hub-status mono text-xs" data-wake-status>Loading…</p>
    <a class="nav-link mono text-xs wake-hub-manage" href="/settings/wake-hub">Manage</a>
    <ul class="wake-hub-list" data-wake-list></ul>
</div>
