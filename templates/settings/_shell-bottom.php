            <?php
            if (!isset($settingsFooterNote)) {
                $settingsFooterNote = ($settingsFormId ?? '') === '' ? 'Changes saved automatically' : '';
            }
            ?>
            <div class="settings-footer">
                <?php if (!empty($settingsPrev)): ?>
                <a class="btn" href="<?= e($settingsPrev['href']) ?>">Back</a>
                <?php else: ?>
                <span></span>
                <?php endif; ?>
                <?php if (($settingsFooterNote ?? '') !== ''): ?>
                <p class="settings-footer-note"><?= e((string) $settingsFooterNote) ?></p>
                <?php endif; ?>
                <?php if (($settingsFormId ?? '') !== ''): ?>
                <button type="submit" class="btn-primary" form="<?= e((string) $settingsFormId) ?>"><?= e((string) ($settingsSubmitLabel ?? 'Save')) ?></button>
                <?php elseif (!empty($settingsNext)): ?>
                <a class="btn-primary" href="<?= e($settingsNext['href']) ?>">Continue</a>
                <?php else: ?>
                <span></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/../partials/appearance-scripts.php'; ?>
<script src="/assets/js/settings-shell.js?v=<?= e($assetVer) ?>"></script>
<?php if (!empty($settingsScripts)): ?>
<?php foreach ((array) $settingsScripts as $script): ?>
<script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
