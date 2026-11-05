<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\Scope;
use Dashboard\Audit\AuditLog;
use Dashboard\Auth\ApiTokenService;
use Dashboard\Support\Clock;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class TokenAdminTest extends PgsqlTestCase
{
    private Services $svc;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['admin', 'admin@x', 'h', 1]
        );
        $this->admin = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['member', 'member@x', 'h', 0]
        );
        $this->member = (int) $this->db->lastInsertId();
        $this->svc = new Services($this->db, new Clock('America/Chicago', new DateTimeImmutable('2026-10-17 15:00:00', new DateTimeZone('UTC'))));
    }

    public function testEditKeepsTheSecretAndDeleteKeepsTheAuditName(): void
    {
        $created = $this->svc->tokens->create($this->admin, 'Claude', ['read'], null, true);
        $plain = $created['token'];
        $hash = (string) $this->db->fetchScalar('SELECT token_hash FROM api_tokens WHERE id = ?', [$created['id']]);
        self::assertSame(ApiTokenService::hash($plain), $hash);

        try {
            $this->svc->tokens->update($this->member, $created['id'], 'Nope', ['read']);
            self::fail('A non-admin must not edit a token.');
        } catch (ForbiddenException $e) {
            self::assertSame('Only an admin can edit or delete an agent token.', $e->getMessage());
        }

        $edited = $this->svc->tokens->update($this->admin, $created['id'], 'Household', ['reddit:read', 'reddit:write', 'system:read']);
        self::assertArrayNotHasKey('token', $edited['token']);
        self::assertSame('Household', $edited['token']['name']);
        self::assertSame('Claude', $edited['previous_name']);
        self::assertSame($hash, (string) $this->db->fetchScalar('SELECT token_hash FROM api_tokens WHERE id = ?', [$created['id']]));
        $auth = $this->svc->tokens->authenticate($plain);
        self::assertNotNull($auth);
        self::assertSame('Household', $auth['name']);
        self::assertContains('reddit:write', $auth['scopes']);
        self::assertContains('system:read', $auth['scopes']);
        self::assertNotContains('pages:read', $auth['scopes']);

        try {
            $this->svc->tokens->delete($this->admin, $created['id']);
            self::fail('An active token must be revoked before it can be deleted.');
        } catch (ValidationException $e) {
            self::assertSame('Revoke the token before deleting it.', $e->getMessage());
        }

        $revoked = $this->svc->tokens->revoke($this->admin, $created['id']);
        self::assertTrue($revoked['revoked']);
        try {
            $this->svc->tokens->update($this->admin, $created['id'], 'Household', ['read']);
            self::fail('A revoked token must not be edited.');
        } catch (ValidationException $e) {
            self::assertSame('A revoked token cannot be edited.', $e->getMessage());
        }

        $audit = new AuditLog($this->db);
        $audit->record($this->admin, 'ai', 'list_pages', 'GET', '/api/v1/pages', [
            'token_id' => $created['id'],
            'token_name' => 'Claude',
            'verb' => 'read',
        ]);

        try {
            $this->svc->tokens->delete($this->member, $created['id']);
            self::fail('A non-admin must not delete a token.');
        } catch (ForbiddenException $e) {
            self::assertSame('Only an admin can edit or delete an agent token.', $e->getMessage());
        }
        self::assertNotNull($this->db->fetchScalar('SELECT id FROM api_tokens WHERE id = ?', [$created['id']]));

        $deleted = $this->svc->tokens->delete($this->admin, $created['id']);
        self::assertTrue($deleted['deleted']);
        self::assertSame('Household', $deleted['token']['name']);
        self::assertArrayNotHasKey('token', $deleted['token']);
        self::assertNull($this->svc->tokens->authenticate($plain));
        try {
            $this->svc->tokens->get($this->admin, $created['id']);
            self::fail('A deleted token must not remain.');
        } catch (NotFoundException) {
        }

        $events = $audit->recent($this->admin, 5);
        self::assertSame('Claude', $events[0]['detail']['token_name']);
        self::assertSame($created['id'], $events[0]['detail']['token_id']);
    }

    public function testNarrowingScopesIsEnforcedAndRevokeLeavesTheAudit(): void
    {
        $created = $this->svc->tokens->create($this->admin, 'Claude', ['*'], null, true);
        $plain = $created['token'];
        $hash = (string) $this->db->fetchScalar('SELECT token_hash FROM api_tokens WHERE id = ?', [$created['id']]);

        $edited = $this->svc->tokens->update($this->admin, $created['id'], 'Claude', ['money:read']);
        self::assertSame(['money:read'], $edited['token']['scopes']);
        self::assertSame(['*'], $edited['previous_scopes']);
        self::assertArrayNotHasKey('token', $edited['token']);
        self::assertSame($hash, (string) $this->db->fetchScalar('SELECT token_hash FROM api_tokens WHERE id = ?', [$created['id']]));

        $auth = $this->svc->tokens->authenticate($plain);
        self::assertNotNull($auth);
        self::assertSame($this->admin, $auth['user_id']);
        self::assertSame(['money:read'], $auth['scopes']);
        self::assertTrue(Scope::allows($auth['scopes'], 'money:read'));
        self::assertFalse(Scope::allows($auth['scopes'], 'health:read'));
        self::assertFalse(Scope::allows($auth['scopes'], 'agents:write'));
        self::assertFalse(Scope::allows($auth['scopes'], 'system:deploy'));

        try {
            $this->svc->tokens->revoke($this->member, $created['id']);
            self::fail('Another user must not revoke this key.');
        } catch (NotFoundException) {
        }
        self::assertNotNull($this->svc->tokens->authenticate($plain));

        $audit = new AuditLog($this->db);
        $audit->record($this->admin, 'ai', 'list_bills', 'GET', '/api/v1/money/bills', [
            'token_id' => $created['id'],
            'token_name' => 'Claude',
            'verb' => 'read',
            'sector' => 'money',
        ]);

        $revoked = $this->svc->tokens->revoke($this->admin, $created['id']);
        self::assertTrue($revoked['revoked']);
        self::assertNull($this->svc->tokens->authenticate($plain));

        $events = $audit->recent($this->admin, 5);
        self::assertSame('Claude', $events[0]['detail']['token_name']);
        self::assertSame('list_bills', $events[0]['tool']);
        self::assertSame('money', $events[0]['detail']['sector']);

        $table = $this->db->fetchScalar(
            "SELECT to_regclass('public.sector_grants')"
        );
        self::assertNull($table);
    }
}
