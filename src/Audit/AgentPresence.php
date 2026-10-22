<?php

declare(strict_types=1);

namespace Dashboard\Audit;

use Dashboard\Money\AccountNumberGuard;

/**
 * Safe label for the dashboard presence sprite.
 * The bubble gets an agent name and an area:tool line. Notes, titles,
 * payees, paths, and account numbers stay out.
 */
final class AgentPresence
{
    /** @var list<array{0: string, 1: list<string>}> */
    private const RULES = [
        ['Money', ['/money', 'money_', 'transaction', 'sinking', 'debt', 'budget', 'net_worth', 'credit_score']],
        ['Reddit', ['/reddit', 'reddit']],
        ['Vault', ['/pages', 'page:', 'folder:', 'search_pages', 'write_page', 'get_page', 'list_folders', 'move_page', 'move_folder', 'create_folder', 'rename_folder', 'delete_folder']],
        ['Todos', ['/todos', 'todo']],
        ['Life', ['/life', 'life_']],
        ['Investing', ['/invest', 'invest_']],
        ['Widgets', ['/widget', 'widget_']],
        ['Habits', ['/metrics', '/habits', '/datapoints', 'habit', 'datapoint', 'metric']],
        ['Bookmarks', ['/bookmark']],
        ['Flights', ['/flight']],
        ['Packages', ['/package']],
        ['Stocks', ['/stock']],
        ['Clipboards', ['/clipboard']],
        ['Search', ['/search']],
        ['Local AI', ['/ollama', 'ollama_']],
    ];

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, agent: string, area: string, tool: string, label: string, at: string}|null
     */
    public static function describe(array $row): ?array
    {
        if ((string) ($row['actor'] ?? '') !== 'ai') {
            return null;
        }
        $tool = self::safeToken((string) ($row['tool'] ?? ''));
        $action = self::safeToken((string) ($row['action'] ?? ''));
        $resource = (string) ($row['resource'] ?? '');
        $area = self::area($resource, $tool);
        $verb = $tool !== '' ? $tool : self::fallbackVerb($action);
        $detail = is_array($row['detail'] ?? null) ? $row['detail'] : [];
        $agent = self::safeName((string) ($detail['token_name'] ?? ''));

        return [
            'id'    => (int) ($row['id'] ?? 0),
            'agent' => $agent !== '' ? $agent : 'Agent',
            'area'  => $area,
            'tool'  => $verb,
            'label' => $area . ': ' . $verb,
            'at'    => (string) ($row['created_at'] ?? ''),
        ];
    }

    private static function area(string $resource, string $tool): string
    {
        return self::match($resource) ?? self::match($tool) ?? 'Dashboard';
    }

    private static function match(string $hay): ?string
    {
        $hay = strtolower($hay);
        if ($hay === '') {
            return null;
        }
        foreach (self::RULES as [$label, $needles]) {
            foreach ($needles as $needle) {
                if (str_contains($hay, $needle)) {
                    return $label;
                }
            }
        }
        return null;
    }

    private static function fallbackVerb(string $action): string
    {
        if ($action === 'post' || str_contains($action, 'write') || str_contains($action, 'create') || str_contains($action, 'update')) {
            return 'write';
        }
        if ($action !== '' && preg_match('/^[a-z][a-z0-9._-]{0,40}$/', $action) === 1) {
            return $action;
        }
        return 'read';
    }

    private static function safeToken(string $value): string
    {
        $value = strtolower(trim(preg_replace('/[^A-Za-z0-9_.:-]+/', '', $value) ?? ''));
        $value = AccountNumberGuard::redact($value) ?? '';
        if ($value === '' || str_contains($value, 'redacted')) {
            return '';
        }
        return mb_substr($value, 0, 64);
    }

    private static function safeName(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x1F]+/u', ' ', $value) ?? '');
        $value = AccountNumberGuard::redact($value) ?? '';
        if ($value === '' || str_contains($value, '[redacted]')) {
            return '';
        }
        return mb_substr($value, 0, 64);
    }
}
