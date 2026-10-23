            <?php
            $settingsFormId = (string) ($settingsFormId ?? '');
            if (!isset($settingsFooterNote)) {
                if ($settingsFormId !== '') {
                    $settingsFooterNote = 'Unsaved changes';
                } elseif (isset($settingsAutosave) && !$settingsAutosave) {
                    $settingsFooterNote = '';
                } else {
                    // Pages without a shell form already save from the page, or have nothing pending.
                    $settingsFooterNote = 'Saved automatically';
                }
            }
            $settingsFooterNote = (string) $settingsFooterNote;
            $settingsSubmitLabel = (string) ($settingsSubmitLabel ?? 'Save');
            ?>
            </div>
            <div class="settings-footer">
                <?php if (!empty($settingsPrev)): ?>
                <a class="btn settings-back" href="<?= e($settingsPrev['href']) ?>">
                    <span class="settings-back-icon" aria-hidden="true"><?php settings_shell_icon('chevron-left'); ?></span>
                    Back
                </a>
                <?php else: ?>
                <span class="btn settings-back is-disabled" aria-disabled="true">
                    <span class="settings-back-icon" aria-hidden="true"><?php settings_shell_icon('chevron-left'); ?></span>
                    Back
                </span>
                <?php endif; ?>
                <p class="settings-footer-note"><?= e($settingsFooterNote) ?></p>
                <?php if ($settingsFormId !== ''): ?>
                <button type="submit" class="btn-primary settings-primary" form="<?= e($settingsFormId) ?>"><?= e($settingsSubmitLabel) ?></button>
                <?php elseif (!empty($settingsNext)): ?>
                <a class="btn-primary settings-primary" href="<?= e($settingsNext['href']) ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($settingsPreviewView)): ?>
        <aside class="settings-preview" aria-label="Settings preview">
            <?php if ($settingsPreviewView['kicker'] !== ''): ?>
            <p class="settings-kicker"><?= e($settingsPreviewView['kicker']) ?></p>
            <?php endif; ?>
            <?php if ($settingsPreviewView['text'] !== ''): ?>
            <p class="settings-preview-text"><?= e($settingsPreviewView['text']) ?></p>
            <?php endif; ?>
            <?php if ($settingsPreviewView['percent'] !== null): ?>
            <div class="settings-progress">
                <p class="settings-progress-label"><span>Configuration</span><strong><?= e((string) $settingsPreviewView['percent']) ?>%</strong></p>
                <div class="settings-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) $settingsPreviewView['percent']) ?>" aria-label="Configuration">
                    <span style="width: <?= e((string) $settingsPreviewView['percent']) ?>%"></span>
                </div>
            </div>
            <?php endif; ?>
        </aside>
        <?php endif; ?>
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
