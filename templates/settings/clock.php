<?php
/**
 * settings/clock.php — focus clock and alarm preferences
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Settings\FocusClockPreference;
use Dashboard\Settings\PreferenceService;

$db             = Database::getInstance();
$userId         = (int) $user['id'];
$prefs          = new PreferenceService($db);
$focusClock     = $prefs->getFocusClock($userId);
$fcAlarm        = $focusClock['alarm'] ?? [];
$fcAlarmSound   = (string) ($fcAlarm['sound'] ?? 'builtin');
$fcAlarmDays    = $fcAlarm['days'] ?? [1, 2, 3, 4, 5];
$fcFonts        = [
    'dseg7'           => '7-segment LED (DSEG)',
    'inconsolata'     => 'Inconsolata',
    'space_mono'      => 'Space Mono',
    'ibm_plex_mono'   => 'IBM Plex Mono',
    'source_code_pro' => 'Source Code Pro',
];
$fcDayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$pageTitle   = 'Focus Clock';
$active      = 'clock';
$flash       = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);
$settingsFormId = 'settings-clock';
$settingsSubmitLabel = 'Save';

require __DIR__ . '/_shell-top.php';
?>

                <?php $pageEyebrow = ''; $pageHeading = 'Focus Clock'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mb-4">
                    Style and alarm for the large clock at
                    <a href="/clock" class="text-accent hover:underline">/clock</a>.
                    Press <kbd class="mono">Alt+Shift+C</kbd> on the dashboard to open it (not while typing in the search box).
                    Dashboard timezone bar options are under <a href="/settings" class="text-accent hover:underline">Dashboard</a> settings.
                </p>

                <form id="settings-clock" method="post" action="/settings/clock" class="space-y-6">
                    <input type="hidden" name="_csrf" value="<?= e(csrfToken()) ?>">

                    <div class="grid sm:grid-cols-2 gap-4 max-w-2xl">
                        <div>
                            <label for="focus-clock-timezone" class="text-sm text-muted block mb-1">Timezone</label>
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-muted mb-2">
                                <input type="checkbox" name="focus_clock_timezone_auto" value="1" id="focus-clock-timezone-auto"
                                    <?= !empty($focusClock['timezone_auto']) ? 'checked' : '' ?>>
                                Use my device timezone
                            </label>
                            <select id="focus-clock-timezone" name="focus_clock_timezone" class="input w-full text-sm">
                                <?php foreach (FocusClockPreference::timezoneOptions() as $tz => $tzLabel): ?>
                                <option value="<?= e($tz) ?>" <?= ($focusClock['timezone'] ?? '') === $tz ? 'selected' : '' ?>><?= e($tzLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p id="focus-clock-timezone-detected" class="text-faint text-xs mono mt-1.5 hidden"></p>
                            <p class="text-faint text-xs mt-1">When auto is on, the clock follows your browser zone (updates when you travel). The list is used as fallback if detection fails.</p>
                        </div>
                        <div>
                            <span class="settings-label" id="focus-format-label">Time format</span>
                            <div class="settings-seg" role="radiogroup" aria-labelledby="focus-format-label">
                                <?php foreach (['12h' => '12-hour', '24h' => '24-hour'] as $key => $label): ?>
                                <label class="settings-seg-btn">
                                    <input class="settings-seg-input" type="radio" name="focus_clock_format" value="<?= e($key) ?>"
                                        <?= ($focusClock['format'] ?? '12h') === $key ? 'checked' : '' ?>>
                                    <?= e($label) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                        <input type="checkbox" name="focus_clock_show_seconds" value="1"
                            <?= !empty($focusClock['show_seconds']) ? 'checked' : '' ?>>
                        Show seconds
                    </label>

                    <div>
                        <span class="text-sm text-muted block mb-2">Font style</span>
                        <div class="flex flex-col gap-1.5">
                            <?php foreach ($fcFonts as $key => $label): ?>
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                                <input type="radio" name="focus_clock_font" value="<?= e($key) ?>"
                                    <?= ($focusClock['font'] ?? 'dseg7') === $key ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-6">
                        <label class="text-sm text-muted">
                            Time color
                            <input type="color" name="focus_clock_time_color" value="<?= e((string) ($focusClock['time_color'] ?? '#39ff14')) ?>" class="block mt-1 h-9 w-14 cursor-pointer">
                        </label>
                        <label class="text-sm text-muted">
                            Date / week color
                            <input type="color" name="focus_clock_date_color" value="<?= e((string) ($focusClock['date_color'] ?? '#64748b')) ?>" class="block mt-1 h-9 w-14 cursor-pointer">
                        </label>
                    </div>

                    <div id="focus-clock-colon-wrap" class="<?= ($focusClock['font'] ?? 'dseg7') !== 'dseg7' ? 'hidden' : '' ?>">
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                            <input type="checkbox" name="focus_clock_colon_blink" value="1"
                                <?= !empty($focusClock['colon_blink']) ? 'checked' : '' ?>>
                            Blinking colon (7-segment LED only)
                        </label>
                    </div>

                    <div>
                        <span class="text-sm text-muted block mb-2">Date &amp; week position</span>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach (['above' => 'Above time', 'below' => 'Below time', 'hidden' => 'Hidden'] as $key => $label): ?>
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-muted">
                                <input type="radio" name="focus_clock_date_position" value="<?= e($key) ?>"
                                    <?= ($focusClock['date_position'] ?? 'below') === $key ? 'checked' : '' ?>>
                                <?= e($label) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted mt-2">
                            <input type="checkbox" name="focus_clock_show_broadcast_week" value="1"
                                <?= !empty($focusClock['show_broadcast_week']) ? 'checked' : '' ?>>
                            Show broadcast week (EPOCH) with date
                        </label>
                    </div>

                    <div class="max-w-md">
                        <label for="focus-clock-scale" class="flex items-center justify-between gap-3 text-sm text-muted mb-2">
                            <span>Clock size</span>
                            <span id="focus-clock-scale-value" class="mono text-xs text-faint"><?= (int) ($focusClock['scale_percent'] ?? 100) ?>%</span>
                        </label>
                        <input
                            type="range"
                            id="focus-clock-scale"
                            name="focus_clock_scale"
                            min="<?= FocusClockPreference::SCALE_MIN ?>"
                            max="<?= FocusClockPreference::SCALE_MAX ?>"
                            step="5"
                            value="<?= (int) ($focusClock['scale_percent'] ?? 100) ?>"
                            class="w-full"
                        >
                    </div>

                    <div class="border-t border-chrome pt-4">
                        <h2 class="text-muted text-sm font-medium mb-2">Alarm</h2>
                        <p class="text-faint text-xs mono mb-3">
                            Rings only on the <a href="/clock" class="text-accent hover:underline">focus clock</a> page while that tab is visible.
                            Missed alarms are not played later.
                        </p>
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-muted mb-3">
                            <input type="checkbox" name="focus_clock_alarm_enabled" value="1"
                                <?= !empty($fcAlarm['enabled']) ? 'checked' : '' ?>>
                            Enable alarm
                        </label>
                        <label class="text-sm text-muted block mb-3">
                            Time
                            <input type="time" name="focus_clock_alarm_time" value="<?= e((string) ($fcAlarm['time'] ?? '07:00')) ?>" class="input mt-1 mono text-sm">
                        </label>
                        <div class="mb-3">
                            <span class="text-sm text-muted block mb-2">Repeat</span>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($fcDayLabels as $d => $dayLabel): ?>
                                <label class="flex items-center gap-1 cursor-pointer text-xs text-muted mono">
                                    <input type="checkbox" name="focus_clock_alarm_day_<?= $d ?>" value="1"
                                        <?= in_array($d, $fcAlarmDays, true) ? 'checked' : '' ?>>
                                    <?= e($dayLabel) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <p class="text-faint text-xs mb-2">Shared sounds are available to everyone. You can also keep a personal upload only you can select.</p>
                        <label class="text-sm text-muted block mb-2">
                            Alarm sound
                            <select id="focus-clock-alarm-sound" name="focus_clock_alarm_sound" class="input w-full max-w-md text-sm mt-1" data-initial="<?= e($fcAlarmSound) ?>">
                                <option value="builtin">Built-in tone</option>
                            </select>
                        </label>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn text-sm" id="focus-clock-alarm-preview">Preview</button>
                            <button type="button" class="btn text-sm hidden" id="focus-clock-alarm-delete">Remove selected</button>
                        </div>
                        <div id="focus-clock-alarm-upload" class="space-y-2 mb-2">
                            <label class="text-sm text-muted block">
                                Upload (MP3, WAV, OGG, WebM — max 2 MB)
                                <input type="file" id="focus-clock-alarm-file" accept="audio/mpeg,audio/wav,audio/ogg,audio/webm,.mp3,.wav,.ogg,.webm" class="block mt-1 text-xs">
                            </label>
                            <div class="flex flex-wrap gap-3 items-center text-sm text-muted">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="focus_clock_alarm_upload_scope" value="shared" checked>
                                    Add to shared library
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="focus_clock_alarm_upload_scope" value="mine">
                                    Personal only
                                </label>
                            </div>
                            <button type="button" class="btn text-sm" id="focus-clock-alarm-upload-btn">Upload</button>
                        </div>
                        <p id="focus-clock-alarm-upload-status" class="text-faint text-xs mono mt-2"></p>
                    </div>

                </form>

            <link rel="stylesheet" href="/assets/css/clock.css?v=<?= e($assetVer ?? '20260604a') ?>">
            <script src="/assets/js/focus-clock-tz.js?v=<?= e($assetVer ?? '20260605a') ?>"></script>
            <script src="/assets/js/clock-alarm.js?v=<?= e($assetVer ?? '20260605a') ?>"></script>
            <script src="/assets/js/settings-clock.js?v=<?= e($assetVer ?? '20260605a') ?>"></script>

<?php require __DIR__ . '/_shell-bottom.php'; ?>
