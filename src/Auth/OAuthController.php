<?php

declare(strict_types=1);

namespace Dashboard\Auth;

use Dashboard\DB\Database;
use Dashboard\Router\Router;
use Dashboard\Support\LocalPath;
use Dashboard\Support\PublicUrl;

/**
 * HTTP surface for MCP OAuth. The consent step uses the dashboard session.
 * Token, register, and metadata calls do not.
 */
final class OAuthController
{
    private readonly OAuthService $oauth;

    public function __construct(Database $db, private readonly Auth $auth)
    {
        $this->oauth = new OAuthService(new PgOAuthStore($db), resource: PublicUrl::mcp());
    }

    public function register(Router $router): void
    {
        $router->get('/.well-known/oauth-protected-resource', function (): void {
            $this->protectedResource();
        });
        $router->get('/.well-known/oauth-protected-resource/mcp', function (): void {
            $this->protectedResource();
        });
        $router->get('/.well-known/oauth-authorization-server', function (): void {
            $this->authorizationServer();
        });
        $router->post('/oauth/register', function (): void {
            $this->registerClient();
        });
        $router->post('/oauth/token', function (): void {
            $this->token();
        });
        $router->post('/oauth/revoke', function (): void {
            $this->revokeToken();
        });
        $router->get('/oauth/authorize', function (): void {
            $this->authorize();
        });
        $router->post('/oauth/authorize', function (): void {
            $this->approve();
        });
        $router->post('/settings/oauth/{id}/revoke', function (array $params): void {
            $this->revokeClient($params);
        });
    }

    private function protectedResource(): void
    {
        $resource = PublicUrl::mcp();
        $issuer = PublicUrl::issuer();
        if ($resource === '' || $issuer === '') {
            $this->json(['error' => 'server_error', 'error_description' => 'MCP_PUBLIC_URL and PUBLIC_BASE_URL (or APP_URL) must be set.'], 503);
        }
        $this->json(OAuthRules::protectedResource($resource, $issuer, OAuthRules::known(true)));
    }

    private function authorizationServer(): void
    {
        $issuer = PublicUrl::issuer();
        if ($issuer === '') {
            $this->json(['error' => 'server_error', 'error_description' => 'PUBLIC_BASE_URL or APP_URL must be set.'], 503);
        }
        $this->json(OAuthRules::authorizationServer($issuer, OAuthRules::known(true)));
    }

    private function registerClient(): void
    {
        try {
            $this->json($this->oauth->register($this->body(), $this->ip()), 201);
        } catch (OAuthException $e) {
            $this->fail($e);
        }
    }

    private function token(): void
    {
        try {
            $this->json($this->oauth->token($this->body(), $this->ip()));
        } catch (OAuthException $e) {
            $this->fail($e);
        }
    }

    private function revokeToken(): void
    {
        try {
            $this->oauth->revokePresented($this->body(), $this->ip());
        } catch (OAuthException $e) {
            $this->fail($e);
        }
        $this->json((object) []);
    }

    private function authorize(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            $next = LocalPath::next('/oauth/authorize' . $this->queryString());
            if ($next !== null) {
                initAppSession();
                $_SESSION['login_next'] = $next;
            }
            redirect('/login');
        }
        try {
            $view = $this->oauth->prepare($_GET, $this->ip(), (int) ($user['is_admin'] ?? 0) === 1);
        } catch (OAuthException $e) {
            if ($e->toClient) {
                redirect(OAuthRules::withQuery((string) ($_GET['redirect_uri'] ?? ''), [
                    'error' => $e->error,
                    'error_description' => $e->getMessage(),
                    'state' => (string) ($_GET['state'] ?? ''),
                ]));
            }
            $this->showError($e);
            return;
        }
        initAppSession();
        $_SESSION['oauth_authorize'] = $view['request'];
        $clientName = $view['client_name'];
        $redirectUri = $view['redirect_uri'];
        $scopes = $view['offered'];
        $error = '';
        require VIEWS_DIR . '/oauth/consent.php';
    }

    private function approve(): void
    {
        $user = $this->auth->requireAuth();
        validateCsrf();
        initAppSession();
        $request = $_SESSION['oauth_authorize'] ?? null;
        if (!is_array($request)) {
            $this->showError(new OAuthException('invalid_request', 'The consent request expired. Start the connection again.'));
            return;
        }
        $selected = $_POST['scopes'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }
        $clean = [];
        foreach ($selected as $scope) {
            if (is_string($scope)) {
                $clean[] = $scope;
            }
        }
        try {
            $url = $this->oauth->grant((int) $user['id'], (int) ($user['is_admin'] ?? 0) === 1, $request, $clean);
        } catch (OAuthException $e) {
            if ($e->toClient && isset($request['redirect_uri'])) {
                unset($_SESSION['oauth_authorize']);
                redirect(OAuthRules::withQuery((string) $request['redirect_uri'], [
                    'error' => $e->error,
                    'error_description' => $e->getMessage(),
                    'state' => (string) ($request['state'] ?? ''),
                ]));
            }
            $clientName = (string) ($request['client_name'] ?? '');
            $redirectUri = (string) ($request['redirect_uri'] ?? '');
            $scopes = is_array($request['requested'] ?? null) ? $request['requested'] : [];
            $error = $e->getMessage();
            require VIEWS_DIR . '/oauth/consent.php';
            return;
        }
        unset($_SESSION['oauth_authorize']);
        redirect($url);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function revokeClient(array $params): void
    {
        $user = $this->auth->requireAuth();
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);
        if (!$this->oauth->revokeClient((int) $user['id'], $id)) {
            initAppSession();
            $_SESSION['settings_flash'] = ['type' => 'error', 'text' => 'That OAuth client is not on this account.'];
        }
        redirect('/settings/agents');
    }

    private function showError(OAuthException $e): void
    {
        $message = $e->getMessage();
        $status = $e->status;
        require VIEWS_DIR . '/oauth/error.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (is_string($type) && str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input');
            $data = json_decode(is_string($raw) ? $raw : '', true);
            return is_array($data) ? $data : [];
        }
        return $_POST;
    }

    private function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) ? $ip : '';
    }

    private function queryString(): string
    {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        if (!is_string($query) || $query === '') {
            return '';
        }
        return '?' . $query;
    }

    /**
     * @param array<string, mixed>|object $payload
     */
    private function json(array|object $payload, int $status = 200): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        jsonResponse($payload, $status);
    }

    private function fail(OAuthException $e): void
    {
        if ($e->status === 401) {
            header('WWW-Authenticate: ' . OAuthRules::wwwAuthenticate(PublicUrl::mcp()));
        }
        if ($e->retryAfter !== null) {
            header('Retry-After: ' . $e->retryAfter);
        }
        $this->json([
            'error' => $e->error,
            'error_description' => $e->getMessage(),
        ], $e->status);
    }
}
