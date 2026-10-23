<?php
/**
 * Small uppercase eyebrow and the 32px page title.
 *
 * Set $pageEyebrow and $pageHeading before including.
 * Optional $pageHeadingId when script fills the title.
 * Optional $pageHeadingCenter for the login card.
 */
$pageEyebrow = (string) ($pageEyebrow ?? '');
$pageHeading = (string) ($pageHeading ?? '');
$pageHeadingId = (string) ($pageHeadingId ?? '');
$pageHeadingCenter = !empty($pageHeadingCenter);
$headingPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($headingPath) || $headingPath === '') {
    $headingPath = '/';
}
// Home keeps the timezone bar and drops this block so the search row can sit higher.
if ($headingPath === '/') {
    unset($pageEyebrow, $pageHeading, $pageHeadingId, $pageHeadingCenter);
    return;
}
$headingClass = 'page-heading' . ($pageHeadingCenter ? ' page-heading-center' : '');
?>
<div class="<?= e($headingClass) ?>">
    <?php if ($pageEyebrow !== ''): ?>
    <p class="page-eyebrow"><?= e($pageEyebrow) ?></p>
    <?php endif; ?>
    <h1 class="page-title"<?= $pageHeadingId !== '' ? ' id="' . e($pageHeadingId) . '"' : '' ?>><?= e($pageHeading) ?></h1>
</div>
<?php
unset($pageEyebrow, $pageHeading, $pageHeadingId, $pageHeadingCenter);
