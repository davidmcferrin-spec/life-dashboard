<?php
/**
 * Cloudflare tunnel token, hostnames, and service controls.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $tunnel
 */

$pageTitle = 'Tunnel';
$active = 'tunnel';
$extraStyles = ['/assets/css/lifedash.css'];
$tunnelExisting = !empty($tunnel['status']['existing']) && !empty($tunnel['status']['connected']);

require __DIR__ . '/_shell-top.php';
?>

            <section class="settings-block space-y-4" id="tunnel-app">
                <header>
                    <?php $pageEyebrow = 'Settings'; $pageHeading = 'Tunnel'; require __DIR__ . '/../partials/page-heading.php'; ?>
                    <p id="tunnel-lead" class="text-muted text-sm">
                        <?php if ($tunnelExisting): ?>
                        cloudflared is already running on this server. This page shows that service. A token is not required, and this page will not install another tunnel.
                        <?php else: ?>
                        Create the tunnel in the Cloudflare Zero Trust dashboard, paste its token here, then add the two public hostnames below.
                        The server does not run <span class="mono">cloudflared login</span>.
                        <?php endif; ?>
                    </p>
                </header>

                <p id="tunnel-status" class="mono text-sm" role="status"><?= e(\Dashboard\Settings\TunnelSettings::statusLine(is_array($tunnel['status'] ?? null) ? $tunnel['status'] : [])) ?></p>

                <form id="tunnel-form" class="space-y-3" novalidate>
                    <div>
                        <label class="ld-field" for="tunnel-dash">Dashboard hostname</label>
                        <input id="tunnel-dash" class="input text-sm w-full" name="dash_host" autocomplete="off"
                            value="<?= e((string) ($tunnel['dash_host'] !== '' ? $tunnel['dash_host'] : $tunnel['suggest_dash'])) ?>"
                            placeholder="dash.example.com">
                    </div>
                    <div>
                        <label class="ld-field" for="tunnel-mcp">MCP hostname</label>
                        <input id="tunnel-mcp" class="input text-sm w-full" name="mcp_host" autocomplete="off"
                            value="<?= e((string) ($tunnel['mcp_host'] !== '' ? $tunnel['mcp_host'] : $tunnel['suggest_mcp'])) ?>"
                            placeholder="mcp.example.com">
                    </div>
                    <div>
                        <label class="ld-field" for="tunnel-token">Tunnel token</label>
                        <p id="tunnel-token-hint" class="text-faint text-xs mono mt-1">
                            <?php if ($tunnelExisting): ?>
                            Not required while cloudflared is already running.
                            <?php else: ?>
                            <?= $tunnel['token_set'] ? ('Set' . ($tunnel['token_hint'] !== '' && $tunnel['token_hint'] !== 'set' ? '. Ending in ' . e((string) $tunnel['token_hint']) : '.')) : 'Not set' ?>
                            <?php endif; ?>
                        </p>
                        <input id="tunnel-token" class="input text-sm w-full hidden" name="token" type="password" autocomplete="off" placeholder="Paste a new token">
                        <div class="flex flex-wrap gap-2 mt-2">
                            <button type="button" class="btn text-xs" id="tunnel-replace">Replace</button>
                            <?php if (!empty($tunnel['token_set'])): ?>
                            <button type="button" class="btn text-xs" id="tunnel-clear">Clear</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p id="tunnel-error" class="text-danger mono text-xs hidden" role="alert"></p>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="btn-primary text-sm">Save</button>
                        <span id="tunnel-actions" class="flex flex-wrap gap-2<?= $tunnelExisting ? ' hidden' : '' ?>">
                            <button type="button" class="btn text-sm" data-tunnel-action="tunnel-start">Start</button>
                            <button type="button" class="btn text-sm" data-tunnel-action="tunnel-stop">Stop</button>
                            <button type="button" class="btn text-sm" data-tunnel-action="tunnel-restart">Restart</button>
                        </span>
                    </div>
                </form>

                <div>
                    <h2 class="section-title text-sm">Public hostname routes</h2>
                    <p class="text-muted text-xs mb-2">Add these in the Cloudflare Zero Trust dashboard for this tunnel. Apache answers the dashboard on port <?= (int) \Dashboard\Settings\TunnelSettings::APACHE_PORT ?>.</p>
                    <ul class="space-y-2" id="tunnel-routes">
                        <?php foreach ($tunnel['routes'] as $route): ?>
                        <li class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="mono"><?= e((string) $route['copy']) ?></span>
                            <button type="button" class="btn text-xs" data-copy="<?= e((string) $route['copy']) ?>">Copy</button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
<?php
$settingsScripts = ['/assets/js/settings-tunnel.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';
