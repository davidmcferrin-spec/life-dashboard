<?php
/**
 * Masked secret control. Callers set the contract variables before require.
 * $secretHint is mask text only. $secretValue is empty when a secret is stored.
 * Show reveals only a value typed into this field.
 *
 * @var string $secretId
 * @var string $secretName
 * @var string $secretLabel
 * @var string $secretHint
 * @var string $secretValue
 * @var string $secretPlaceholder
 * @var string $secretAutocomplete
 * @var bool   $secretHidden
 */
$secretId = (string) ($secretId ?? '');
$secretName = (string) ($secretName ?? '');
$secretLabel = (string) ($secretLabel ?? '');
$secretHint = (string) ($secretHint ?? '');
$secretValue = (string) ($secretValue ?? '');
$secretPlaceholder = (string) ($secretPlaceholder ?? '');
$secretAutocomplete = (string) ($secretAutocomplete ?? 'off');
$secretHidden = (bool) ($secretHidden ?? false);

// Only a SecretMask hint is safe to print: "set" or the last 4 characters.
// Anything longer is treated as a full secret and stays out of the markup.
$maskText = '';
if ($secretHint === 'set') {
    $maskText = '••••••••';
} elseif (strlen($secretHint) === 4) {
    $maskText = '•••••••• ' . $secretHint;
} elseif ($secretHint !== '') {
    $maskText = '••••••••';
}
$placeholder = $secretPlaceholder !== '' ? $secretPlaceholder : $maskText;
$masked = $secretPlaceholder === '' && $maskText !== '';
$stored = $maskText !== '';
?>
<div class="secret-field" data-secret-field<?= $stored ? ' data-stored="1"' : '' ?>>
    <?php if ($secretLabel !== ''): ?>
    <label class="secret-field-label" for="<?= e($secretId) ?>"><?= e($secretLabel) ?></label>
    <?php endif; ?>
    <div class="secret-field-row">
        <div class="secret-field-control">
            <?php
            $secretAttrs = [
                'class' => 'input secret-field-input' . ($secretHidden ? ' hidden' : ''),
                'type' => 'password',
                'id' => $secretId,
                'name' => $secretName,
                'autocomplete' => $secretAutocomplete,
                'spellcheck' => 'false',
                'autocapitalize' => 'off',
            ];
            if ($placeholder !== '') {
                $secretAttrs['placeholder'] = $placeholder;
            }
            if ($masked) {
                $secretAttrs['data-mask'] = '1';
            }
            if ($secretValue !== '') {
                $secretAttrs['value'] = $secretValue;
            }
            $secretAttrHtml = '';
            foreach ($secretAttrs as $secretAttr => $secretAttrValue) {
                $secretAttrHtml .= ' ' . $secretAttr . '="' . e($secretAttrValue) . '"';
            }
            ?>
            <input<?= $secretAttrHtml ?>>
            <button type="button" class="secret-field-show" data-secret-show aria-pressed="false" aria-label="Show secret" disabled>
                <span class="secret-field-icon secret-field-icon-show"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/eye.svg'); ?></span>
                <span class="secret-field-icon secret-field-icon-hide"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/eye-off.svg'); ?></span>
            </button>
        </div>
        <?php if ($stored && !$secretHidden): ?>
        <button type="button" class="btn secret-field-replace" data-secret-replace><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/refresh-cw.svg'); ?>Replace</button>
        <?php endif; ?>
    </div>
    <p class="secret-field-hint"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/lock-keyhole.svg'); ?>Encrypted<?php if ($maskText !== ''): ?> <span class="secret-field-mask"><?= e($maskText) ?></span><?php endif; ?></p>
</div>
<?php
if (!defined('LIFEDASH_SECRET_FIELD_JS')) {
    define('LIFEDASH_SECRET_FIELD_JS', true);
    $secretJsVer = isset($assetVer) ? (string) $assetVer : '';
    echo '<script src="/assets/js/secret-field.js?v=' . e($secretJsVer) . '" defer></script>';
    unset($secretJsVer);
}
unset(
    $secretId,
    $secretName,
    $secretLabel,
    $secretHint,
    $secretValue,
    $secretPlaceholder,
    $secretAutocomplete,
    $secretHidden,
    $maskText,
    $placeholder,
    $masked,
    $stored,
    $secretAttrs,
    $secretAttr,
    $secretAttrValue,
    $secretAttrHtml
);
