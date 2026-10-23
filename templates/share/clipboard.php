<?php
/**
 * Public share view — access via token only (one clipboard).
 *
 * @var string $shareToken 12-char share code
 */
$shareToken = $shareToken ?? '';
?>
<?php require __DIR__ . '/../partials/head.php'; ?>
<body class="page min-h-screen">

<nav class="chrome flex items-center justify-between px-6 py-3">
    <span class="brand">Shared Clipboard</span>
    <span id="share-status" class="text-secondary mono text-xs"></span>
</nav>

<main class="max-w-screen-lg mx-auto px-4 py-6">
    <section id="share-editor-wrap" class="hidden surface p-4">
        <?php $pageEyebrow = 'Shared clipboard'; $pageHeading = ''; $pageHeadingId = 'share-board-name'; require __DIR__ . '/../partials/page-heading.php'; ?>
        <p id="share-perm-hint" class="text-faint mono text-xs mb-3"></p>

        <div id="share-remote-banner" class="hidden mb-2 mono text-xs px-3 py-2 rounded flash-warn">
            Updated elsewhere.
            <button type="button" id="share-reload" class="underline ml-2">Reload</button>
        </div>

        <div class="flex items-center gap-2 mb-2">
            <span class="text-faint mono text-xs">View:</span>
            <button type="button" id="share-view-edit" class="clip-view-tab clip-view-active mono text-xs px-2 py-1 rounded" aria-pressed="true">Edit</button>
            <button type="button" id="share-view-preview" class="clip-view-tab mono text-xs px-2 py-1 rounded" aria-pressed="false">Preview</button>
        </div>

        <textarea id="share-editor" rows="16" class="input w-full mono text-sm leading-relaxed"
            style="background: var(--editor-bg);" spellcheck="false"
            placeholder="Shared clipboard content…"></textarea>
        <div id="share-preview" class="clipboard-preview hidden w-full min-h-[50vh] overflow-auto p-3 text-sm leading-relaxed surface" tabindex="0" aria-label="Markdown preview"></div>

        <div class="mt-3 flex flex-wrap gap-2 justify-end">
            <button type="button" id="share-copy" class="btn">Copy</button>
            <button type="button" id="share-save" class="hidden btn-primary">Save</button>
        </div>
    </section>
</main>

<script>
    window.SHARE_CLIPBOARD_TOKEN = <?= json_encode($shareToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="/assets/js/clipboard-markdown.js" defer></script>
<script src="/assets/js/share-clipboard.js" defer></script>
</body>
</html>
