<?php
/**
 * Chat with the local Hermes Agent. The gateway key stays on the server.
 *
 * @var array<string, mixed> $user
 * @var string $assetVer
 */
$pageTitle = 'Hermes';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page hermes-chat min-h-screen">

<?php require __DIR__ . '/partials/app-shell-open.php'; ?>

<main class="hermes-page" id="hermes-app">
    <header class="hermes-head">
        <p class="hermes-kicker">Local agent</p>
        <h1 class="hermes-title">Hermes</h1>
        <p class="hermes-lede">
            Messages go to Hermes on this machine. Hermes and OpenCode both use
            <span class="hermes-mono">lifedash-coder</span>
            (<span class="hermes-mono">qwen2.5-coder:7b</span>, 65536 token context)
            through Ollama at 127.0.0.1:11434.
            A 12 GiB GPU holds that model and the 64k cache. An 8 GiB GPU, or 16 GiB of RAM with no GPU, still runs it with some layers in system RAM.
        </p>
    </header>
    <p class="hermes-status" data-hermes-status role="status"></p>
    <div class="hermes-log" data-hermes-log data-page="<?= (int) \Dashboard\Hermes\Chat::PAGE ?>" role="log" aria-relevant="additions">
        <p class="hermes-empty">Loading…</p>
    </div>
    <form class="hermes-form" data-hermes-form>
        <label class="text-sm" for="hermes-message">Message</label>
        <textarea id="hermes-message" class="input" name="message" maxlength="8000" placeholder="Ask Hermes…" aria-describedby="hermes-hint" required></textarea>
        <p class="hermes-hint" id="hermes-hint">Enter adds a line. Ctrl+Enter sends.</p>
        <div class="hermes-actions">
            <button type="submit" class="btn-primary" data-hermes-send>Send</button>
            <button type="button" class="btn" data-hermes-new>New chat</button>
        </div>
    </form>
</main>

<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/hermes.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
