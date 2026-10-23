<?php
/**
 * settings/trackers.php — habits, mood and other metrics (the data behind tracking widgets)
 *
 * @var array<string, mixed> $user
 */

$pageTitle   = 'Habits & trackers';
$active      = 'trackers';
$extraStyles = ['/assets/css/lifedash.css'];

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Habits & trackers'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Everything you track is a <span class="text-secondary">metric</span>: habits (done / not done per day),
                    scales like mood, numbers (weight, steps) and durations (sleep). Widgets on the dashboard show them;
                    later phases let integrations and your AI assistants write to them too.
                </p>
            </header>

            <form id="trk-add-form" class="surface p-4 space-y-3 mb-4" novalidate>
                <h2 class="section-title">Add</h2>
                <div class="ld-form-grid">
                    <label class="block ld-field">
                        <span>Name</span>
                        <input type="text" name="name" required maxlength="80" placeholder="Drink water" class="input w-full" autocomplete="off">
                    </label>
                    <label class="block ld-field">
                        <span>Kind</span>
                        <select name="kind" class="input w-full">
                            <option value="habit">Habit (daily check-off)</option>
                            <option value="number">Number</option>
                            <option value="duration">Duration</option>
                            <option value="scale">Scale (e.g. 1–5)</option>
                        </select>
                    </label>
                    <label class="block ld-field" data-kinds="habit scale">
                        <span>Emoji (optional)</span>
                        <input type="text" name="emoji" maxlength="8" placeholder="💧" class="input w-full" autocomplete="off">
                    </label>
                    <label class="block ld-field" data-kinds="habit">
                        <span>Target days / week</span>
                        <input type="number" name="target_per_week" min="1" max="7" placeholder="7" class="input w-full">
                    </label>
                    <label class="block ld-field" data-kinds="number">
                        <span>Unit (optional)</span>
                        <input type="text" name="unit" maxlength="16" placeholder="lb, steps, $" class="input w-full" autocomplete="off">
                    </label>
                    <label class="block ld-field" data-kinds="number">
                        <span>Decimals</span>
                        <input type="number" name="decimals" min="0" max="4" placeholder="1" class="input w-full">
                    </label>
                    <label class="block ld-field" data-kinds="number">
                        <span>Goal (optional)</span>
                        <input type="number" name="goal" step="any" class="input w-full">
                    </label>
                    <label class="block ld-field" data-kinds="number">
                        <span>Better when</span>
                        <select name="goal_direction" class="input w-full">
                            <option value="">—</option>
                            <option value="up">Higher</option>
                            <option value="down">Lower</option>
                        </select>
                    </label>
                    <label class="block ld-field" data-kinds="duration">
                        <span>Goal (minutes, optional)</span>
                        <input type="number" name="goal_minutes" min="1" max="10080" class="input w-full">
                    </label>
                    <label class="block ld-field" data-kinds="scale">
                        <span>Min</span>
                        <input type="number" name="min" value="1" min="-100" max="100" class="input w-full">
                    </label>
                    <label class="block ld-field" data-kinds="scale">
                        <span>Max</span>
                        <input type="number" name="max" value="5" min="-100" max="100" class="input w-full">
                    </label>
                </div>
                <p id="trk-add-error" class="text-danger mono text-xs hidden" role="alert"></p>
                <button type="submit" class="btn-primary">Add</button>
            </form>

            <div class="flex items-center justify-between mb-2">
                <h2 class="section-title">Your trackers</h2>
                <label class="mono text-xs text-faint flex items-center gap-2">
                    <input type="checkbox" id="trk-show-archived"> Show archived
                </label>
            </div>
            <div id="trk-list">
                <p class="empty-state mono">Loading…</p>
            </div>

<?php
$settingsScripts = [
    '/assets/js/lifedash-api.js?v=' . $assetVer,
    '/assets/js/lifedash-trackers.js?v=' . $assetVer,
];
require __DIR__ . '/_shell-bottom.php';
