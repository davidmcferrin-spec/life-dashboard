<?php
/**
 * Generic public share denial — no token or reason disclosed.
 *
 * @var string $pageTitle
 * @var string $themePref
 * @var bool   $themeLocalOnly
 */
?>
<?php require __DIR__ . '/../partials/head.php'; ?>
<body class="page min-h-screen flex items-center justify-center">

<main class="text-center px-4">
    <?php $pageEyebrow = 'Dashboard'; $pageHeading = 'Access denied'; $pageHeadingCenter = true; require __DIR__ . '/../partials/page-heading.php'; ?>
    <p class="text-muted text-sm mt-4">This link cannot be opened.</p>
</main>

</body>
</html>
