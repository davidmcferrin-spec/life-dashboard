<?php
/**
 * Local Ollama: URL, models, and the background pull.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $ollama
 */

$pageTitle   = 'Local AI (Ollama)';
$active      = 'ollama';
$extraStyles = ['/assets/css/lifedash.css'];
$settings    = $ollama['settings'];
$effective   = $ollama['effective'];
$hardware    = $ollama['hardware'];
$job         = $ollama['job'];
$health      = $ollama['health'];

require __DIR__ . '/_shell-top.php';
?>

            <div id="ollama-app">
            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Local AI (Ollama)'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    The service listens on 127.0.0.1:11434. Vault and search embeddings use the embedding model.
                    A model download runs in the background and does not hold a deploy.
                </p>
            </header>

            <section class="surface p-4 space-y-2 mb-4">
                <h2 class="section-title">Status</h2>
                <p class="text-sm ld-ollama-status" data-ollama-health data-status="<?= e((string) $health['status']) ?>"><?= e((string) $health['detail']) ?></p>
                <p class="text-sm text-muted" data-ollama-hardware>
                    <?php if (($hardware['accelerator'] ?? '') !== ''): ?>
                    Accelerator <?= e((string) $hardware['accelerator']) ?>,
                    RAM <?= e((string) $hardware['ram_mib']) ?> MiB,
                    VRAM <?= e((string) $hardware['vram_mib']) ?> MiB.
                    Defaults <?= e((string) $hardware['chat_model']) ?> and <?= e((string) $hardware['embed_model']) ?>.
                    <?php else: ?>
                    Hardware has not been recorded yet.
                    <?php endif; ?>
                </p>
                <p class="text-sm text-muted" data-ollama-job>
                    <?php if (($job['state'] ?? 'idle') !== 'idle' && ($job['detail'] ?? '') !== ''): ?>
                    <?= e((string) $job['detail']) ?>
                    <?php endif; ?>
                </p>
                <button type="button" class="btn" data-ollama-test>Test connection</button>
                <p class="text-sm" data-ollama-test-result></p>
            </section>

            <section class="surface p-4 space-y-3 mb-4">
                <h2 class="section-title">Settings</h2>
                <form class="space-y-3" data-ollama-settings>
                    <label class="block text-sm">
                        Base URL
                        <input class="input w-full mt-1" name="base_url" value="<?= e((string) $settings['base_url']) ?>" autocomplete="off">
                    </label>
                    <label class="block text-sm">
                        Chat model
                        <input class="input w-full mt-1" name="chat_model" value="<?= e((string) $settings['chat_model']) ?>" placeholder="<?= e((string) $effective['chat_model']) ?>" autocomplete="off">
                    </label>
                    <label class="block text-sm">
                        Embedding model
                        <input class="input w-full mt-1" name="embed_model" value="<?= e((string) $settings['embed_model']) ?>" autocomplete="off">
                    </label>
                    <p class="text-xs text-muted">An empty chat model uses the hardware default (<?= e((string) $effective['chat_model']) ?>).</p>
                    <p class="text-sm ld-field-error" data-ollama-error></p>
                    <button type="submit" class="btn-primary">Save</button>
                </form>
            </section>

            <section class="surface p-4 space-y-3">
                <h2 class="section-title">Models</h2>
                <div class="ld-ollama-list" data-ollama-models>
                    <?php if ($ollama['models'] === []): ?>
                    <p class="text-sm text-muted">No models installed yet.</p>
                    <?php else: ?>
                    <?php foreach ($ollama['models'] as $model): ?>
                    <div class="ld-ollama-row">
                        <span class="text-sm"><?= e((string) $model['name']) ?></span>
                        <button type="button" class="btn" data-ollama-remove="<?= e((string) $model['name']) ?>">Remove</button>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <form class="flex flex-wrap gap-2" data-ollama-pull>
                    <input class="input" name="model" placeholder="llama3.2:3b" autocomplete="off">
                    <button type="submit" class="btn-primary">Pull</button>
                </form>
            </section>
            </div>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/ollama.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
