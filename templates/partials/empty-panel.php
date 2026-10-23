<?php
/**
 * Shared empty panel. Set $emptyTitle, $emptyBody, and optionally
 * $emptyAction with $emptyHref before including.
 */
$emptyTitle = (string) ($emptyTitle ?? '');
$emptyBody = (string) ($emptyBody ?? '');
$emptyAction = (string) ($emptyAction ?? '');
$emptyHref = (string) ($emptyHref ?? '');
$emptyButtonId = (string) ($emptyButtonId ?? '');
?>
<div class="empty-panel">
    <p class="empty-panel-title"><?= e($emptyTitle) ?></p>
    <p class="empty-panel-body"><?= e($emptyBody) ?></p>
    <?php if ($emptyAction !== '' && $emptyHref !== ''): ?>
    <a class="btn-primary" href="<?= e($emptyHref) ?>"><?= e($emptyAction) ?></a>
    <?php elseif ($emptyAction !== '' && $emptyButtonId !== ''): ?>
    <button type="button" class="btn-primary" id="<?= e($emptyButtonId) ?>"><?= e($emptyAction) ?></button>
    <?php endif; ?>
</div>
<?php
unset($emptyTitle, $emptyBody, $emptyAction, $emptyHref, $emptyButtonId);
