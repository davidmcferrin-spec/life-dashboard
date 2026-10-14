<?php
/**
 * Agent tokens, the MCP prompt, and the audit trail.
 *
 * @var array<string, mixed> $user
 */

$pageTitle   = 'Agents';
$active      = 'agents';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <h1 class="text-primary text-xl font-medium">Agents</h1>
                <p class="text-muted text-sm mt-1">
                    A bearer token lets Claude or Grok call <span class="text-secondary">/api/v1</span> through the MCP server.
                    The token is shown once. Writes are marked <span class="text-secondary">source=ai</span> and listed below.
                </p>
            </header>

            <form id="agent-create" class="surface p-4 space-y-3 mb-4">
                <h2 class="section-title">New token</h2>
                <div class="ld-form-grid">
                    <label class="block ld-field">
                        <span>Name</span>
                        <input name="name" class="input w-full" required maxlength="64" placeholder="Claude" autocomplete="off">
                    </label>
                    <label class="block ld-field">
                        <span>Access</span>
                        <select name="access" class="input w-full">
                            <option value="all">Read and write</option>
                            <option value="read">Read only</option>
                        </select>
                    </label>
                </div>
                <button type="submit" class="btn-primary">Create token</button>
                <p id="agent-token-once" class="mono text-xs hidden"></p>
            </form>

            <section class="surface p-4 mb-4">
                <h2 class="section-title">Tokens</h2>
                <div id="agent-tokens" class="mt-2"><p class="empty-state mono text-xs">Loading…</p></div>
            </section>

            <section class="surface p-4 mb-4">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="section-title">Prompt</h2>
                    <button type="button" id="agent-copy-prompt" class="btn text-xs">Copy</button>
                </div>
                <textarea id="agent-prompt" class="input w-full mt-2 ld-markup" rows="10" readonly></textarea>
            </section>

            <section class="surface p-4">
                <h2 class="section-title">Audit</h2>
                <div id="agent-audit" class="mt-2"><p class="empty-state mono text-xs">Loading…</p></div>
            </section>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-agents.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
