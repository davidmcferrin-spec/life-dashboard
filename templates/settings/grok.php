<?php
/**
 * Grok API URL and secret on the Connections step.
 *
 * @var array<string, mixed> $user
 * @var array{api_url: string, secret_hint: string} $grok
 */

if (!function_exists('grok_connection_intro')) {
    function grok_connection_intro(): void
    {
        $pageEyebrow = 'Connections';
        $pageHeading = 'Connect your digital world.';
        require __DIR__ . '/../partials/page-heading.php';
        echo '<p class="text-muted text-sm mt-1 mb-6">Add service URLs and credentials without touching a config file.</p>';
    }

    function grok_connection_url_field(string $url): void
    {
        ?>
        <div class="settings-field">
            <label class="settings-label" for="grok-api-url">Grok API URL</label>
            <input class="input" id="grok-api-url" name="api_url" type="url" inputmode="url" autocomplete="off" spellcheck="false" placeholder="https://api.x.ai/v1" value="<?= e($url) ?>">
        </div>
        <?php
    }

    /**
     * @param string $hint SecretMask text only: "set" or the last 4 characters.
     */
    function grok_connection_secret_field(string $hint, string $assetVer = ''): void
    {
        $secretId = 'grok-api-secret';
        $secretName = 'api_secret';
        $secretLabel = 'API secret';
        $secretHint = $hint;
        $secretValue = '';
        $secretPlaceholder = '';
        $secretAutocomplete = 'off';
        $secretHidden = false;
        require __DIR__ . '/../partials/secret-field.php';
    }
}

$pageTitle = 'Grok';
$active = 'grok';
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsFormId = 'grok-connection-form';
$settingsSubmitLabel = 'Save';

require __DIR__ . '/_shell-top.php';
?>

            <?php grok_connection_intro(); ?>
            <form id="grok-connection-form" method="post" action="/settings/grok" autocomplete="off">
                <?php // A page render in tests sets $grokCsrf so this form does not open a session. ?>
                <input type="hidden" name="_csrf" value="<?= e(isset($grokCsrf) ? (string) $grokCsrf : csrfToken()) ?>">
                <?php grok_connection_url_field((string) ($grok['api_url'] ?? '')); ?>
                <?php grok_connection_secret_field((string) ($grok['secret_hint'] ?? ''), (string) ($assetVer ?? '')); ?>
            </form>

<?php require __DIR__ . '/_shell-bottom.php'; ?>
