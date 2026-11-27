<?php
/**
 * Homelab URLs and secrets. Secrets stay encrypted and are shown masked.
 *
 * @var array<string, mixed> $user
 * @var array<string, array<string, mixed>> $homelabSettings
 */
$pageTitle = 'Homelab';
$active = 'homelab';
$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsWide = true;
$settingsMainClass = 'settings-main homelab-settings-main';
require __DIR__ . '/_shell-top.php';
?>
            <?php $pageEyebrow = 'Connections'; $pageHeading = 'Homelab'; require __DIR__ . '/../partials/page-heading.php'; ?>
            <p class="platform-lead">The dashboard calls each app on the LAN by the URL you enter. API keys, the qBittorrent password, and the qBittorrent API token are stored encrypted and are not shown again. Leave a secret blank to keep the saved one. Nothing here is read from an environment file.</p>
            <p class="text-sm text-muted mb-4"><a class="nav-link" href="/homelab">Open the homelab page</a></p>
            <div class="homelab-settings">
                <?php foreach ($homelabSettings as $service => $row): ?>
                <?php
                    $hint = (string) ($row['secret_hint'] ?? '');
                    $secretLabel = (string) ($row['secret_label'] ?? 'Secret');
                    $placeholder = $hint === '' ? 'Paste the ' . strtolower($secretLabel) : ($hint === 'set' ? 'Saved. Leave blank to keep it.' : 'Saved · ' . $hint . '. Leave blank to keep it.');
                    $icon = (string) ($row['icon'] ?? 'server');
                    $iconPath = __DIR__ . '/../../public/assets/vendor/lucide/' . $icon . '.svg';
                ?>
                <section class="platform-card homelab-setting" id="homelab-<?= e((string) $service) ?>">
                    <h2 class="section-title">
                        <?php if (is_file($iconPath)): ?><span class="ld-w-icon" aria-hidden="true"><?php readfile($iconPath); ?></span><?php endif; ?>
                        <?= e((string) $row['label']) ?>
                    </h2>
                    <p class="text-sm text-muted"><?= !empty($row['ready']) ? 'Connected.' : (!empty($row['configured']) ? 'Saved, turned off.' : 'Not connected.') ?></p>
                    <p class="text-xs text-muted"><?= e((string) ($row['where'] ?? '')) ?></p>
                    <form method="post" action="/settings/homelab/<?= e((string) $service) ?>" autocomplete="off">
                        <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="enabled" value="0">
                        <label class="flex items-center gap-2 text-sm text-primary settings-field">
                            <input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>>
                            Enabled
                        </label>
                        <div class="settings-field">
                            <label class="settings-label" for="homelab-<?= e((string) $service) ?>-url">Base URL</label>
                            <input class="input" id="homelab-<?= e((string) $service) ?>-url" name="base_url" autocomplete="off" spellcheck="false" inputmode="url" placeholder="http://host:port" value="<?= e((string) ($row['base_url'] ?? '')) ?>">
                        </div>
                        <?php if ($service === 'qbittorrent'): ?>
                        <?php
                            $auth = (string) ($row['auth'] ?? 'password');
                            $tokenHint = (string) ($row['token_hint'] ?? '');
                            $tokenPlaceholder = $tokenHint === '' ? 'Paste the API token' : ($tokenHint === 'set' ? 'Saved. Leave blank to keep it.' : 'Saved · ' . $tokenHint . '. Leave blank to keep it.');
                        ?>
                        <div class="settings-field">
                            <label class="settings-label" for="homelab-qbittorrent-auth">Auth</label>
                            <select class="input" id="homelab-qbittorrent-auth" name="auth" data-qbit-auth>
                                <option value="none"<?= $auth === 'none' ? ' selected' : '' ?>>No login (whitelist)</option>
                                <option value="password"<?= $auth === 'password' ? ' selected' : '' ?>>Username and password</option>
                                <option value="token"<?= $auth === 'token' ? ' selected' : '' ?>>API token (5.2.0+)</option>
                            </select>
                        </div>
                        <div class="settings-field" data-qbit-field="password"<?= $auth === 'password' ? '' : ' hidden' ?>>
                            <label class="settings-label" for="homelab-qbittorrent-user">Username</label>
                            <input class="input" id="homelab-qbittorrent-user" name="username" autocomplete="off" spellcheck="false" value="<?= e((string) ($row['username'] ?? '')) ?>">
                        </div>
                        <div class="settings-field" data-qbit-field="password"<?= $auth === 'password' ? '' : ' hidden' ?>>
                            <label class="settings-label" for="homelab-qbittorrent-secret">Password</label>
                            <input class="input" id="homelab-qbittorrent-secret" name="password" type="password" spellcheck="false" autocomplete="new-password" placeholder="<?= e($placeholder) ?>">
                            <p class="secret-field-hint"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/lock-keyhole.svg'); ?>Encrypted<?php if ($hint !== ''): ?> <span class="secret-field-mask"><?= e($hint === 'set' ? '••••••••' : '•••••••• ' . $hint) ?></span><?php endif; ?></p>
                        </div>
                        <div class="settings-field" data-qbit-field="token"<?= $auth === 'token' ? '' : ' hidden' ?>>
                            <label class="settings-label" for="homelab-qbittorrent-token">API token</label>
                            <input class="input" id="homelab-qbittorrent-token" name="token" type="password" spellcheck="false" autocomplete="new-password" placeholder="<?= e($tokenPlaceholder) ?>">
                            <p class="secret-field-hint"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/lock-keyhole.svg'); ?>Encrypted<?php if ($tokenHint !== ''): ?> <span class="secret-field-mask"><?= e($tokenHint === 'set' ? '••••••••' : '•••••••• ' . $tokenHint) ?></span><?php endif; ?></p>
                            <p class="text-xs text-muted">qBittorrent 5.2.0 or newer. Preferences, Web UI, API Key. Sent as Authorization: Bearer.</p>
                        </div>
                        <?php else: ?>
                        <?php if ($service === 'jellyfin'): ?>
                        <div class="settings-field">
                            <label class="settings-label" for="homelab-jellyfin-user">Jellyfin user</label>
                            <input class="input" id="homelab-jellyfin-user" name="user" autocomplete="off" spellcheck="false" placeholder="Optional. Blank uses the first administrator." value="<?= e((string) ($row['user'] ?? '')) ?>">
                        </div>
                        <?php endif; ?>
                        <?php if (array_key_exists('username', $row)): ?>
                        <div class="settings-field">
                            <label class="settings-label" for="homelab-<?= e((string) $service) ?>-user">Username</label>
                            <input class="input" id="homelab-<?= e((string) $service) ?>-user" name="username" autocomplete="off" spellcheck="false" value="<?= e((string) $row['username']) ?>">
                        </div>
                        <?php endif; ?>
                        <div class="settings-field">
                            <label class="settings-label" for="homelab-<?= e((string) $service) ?>-secret"><?= e($secretLabel) ?></label>
                            <input class="input" id="homelab-<?= e((string) $service) ?>-secret" name="<?= e((string) ($row['secret_name'] ?? 'api_key')) ?>" type="password" spellcheck="false" autocomplete="new-password" placeholder="<?= e($placeholder) ?>">
                            <p class="secret-field-hint"><?php readfile(__DIR__ . '/../../public/assets/vendor/lucide/lock-keyhole.svg'); ?>Encrypted<?php if ($hint !== ''): ?> <span class="secret-field-mask"><?= e($hint === 'set' ? '••••••••' : '•••••••• ' . $hint) ?></span><?php endif; ?></p>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn-primary">Save</button>
                    </form>
                    <form method="post" action="/settings/homelab/<?= e((string) $service) ?>/test" class="mt-3">
                        <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="btn">Test connection</button>
                    </form>
                </section>
                <?php endforeach; ?>
            </div>
<script src="/assets/js/homelab.js?v=<?= e($assetVer ?? '1') ?>"></script>
<?php require __DIR__ . '/_shell-bottom.php'; ?>
