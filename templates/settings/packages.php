<?php
/**
 * settings/packages.php — package tracking manager
 *
 * @var array<string, mixed> $user
 */

$pageTitle = 'Package tracking';
$active    = 'packages';

require __DIR__ . '/_shell-top.php';
?>

            <header class="mb-4">
                <?php $pageEyebrow = 'Settings'; $pageHeading = 'Package tracking'; require __DIR__ . '/../partials/page-heading.php'; ?>
                <p class="text-muted text-sm mt-1">
                    Live status via direct UPS, FedEx, and DHL APIs (cron every 2 hours).
                    USPS, Amazon, OnTrac, and LaserShip are link-only — no public recipient tracking API.
                    Set live carrier keys in <code class="mono text-xs">.env</code>.
                </p>
            </header>

            <p class="mb-4">
                <button type="button" id="package-add-open" class="btn-primary">Add package</button>
            </p>

            <div id="package-add-modal" class="app-modal hidden" role="dialog" aria-modal="true" aria-labelledby="package-add-title">
                <div class="app-modal-backdrop" data-close aria-hidden="true"></div>
                <div class="app-modal-panel surface ld-modal-wide">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h2 id="package-add-title" class="section-title text-sm">Add package</h2>
                        <button type="button" class="app-modal-close" data-close aria-label="Close">×</button>
                    </div>
                    <form id="package-add-form" class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block sm:col-span-2">
                                <span class="mono text-xs text-faint">Tracking number</span>
                                <input type="text" name="tracking_number" required placeholder="1Z999AA10123456784" maxlength="64" class="input mono w-full mt-1">
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">Carrier</span>
                                <select name="carrier" class="input mono w-full mt-1">
                                    <option value="auto">Auto-detect</option>
                                    <option value="ups">UPS</option>
                                    <option value="fedex">FedEx</option>
                                    <option value="dhl">DHL</option>
                                    <option value="usps">USPS (link only)</option>
                                    <option value="amazon">Amazon (link only)</option>
                                    <option value="ontrac">OnTrac (link only)</option>
                                    <option value="lasership">LaserShip (link only)</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mono text-xs text-faint">Label (optional)</span>
                                <input type="text" name="label" placeholder="MacBook dock" maxlength="128" class="input w-full mt-1">
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="mono text-xs text-faint">External URL (optional, for Amazon orders)</span>
                                <input type="url" name="external_url" placeholder="https://www.amazon.com/gp/your-account/order-details?orderID=..." class="input w-full mt-1">
                            </label>
                        </div>
                        <p id="package-add-error" class="ld-field-error hidden" role="alert"></p>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="btn-primary">Add watch</button>
                            <button type="button" class="btn" data-close>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="package-manage-list" class="space-y-2">
                <p class="empty-state mono">Loading…</p>
            </div>

<?php
$settingsScripts = ['/assets/js/packages-manage.js?v=' . $assetVer];
require __DIR__ . '/_shell-bottom.php';
