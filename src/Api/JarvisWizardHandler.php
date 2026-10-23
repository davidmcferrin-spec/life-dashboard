<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\DB\Database;
use Dashboard\Jarvis\Wizard;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

final class JarvisWizardHandler
{
    private Wizard $wizard;

    public function __construct(Database $db, ?Wizard $wizard = null)
    {
        $this->wizard = $wizard ?? new Wizard($db, Clock::fromConfig());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function saveIdentity(array $user): void
    {
        $body = $this->body();
        $this->send(fn (): array => $this->wizard->saveIdentity(
            (int) $user['id'],
            (string) ($body['display_name'] ?? ''),
            (string) ($body['assistant_name'] ?? ''),
            (string) ($body['style'] ?? ''),
            (string) $user['username']
        ));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function saveVoice(array $user): void
    {
        $body = $this->body();
        $this->send(fn (): array => $this->wizard->saveVoice(
            (int) $user['id'],
            (string) ($body['profile'] ?? ''),
            (string) $user['username']
        ));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function saveRoutines(array $user): void
    {
        $body = $this->body();
        $routines = $body['routines'] ?? null;
        if (!is_array($routines)) {
            jsonResponse(['error' => 'Routines must be a map of names to on or off.', 'fields' => ['routines' => 'Routines must be a map of names to on or off.']], 422);
        }
        $this->send(fn (): array => $this->wizard->saveRoutines(
            (int) $user['id'],
            $routines,
            (string) $user['username']
        ));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function savePrivacy(array $user): void
    {
        $this->send(fn (): array => $this->wizard->savePrivacy(
            (int) $user['id'],
            $this->body(),
            (string) $user['username']
        ));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function initialize(array $user): void
    {
        $this->send(fn (): array => $this->wizard->initialize(
            (int) $user['id'],
            (string) $user['username']
        ));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function confirmRoutine(array $user): void
    {
        $body = $this->body();
        $this->send(fn (): array => $this->wizard->confirmRoutine(
            (int) $user['id'],
            (string) ($body['routine'] ?? ''),
            (string) $user['username']
        ));
    }

    /**
     * @param callable(): array<string, mixed> $work
     */
    private function send(callable $work): void
    {
        try {
            jsonResponse($work());
        } catch (ValidationException $e) {
            jsonResponse(['error' => $e->getMessage(), 'fields' => $e->fields()], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
