<div class="flex items-center justify-between dashboard-widget-head weather-head">
    <h2 class="section-title ld-legacy-title">
        <span class="ld-legacy-ico" aria-hidden="true"><?php readfile(__DIR__ . '/../../../public/assets/vendor/lucide/cloud-rain.svg'); ?></span>
        Weather
    </h2>
    <div class="flex items-center gap-2">
        <a href="/settings/weather" class="nav-link mono text-xs">Manage</a>
        <button type="button" id="weather-refresh" class="nav-link mono text-xs">Refresh</button>
    </div>
</div>
<div id="weather-widget">
    <div class="empty-state mono">Detecting location...</div>
</div>
