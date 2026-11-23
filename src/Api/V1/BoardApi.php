<?php

declare(strict_types=1);

namespace Dashboard\Api\V1;

use Dashboard\Agents\AgentName;
use Dashboard\Router\Router;
use Dashboard\Support\NotFoundException;
use Dashboard\Tracking\Services;

/** Briefs, trips, the video slate, and research findings. Writes are audited by ApiRunner. */
final class BoardApi
{
    public function __construct(
        private readonly ApiRunner $runner,
        private readonly Services $svc,
    ) {}

    public function register(Router $r): void
    {
        $r->post('/api/v1/digests', fn () => $this->run(fn (int $u) => $this->svc->briefsDigest->publish($u, $this->runner->body()), 201, true));
        $r->get('/api/v1/trips', fn () => $this->run(fn (int $u) => $this->svc->trips->list($u, $this->tripFilters())));
        $r->post('/api/v1/trips', fn () => $this->run(fn (int $u) => $this->svc->trips->create($u, $this->runner->body(), $this->actor(), $this->ai()), 201, true));
        $r->get('/api/v1/trips/{id}', fn (array $p) => $this->run(fn (int $u) => $this->svc->trips->get($u, self::id($p, 'That trip was not found.'))));
        $r->post('/api/v1/trips/{id}', fn (array $p) => $this->run(fn (int $u) => $this->svc->trips->update($u, self::id($p, 'That trip was not found.'), $this->runner->body(), $this->actor(), $this->ai()), 200, true));
        $r->post('/api/v1/trips/{id}/template', fn (array $p) => $this->run(function (int $u) use ($p): array {
            $body = $this->runner->body();
            $body['template'] = true;

            return $this->svc->trips->update($u, self::id($p, 'That trip was not found.'), $body, $this->actor(), $this->ai());
        }, 200, true));
        $r->get('/api/v1/videos', fn () => $this->run(fn (int $u) => $this->svc->videos->list($u, [
            'channel' => self::str('channel'),
            'status'  => self::str('status'),
            'tag'     => self::str('tag'),
        ])));
        $r->post('/api/v1/videos', fn () => $this->run(fn (int $u) => $this->svc->videos->add($u, $this->runner->body()), 201, true));
        $r->post('/api/v1/videos/{id}', fn (array $p) => $this->run(fn (int $u) => $this->svc->videos->update($u, self::id($p, 'That video was not found.'), $this->runner->body()), 200, true));
        $r->get('/api/v1/findings', fn () => $this->run(fn (int $u) => $this->svc->findings->list($u, [
            'topic'      => self::str('topic'),
            'source_bot' => self::str('source_bot'),
            'tag'        => self::str('tag'),
            'since'      => self::str('since'),
        ])));
        $r->post('/api/v1/findings', fn () => $this->run(fn (int $u) => $this->svc->findings->log($u, $this->runner->body(), $this->actor(), $this->ai()), 201, true));
    }

    /**
     * @return array<string, mixed>
     */
    private function tripFilters(): array
    {
        return [
            'status'    => self::str('status'),
            'owner_bot' => self::str('owner_bot'),
            'tag'       => self::str('tag'),
            'upcoming'  => self::flag('upcoming'),
        ];
    }

    private function actor(): string
    {
        return AgentName::identity(
            $this->runner->actor()->kind === 'ai',
            $this->runner->tokenName(),
            $this->runner->editorName()
        );
    }

    private function ai(): bool
    {
        return $this->runner->actor()->kind === 'ai';
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function run(callable $fn, int $status = 200, bool $mutating = false): void
    {
        $this->runner->run($fn, $status, $mutating);
    }

    private static function str(string $name): ?string
    {
        $value = $_GET[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function flag(string $name): bool
    {
        $value = $_GET[$name] ?? null;

        return $value === '1' || $value === 'true' || $value === 'yes';
    }

    /** @param array<string, string> $params */
    private static function id(array $params, string $message): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new NotFoundException($message);
        }

        return $id;
    }
}
