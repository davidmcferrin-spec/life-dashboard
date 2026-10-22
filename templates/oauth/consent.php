<?php
/**
 * OAuth consent. The signed-in user picks which of the client's requested
 * scopes this connection may use.
 *
 * @var string $clientName
 * @var string $redirectUri
 * @var list<string> $scopes
 * @var string $error
 */
$pageTitle = 'Allow access';
$user = $user ?? [];
$themeLocalOnly = false;
if (!isset($themePref) && !empty($user['id'])) {
    $consentPrefs = new \Dashboard\Settings\PreferenceService(\Dashboard\DB\Database::getInstance());
    $themePref = $consentPrefs->getTheme((int) $user['id']);
    $starfieldPref = $consentPrefs->getStarfield((int) $user['id']);
}
require dirname(__DIR__) . '/partials/head.php';
?>
<body class="page min-h-screen flex items-center justify-center px-4 py-8">
    <form method="POST" action="/oauth/authorize" class="surface p-6 w-full max-w-md" id="oauth-consent" data-oauth-consent="1">
        <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
        <h1 class="text-primary text-xl font-medium">Allow <?= e($clientName) ?></h1>
        <p class="text-muted text-sm mt-2">
            This client wants to use your dashboard through the MCP server.
            It will return to <span class="mono text-xs"><?= e($redirectUri) ?></span>.
            Uncheck anything you do not want it to do.
        </p>
        <?php if ($error !== ''): ?>
        <p class="ld-field-error mt-3" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <fieldset class="mt-4 space-y-1">
            <legend class="text-sm text-primary mb-1">Scopes</legend>
            <?php foreach ($scopes as $scope): ?>
            <label class="flex items-center gap-2 text-sm text-primary">
                <input type="checkbox" name="scopes[]" value="<?= e($scope) ?>" checked>
                <span class="mono"><?= e($scope) ?></span>
            </label>
            <?php endforeach; ?>
        </fieldset>
        <div class="flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn-primary">Allow</button>
            <a class="btn" href="/">Cancel</a>
        </div>
    </form>
</body>
</html>
