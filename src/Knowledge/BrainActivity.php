<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

/**
 * Turns audit rows into brain-map traces. One trace per page and verb.
 * A row that names a token wins over the plain page.update written beside it.
 */
final class BrainActivity
{
    public static function pageId(?string $resource): ?int
    {
        if ($resource === null || $resource === '') {
            return null;
        }
        if (preg_match('/(?:^page:|\/pages\/)(\d+)/', $resource, $match) !== 1) {
            return null;
        }
        $id = (int) $match[1];
        return $id > 0 ? $id : null;
    }

    /**
     * @param list<array<string, mixed>> $events newest first, as AuditLog::recent() returns them
     * @return array{activity: list<array<string, mixed>>, tokens: list<array{id: int, name: string}>}
     */
    public static function present(array $events): array
    {
        /** @var array<string, array<string, mixed>> $byPage */
        $byPage = [];
        $loose = [];
        foreach ($events as $event) {
            $actor = (string) ($event['actor'] ?? '');
            if ($actor !== 'ai' && $actor !== 'user') {
                continue;
            }
            $detail = is_array($event['detail'] ?? null) ? $event['detail'] : [];
            $tokenId = isset($detail['token_id']) ? (int) $detail['token_id'] : 0;
            $verb = self::verb($detail, (string) ($event['action'] ?? ''));
            $pageId = self::pageId(isset($event['resource']) ? (string) $event['resource'] : null);
            $item = [
                'id'       => (int) ($event['id'] ?? 0),
                'token_id' => $tokenId,
                'token'    => self::tokenName($actor, $tokenId, $detail),
                'verb'     => $verb,
                'page_id'  => $pageId,
                'label'    => self::label($event, $detail),
                'at'       => (string) ($event['created_at'] ?? ''),
                'actor'    => $actor,
            ];
            if ($pageId === null) {
                $loose[] = $item;
                continue;
            }
            $key = $pageId . ':' . $verb;
            if (!isset($byPage[$key]) || ($tokenId > 0 && (int) $byPage[$key]['token_id'] === 0)) {
                $byPage[$key] = $item;
            }
        }

        $activity = array_values($byPage);
        foreach ($loose as $item) {
            $activity[] = $item;
        }
        usort($activity, static fn (array $a, array $b): int => ((int) $b['id']) <=> ((int) $a['id']));
        $activity = array_slice($activity, 0, 24);

        $tokens = [];
        foreach ($activity as $item) {
            $id = (int) $item['token_id'];
            if ($id > 0 && !isset($tokens[$id])) {
                $tokens[$id] = ['id' => $id, 'name' => (string) $item['token']];
            }
        }

        return [
            'activity' => $activity,
            'tokens'   => array_values($tokens),
        ];
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function verb(array $detail, string $action): string
    {
        $verb = (string) ($detail['verb'] ?? '');
        if ($verb === 'read' || $verb === 'write') {
            return $verb;
        }
        return strtoupper($action) === 'GET' ? 'read' : 'write';
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function tokenName(string $actor, int $tokenId, array $detail): string
    {
        if ($tokenId > 0) {
            $name = trim((string) ($detail['token_name'] ?? ''));
            return $name === '' ? 'Agent' : $name;
        }
        return $actor === 'ai' ? 'Agent' : 'You';
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $detail
     */
    private static function label(array $event, array $detail): string
    {
        $title = trim((string) ($detail['title'] ?? ''));
        if ($title !== '') {
            return mb_substr($title, 0, 80);
        }
        $tool = trim((string) ($event['tool'] ?? ''));
        if ($tool !== '') {
            return mb_substr($tool, 0, 64);
        }
        $resource = trim((string) ($event['resource'] ?? ''));
        $resource = preg_replace('#^/api/v1/#', '', $resource) ?? $resource;
        $resource = preg_replace('#^/api/#', '', $resource) ?? $resource;
        return $resource === '' ? 'access' : mb_substr($resource, 0, 80);
    }
}
