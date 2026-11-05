<?php
/**
 * @var array<string, mixed> $user
 * @var array{base_url: string, secret_hint: string, configured: bool} $homeAssistant
 */
$pageTitle = 'Home Assistant';
$active = 'home';
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsFormId = 'home-connection-form';
$settingsSubmitLabel = 'Save';
require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Connections'; $pageHeading = 'Home Assistant'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead">Optional read of your Home Assistant. The token is stored encrypted. A connecting key still needs a home grant before a brief or status call includes it.</p>
            <form id="home-connection-form" method="post" action="/settings/home" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                <div class="settings-field">
                    <label class="settings-label" for="ha-url">Base URL</label>
                    <input class="input" id="ha-url" name="base_url" type="url" inputmode="url" autocomplete="off" spellcheck="false" placeholder="http://homeassistant.local:8123" value="<?= e((string) ($homeAssistant['base_url'] ?? '')) ?>">
                </div>
                <?php
                $secretId = 'ha-token';
                $secretName = 'api_secret';
                $secretLabel = 'Long-lived token';
                $secretHint = (string) ($homeAssistant['secret_hint'] ?? '');
                $secretValue = '';
                $secretPlaceholder = '';
                $secretAutocomplete = 'off';
                $secretHidden = false;
                require __DIR__ . '/../partials/secret-field.php';
                ?>
            </form>
<?php require __DIR__ . '/_shell-bottom.php'; ?>
