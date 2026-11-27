<?php
/**
 * Compact status for the dashboard card.
 *
 * @var array<string, array<string, mixed>>|null $homelabServices
 */
$homelabServices = $homelabServices ?? \Dashboard\Homelab\Catalog::SERVICES;
?>
<div class="homelab-card" data-homelab="card">
    <div class="homelab-head">
        <h2 class="section-title">
            <span class="ld-w-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/monitor.svg'); ?></span>
            Homelab
        </h2>
        <a class="nav-link mono text-xs" href="/homelab">Open</a>
    </div>
    <ul class="homelab-card-list">
        <?php foreach ($homelabServices as $service => $meta): ?>
        <li data-homelab-row="<?= e((string) $service) ?>">
            <span class="homelab-name"><?= e((string) $meta['label']) ?></span>
            <span class="homelab-version mono text-xs" data-homelab-version>…</span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
