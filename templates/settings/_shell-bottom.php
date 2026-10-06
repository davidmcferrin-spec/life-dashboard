        </div>
    </div>
</main>
<?php require __DIR__ . '/../partials/appearance-scripts.php'; ?>
<?php if (!empty($settingsScripts)): ?>
<?php foreach ((array) $settingsScripts as $script): ?>
<script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
