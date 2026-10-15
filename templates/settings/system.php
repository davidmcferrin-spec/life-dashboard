<?php
/**
 * settings/system.php — admin system health and cron log viewer
 *
 * @var array<string, mixed> $user
 * @var array<string, array<string, mixed>> $cronJobs
 * @var list<array{label: string, status: string, detail: string}> $healthChecks
 * @var array<string, string> $logTails
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

<?php require __DIR__ . '/_shell-bottom.php'; ?>
