<?php
/**
 * @var array<string, mixed> $user
 * @var array{api_url: string, user_id: string, secret_hint: string, configured: bool} $wearables
 */
$pageTitle = 'Open Wearables';
$active = 'health';
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsFormId = 'health-connection-form';
$settingsSubmitLabel = 'Save';
require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Connections'; $pageHeading = 'Open Wearables'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead">Samsung Health and Health Connect sync through Open Wearables. The API key is stored encrypted and is not shown again. Leave the key blank to keep the saved one.</p>
            <form id="health-connection-form" method="post" action="/settings/health" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <div class="settings-field">
                    <label class="settings-label" for="ow-url">Open Wearables URL</label>
                    <input class="input" id="ow-url" name="api_url" type="url" inputmode="url" autocomplete="off" spellcheck="false" placeholder="https://wearables.example" value="<?= e((string) ($wearables['api_url'] ?? '')) ?>">
                </div>
                <div class="settings-field">
                    <label class="settings-label" for="ow-user">User id</label>
                    <input class="input" id="ow-user" name="user_id" autocomplete="off" spellcheck="false" placeholder="UUID from Open Wearables" value="<?= e((string) ($wearables['user_id'] ?? '')) ?>">
                </div>
                <?php
                $secretId = 'ow-api-secret';
                $secretName = 'api_secret';
                $secretLabel = 'API key';
                $secretHint = (string) ($wearables['secret_hint'] ?? '');
                $secretValue = '';
                $secretPlaceholder = '';
                $secretAutocomplete = 'off';
                $secretHidden = false;
                require __DIR__ . '/../partials/secret-field.php';
                ?>
            </form>
<?php require __DIR__ . '/_shell-bottom.php'; ?>
