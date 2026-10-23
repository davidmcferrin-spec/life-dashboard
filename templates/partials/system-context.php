<?php
/**
 * System-context card. Caller supplies real sync state; this partial does not invent it.
 *
 * @var array{connected: int, last_sync: string, status: string, ready: bool}|null $systemContext
 */
$systemContext = is_array($systemContext ?? null) ? $systemContext : null;
if ($systemContext === null) {
    return;
}
$systemReady = !empty($systemContext['ready']);
?>
<section class="system-context" aria-labelledby="system-context-title">
    <header class="feeds-card-head">
        <span class="feeds-card-icon" aria-hidden="true"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/activity.svg'); ?></span>
        <h2 id="system-context-title">System context</h2>
    </header>
    <dl class="context-stats">
        <div>
            <dt>Last sync</dt>
            <dd><?= e((string) ($systemContext['last_sync'] ?? 'Not yet')) ?></dd>
        </div>
        <div>
            <dt>Connected items</dt>
            <dd><?= (int) ($systemContext['connected'] ?? 0) ?></dd>
        </div>
        <div>
            <dt>Status</dt>
            <dd class="<?= $systemReady ? 'is-ready' : 'is-setup' ?>"><?= e((string) ($systemContext['status'] ?? 'Needs setup')) ?></dd>
        </div>
    </dl>
    <?php if (!$systemReady): ?>
    <p class="system-context-action"><a href="/settings/feeds">Feed settings</a></p>
    <?php endif; ?>
</section>
