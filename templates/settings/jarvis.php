<?php
/**
 * JARVIS setup wizard. Identity, voice, routines, privacy, and initialize.
 *
 * @var array<string, mixed> $user
 */

use Dashboard\DB\Database;
use Dashboard\Jarvis\Wizard;
use Dashboard\Settings\PreferenceService;
use Dashboard\Support\Clock;

if (!function_exists('jarvis_wizard_icon')) {
    function jarvis_wizard_icon(string $name): void
    {
        $allowed = ['brain', 'check', 'chevron-left', 'chevron-right', 'mic', 'shield-check', 'sparkles'];
        if (!in_array($name, $allowed, true)) {
            return;
        }
        $path = __DIR__ . '/../../public/assets/vendor/lucide/' . $name . '.svg';
        if (is_file($path)) {
            readfile($path);
        }
    }
}

$db = Database::getInstance();
$userId = (int) $user['id'];
$username = (string) $user['username'];
$prefs = new PreferenceService($db);
$themePref = $prefs->getTheme($userId);
$starfieldPref = $prefs->getStarfield($userId);
$wizard = new Wizard($db, Clock::fromConfig());
$state = $wizard->state($userId, $username);
$wizardJson = json_encode($state, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$quote = Wizard::greeting(
    (int) $state['hour'],
    (string) $state['identity']['display_name'],
    (string) $state['identity']['style']
);
$pageTitle = 'Configure JARVIS';
$savedSteps = [
    (bool) $state['identity']['saved'],
    (bool) $state['voice']['saved'],
    (bool) $state['routines_saved'],
    (bool) $state['privacy']['saved'],
    (bool) $state['initialize']['initialized'],
];
?>
<?php require __DIR__ . '/../partials/head.php'; ?>
<body class="page ld-shell-page min-h-screen" data-page="settings-jarvis">

<?php require __DIR__ . '/../partials/app-shell-open.php'; ?>

<main class="jarvis-wizard-page">
    <div class="jarvis-wizard" id="jarvis-wizard">
        <aside class="jarvis-steps">
            <p class="jarvis-kicker">System setup</p>
            <h2>Configure JARVIS</h2>
            <div class="jarvis-step-list">
                <?php foreach (Wizard::STEPS as $index => $step): ?>
                <button type="button" class="jarvis-step<?= $index === 0 ? ' is-active' : '' ?><?= $savedSteps[$index] ? ' is-complete' : '' ?>" data-step="<?= e((string) $index) ?>">
                    <span>
                        <span class="jarvis-step-index"><?= e(sprintf('%02d', $index + 1)) ?></span>
                        <span class="jarvis-step-check" aria-hidden="true"><?php jarvis_wizard_icon('check'); ?></span>
                    </span>
                    <?= e($step['label']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <p class="jarvis-steps-note"><span class="jarvis-ico" aria-hidden="true"><?php jarvis_wizard_icon('shield-check'); ?></span> Your setup is saved on this dashboard.</p>
        </aside>

        <section class="jarvis-main">
            <p class="jarvis-kicker" id="jarvis-step-kicker">Step 01 of 05</p>
            <h1 id="jarvis-title"><?= e(Wizard::STEPS[0]['title']) ?></h1>
            <p class="jarvis-lead" id="jarvis-lead"><?= e(Wizard::STEPS[0]['lead']) ?></p>

            <div class="jarvis-form">
                <div class="jarvis-panel" data-panel="0">
                    <label class="jarvis-field">
                        <span>Your name</span>
                        <input class="input" id="jarvis-name" name="display_name" maxlength="80" value="<?= e((string) $state['identity']['display_name']) ?>" autocomplete="name">
                    </label>
                    <label class="jarvis-field">
                        <span>Assistant name</span>
                        <input class="input" id="jarvis-assistant" name="assistant_name" maxlength="40" value="<?= e((string) $state['identity']['assistant_name']) ?>" autocomplete="off">
                    </label>
                    <div class="jarvis-field">
                        <span>Communication style</span>
                        <div class="seg-group jarvis-choices" role="group" aria-label="Communication style">
                            <?php foreach (Wizard::STYLES as $style): ?>
                            <button type="button" class="seg-btn<?= $style === $state['identity']['style'] ? ' seg-btn-active' : '' ?>" data-style="<?= e($style) ?>"><?= e(ucfirst($style)) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="jarvis-panel" data-panel="1" hidden>
                    <div class="jarvis-voice">
                        <div class="jarvis-mic" id="jarvis-mic" aria-hidden="true"><?php jarvis_wizard_icon('mic'); ?></div>
                        <p id="jarvis-voice-status">Voice channel ready</p>
                        <div class="jarvis-wave" aria-hidden="true"><?php for ($i = 0; $i < 18; $i++): ?><i></i><?php endfor; ?></div>
                        <p id="jarvis-transcript"></p>
                    </div>
                    <div class="jarvis-field">
                        <span>Voice profile</span>
                        <div class="seg-group jarvis-choices" role="group" aria-label="Voice profile">
                            <?php foreach (Wizard::VOICES as $voice): ?>
                            <?php $voiceOn = $state['voice']['profile'] !== '' ? $state['voice']['profile'] === $voice : $voice === 'atlas'; ?>
                            <button type="button" class="seg-btn<?= $voiceOn ? ' seg-btn-active' : '' ?>" data-voice="<?= e($voice) ?>"><?= e(ucfirst($voice)) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="button" class="btn" id="jarvis-listen">
                        <span class="jarvis-ico" aria-hidden="true"><?php jarvis_wizard_icon('mic'); ?></span>
                        <span id="jarvis-listen-label">Listen</span>
                    </button>
                </div>

                <div class="jarvis-panel" data-panel="2" hidden>
                    <div class="jarvis-routines">
                        <?php foreach ($state['routines'] as $routine): ?>
                        <div class="jarvis-routine" data-routine="<?= e((string) $routine['key']) ?>">
                            <label>
                                <span>
                                    <strong><?= e((string) $routine['label']) ?></strong>
                                    <small><?= e((string) $routine['detail']) ?></small>
                                </span>
                                <button type="button" class="jarvis-toggle" aria-pressed="<?= $routine['enabled'] ? 'true' : 'false' ?>" aria-label="<?= e((string) $routine['label']) ?>"><i></i></button>
                            </label>
                            <p class="jarvis-run"<?= $routine['run_summary'] === '' ? ' hidden' : '' ?>><?= e((string) $routine['run_summary']) ?></p>
                            <button type="button" class="btn jarvis-confirm"<?= ($routine['run_status'] ?? '') === 'waiting' ? '' : ' hidden' ?>>Confirm</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="jarvis-panel" data-panel="3" hidden>
                    <div class="jarvis-privacy">
                        <?php foreach (Wizard::PRIVACY as $key => $def): ?>
                        <button type="button" class="jarvis-permission" data-privacy="<?= e($key) ?>">
                            <span><?= e($def['label']) ?></span>
                            <strong><?= e((string) $state['privacy'][$key . '_label']) ?></strong>
                            <span class="jarvis-ico" aria-hidden="true"><?php jarvis_wizard_icon('chevron-right'); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="jarvis-panel" data-panel="4" hidden>
                    <div class="jarvis-diagnostics" id="jarvis-diagnostics">
                        <?php foreach ($state['initialize']['checks'] as $check): ?>
                        <div>
                            <span><?= e((string) $check['label']) ?></span>
                            <i></i>
                            <strong><?= e((string) $check['value']) ?></strong>
                        </div>
                        <?php endforeach; ?>
                        <div class="jarvis-ready" id="jarvis-ready"<?= $state['initialize']['ready'] ? '' : ' hidden' ?>>
                            <span class="jarvis-ico" aria-hidden="true"><?php jarvis_wizard_icon('sparkles'); ?></span>
                            <span><strong>All systems ready</strong><small>Your setup is saved on this dashboard.</small></span>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="jarvis-footer">
                <button type="button" class="btn-ghost" id="jarvis-back" disabled>
                    <span class="jarvis-ico" aria-hidden="true"><?php jarvis_wizard_icon('chevron-left'); ?></span>
                    Back
                </button>
                <p id="jarvis-save-note"><?= $savedSteps[0] ? 'Saved' : 'Unsaved changes' ?></p>
                <button type="button" class="btn-primary" id="jarvis-next">
                    <span id="jarvis-next-label">Save &amp; continue</span>
                    <span class="jarvis-ico" aria-hidden="true"><?php jarvis_wizard_icon('chevron-right'); ?></span>
                </button>
            </footer>
        </section>

        <aside class="jarvis-preview" aria-label="Live preview">
            <p class="jarvis-kicker">Live preview</p>
            <div class="jarvis-orb" aria-hidden="true"><div><?php jarvis_wizard_icon('brain'); ?></div></div>
            <p class="jarvis-live"><i></i> <span id="jarvis-live-label">Profile adapting</span></p>
            <blockquote id="jarvis-quote"><?= e($quote) ?></blockquote>
            <div class="jarvis-progress">
                <p><span>Configuration</span> <strong id="jarvis-percent">20%</strong></p>
                <div class="jarvis-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="20" aria-label="Configuration">
                    <span id="jarvis-percent-bar" style="width: 20%"></span>
                </div>
            </div>
        </aside>
    </div>
</main>

<script type="application/json" id="jarvis-wizard-state"><?= $wizardJson ?></script>
<?php require __DIR__ . '/../partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/../partials/appearance-scripts.php'; ?>
<script src="/assets/js/jarvis-wizard.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
