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
            <p class="platform-lead">The dashboard calls each app on the LAN by the URL you enter. API keys and the qBittorrent password are stored encrypted and are not shown again. Leave a secret blank to keep the saved one. Nothing here is read from an environment file.</p>
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
                        <button type="submit" class="btn-primary">Save</button>
                    </form>
                    <form method="post" action="/settings/homelab/<?= e((string) $service) ?>/test" class="mt-3">
                        <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                        <button type="submit" class="btn">Test connection</button>
                    </form>
                </section>
                <?php endforeach; ?>
            </div>
<?php require __DIR__ . '/_shell-bottom.php'; ?>
