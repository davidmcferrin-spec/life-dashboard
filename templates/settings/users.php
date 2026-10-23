<?php
/**
 * settings/users.php — admin user account management (no access to other users' data)
 *
 * @var array<string, mixed> $user
 * @var list<array<string, mixed>> $accounts
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

$prefService   = new PreferenceService(Database::getInstance());
$themePref     = $prefService->getTheme((int) $user['id']);
$starfieldPref = $prefService->getStarfield((int) $user['id']);
$pageTitle     = 'User management';
$active        = 'users';
$actingId      = (int) $user['id'];

require __DIR__ . '/_shell-top.php';
?>

            <?php $pageEyebrow = 'Settings'; $pageHeading = 'Users'; require __DIR__ . '/../partials/page-heading.php'; ?>

            <section class="surface p-5">
                <h2 class="text-primary font-medium mb-1">Create user</h2>
                <p class="text-muted text-sm mb-4">
                    Admins manage login accounts only. Each user’s bookmarks, clipboards, and widgets stay private
                    to that account.
                </p>

                <form method="post" action="/settings/users/create" class="grid sm:grid-cols-2 gap-3 max-w-xl">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                    <div>
                        <label class="block text-muted text-xs mono mb-1" for="new-username">Username</label>
                        <input type="text" id="new-username" name="username" required pattern="[a-zA-Z0-9_]{3,64}"
                            autocomplete="off" class="input mono w-full">
                    </div>
                    <div>
                        <label class="block text-muted text-xs mono mb-1" for="new-email">Email</label>
                        <input type="email" id="new-email" name="email" required class="input mono w-full">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-muted text-xs mono mb-1" for="new-password">Password</label>
                        <input type="password" id="new-password" name="password" required minlength="8"
                            autocomplete="new-password" class="input mono w-full">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                            <input type="checkbox" name="is_admin" value="1">
                            Administrator
                        </label>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary">Create user</button>
                    </div>
                </form>
            </section>

            <section class="surface p-5">
                <h2 class="text-primary font-medium mb-1">Accounts</h2>
                <p class="text-muted text-sm mb-4"><?= count($accounts) ?> user<?= count($accounts) === 1 ? '' : 's' ?></p>

                <div class="space-y-6">
                    <?php foreach ($accounts as $acct): ?>
                    <?php
                        $acctId    = (int) $acct['id'];
                        $isSelf    = $acctId === $actingId;
                        $isAdmin   = (int) ($acct['is_admin'] ?? 0) === 1;
                    ?>
                    <div class="border border-chrome rounded-lg p-4 space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-primary font-medium mono"><?= e((string) $acct['username']) ?></span>
                            <?php if ($isAdmin): ?>
                            <span class="text-xs mono px-2 py-0.5 rounded border border-accent text-accent">admin</span>
                            <?php endif; ?>
                            <?php if ($isSelf): ?>
                            <span class="text-xs mono text-faint">(you)</span>
                            <?php endif; ?>
                            <span class="text-faint text-xs mono">#<?= $acctId ?></span>
                        </div>

                        <form method="post" action="/settings/users/<?= $acctId ?>/update"
                            class="grid sm:grid-cols-2 gap-2">
                            <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                            <div>
                                <label class="block text-faint text-xs mono mb-1">Username</label>
                                <input type="text" name="username" required pattern="[a-zA-Z0-9_]{3,64}"
                                    value="<?= e((string) $acct['username']) ?>" class="input mono w-full text-sm">
                            </div>
                            <div>
                                <label class="block text-faint text-xs mono mb-1">Email</label>
                                <input type="email" name="email" required
                                    value="<?= e((string) $acct['email']) ?>" class="input mono w-full text-sm">
                            </div>
                            <div class="sm:col-span-2 flex flex-wrap items-center gap-4">
                                <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                                    <input type="checkbox" name="is_admin" value="1" <?= $isAdmin ? 'checked' : '' ?>
                                        <?= $isSelf ? 'title="Use another admin account to remove your own admin role"' : '' ?>>
                                    Administrator
                                </label>
                                <button type="submit" class="btn text-sm">Save account</button>
                            </div>
                        </form>

                        <form method="post" action="/settings/users/<?= $acctId ?>/password"
                            class="flex flex-wrap items-end gap-2">
                            <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                            <div class="flex-1 min-w-[12rem]">
                                <label class="block text-faint text-xs mono mb-1">Set new password</label>
                                <input type="password" name="password" required minlength="8"
                                    autocomplete="new-password" class="input mono w-full text-sm"
                                    placeholder="Min. 8 characters">
                            </div>
                            <button type="submit" class="btn text-sm">Update password</button>
                        </form>

                        <?php if (!$isSelf): ?>
                        <form method="post" action="/settings/users/<?= $acctId ?>/delete"
                            data-confirm-title="Delete <?= e((string) $acct['username']) ?>"
                            data-confirm-message="Delete this user and all of their private data? This cannot be undone."
                            data-confirm-label="Delete"
                            data-confirm-danger="1">
                            <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">
                            <button type="submit" class="text-danger text-xs mono hover:underline">Delete user</button>
                        </form>
                        <?php else: ?>
                        <p class="text-faint text-xs mono">Sign in as another admin to delete your account.</p>
                        <?php endif; ?>

                        <p class="text-faint text-xs mono">
                            Created <?= e((string) ($acct['created_at'] ?? '—')) ?>
                            <?php if (!empty($acct['last_login_at'])): ?>
                            · Last login <?= e((string) $acct['last_login_at']) ?>
                            <?php endif; ?>
                            <?php if (!empty($acct['last_active_at'])): ?>
                            · Last active <?= e((string) $acct['last_active_at']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

<?php
$settingsScripts = ['/assets/js/settings-confirms.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';

