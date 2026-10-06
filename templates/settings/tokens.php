<?php
/**
 * settings/tokens.php — auto-login token management
 *
 * @var array<string, mixed> $user
 * @var array<int, array<string, mixed>> $tokens
 */

use Dashboard\Config;

$appUrl    = rtrim((string) Config::get('APP_URL', ''), '/');
$newToken  = $_SESSION['new_token'] ?? null;
unset($_SESSION['new_token']);
$flash     = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

$pageTitle = 'Auto-login tokens';
$active    = 'tokens';

require __DIR__ . '/_shell-top.php';
?>

            <?php if ($newToken): ?>
            <div class="mb-6 p-4 rounded-lg flash-warn">
                <p class="text-sm font-medium mb-2">New token created — copy it now. It will not be shown again.</p>
                <?php $autoUrl = ($appUrl !== '' ? $appUrl : '') . '/auto/' . $newToken; ?>
                <p class="mono text-xs text-muted mb-1">Auto-login URL</p>
                <input type="text" readonly value="<?= e($autoUrl) ?>" class="input mono w-full mb-2 text-ok" onclick="this.select();">
                <p class="mono text-xs text-faint">Raw token (64 chars)</p>
                <input type="text" readonly value="<?= e($newToken) ?>" class="input mono w-full text-xs" onclick="this.select();">
            </div>
            <?php endif; ?>

            <section class="surface p-5">
                <h1 class="text-primary text-xl font-medium mb-1">Create token</h1>
                <p class="text-muted text-sm mb-4">
                    Tokens log you in via <code class="mono text-faint">/auto/{token}</code> without a password.
                    Treat them like passwords.
                </p>

                <form method="post" action="/settings/tokens/create" class="flex flex-wrap gap-3 items-end">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                    <div>
                        <label class="block text-faint mono text-xs mb-1">Label (optional)</label>
                        <input type="text" name="label" placeholder="e.g. iPad home screen" class="input w-48">
                    </div>
                    <div>
                        <label class="block text-faint mono text-xs mb-1">Expires</label>
                        <select name="expires_in" class="input">
                            <option value="">Never</option>
                            <option value="P30D">30 days</option>
                            <option value="P90D">90 days</option>
                            <option value="P1Y">1 year</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Create token</button>
                </form>
            </section>

            <section class="surface p-5">
                <h2 class="text-primary font-medium mb-4">Your tokens</h2>

                <?php if (empty($tokens)): ?>
                <p class="empty-state mono">No tokens yet.</p>
                <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($tokens as $tok):
                        $revoked  = (bool) ($tok['revoked'] ?? false);
                        $expired  = (bool) ($tok['is_expired'] ?? false);
                        $inactive = $revoked || $expired;
                    ?>
                    <div class="box-subtle p-4 <?= $inactive ? 'opacity-50' : '' ?>">
                        <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
                            <div>
                                <span class="text-primary font-medium"><?= e((string) ($tok['label'] ?: 'Unlabeled')) ?></span>
                                <?php if ($revoked): ?>
                                <span class="ml-2 mono text-xs text-danger">Revoked</span>
                                <?php elseif ($expired): ?>
                                <span class="ml-2 mono text-xs text-warn">Expired</span>
                                <?php else: ?>
                                <span class="ml-2 mono text-xs text-ok">Active</span>
                                <?php endif; ?>
                            </div>
                            <span class="text-faint mono text-xs">#<?= (int) $tok['id'] ?></span>
                        </div>

                        <dl class="grid sm:grid-cols-2 gap-x-4 gap-y-1 text-xs mono text-faint mb-3">
                            <div><dt class="inline">Created </dt><dd class="inline text-muted"><?= e((string) ($tok['created_at'] ?? '—')) ?></dd></div>
                            <div><dt class="inline">Expires </dt><dd class="inline text-muted"><?= e((string) ($tok['expires_at'] ?? 'Never')) ?></dd></div>
                            <div><dt class="inline">Last used </dt><dd class="inline text-muted"><?= e((string) ($tok['last_used_at'] ?? 'Never')) ?></dd></div>
                        </dl>

                        <?php if (!$revoked): ?>
                        <div class="flex flex-wrap gap-2">
                            <form method="post" action="/settings/tokens/regenerate" class="inline"
                                onsubmit="return confirm('Regenerate? The old token will stop working immediately.');">
                                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="token_id" value="<?= (int) $tok['id'] ?>">
                                <button type="submit" class="btn">Regenerate</button>
                            </form>

                            <form method="post" action="/settings/tokens/revoke" class="inline"
                                onsubmit="return confirm('Revoke this token?');">
                                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="token_id" value="<?= (int) $tok['id'] ?>">
                                <button type="submit" class="btn-danger">Revoke</button>
                            </form>

                            <form method="post" action="/settings/tokens/expiry" class="inline flex flex-wrap items-center gap-2">
                                <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="token_id" value="<?= (int) $tok['id'] ?>">
                                <select name="expires_in" class="input text-xs">
                                    <option value="">No expiry</option>
                                    <option value="P30D">30 days</option>
                                    <option value="P90D">90 days</option>
                                    <option value="P1Y">1 year</option>
                                </select>
                                <button type="submit" class="btn">Set expiry</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

<?php require __DIR__ . '/_shell-bottom.php'; ?>
