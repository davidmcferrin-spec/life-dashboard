<?php
/**
 * Google Sheet connection for the Stocks page. The key stays encrypted.
 *
 * @var array<string, mixed> $user
 * @var array{spreadsheet_id: string, key_hint: string, configured: bool} $sheetSettings
 * @var array<string, mixed> $sheetSync
 */
$pageTitle = 'Stocks sheet';
$active = 'stock-sheet';
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsFormId = 'stock-sheet-form';
$settingsSubmitLabel = 'Save';
$hint = (string) ($sheetSettings['key_hint'] ?? '');
$placeholder = $hint === '' ? 'Paste the JSON key' : ($hint === 'set' ? 'Saved. Leave blank to keep it.' : 'Saved · ' . $hint . '. Leave blank to keep it.');
require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Connections'; $pageHeading = 'Stocks sheet'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead">The Stocks page reads this Google Sheet. The service account key is stored encrypted and is not shown again. Leave the key blank to keep the saved one. The sheet is read only.</p>
            <p class="text-sm text-muted mb-4"><?php
                if (empty($sheetSettings['configured'])) {
                    echo 'Not connected.';
                } elseif (($sheetSync['status'] ?? '') === 'error') {
                    echo 'Last pull failed. ' . e((string) ($sheetSync['sync_error'] ?? ''));
                } elseif (!empty($sheetSync['last_synced_at'])) {
                    echo 'Last synced ' . e((string) $sheetSync['last_synced_at']) . '.';
                } else {
                    echo 'Connected. Not synced yet.';
                }
            ?></p>
            <form id="stock-sheet-form" method="post" action="/settings/stock-sheet" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <div class="settings-field">
                    <label class="settings-label" for="stock-sheet-id">Spreadsheet id</label>
                    <input class="input" id="stock-sheet-id" name="spreadsheet_id" autocomplete="off" spellcheck="false" value="<?= e((string) ($sheetSettings['spreadsheet_id'] ?? '')) ?>">
                </div>
                <div class="settings-field">
                    <label class="settings-label" for="stock-sheet-key">Service account JSON</label>
                    <textarea class="input" id="stock-sheet-key" name="service_account_json" rows="6" spellcheck="false" autocomplete="off" placeholder="<?= e($placeholder) ?>"></textarea>
                    <p class="secret-field-hint"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/lock-keyhole.svg'); ?>Encrypted<?php if ($hint !== ''): ?> <span class="secret-field-mask"><?= e($hint === 'set' ? '••••••••' : '•••••••• ' . $hint) ?></span><?php endif; ?></p>
                </div>
            </form>
            <form method="post" action="/settings/stock-sheet/refresh" class="mt-4">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <button type="submit" class="btn">Pull sheet</button>
            </form>
<?php require __DIR__ . '/_shell-bottom.php'; ?>
