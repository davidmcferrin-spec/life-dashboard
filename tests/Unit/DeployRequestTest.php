<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Auth\ApiTokenService;
use Dashboard\Deploy\DeployService;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class DeployRequestTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
        parent::tearDown();
    }

    public function testDeployRoutesKeepTheirScopeArgument(): void
    {
        $method = new \ReflectionMethod(\Dashboard\Api\V1\ExtendedApi::class, 'run');
        $params = $method->getParameters();
        self::assertCount(4, $params);
        self::assertSame('scope', $params[3]->getName());
    }

    public function testExplicitScopesStayOffFullTokens(): void
    {
        self::assertFalse(\Dashboard\Api\V1\Scope::allows(['*'], 'system:deploy'));
        ApiTokenService::assertAssignable(['*'], false);
        try {
            ApiTokenService::assertAssignable(['*', 'system:deploy'], false);
            self::fail('A non-admin must not grant system:deploy.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('admin', $e->getMessage());
        }
        ApiTokenService::assertAssignable(['*', 'system:read', 'system:deploy'], true);
        try {
            \Dashboard\Api\V1\Scope::normalize(['system:write']);
            self::fail('system:write is not a scope.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function testPendingMigrationNames(): void
    {
        self::assertSame(
            ['0002_next'],
            DeployService::pendingNames(['0001_base', '0002_next'], ['0001_base'])
        );
    }

    public function testConfirmShaMustMatchOriginMainAndIsQueuedWithoutRoot(): void
    {
        [$app, $spool, $origin] = $this->repo();
        $svc = new DeployService(null, $app, $spool);
        $status = $svc->status();
        self::assertSame('main', $status['branch']);
        self::assertSame($origin, $status['origin_main']);
        self::assertSame([], $status['behind']);
        self::assertTrue($status['spool']);

        $this->commitAndPush($app, 'two', 'second');
        $this->git($app, ['fetch', 'origin', 'main']);
        $origin = trim($this->git($app, ['rev-parse', 'origin/main']));
        $svc = new DeployService(null, $app, $spool);
        $status = $svc->status();
        self::assertSame($origin, $status['origin_main']);
        self::assertNotSame($status['head'], $status['origin_main']);
        self::assertSame('second', $status['behind'][0]['title'] ?? null);
        self::assertSame([], $status['migrations_pending']);
        self::assertFalse($status['mcp_updates']);

        try {
            $svc->request($origin, true, 7, 4, 'Claude');
            self::fail('A token deploy must wait until MCP updates are enabled.');
        } catch (ValidationException $e) {
            self::assertSame(DeployService::MCP_DISABLED, $e->getMessage());
        }
        self::assertSame([], glob($spool . '/incoming/*.json') ?: []);
        $session = $svc->request($origin, true, 7, null, null);
        self::assertTrue($session['dry_run']);
        self::assertSame([], glob($spool . '/incoming/*.json') ?: []);
        self::assertTrue(DeployService::mcpRequestAllowed(false, null));
        self::assertFalse(DeployService::mcpRequestAllowed(false, 4));
        self::assertTrue(DeployService::mcpRequestAllowed(true, 4));

        $svc = new DeployService(null, $app, $spool, true);
        $dry = $svc->request($origin, true, 7, 4, "Claude\n<script>");
        self::assertTrue($dry['dry_run']);
        self::assertSame('dry_run', $dry['state']);
        self::assertSame([], glob($spool . '/incoming/*.json') ?: []);

        try {
            $svc->request(str_repeat('a', 40), false, 7, 4, 'Claude');
            self::fail('A different sha must not queue.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('origin/main', $e->getMessage());
        }
        try {
            $svc->request(strtoupper($origin), false, 7, 4, 'Claude');
            self::fail('Uppercase sha must not queue.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('lowercase', $e->getMessage());
        }

        $limited = [
            'job_id' => str_repeat('a', 32),
            'confirm_sha' => $origin,
            'state' => 'succeeded',
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'summary' => 'ok',
        ];
        file_put_contents($spool . '/state/last.json', json_encode($limited));
        try {
            $svc->request($origin, false, 7, 4, 'Claude');
            self::fail('A recent deploy must wait.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('recently', $e->getMessage());
        }
        $limited['started_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 400);
        file_put_contents($spool . '/state/last.json', json_encode($limited));

        $queued = $svc->request($origin, false, 7, 4, "Claude; rm -rf /");
        self::assertSame('queued', $queued['state']);
        self::assertSame(202, $queued['http_status']);
        self::assertSame($origin, $queued['confirm_sha']);
        $files = glob($spool . '/incoming/*.json') ?: [];
        self::assertCount(1, $files);
        $raw = json_decode((string) file_get_contents($files[0]), true);
        self::assertIsArray($raw);
        self::assertSame($origin, $raw['confirm_sha']);
        self::assertSame(4, $raw['token_id']);
        self::assertSame(7, $raw['user_id']);
        self::assertSame('Claude rm -rf ', $raw['token_name']);
        self::assertSame(['job_id', 'confirm_sha', 'token_id', 'token_name', 'user_id', 'requested_at'], array_keys($raw));
        try {
            $svc->request($origin, false, 7, 4, 'Claude');
            self::fail('A second request must wait for the queue.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('already queued', $e->getMessage());
        }

        $this->git($app, ['checkout', '-q', '-b', 'side']);
        $side = new DeployService(null, $app, $spool . '-empty');
        mkdir($spool . '-empty/incoming', 0777, true);
        try {
            $side->request($origin, true, 7, null, null);
            self::fail('A side branch must not deploy.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('main', $e->getMessage());
        }
    }

    public function testLogStaysInsideTheSpool(): void
    {
        $this->root = sys_get_temp_dir() . '/ld-deploy-log-' . bin2hex(random_bytes(4));
        $jobs = $this->root . '/state/jobs';
        mkdir($jobs, 0777, true);
        $id = str_repeat('b', 32);
        file_put_contents($jobs . '/' . $id . '.log', "line one\nline two\n");
        file_put_contents($jobs . '/' . $id . '.json', json_encode(['job_id' => $id, 'state' => 'succeeded']));
        file_put_contents($this->root . '/state/last.json', json_encode(['job_id' => $id, 'state' => 'succeeded']));
        $svc = new DeployService(null, null, $this->root);
        $log = $svc->log(null);
        self::assertSame($id, $log['job_id']);
        self::assertStringContainsString('line two', $log['log']);

        $escape = str_repeat('c', 32);
        symlink('/etc/passwd', $jobs . '/' . $escape . '.log');
        try {
            $svc->log($escape);
            self::fail('A log symlink must not be read.');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
        try {
            $svc->log('../' . $id);
            self::fail('A path must not be a job id.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function testMissingSpoolDoesNotPretendToQueue(): void
    {
        [$app, $spool, $origin] = $this->repo();
        rmdir($spool . '/incoming');
        rmdir($spool . '/state/jobs');
        rmdir($spool . '/state');
        rmdir($spool);
        $svc = new DeployService(null, $app, $spool);
        $dry = $svc->request($origin, true, 1, null, 'Admin');
        self::assertTrue($dry['would_deploy']);
        try {
            $svc->request($origin, false, 1, null, 'Admin');
            self::fail('A missing spool must not report success.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('spool', $e->getMessage());
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function repo(): array
    {
        $this->root = sys_get_temp_dir() . '/ld-deploy-' . bin2hex(random_bytes(4));
        $originRepo = $this->root . '/origin.git';
        $app = $this->root . '/app';
        $spool = $this->root . '/spool';
        mkdir($app, 0777, true);
        mkdir($spool . '/incoming', 0777, true);
        mkdir($spool . '/state/jobs', 0777, true);
        $this->git($originRepo, ['init', '--bare', '-q', '-b', 'main', $originRepo]);
        $this->git($app, ['init', '-q', '-b', 'main', $app]);
        $this->git($app, ['config', 'user.email', 't@example.com']);
        $this->git($app, ['config', 'user.name', 'test']);
        file_put_contents($app . '/readme.txt', "one\n");
        $this->git($app, ['add', 'readme.txt']);
        $this->git($app, ['commit', '-qm', 'first']);
        $this->git($app, ['remote', 'add', 'origin', $originRepo]);
        $this->git($app, ['push', '-q', '-u', 'origin', 'main']);
        $sha = trim($this->git($app, ['rev-parse', 'origin/main']));
        return [$app, $spool, $sha];
    }

    private function commitAndPush(string $app, string $name, string $message): void
    {
        $clone = $this->root . '/other';
        $this->git($clone, ['clone', '-q', $this->root . '/origin.git', $clone]);
        $this->git($clone, ['config', 'user.email', 't@example.com']);
        $this->git($clone, ['config', 'user.name', 'test']);
        file_put_contents($clone . '/' . $name . '.txt', $message . "\n");
        $this->git($clone, ['add', $name . '.txt']);
        $this->git($clone, ['commit', '-qm', $message]);
        $this->git($clone, ['push', '-q', 'origin', 'main']);
    }

    /**
     * @param list<string> $args
     */
    private function git(string $dir, array $args): string
    {
        $cmd = array_merge(['git'], $args);
        if ($args[0] !== 'init' && $args[0] !== 'clone') {
            array_splice($cmd, 1, 0, ['-C', $dir]);
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            self::fail('git ' . implode(' ', $args) . ' failed: ' . $err);
        }
        return is_string($out) ? $out : '';
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }
            $this->removeTree($path);
        }
        rmdir($dir);
    }
}
