<?php
/**
 * Agent tokens, the MCP prompt, and the audit trail.
 *
 * @var array<string, mixed> $user
 */

use Dashboard\Config;

$mcpPublic = Config::maybe('MCP_PUBLIC_URL');
$mcpPublic = is_string($mcpPublic) ? trim($mcpPublic) : '';
$mcpPort   = Config::maybe('MCP_PORT') ?: '8765';
$mcpLocal  = 'http://127.0.0.1:' . $mcpPort . '/mcp';

$pageTitle   = 'Agents';
$active      = 'agents';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <h1 class="text-primary text-xl font-medium">Agents</h1>
                <p class="text-muted text-sm mt-1">
                    A bearer token lets Claude or Grok use the same dashboard data as this account.
                    Read and write covers every area, including money, habits, and widgets.
                    Read only cannot change anything.
                    The full token appears once, in a dialog. Writes are marked <span class="text-secondary">source=ai</span> and listed below.
                    If a token is already in the list and you never copied it, revoke it and create a new one.
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
                <p id="agent-create-error" class="ld-field-error" role="alert"></p>
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

            <div id="agent-token-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="agent-token-title" data-mcp-url="<?= e($mcpPublic !== '' ? $mcpPublic : $mcpLocal) ?>">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface ld-modal-wide">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h3 id="agent-token-title" class="section-title text-sm">Copy your agent token</h3>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <p class="text-muted text-sm">
                        This is the only time the full token is shown. The list on this page keeps the prefix.
                        Copy it before you close. If you lose it, revoke the token and create another.
                    </p>
                    <h4 class="section-title text-sm mt-3">Connect Grok or Cursor</h4>
                    <ol class="ld-token-steps">
                        <li>Add an MCP server and choose HTTP. Leave <strong>OAuth client ID</strong> and <strong>OAuth client secret</strong> empty. This server uses the bearer token below, not OAuth.</li>
                        <li>Set the URL to the MCP address. It must end in <code>/mcp</code>. <code>/api/v1</code> is the API the MCP server calls for you, not the address you give the client.</li>
                        <li>Add an HTTP header named <code>Authorization</code>. The value is <code>Bearer</code>, a space, then the token. <strong>Copy header</strong> puts that whole value on the clipboard.</li>
                        <li>Do not paste the token into a chat.</li>
                    </ol>
                    <label class="block ld-field">
                        <span>MCP URL</span>
                        <input id="agent-token-url" class="input mono w-full" readonly>
                    </label>
                    <p id="agent-token-url-note" class="text-muted text-xs mt-1<?= $mcpPublic !== '' ? ' hidden' : '' ?>">
                        No public MCP URL is set (<code>MCP_PUBLIC_URL</code>). The address above is the server on this machine.
                        Grok in the cloud cannot reach it. Use the Cloudflare tunnel URL that ends in <code>/mcp</code>, or use Cursor on a computer that can reach this host.
                    </p>
                    <label class="block ld-field mt-3">
                        <span>Token</span>
                        <textarea id="agent-token-value" class="input mono w-full ld-token-secret" rows="2" readonly spellcheck="false"></textarea>
                    </label>
                    <label class="block ld-field mt-3">
                        <span>Authorization header value</span>
                        <textarea id="agent-token-header" class="input mono w-full ld-token-secret" rows="2" readonly spellcheck="false"></textarea>
                    </label>
                    <p id="agent-token-status" class="text-muted text-xs mt-2" role="status"></p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <button type="button" id="agent-token-copy" class="btn-primary text-sm">Copy token</button>
                        <button type="button" id="agent-token-copy-header" class="btn text-sm">Copy header</button>
                        <button type="button" class="btn text-sm" data-close>Close</button>
                    </div>
                </div>
            </div>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-agents.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
