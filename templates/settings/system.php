<?php
/**
 * settings/system.php — admin system health and cron log viewer
 *
 * @var array<string, mixed> $user
 * @var array<string, array<string, mixed>> $cronJobs
 * @var list<array{label: string, status: string, detail: string}> $healthChecks
 * @var array<string, string> $logTails
 * @var array<string, mixed> $deployStatus
 */

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

$prefService   = new PreferenceService(Database::getInstance());
$themePref     = $prefService->getTheme((int) $user['id']);
$starfieldPref = $prefService->getStarfield((int) $user['id']);
$pageTitle     = 'System health';
$active        = 'system';

$statusClass = static function (string $status): string {
    return match ($status) {
        'ok'      => 'text-ok',
        'warn'    => 'text-warn',
        'fail'    => 'text-danger',
        'stale'   => 'text-warn',
        'missing' => 'text-faint',
        'empty'   => 'text-faint',
        default   => 'text-muted',
    };
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'ok'      => 'OK',
        'warn'    => 'Warning',
        'fail'    => 'Failed',
        'stale'   => 'Stale',
        'missing' => 'No log',
        'empty'   => 'Empty',
        default   => ucfirst($status),
    };
};

$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / 1024 / 1024, 2) . ' MB';
};

$selectedLog = (string) ($_GET['log'] ?? 'feeds');

if (!isset($cronJobs[$selectedLog])) {
    $selectedLog = array_key_first($cronJobs) ?? 'feeds';
}

require __DIR__ . '/_shell-top.php';
?>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-muted text-sm">
                    Application diagnostics and cron job monitoring. Log files live under
                    <span class="mono text-faint">storage/logs/</span>.
                </p>
                <a href="/settings/system" class="text-xs mono text-accent hover:underline">Refresh</a>
            </div>

            <section class="surface p-5" id="deploy">
                <h2 class="text-primary font-medium mb-1">Update from main</h2>
                <p class="text-muted text-sm mb-4">
                    Queues the same update as <span class="mono">lifedash-deploy</span>. The server fetches
                    <span class="mono">origin/main</span> again and continues only when it still matches the commit below.
                    The pre-migration database dump still runs. This page does not get root.
                </p>
                <?php
                    $deployHead = is_string($deployStatus['head'] ?? null) ? $deployStatus['head'] : '';
                    $deployOrigin = is_string($deployStatus['origin_main'] ?? null) ? $deployStatus['origin_main'] : '';
                    $deployBranch = is_string($deployStatus['branch'] ?? null) ? $deployStatus['branch'] : 'unknown';
                    $deployBehind = is_array($deployStatus['behind'] ?? null) ? $deployStatus['behind'] : [];
                    $deployAhead = is_array($deployStatus['ahead'] ?? null) ? $deployStatus['ahead'] : [];
                    $deployPending = is_array($deployStatus['migrations_pending'] ?? null) ? $deployStatus['migrations_pending'] : [];
                    $deployReady = $deployOrigin !== '' && $deployBranch === 'main' && $deployAhead === [];
                    $deployLast = is_array($deployStatus['last'] ?? null) ? $deployStatus['last'] : null;
                    $deployError = is_string($deployStatus['git_error'] ?? null) ? $deployStatus['git_error'] : '';
                    $deploySpool = (bool) ($deployStatus['spool'] ?? false);
                ?>
                <dl class="text-sm space-y-1 mb-4">
                    <div class="flex flex-wrap gap-2"><dt class="text-muted">Branch</dt><dd class="mono"><?= e($deployBranch) ?></dd></div>
                    <div class="flex flex-wrap gap-2"><dt class="text-muted">Checked out</dt><dd class="mono"><?= e($deployHead !== '' ? $deployHead : '—') ?></dd></div>
                    <div class="flex flex-wrap gap-2"><dt class="text-muted">origin/main</dt><dd class="mono"><?= e($deployOrigin !== '' ? $deployOrigin : '—') ?></dd></div>
                    <div class="flex flex-wrap gap-2"><dt class="text-muted">Migrations pending</dt><dd class="mono"><?= e($deployPending === [] ? 'none' : implode(', ', array_map('strval', $deployPending))) ?></dd></div>
                    <div class="flex flex-wrap gap-2">
                        <dt class="text-muted">Last deploy</dt>
                        <dd class="mono">
                            <?php if ($deployLast === null): ?>
                            none yet
                            <?php else: ?>
                            <?= e((string) ($deployLast['state'] ?? '')) ?>
                            <?= e((string) ($deployLast['finished_at'] ?? $deployLast['started_at'] ?? '')) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
                <?php if ($deployBehind !== []): ?>
                <ul class="text-sm mono mb-4 space-y-1">
                    <?php foreach ($deployBehind as $commit): ?>
                    <?php if (!is_array($commit)) { continue; } ?>
                    <li><?= e(substr((string) ($commit['sha'] ?? ''), 0, 12)) ?> <?= e((string) ($commit['title'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php if ($deployAhead !== []): ?>
                <p class="text-warn text-sm mb-3">Local main has commits that are not on origin/main, so an update would not be a fast-forward.</p>
                <?php endif; ?>
                <?php if ($deployError !== ''): ?>
                <p class="text-warn text-sm mb-3"><?= e($deployError) ?></p>
                <?php endif; ?>
                <?php if (!$deploySpool): ?>
                <p class="text-warn text-sm mb-3">The deploy spool is not installed yet. Dry run still works. See docs/deploy/self-update.md.</p>
                <?php endif; ?>
                <label class="block text-sm text-muted mb-3">
                    Confirm origin/main
                    <input id="deploy-confirm-sha" class="input mono w-full mt-1" readonly value="<?= e($deployOrigin) ?>">
                </label>
                <p id="deploy-result" class="text-sm mb-3" role="status"></p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn" id="deploy-dry-run" <?= $deployReady ? '' : 'disabled' ?>>Dry run</button>
                    <button type="button" class="btn-primary" id="deploy-now" <?= ($deployReady && $deploySpool) ? '' : 'disabled' ?>>Update now</button>
                </div>
                <pre id="deploy-log" class="mono text-xs overflow-x-auto max-h-64 overflow-y-auto mt-3 hidden whitespace-pre-wrap break-words"></pre>
            </section>

            <section class="surface p-5">
                <h2 class="text-primary font-medium mb-1">Application health</h2>
                <p class="text-muted text-sm mb-4">Runtime checks for PHP, the database, storage, lifedash-mcp, lifedash-push, and API configuration.</p>

                <div class="divide-y divide-chrome">
                    <?php foreach ($healthChecks as $check): ?>
                    <?php $class = $statusClass($check['status']); ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="inline-block w-2 h-2 rounded-full flex-shrink-0 <?= $class ?>"
                                style="background: currentColor;"></span>
                            <span class="text-sm text-primary"><?= e($check['label']) ?></span>
                        </div>
                        <div class="text-right">
                            <span class="text-xs mono <?= $class ?>"><?= e($statusLabel($check['status'])) ?></span>
                            <span class="text-xs mono text-faint ml-2"><?= e($check['detail']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="surface p-5">
                <h2 class="text-primary font-medium mb-1">Cron jobs</h2>
                <p class="text-muted text-sm mb-4">Last run times are parsed from the most recent timestamp in each log file.</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-faint mono text-xs border-b border-chrome">
                                <th class="pb-2 pr-4 font-normal">Job</th>
                                <th class="pb-2 pr-4 font-normal">Schedule</th>
                                <th class="pb-2 pr-4 font-normal">Last run</th>
                                <th class="pb-2 pr-4 font-normal">Status</th>
                                <th class="pb-2 font-normal">Log size</th>
                            </tr>
                        </thead>
                        <tbody class="mono text-xs">
                            <?php foreach ($cronJobs as $job): ?>
                            <?php $class = $statusClass((string) $job['status']); ?>
                            <tr class="border-b border-chrome last:border-0">
                                <td class="py-2.5 pr-4 text-primary"><?= e((string) $job['label']) ?></td>
                                <td class="py-2.5 pr-4 text-muted"><?= e((string) $job['schedule_hint']) ?></td>
                                <td class="py-2.5 pr-4 text-muted">
                                    <?= e((string) ($job['last_run_at'] ?? '—')) ?>
                                </td>
                                <td class="py-2.5 pr-4 <?= $class ?>">
                                    <?= e($statusLabel((string) $job['status'])) ?>
                                </td>
                                <td class="py-2.5 text-faint">
                                    <?php if ((bool) $job['log_exists']): ?>
                                    <?= e($formatBytes((int) $job['log_size'])) ?>
                                    <?php else: ?>
                                    —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="surface p-5">
                <h2 class="text-primary font-medium mb-1">Recent logs</h2>
                <p class="text-muted text-sm mb-4">Last 150 lines per job (tail read).</p>

                <div class="flex flex-wrap gap-2 mb-4">
                    <?php foreach ($cronJobs as $jobId => $job): ?>
                    <a href="/settings/system?log=<?= e((string) $jobId) ?>#logs"
                        class="text-xs mono px-3 py-1.5 rounded border transition-colors
                            <?= $selectedLog === $jobId
                                ? 'border-accent text-accent'
                                : 'border-chrome text-muted hover:border-surface-hover' ?>">
                        <?= e((string) $job['label']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <div id="logs">
                    <?php
                        $tail = $logTails[$selectedLog] ?? '';
                        $job  = $cronJobs[$selectedLog] ?? null;
                    ?>
                    <?php if ($job !== null): ?>
                    <p class="text-faint text-xs mono mb-2">
                        <?= e((string) $job['label']) ?> · storage/logs/<?= e((string) $job['log_file']) ?>
                    </p>
                    <?php endif; ?>

                    <?php if ($tail === ''): ?>
                    <p class="text-muted text-sm mono">
                        No log content yet. On Dreamhost, ensure crontab redirects stdout to
                        <span class="text-faint">storage/logs/</span>.
                    </p>
                    <?php else: ?>
                    <pre class="mono text-xs overflow-x-auto max-h-96 overflow-y-auto p-3 rounded border border-chrome bg-[var(--surface-bg)] whitespace-pre-wrap break-words"><?= e($tail) ?></pre>
                    <?php endif; ?>
                </div>
            </section>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/settings-deploy.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';

