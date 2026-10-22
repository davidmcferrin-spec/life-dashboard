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
                    Read and write covers every area, including money, todos, habits, and widgets.
                    Read only cannot change anything.
                    The full token appears once, in a dialog. Writes are marked <span class="text-secondary">source=ai</span> and listed below.
                    If a token is already in the list and you never copied it, revoke it and create a new one.
                    An admin can change the name and scopes of an active token. The secret stays the same and is not shown again.
                    Delete removes a revoked token. The audit log keeps its name.
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
                            <option value="reddit">Reddit only</option>
                            <option value="money">Money only (90 days)</option>
                            <option value="todos">Todos only</option>
                        </select>
                    </label>
                </div>
                <?php if ((int) ($user['is_admin'] ?? 0) === 1): ?>
                <div class="space-y-1">
                    <label class="flex items-center gap-2 text-sm text-primary">
                        <input type="checkbox" name="system_read" value="1">
                        <span><span class="mono">system:read</span> — deploy status and log</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-primary">
                        <input type="checkbox" name="system_deploy" value="1">
                        <span><span class="mono">system:deploy</span> — queue an update of main</span>
                    </label>
                    <p class="text-muted text-xs">
                        These are not included in Read and write or Read only. Only an admin can add them.
                        <span class="mono">system:deploy</span> can also read status and the log.
                    </p>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-primary">Create token</button>
                <p id="agent-create-error" class="ld-field-error" role="alert"></p>
            </form>

            <section class="surface p-4 mb-4" id="oauth-clients">
                <h2 class="section-title">OAuth clients</h2>
                <p class="text-muted text-sm mt-1">
                    Claude custom connectors register here and ask you to allow scopes.
                    Existing bearer tokens on this page are unchanged.
                    Revoke cuts off that client and its refresh tokens.
                </p>
                <?php $oauthClients = $oauthClients ?? []; ?>
                <?php if ($oauthClients === []): ?>
                <p class="empty-state mono text-xs mt-3">No OAuth clients yet.</p>
                <?php else: ?>
                <ul class="mt-3 space-y-3">
                    <?php foreach ($oauthClients as $client): ?>
                    <li class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-primary text-sm"><?= e((string) $client['client_name']) ?></div>
                            <div class="mono text-xs text-muted mt-1"><?= e(implode(' ', $client['granted_scopes'] ?? [])) ?></div>
                            <div class="text-xs text-muted mt-1">
                                Last used
                                <?php if (!empty($client['last_used_at'])): ?>
                                    <?= e(gmdate('Y-m-d H:i', (int) $client['last_used_at'])) ?> UTC
                                <?php else: ?>
                                    never
                                <?php endif; ?>
                            </div>
                        </div>
                        <form method="POST" action="/settings/oauth/<?= (int) $client['id'] ?>/revoke">
                            <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                            <button type="submit" class="btn text-xs">Revoke</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>

            <section class="surface p-4 mb-4">
                <h2 class="section-title">Tokens</h2>
                <div id="agent-tokens" class="mt-2" data-admin="<?= (int) ($user['is_admin'] ?? 0) === 1 ? '1' : '0' ?>" data-areas="<?= e(json_encode(\Dashboard\Api\V1\Scope::AREAS, JSON_THROW_ON_ERROR)) ?>"><p class="empty-state mono text-xs">Loading…</p></div>
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

            <?php if ((int) ($user['is_admin'] ?? 0) === 1): ?>
            <div id="agent-edit-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="agent-edit-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h3 id="agent-edit-title" class="section-title text-sm">Edit token</h3>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <p class="text-muted text-sm">The secret does not change and is not shown again. Scope changes apply on the next request.</p>
                    <form id="agent-edit" class="space-y-3 mt-3">
                        <label class="block ld-field">
                            <span>Name</span>
                            <input name="name" class="input w-full" required maxlength="64" autocomplete="off">
                        </label>
                        <fieldset class="space-y-1">
                            <legend class="text-sm text-primary mb-1">Access</legend>
                            <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" data-access value="all"> Read and write</label>
                            <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" data-access value="read"> Read only</label>
                            <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" data-access value="reddit"> Reddit only</label>
                            <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" data-access value="money"> Money only</label>
                            <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" data-access value="todos"> Todos only</label>
                            <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" name="system_read" value="1"> <span class="mono">system:read</span></label>
                            <label class="flex items-center gap-2 text-sm text-primary"><input type="checkbox" name="system_deploy" value="1"> <span class="mono">system:deploy</span></label>
                        </fieldset>
                        <p id="agent-edit-current" class="text-muted text-xs mono"></p>
                        <p id="agent-edit-error" class="ld-field-error" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary text-sm">Save</button>
                            <button type="button" class="btn text-sm" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <div id="agent-delete-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="agent-delete-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h3 id="agent-delete-title" class="section-title text-sm">Delete token</h3>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <p class="text-muted text-sm">Delete <strong id="agent-delete-name"></strong>? This removes the revoked token. Audit entries keep its name.</p>
                    <p id="agent-delete-error" class="ld-field-error" role="alert"></p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <button type="button" id="agent-delete-confirm" class="btn-danger text-sm">Delete</button>
                        <button type="button" class="btn text-sm" data-close>Cancel</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-agents.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
