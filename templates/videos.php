<?php
/** @var array<string, mixed> $user */
$pageTitle = 'Videos';
?>
<?php require __DIR__ . '/partials/head.php'; ?>
<body class="page min-h-screen">
<?php require __DIR__ . '/partials/app-shell-open.php'; ?>
<main class="dash-main mx-auto py-6 platform-page" id="videos-app">
    <?php $pageEyebrow = 'YouTube'; $pageHeading = 'Slate'; require __DIR__ . '/partials/page-heading.php'; ?>
    <p class="platform-lead">Ideas through publish. YouTube Studio starts with the Disney trivia channel.</p>
    <p id="board-error" class="ld-error hidden"></p>
    <form class="platform-card board-create" id="video-add">
        <p class="platform-kicker">Add a video</p>
        <input class="input" name="channel" placeholder="Channel" required maxlength="120" value="Disney trivia">
        <input class="input" name="title" placeholder="Title" required maxlength="200">
        <select class="input" name="status" aria-label="Status">
            <option value="idea">Idea</option>
            <option value="scripted">Scripted</option>
            <option value="voiced">Voiced</option>
            <option value="edited">Edited</option>
            <option value="scheduled">Scheduled</option>
            <option value="published">Published</option>
        </select>
        <input class="input" name="target_date" type="date" aria-label="Target date">
        <input class="input" name="publish_url" placeholder="Publish URL" maxlength="500">
        <input class="input" name="tags" placeholder="Tags, comma separated" maxlength="200">
        <textarea class="input" name="notes" placeholder="Notes" rows="2" maxlength="8000"></textarea>
        <button class="btn-primary" type="submit">Add</button>
    </form>
    <div class="board-columns" id="video-board"></div>
</main>
<?php require __DIR__ . '/partials/app-shell-close.php'; ?>
<?php require __DIR__ . '/partials/appearance-scripts.php'; ?>
<script src="/assets/js/lifedash-api.js?v=<?= e($assetVer) ?>"></script>
<script src="/assets/js/boards.js?v=<?= e($assetVer) ?>"></script>
</body>
</html>
