<?php
/**
 * Homelab scopes are explicit. Read and write does not check these.
 */
?>
<fieldset class="homelab-scopes">
    <legend class="text-sm text-primary mb-1">Homelab</legend>
    <p class="text-muted text-xs">Not included in Read and write or Read only. Each app is granted on its own. Write includes read for that app.</p>
    <div class="homelab-scope-grid">
        <?php foreach (\Dashboard\Api\V1\Scope::HOMELAB as $homelabArea): ?>
        <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" name="homelab_scope" value="<?= e($homelabArea) ?>:read"> <span class="mono"><?= e($homelabArea) ?>:read</span></label>
        <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" name="homelab_scope" value="<?= e($homelabArea) ?>:write"> <span class="mono"><?= e($homelabArea) ?>:write</span></label>
        <?php endforeach; ?>
    </div>
</fieldset>
