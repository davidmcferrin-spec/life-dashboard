<?php
/**
 * Canvas controls that used to sit in the CloudPad top bar.
 *
 * @var int $canvasId
 */
$shellCanvasId = (int) ($canvasId ?? 0);
?>
<div class="cloudpad-nav app-cloudpad-tools">
    <a href="/settings/clipboards?tab=cloudpad&amp;canvas=<?= $shellCanvasId ?>" class="brand app-cloudpad-back">← CloudPad</a>
    <input type="text" id="cloudpad-canvas-name" class="input mono text-sm app-cloudpad-name" maxlength="128" title="Canvas name">
    <span id="cloudpad-save-status" class="text-faint mono text-xs">Loading…</span>
    <button type="button" id="cloudpad-save-now" class="btn text-xs" title="Save now (Ctrl+S)">Save</button>
    <button type="button" id="cloudpad-fit-content" class="btn text-xs" title="Fit content">Fit</button>
    <button type="button" id="cloudpad-export-png" class="btn text-xs">PNG</button>
    <button type="button" id="cloudpad-save-files" class="btn text-xs">Save to Files</button>
    <button type="button" id="cloudpad-sharing-toggle" class="btn text-xs hidden">Sharing</button>
</div>
