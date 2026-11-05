<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Agents';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page" id="agents-app">
    <?php $pageEyebrow = 'Fleet'; $pageHeading = 'Agents'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Hermes, OpenCode, and OpenClaw on this machine. OpenCode stays status-only. A connecting key needs an agents grant before it can send, stop, or ask. Your signed-in session can.</p>
    <p id="agents-error" class="ld-error hidden"></p>
    <div class="platform-grid" id="agents-cards"></div>
    <form class="platform-card platform-form" id="agents-send">
        <p class="platform-kicker">Hermes</p>
        <textarea class="input" name="message" maxlength="8000" placeholder="Message" required></textarea>
        <div class="platform-actions">
            <button class="btn-primary" type="submit">Send</button>
            <button class="btn" type="button" id="agents-poll">Poll</button>
            <button class="btn" type="button" id="agents-stop">Stop</button>
        </div>
    </form>
    <form class="platform-card platform-form" id="agents-ask">
        <p class="platform-kicker">OpenClaw</p>
        <textarea class="input" name="prompt" maxlength="4000" placeholder="Prompt" required></textarea>
        <button class="btn-primary" type="submit">Ask</button>
    </form>
    <pre class="platform-card platform-pre" id="agents-output"></pre>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/agents-fleet.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
