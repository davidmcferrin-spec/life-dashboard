<?php
/**
 * Wake Hub origin and bearer token. The token stays encrypted.
 *
 * @var array<string, mixed> $user
 * @var array{base_url: string, token_hint: string, configured: bool} $wakeSettings
 */
$pageTitle = 'Wake Hub';
$active = 'wake-hub';
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsFormId = 'wake-hub-form';
$settingsSubmitLabel = 'Save';
$hint = (string) ($wakeSettings['token_hint'] ?? '');
$placeholder = $hint === '' ? 'Paste the bearer token' : ($hint === 'set' ? 'Saved. Leave blank to keep it.' : 'Saved · ' . $hint . '. Leave blank to keep it.');
require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Connections'; $pageHeading = 'Wake Hub'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead">The dashboard calls Wake Hub's REST API. The token is stored encrypted and is not shown again. Leave it blank to keep the saved one. Enter the base URL with its host and port. Nothing here assumes either. Wake Hub's own default port is 8765, which is also this dashboard's MCP port, so use the port Wake Hub is actually listening on.</p>
            <p class="text-sm text-muted mb-4"><?= empty($wakeSettings['configured']) ? 'Not connected.' : 'Connected.' ?> <a class="nav-link" href="/wake">Open the device list</a></p>
            <form id="wake-hub-form" method="post" action="/settings/wake-hub" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <div class="settings-field">
                    <label class="settings-label" for="wake-hub-url">Base URL</label>
                    <input class="input" id="wake-hub-url" name="base_url" autocomplete="off" spellcheck="false" inputmode="url" placeholder="http://host:port" value="<?= e((string) ($wakeSettings['base_url'] ?? '')) ?>">
                </div>
                <div class="settings-field">
                    <label class="settings-label" for="wake-hub-token">Bearer token</label>
                    <input class="input" id="wake-hub-token" name="token" type="password" spellcheck="false" autocomplete="new-password" placeholder="<?= e($placeholder) ?>">
                    <p class="secret-field-hint"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/lock-keyhole.svg'); ?>Encrypted<?php if ($hint !== ''): ?> <span class="secret-field-mask"><?= e($hint === 'set' ? '••••••••' : '•••••••• ' . $hint) ?></span><?php endif; ?></p>
                </div>
            </form>
            <form method="post" action="/settings/wake-hub/test" class="mt-4">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <button type="submit" class="btn">Test connection</button>
            </form>
<?php require __DIR__ . '/_shell-bottom.php'; ?>
