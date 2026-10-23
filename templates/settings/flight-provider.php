<?php
/**
 * Flight provider. The key is encrypted with the other setup secrets.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $flightSetup
 */

$byKey = [];
foreach ($flightSetup['fields'] as $field) {
    if (is_array($field) && isset($field['key'])) {
        $byKey[(string) $field['key']] = $field;
    }
}

$provider = (string) ($byKey['FLIGHT_PROVIDER']['value'] ?? 'opensky');
if (!in_array($provider, ['opensky', 'flightaware'], true)) {
    $provider = 'opensky';
}

$flightSecretStatus = static function (array $field): string {
    $hint = (string) ($field['hint'] ?? '');
    if ($hint === '') {
        return 'Not set. Leave blank to keep it that way.';
    }
    if ($hint === 'set') {
        return 'Saved. Leave blank to keep the current value.';
    }
    return 'Saved. Ends in ' . $hint . '. Leave blank to keep it.';
};

$pageTitle = 'Flight provider';
$active = 'flight-provider';
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsFormId = 'flight-provider-form';
$settingsSubmitLabel = 'Save';

require __DIR__ . '/_shell-top.php';
?>

            <?php $pageEyebrow = ''; $pageHeading = 'Flight provider'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="text-muted text-sm mt-1 mb-6">
                OpenSky reports aircraft positions. FlightAware adds airline delay and gate data.
                Keys are stored encrypted and shown masked.
            </p>

            <form id="flight-provider-form" class="flight-provider-form" method="post" action="/settings/flight-provider">
                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">

                <div class="settings-field">
                    <span class="settings-label" id="flight-provider-label">Provider</span>
                    <div class="settings-seg" role="radiogroup" aria-labelledby="flight-provider-label">
                        <label class="settings-seg-btn">
                            <input class="settings-seg-input" type="radio" name="provider" value="opensky"<?= $provider === 'opensky' ? ' checked' : '' ?>>
                            OpenSky
                        </label>
                        <label class="settings-seg-btn">
                            <input class="settings-seg-input" type="radio" name="provider" value="flightaware"<?= $provider === 'flightaware' ? ' checked' : '' ?>>
                            FlightAware
                        </label>
                    </div>
                </div>

                <div data-provider="opensky">
                    <div class="settings-field">
                        <?php
                        $secretId = 'opensky-client-id';
                        $secretName = 'opensky_client_id';
                        $secretLabel = 'OpenSky client id';
                        $secretHint = (string) ($byKey['OPENSKY_CLIENT_ID']['hint'] ?? '');
                        $secretValue = '';
                        $secretPlaceholder = '';
                        $secretAutocomplete = 'off';
                        $secretHidden = false;
                        require __DIR__ . '/../partials/secret-field.php';
                        ?>
                        <p class="settings-help"><?= e($flightSecretStatus($byKey['OPENSKY_CLIENT_ID'] ?? [])) ?></p>
                    </div>
                    <div class="settings-field">
                        <?php
                        $secretId = 'opensky-client-secret';
                        $secretName = 'opensky_client_secret';
                        $secretLabel = 'OpenSky client secret';
                        $secretHint = (string) ($byKey['OPENSKY_CLIENT_SECRET']['hint'] ?? '');
                        $secretValue = '';
                        $secretPlaceholder = '';
                        $secretAutocomplete = 'off';
                        $secretHidden = false;
                        require __DIR__ . '/../partials/secret-field.php';
                        ?>
                        <p class="settings-help"><?= e($flightSecretStatus($byKey['OPENSKY_CLIENT_SECRET'] ?? [])) ?></p>
                    </div>
                </div>

                <div data-provider="flightaware">
                    <div class="settings-field">
                        <?php
                        $secretId = 'flightaware-key';
                        $secretName = 'flightaware_key';
                        $secretLabel = 'FlightAware key';
                        $secretHint = (string) ($byKey['FLIGHTAWARE_API_KEY']['hint'] ?? '');
                        $secretValue = '';
                        $secretPlaceholder = '';
                        $secretAutocomplete = 'off';
                        $secretHidden = false;
                        require __DIR__ . '/../partials/secret-field.php';
                        ?>
                        <p class="settings-help"><?= e($flightSecretStatus($byKey['FLIGHTAWARE_API_KEY'] ?? [])) ?></p>
                    </div>
                </div>
            </form>

<?php require __DIR__ . '/_shell-bottom.php'; ?>
