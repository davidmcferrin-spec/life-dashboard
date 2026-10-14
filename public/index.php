<?php

declare(strict_types=1);

require __DIR__ . '/load-app.php';

use Dashboard\Api\AdminCloudStorageHandler;
use Dashboard\Api\ClipboardsHandler;
use Dashboard\Api\CloudPadHandler;
use Dashboard\Api\SharedCloudPadHandler;
use Dashboard\Api\SharedClipboardHandler;
use Dashboard\Api\FilesHandler;
use Dashboard\Api\StorageHandler;
use Dashboard\Api\SharedFileHandler;
use Dashboard\Share\ShareAccessGate;
use Dashboard\Share\ShareDeniedResponse;
use Dashboard\Api\FeedsHandler;
use Dashboard\Api\FlightsHandler;
use Dashboard\Api\PackagesHandler;
use Dashboard\Api\BookmarksHandler;
use Dashboard\Api\PingsHandler;
use Dashboard\Api\ClockAlarmHandler;
use Dashboard\Api\PreferencesHandler;
use Dashboard\Api\StocksHandler;
use Dashboard\Api\TodosHandler;
use Dashboard\Auth\Auth;
use Dashboard\Bookmark\BookmarkService;
use Dashboard\DB\Database;
use Dashboard\DB\Schema;
use Dashboard\Router\Router;
use Dashboard\Settings\FeedPreferenceService;
use Dashboard\Settings\PreferenceService;
use Dashboard\System\SystemHealthService;
use Dashboard\User\UserService;

$db = Database::getInstance();

// Legacy (SQLite on Dreamhost) auto-migrated on every request. On PostgreSQL,
// migrations run at deploy time (deploy/bin/lifedash-deploy) so concurrent
// requests never race a schema change; AUTO_MIGRATE=true restores the old behaviour.
if (!$db->isPgsql() || \Dashboard\Config::bool('AUTO_MIGRATE', false)) {
    (new Schema($db))->migrate(quiet: true);
}
$auth = new Auth($db);

$router = new Router();

// Unauthenticated liveness/readiness probe for uptime monitors and the
// Cloudflare tunnel. Reveals nothing beyond up/down.
$router->get('/healthz', function () use ($db): void {
    header('Cache-Control: no-store');
    try {
        $db->fetchScalar('SELECT 1');
        jsonResponse(['status' => 'ok']);
    } catch (\Throwable $e) {
        error_log('[Dashboard][healthz] ' . $e->getMessage());
        jsonResponse(['status' => 'db_unavailable'], 503);
    }
});

// -------------------------------------------------------
// Public routes
// -------------------------------------------------------

$router->get('/login', function () use ($auth): void {
    if ($auth->user() !== null) {
        redirect('/');
    }
    require VIEWS_DIR . '/login.php';
});

$router->post('/login', function () use ($auth): void {
    validateCsrf();

    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    $user = $auth->attempt($identifier, $password);

    if ($user === null) {
        $_SESSION['login_error'] = 'Invalid username or password.';
        redirect('/login');
    }

    redirect('/');
});

$router->get('/logout', function () use ($auth): void {
    $auth->logout();
    redirect('/login');
});

// Auto-login via token URL: /auto/{64-char hex token}
$router->get('/auto/{token}', function (array $params) use ($auth): void {
    $user = $auth->attemptWithToken($params['token']);

    if ($user === null) {
        // Don't reveal why it failed — just redirect to login
        redirect('/login');
    }

    redirect('/');
});

// Public clipboard share links (12-char code grants access to one board only)
$sharedClipboardApi = new SharedClipboardHandler($db);

$router->get('/share/denied', function (): void {
    ShareDeniedResponse::html();
});

$router->get('/share/clipboard/{token}', function (array $params) use ($db): void {
    $shareToken = (string) ($params['token'] ?? '');
    if (!ShareAccessGate::clipboardShareAllowed($shareToken, $db)) {
        ShareDeniedResponse::html();
    }
    $pageTitle      = 'Shared clipboard';
    $themePref      = 'jarvis';
    $themeLocalOnly = true;
    require VIEWS_DIR . '/share/clipboard.php';
});

$router->get('/api/share/clipboard/{token}', function (array $params) use ($sharedClipboardApi): void {
    $sharedClipboardApi->get($params);
});

$router->post('/api/share/clipboard/{token}/save', function (array $params) use ($sharedClipboardApi): void {
    $sharedClipboardApi->save($params);
});

$sharedCloudPadApi = new SharedCloudPadHandler($db);

$router->get('/share/cloudpad/{token}', function (array $params) use ($db): void {
    $shareToken = (string) ($params['token'] ?? '');
    if (!ShareAccessGate::cloudpadShareAllowed($shareToken, $db)) {
        ShareDeniedResponse::html();
    }
    $pageTitle      = 'Shared CloudPad canvas';
    $themePref      = 'jarvis';
    $themeLocalOnly = true;
    require VIEWS_DIR . '/share/cloudpad.php';
});

$router->get('/api/share/cloudpad/{token}', function (array $params) use ($sharedCloudPadApi): void {
    $sharedCloudPadApi->get($params);
});

$sharedFileApi = new SharedFileHandler($db);

$router->get('/share/file/{token}', function (array $params) use ($db): void {
    $shareToken = (string) ($params['token'] ?? '');
    if (!ShareAccessGate::fileShareAllowed($shareToken, $db)) {
        ShareDeniedResponse::html();
    }
    $pageTitle      = 'Shared file';
    $themePref      = 'jarvis';
    $themeLocalOnly = true;
    require VIEWS_DIR . '/share/file.php';
});

$router->get('/api/share/file/{token}', function (array $params) use ($sharedFileApi): void {
    $sharedFileApi->get($params);
});

$router->get('/api/share/file/{token}/download', function (array $params) use ($sharedFileApi): void {
    $sharedFileApi->download($params);
});

// -------------------------------------------------------
// Authenticated routes
// -------------------------------------------------------

$router->get('/', function () use ($auth, $db): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/dashboard.php';
});

$router->get('/clipboards', function (): void {
    redirect('/settings/clipboards');
});

$router->get('/flights', function (): void {
    redirect('/settings/flights');
});

$router->get('/packages', function (): void {
    redirect('/settings/packages');
});

$router->get('/news', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/news.php';
});

$router->get('/todos', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/todos.php';
});

$router->get('/clock', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/clock.php';
});

$router->get('/settings', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings.php';
});

$router->get('/settings/clock', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/clock.php';
});

$router->get('/settings/stocks', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/stocks.php';
});

$router->get('/settings/bookmarks', function () use ($auth, $db): void {
    $user = $auth->requireAuth();
    $capture = \Dashboard\Bookmark\BookmarkCaptureService::fromDatabase($db);
    $bookmarkletHref = $capture->bookmarkletHref((int) $user['id']);
    require VIEWS_DIR . '/settings/bookmarks.php';
});

$router->get('/settings/feeds', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/feeds.php';
});

$router->get('/settings/flights', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/flights.php';
});

$router->get('/settings/packages', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/packages.php';
});

$router->get('/settings/clipboards', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/clipboards.php';
});

$router->get('/settings/cloudpad', function () use ($auth): void {
    $auth->requireAuth();
    redirect('/settings/clipboards?tab=cloudpad');
});

$router->get('/cloudpad/canvas/{id}', function (array $params) use ($auth): void {
    $user = $auth->requireAuth();
    $canvasId = (int) ($params['id'] ?? 0);
    if ($canvasId <= 0) {
        redirect('/settings/clipboards?tab=cloudpad');
    }
    require VIEWS_DIR . '/cloudpad/canvas.php';
});

$router->get('/settings/weather', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/weather.php';
});

$router->get('/settings/pings', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/pings.php';
});

$router->get('/settings/todos', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/todos.php';
});

$router->get('/settings/tokens', function () use ($auth, $db): void {
    $user   = $auth->requireAuth();
    $tokens = $auth->getTokens((int) $user['id']);
    require VIEWS_DIR . '/settings/tokens.php';
});

$router->post('/settings/tokens/create', function () use ($auth): void {
    validateCsrf();
    $user = $auth->requireAuth();

    $label    = trim($_POST['label'] ?? '');
    $expiry   = $_POST['expires_in'] ?: null; // e.g. 'P30D', 'P1Y', or empty

    $token = $auth->createToken((int) $user['id'], $label ?: null, $expiry);

    $_SESSION['new_token'] = $token;
    redirect('/settings/tokens');
});

$router->post('/settings/tokens/revoke', function () use ($auth): void {
    validateCsrf();
    $user    = $auth->requireAuth();
    $tokenId = (int) ($_POST['token_id'] ?? 0);

    $auth->revokeToken($tokenId, (int) $user['id']);
    redirect('/settings/tokens');
});

$router->post('/settings/tokens/regenerate', function () use ($auth): void {
    validateCsrf();
    $user    = $auth->requireAuth();
    $tokenId = (int) ($_POST['token_id'] ?? 0);

    $newToken = $auth->regenerateToken($tokenId, (int) $user['id']);

    if ($newToken !== null) {
        $_SESSION['new_token'] = $newToken;
    }

    redirect('/settings/tokens');
});

$router->post('/settings/tokens/expiry', function () use ($auth): void {
    validateCsrf();
    $user      = $auth->requireAuth();
    $tokenId   = (int) ($_POST['token_id'] ?? 0);
    $expiresIn = $_POST['expires_in'] ?: null;

    $auth->setTokenExpiry($tokenId, (int) $user['id'], $expiresIn);
    redirect('/settings/tokens');
});

$userService = new UserService($db, $auth);

$router->get('/settings/setup', function () use ($auth, $db): void {
    $user = $auth->requireAdmin();
    $setupState = (new \Dashboard\Settings\SetupConfig($db))->view();
    require VIEWS_DIR . '/settings/setup.php';
});

$router->post('/api/setup', function () use ($auth, $db): void {
    validateCsrf();
    $user = $auth->requireAuth();
    if (!$auth->isAdmin($user)) {
        jsonResponse(['error' => 'Admin only.'], 403);
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        jsonResponse(['error' => 'Body must be a JSON object.'], 422);
    }
    $values = is_array($body['values'] ?? null) ? $body['values'] : [];
    try {
        $setup = new \Dashboard\Settings\SetupConfig($db);
        $setup->save((string) ($body['group'] ?? ''), $values, (int) $user['id']);
        jsonResponse(['ok' => true, 'setup' => $setup->view()]);
    } catch (\InvalidArgumentException $e) {
        jsonResponse(['error' => $e->getMessage()], 422);
    }
});

$router->get('/settings/system', function () use ($auth): void {
    $user         = $auth->requireAdmin();
    $health       = new SystemHealthService();
    $cronJobs     = $health->getCronJobs();
    $healthChecks = $health->getHealthChecks();
    $logTails     = [];

    foreach ($health->jobIds() as $jobId) {
        $logTails[$jobId] = $health->tailLog($jobId);
    }

    require VIEWS_DIR . '/settings/system.php';
});

$router->get('/settings/cloud-storage', function () use ($auth): void {
    $user = $auth->requireAdmin();
    require VIEWS_DIR . '/settings/cloud-storage.php';
});

$adminCloudApi = new AdminCloudStorageHandler();

$router->get('/api/admin/cloud-storage', function () use ($auth, $adminCloudApi): void {
    $user = $auth->requireAdmin();
    $adminCloudApi->status($user);
});

$router->post('/api/admin/cloud-storage', function () use ($auth, $adminCloudApi): void {
    $user = $auth->requireAdmin();
    $adminCloudApi->save($user);
});

$router->get('/settings/users', function () use ($auth, $userService): void {
    $user     = $auth->requireAdmin();
    $accounts = $userService->listAccounts();
    require VIEWS_DIR . '/settings/users.php';
});

$router->post('/settings/users/create', function () use ($auth, $userService): void {
    validateCsrf();
    $auth->requireAdmin();

    try {
        $userService->createAccount(
            trim((string) ($_POST['username'] ?? '')),
            trim((string) ($_POST['email'] ?? '')),
            (string) ($_POST['password'] ?? ''),
            isset($_POST['is_admin']),
        );
        $_SESSION['settings_flash'] = 'User created.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }

    redirect('/settings/users');
});

$router->post('/settings/users/{id}/update', function (array $params) use ($auth, $userService): void {
    validateCsrf();
    $acting = $auth->requireAdmin();
    $id     = (int) $params['id'];

    try {
        $userService->updateAccount(
            $id,
            trim((string) ($_POST['username'] ?? '')),
            trim((string) ($_POST['email'] ?? '')),
            isset($_POST['is_admin']),
            (int) $acting['id'],
        );
        $_SESSION['settings_flash'] = 'Account updated.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }

    redirect('/settings/users');
});

$router->post('/settings/users/{id}/password', function (array $params) use ($auth, $userService): void {
    validateCsrf();
    $auth->requireAdmin();
    $id = (int) $params['id'];

    try {
        $userService->setPassword($id, (string) ($_POST['password'] ?? ''));
        $_SESSION['settings_flash'] = 'Password updated.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }

    redirect('/settings/users');
});

$router->post('/settings/users/{id}/delete', function (array $params) use ($auth, $userService): void {
    validateCsrf();
    $acting = $auth->requireAdmin();
    $id     = (int) $params['id'];

    try {
        $userService->deleteAccount($id, (int) $acting['id']);
        $_SESSION['settings_flash'] = 'User deleted.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }

    redirect('/settings/users');
});

$prefService = new PreferenceService($db);
$feedPrefs   = FeedPreferenceService::fromDatabase($db);
$bookmarks   = BookmarkService::fromDatabase($db);

$router->post('/settings/preferences', function () use ($auth, $prefService): void {
    validateCsrf();
    $user   = $auth->requireAuth();
    $userId = (int) $user['id'];

    try {
        $prefService->setSearchEngine($userId, (string) ($_POST['search_engine'] ?? 'google'));
        $prefService->setTheme($userId, (string) ($_POST['theme'] ?? 'jarvis'));
        if (isset($_POST['jarvis_accent'])) {
            $prefService->setJarvisAccent($userId, (string) $_POST['jarvis_accent']);
        }
        $prefService->setClockFormat($userId, (string) ($_POST['clock_format'] ?? '12h'));
        $prefService->setShowBroadcastWeek($userId, isset($_POST['show_broadcast_week']));
        $prefService->setShowBookmarkIcons($userId, isset($_POST['show_bookmark_icons']));
    } catch (\InvalidArgumentException $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
        redirect('/settings');
    }

    // Widget visibility/order/height/columns are legacy (pre-grid) prefs: the grid
    // (dashboard_widgets) is the source of truth; they are read once to seed it.

    $_SESSION['settings_flash'] = 'General settings saved.';
    redirect('/settings');
});

$router->post('/settings/clock', function () use ($auth, $prefService): void {
    validateCsrf();
    $user   = $auth->requireAuth();
    $userId = (int) $user['id'];

    try {
        $prefService->setFocusClockFromPost($userId, $_POST);
    } catch (\InvalidArgumentException $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
        redirect('/settings/clock');
    }

    $_SESSION['settings_flash'] = 'Focus clock settings saved.';
    redirect('/settings/clock');
});

$router->post('/settings/feeds', function () use ($auth, $feedPrefs): void {
    validateCsrf();
    $user   = $auth->requireAuth();
    $userId = (int) $user['id'];

    $sources = $feedPrefs->listWithState($userId);
    $enabled = [];
    foreach ($sources as $source) {
        $id           = (int) $source['id'];
        $enabled[$id] = isset($_POST['feed'][$id]);
    }
    $feedPrefs->save($userId, $enabled);

    $_SESSION['settings_flash'] = 'Dashboard feed pins saved.';
    redirect('/settings/feeds');
});

$router->post('/settings/feeds/add', function () use ($auth, $feedPrefs): void {
    validateCsrf();
    $user   = $auth->requireAuth();
    $userId = (int) $user['id'];

    try {
        $feedPrefs->feedSourceService()->create(
            $userId,
            trim((string) ($_POST['name'] ?? '')),
            trim((string) ($_POST['url'] ?? '')),
        );
        $_SESSION['settings_flash'] = 'Feed added.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }

    redirect('/settings/feeds');
});

$router->post('/settings/feeds/{id}/delete', function (array $params) use ($auth, $feedPrefs): void {
    validateCsrf();
    $user     = $auth->requireAuth();
    $userId   = (int) $user['id'];
    $sourceId = (int) ($params['id'] ?? 0);

    try {
        $feedPrefs->feedSourceService()->delete($userId, $sourceId);
        $_SESSION['settings_flash'] = 'Feed deleted.';
    } catch (\InvalidArgumentException $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }

    redirect('/settings/feeds');
});

$router->post('/settings/bookmarks/folder/create', function () use ($auth, $bookmarks): void {
    validateCsrf();
    $user = $auth->requireAuth();
    try {
        $bookmarks->createFolder((int) $user['id'], (string) ($_POST['name'] ?? ''), $_POST['icon'] ?? null);
        $_SESSION['settings_flash'] = 'Folder created.';
    } catch (\Throwable $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }
    redirect('/settings/bookmarks');
});

$router->post('/settings/bookmarks/folder/{id}/update', function (array $params) use ($auth, $bookmarks): void {
    validateCsrf();
    $user = $auth->requireAuth();
    $id   = (int) $params['id'];
    try {
        if (!$bookmarks->updateFolder($id, (int) $user['id'], (string) ($_POST['name'] ?? ''), $_POST['icon'] ?? null)) {
            $_SESSION['settings_flash'] = 'Folder not found.';
        } else {
            $_SESSION['settings_flash'] = 'Folder updated.';
        }
    } catch (\Throwable $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }
    redirect('/settings/bookmarks');
});

$router->post('/settings/bookmarks/folder/{id}/delete', function (array $params) use ($auth, $bookmarks): void {
    validateCsrf();
    $user = $auth->requireAuth();
    $bookmarks->deleteFolder((int) $params['id'], (int) $user['id']);
    $_SESSION['settings_flash'] = 'Folder deleted.';
    redirect('/settings/bookmarks');
});

$router->post('/settings/bookmarks/create', function () use ($auth, $bookmarks): void {
    validateCsrf();
    $user     = $auth->requireAuth();
    $folderId = isset($_POST['folder_id']) && $_POST['folder_id'] !== ''
        ? (int) $_POST['folder_id']
        : null;
    try {
        $bookmarks->createBookmark(
            (int) $user['id'],
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['url'] ?? ''),
            $folderId,
            $_POST['favicon_url'] ?? null,
        );
        $_SESSION['settings_flash'] = 'Bookmark added.';
    } catch (\Throwable $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }
    redirect('/settings/bookmarks');
});

$router->post('/settings/bookmarks/{id}/update', function (array $params) use ($auth, $bookmarks): void {
    validateCsrf();
    $user = $auth->requireAuth();
    $folderRaw = $_POST['folder_id'] ?? '';
    $folderId  = $folderRaw !== '' && $folderRaw !== null ? (int) $folderRaw : null;
    try {
        if (!$bookmarks->updateBookmark(
            (int) $params['id'],
            (int) $user['id'],
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['url'] ?? ''),
            $folderId,
            $_POST['favicon_url'] ?? null,
        )) {
            $_SESSION['settings_flash'] = 'Bookmark not found.';
        } else {
            $_SESSION['settings_flash'] = 'Bookmark updated.';
        }
    } catch (\Throwable $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }
    redirect('/settings/bookmarks');
});

$router->post('/settings/bookmarks/{id}/delete', function (array $params) use ($auth, $bookmarks): void {
    validateCsrf();
    $user = $auth->requireAuth();
    $bookmarks->deleteBookmark((int) $params['id'], (int) $user['id']);
    $_SESSION['settings_flash'] = 'Bookmark deleted.';
    redirect('/settings/bookmarks');
});

// -------------------------------------------------------
// JSON API routes (called by JS widgets)
// -------------------------------------------------------

$preferencesApi = new PreferencesHandler($db);

$router->get('/api/preferences', function () use ($auth, $preferencesApi): void {
    $user = $auth->requireAuth();
    $preferencesApi->get($user);
});

$router->post('/api/preferences', function () use ($auth, $preferencesApi): void {
    $user = $auth->requireAuth();
    $preferencesApi->save($user);
});

$router->get('/api/weather', function () use ($auth): void {
    $auth->requireAuth();
    require PUBLIC_DIR . '/api/weather.php';
});

$router->get('/api/time', function () use ($auth): void {
    $auth->requireAuth();
    require PUBLIC_DIR . '/api/time.php';
});

$feedsApi = new FeedsHandler($db);

$router->get('/api/feeds', function () use ($auth, $feedsApi): void {
    $user = $auth->requireAuth();
    $feedsApi->list($user);
});

$flightsApi  = new FlightsHandler($db);
$packagesApi = new PackagesHandler($db);
$pingsApi    = new PingsHandler($db);
$stocksApi   = new StocksHandler($db);
$bookmarksApi = new BookmarksHandler($db);

$router->get('/api/flights', function () use ($auth, $flightsApi): void {
    $user = $auth->requireAuth();
    $flightsApi->list($user);
});

$router->post('/api/flights/create', function () use ($auth, $flightsApi): void {
    $user = $auth->requireAuth();
    $flightsApi->create($user);
});

$router->post('/api/flights/{id}/update', function (array $params) use ($auth, $flightsApi): void {
    $user = $auth->requireAuth();
    $flightsApi->update($user, $params);
});

$router->post('/api/flights/{id}/delete', function (array $params) use ($auth, $flightsApi): void {
    $user = $auth->requireAuth();
    $flightsApi->delete($user, $params);
});

$router->get('/api/packages', function () use ($auth, $packagesApi): void {
    $user = $auth->requireAuth();
    $packagesApi->list($user);
});

$router->post('/api/packages/create', function () use ($auth, $packagesApi): void {
    $user = $auth->requireAuth();
    $packagesApi->create($user);
});

$router->post('/api/packages/{id}/update', function (array $params) use ($auth, $packagesApi): void {
    $user = $auth->requireAuth();
    $packagesApi->update($user, $params);
});

$router->post('/api/packages/{id}/delete', function (array $params) use ($auth, $packagesApi): void {
    $user = $auth->requireAuth();
    $packagesApi->delete($user, $params);
});

$router->get('/api/pings', function () use ($auth, $pingsApi): void {
    $user = $auth->requireAuth();
    $pingsApi->list($user);
});

$router->post('/api/pings/create', function () use ($auth, $pingsApi): void {
    $user = $auth->requireAuth();
    $pingsApi->create($user);
});

$router->post('/api/pings/{id}/update', function (array $params) use ($auth, $pingsApi): void {
    $user = $auth->requireAuth();
    $pingsApi->update($user, $params);
});

$router->post('/api/pings/{id}/delete', function (array $params) use ($auth, $pingsApi): void {
    $user = $auth->requireAuth();
    $pingsApi->delete($user, $params);
});

$router->get('/api/bookmarks', function () use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->list($user);
});

$router->post('/api/bookmarks/folders/create', function () use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->createFolder($user);
});

$router->post('/api/bookmarks/folders/{id}/update', function (array $params) use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->updateFolder($user, $params);
});

$router->post('/api/bookmarks/folders/{id}/delete', function (array $params) use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->deleteFolder($user, $params);
});

$router->post('/api/bookmarks/create', function () use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->createBookmark($user);
});

$router->get('/api/bookmarks/{id}/favicon', function (array $params) use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->streamFavicon($user, $params);
});

$router->get('/api/bookmarks/{id}/favicon/candidates', function (array $params) use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->faviconCandidates($user, $params);
});

$router->post('/api/bookmarks/{id}/favicon/fetch', function (array $params) use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->fetchFavicon($user, $params);
});

$router->post('/api/bookmarks/{id}/favicon/upload', function (array $params) use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->uploadFavicon($user, $params);
});

$router->get('/api/bookmarks/capture-token', function () use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->captureToken($user);
});

$router->post('/api/bookmarks/capture-from-page', function () use ($bookmarksApi): void {
    $bookmarksApi->captureFromPage();
});

$router->post('/api/bookmarks/{id}/update', function (array $params) use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->updateBookmark($user, $params);
});

$router->post('/api/bookmarks/{id}/delete', function (array $params) use ($auth, $bookmarksApi): void {
    $user = $auth->requireAuth();
    $bookmarksApi->deleteBookmark($user, $params);
});

$router->get('/api/stocks', function () use ($auth, $stocksApi): void {
    $user = $auth->requireAuth();
    $stocksApi->list($user);
});

$router->post('/api/stocks/create', function () use ($auth, $stocksApi): void {
    $user = $auth->requireAuth();
    $stocksApi->create($user);
});

$router->post('/api/stocks/{id}/update', function (array $params) use ($auth, $stocksApi): void {
    $user = $auth->requireAuth();
    $stocksApi->update($user, $params);
});

$router->post('/api/stocks/{id}/delete', function (array $params) use ($auth, $stocksApi): void {
    $user = $auth->requireAuth();
    $stocksApi->delete($user, $params);
});

$todosApi = new TodosHandler($db);

$router->get('/api/todos', function () use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->list($user);
});

$router->get('/api/todos/widget', function () use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->widget($user);
});

$router->get('/api/todos/assigned', function () use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->assigned($user);
});

$router->post('/api/todos/create', function () use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->createList($user);
});

$router->get('/api/todos/{id}/items', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->listItems($user, $params);
});

$router->post('/api/todos/{id}/update', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->updateList($user, $params);
});

$router->post('/api/todos/{id}/delete', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->deleteList($user, $params);
});

$router->post('/api/todos/{id}/items/create', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->createItem($user, $params);
});

$router->post('/api/todos/{id}/archive-completed', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->archiveCompleted($user, $params);
});

$router->post('/api/todos/items/{id}/update', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->updateItem($user, $params);
});

$router->post('/api/todos/items/{id}/delete', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->deleteItem($user, $params);
});

$router->get('/api/todos/{id}/shares', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->listShares($user, $params);
});

$router->post('/api/todos/{id}/shares/user', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->shareWithUser($user, $params);
});

$router->post('/api/todos/{id}/shares/user/{shareId}/remove', function (array $params) use ($auth, $todosApi): void {
    $user = $auth->requireAuth();
    $todosApi->removeUserShare($user, $params);
});

$clipboardsApi = new ClipboardsHandler($db);

$router->get('/api/clipboards', function () use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->list($user);
});

$router->post('/api/clipboards/create', function () use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->create($user);
});

$router->get('/api/clipboards/{id}', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->get($user, $params);
});

$router->post('/api/clipboards/{id}/save', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->save($user, $params);
});

$router->post('/api/clipboards/{id}/settings', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->settings($user, $params);
});

$router->post('/api/clipboards/{id}/delete', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->delete($user, $params);
});

$router->get('/api/clipboards/{id}/shares', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->listShares($user, $params);
});

$router->post('/api/clipboards/{id}/shares/link', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->createShareLink($user, $params);
});

$router->post('/api/clipboards/{id}/shares/link/{linkId}/revoke', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->revokeShareLink($user, $params);
});

$router->post('/api/clipboards/{id}/shares/user', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->shareWithUser($user, $params);
});

$router->post('/api/clipboards/{id}/shares/user/{shareId}/remove', function (array $params) use ($auth, $clipboardsApi): void {
    $user = $auth->requireAuth();
    $clipboardsApi->removeUserShare($user, $params);
});

$cloudpadApi = new CloudPadHandler($db);

$router->get('/api/cloudpad', function () use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->list($user);
});

$router->post('/api/cloudpad/create', function () use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->create($user);
});

$router->get('/api/cloudpad/{id}', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->get($user, $params);
});

$router->post('/api/cloudpad/{id}/save', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->save($user, $params);
});

$router->post('/api/cloudpad/{id}/update', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->update($user, $params);
});

$router->post('/api/cloudpad/{id}/delete', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->delete($user, $params);
});

$router->get('/api/cloudpad/{id}/control', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->controlStatus($user, $params);
});

$router->post('/api/cloudpad/{id}/control/take', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->controlTake($user, $params);
});

$router->post('/api/cloudpad/{id}/control/request', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->controlRequest($user, $params);
});

$router->post('/api/cloudpad/{id}/control/grant', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->controlGrant($user, $params);
});

$router->post('/api/cloudpad/{id}/control/deny', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->controlDeny($user, $params);
});

$router->post('/api/cloudpad/{id}/control/release', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->controlRelease($user, $params);
});

$router->post('/api/cloudpad/{id}/control/heartbeat', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->controlHeartbeat($user, $params);
});

$router->get('/api/cloudpad/{id}/shares', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->listShares($user, $params);
});

$router->post('/api/cloudpad/{id}/shares/link', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->createShareLink($user, $params);
});

$router->post('/api/cloudpad/{id}/shares/link/{linkId}/revoke', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->revokeShareLink($user, $params);
});

$router->post('/api/cloudpad/{id}/shares/user', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->shareWithUser($user, $params);
});

$router->post('/api/cloudpad/{id}/shares/user/{shareId}/remove', function (array $params) use ($auth, $cloudpadApi): void {
    $user = $auth->requireAuth();
    $cloudpadApi->removeUserShare($user, $params);
});

$filesApi = new FilesHandler($db);

$router->get('/api/files', function () use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->list($user);
});

$router->get('/api/files/shared-with-me', function () use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->listSharedWithMe($user);
});

$router->post('/api/files/upload', function () use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->upload($user);
});

$router->get('/api/files/{id}/download', function (array $params) use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->download($user, $params);
});

$router->post('/api/files/{id}/delete', function (array $params) use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->delete($user, $params);
});

$router->get('/api/files/{id}/shares', function (array $params) use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->listShares($user, $params);
});

$router->post('/api/files/{id}/shares/link', function (array $params) use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->createShareLink($user, $params);
});

$router->post('/api/files/{id}/shares/link/{linkId}/revoke', function (array $params) use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->revokeShareLink($user, $params);
});

$router->post('/api/files/{id}/shares/user', function (array $params) use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->shareWithUser($user, $params);
});

$router->post('/api/files/{id}/shares/user/{shareId}/remove', function (array $params) use ($auth, $filesApi): void {
    $user = $auth->requireAuth();
    $filesApi->removeUserShare($user, $params);
});

$storageApi = new StorageHandler($db);

$router->get('/api/storage/status', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->connectionStatus($user);
});

$router->get('/api/storage/browse', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->browse($user);
});

$router->post('/api/storage/upload', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->upload($user);
});

$router->post('/api/storage/folder', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->createFolder($user);
});

$router->get('/api/storage/shared-with-me', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->listSharedWithMe($user);
});

$router->post('/api/storage/connect/s3', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->connectS3($user);
});

$router->post('/api/storage/dropbox-app', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->saveDropboxApp($user);
});

$router->post('/api/storage/dropbox-app/test', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->testDropboxApp($user);
});

$router->post('/api/storage/dropbox-app/clear', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->clearDropboxApp($user);
});

$router->post('/api/storage/disconnect', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->disconnect($user);
});

$router->get('/api/storage/disconnect/stats', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->disconnectStats($user);
});

$router->post('/api/storage/migrate-local', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->migrateLocal($user);
});

$router->post('/api/storage/dismiss-migration', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->dismissMigration($user);
});

$router->get('/api/storage/connect/dropbox', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->startOAuthRedirect($user, 'dropbox');
});

$router->get('/api/storage/oauth/callback/dropbox', function () use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->oauthCallback($user, 'dropbox');
});

$router->get('/api/storage/{id}/download', function (array $params) use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->download($user, $params);
});

$router->post('/api/storage/{id}/delete', function (array $params) use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->deleteNode($user, $params);
});

$router->get('/api/storage/{id}/shares', function (array $params) use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->listShares($user, $params);
});

$router->post('/api/storage/{id}/shares/link', function (array $params) use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->createShareLink($user, $params);
});

$router->post('/api/storage/{id}/shares/link/{linkId}/revoke', function (array $params) use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->revokeShareLink($user, $params);
});

$router->post('/api/storage/{id}/shares/user', function (array $params) use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->shareWithUser($user, $params);
});

$router->post('/api/storage/{id}/shares/user/{shareId}/remove', function (array $params) use ($auth, $storageApi): void {
    $user = $auth->requireAuth();
    $storageApi->removeUserShare($user, $params);
});

$clockAlarmApi = new ClockAlarmHandler($db);

$router->get('/api/clock/alarm-sounds', function () use ($auth, $clockAlarmApi): void {
    $user = $auth->requireAuth();
    $clockAlarmApi->list($user);
});

$router->get('/api/clock/alarm-sound/mine', function () use ($auth, $clockAlarmApi): void {
    $user = $auth->requireAuth();
    $clockAlarmApi->stream($user, 'mine');
});

$router->get('/api/clock/alarm-sound/{id}', function (array $params) use ($auth, $clockAlarmApi): void {
    $user = $auth->requireAuth();
    $clockAlarmApi->stream($user, $params['id'] ?? '');
});

$router->get('/api/clock/alarm-sound', function () use ($auth, $clockAlarmApi): void {
    $user = $auth->requireAuth();
    $clockAlarmApi->stream($user, 'mine');
});

$router->post('/api/clock/alarm-sound', function () use ($auth, $clockAlarmApi): void {
    $user = $auth->requireAuth();
    $clockAlarmApi->upload($user);
});

$router->post('/api/clock/alarm-sound/delete', function () use ($auth, $clockAlarmApi): void {
    $user = $auth->requireAuth();
    $clockAlarmApi->delete($user, $auth->isAdmin($user));
});

// -------------------------------------------------------
// L1: /api/v1 (metrics, datapoints, habits, widgets, layout, realtime)
// -------------------------------------------------------
$lifedash = \Dashboard\Tracking\Services::fromDatabase($db);
(new \Dashboard\Api\V1\ApiV1($auth, $lifedash))->register($router);

$router->get('/settings/trackers', function () use ($auth, $lifedash): void {
    $user = $auth->requireAuth();
    $lifedash->metrics->ensureMood((int) $user['id']);
    require VIEWS_DIR . '/settings/trackers.php';
});

$router->get('/brain', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/brain.php';
});

$router->get('/brain/graph', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/brain-graph.php';
});

$router->get('/settings/agents', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/agents.php';
});

$router->get('/settings/life', function () use ($auth, $lifedash): void {
    $user = $auth->requireAuth();
    $lifedash->life->ensureDefaults((int) $user['id']);
    require VIEWS_DIR . '/settings/life.php';
});

$router->get('/settings/money', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/money.php';
});

$router->get('/settings/investing', function () use ($auth): void {
    $user = $auth->requireAuth();
    require VIEWS_DIR . '/settings/investing.php';
});

$router->get('/settings/reddit', function () use ($auth, $lifedash): void {
    $user = $auth->requireAuth();
    $subs = $lifedash->reddit->list((int) $user['id']);
    $flash = $_SESSION['settings_flash'] ?? null;
    unset($_SESSION['settings_flash']);
    require VIEWS_DIR . '/settings/reddit.php';
});

$router->post('/settings/reddit/add', function () use ($auth, $lifedash): void {
    validateCsrf();
    $user = $auth->requireAuth();
    try {
        $lifedash->reddit->add(
            (int) $user['id'],
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['sort'] ?? 'hot'),
            (string) ($_POST['include'] ?? ''),
            (string) ($_POST['exclude'] ?? ''),
        );
        $_SESSION['settings_flash'] = 'Subreddit saved. Fetch now, or wait for the half-hour job.';
    } catch (\Throwable $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }
    redirect('/settings/reddit');
});

$router->post('/settings/reddit/update', function () use ($auth, $lifedash): void {
    validateCsrf();
    $user = $auth->requireAuth();
    try {
        $lifedash->reddit->add(
            (int) $user['id'],
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['sort'] ?? 'hot'),
            (string) ($_POST['include'] ?? ''),
            (string) ($_POST['exclude'] ?? ''),
        );
        $_SESSION['settings_flash'] = 'Saved.';
    } catch (\Throwable $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }
    redirect('/settings/reddit');
});

$router->post('/settings/reddit/remove', function () use ($auth, $lifedash): void {
    validateCsrf();
    $user = $auth->requireAuth();
    try {
        $lifedash->reddit->remove((int) $user['id'], (string) ($_POST['name'] ?? ''));
        $_SESSION['settings_flash'] = 'Removed.';
    } catch (\Throwable $e) {
        $_SESSION['settings_flash'] = $e->getMessage();
    }
    redirect('/settings/reddit');
});

$router->post('/settings/reddit/refresh', function () use ($auth, $lifedash): void {
    validateCsrf();
    $user = $auth->requireAuth();
    $errors = $lifedash->reddit->refresh((int) $user['id']);
    $_SESSION['settings_flash'] = $errors === []
        ? 'Fetched.'
        : 'Fetched with errors: ' . implode('; ', $errors);
    redirect('/settings/reddit');
});

$router->get('/reddit', function () use ($auth, $lifedash): void {
    $user = $auth->requireAuth();
    $subs = $lifedash->reddit->list((int) $user['id']);
    $posts = $lifedash->reddit->posts((int) $user['id'], ['limit' => 60]);
    require VIEWS_DIR . '/reddit.php';
});

// -------------------------------------------------------
// Dispatch
// -------------------------------------------------------

$router->dispatch();
