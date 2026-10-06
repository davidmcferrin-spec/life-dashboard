<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;
use Dashboard\Support\HttpClient;

final class StockService
{
    private const MAX_SYMBOLS        = 10;
    private const WIDGET_MAX_SYMBOLS = 10;

    /** @var list<string> */
    private const DEFAULT_SYMBOLS = ['SPY', 'QQQ', 'DIA', 'AAPL', 'MSFT', 'GOOGL', 'AMZN', 'NVDA'];

    public function __construct(
        private readonly StockRepository $repo,
        private readonly PreferenceService $prefs,
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self(new StockRepository($db), new PreferenceService($db));
    }

    public static function maxSymbols(): int
    {
        return self::MAX_SYMBOLS;
    }

    public static function widgetMaxSymbols(): int
    {
        return max(1, Config::int('STOCK_WIDGET_MAX_SYMBOLS', self::WIDGET_MAX_SYMBOLS));
    }

    /**
     * @return list<string>
     */
    public static function defaultSymbols(): array
    {
        return self::DEFAULT_SYMBOLS;
    }

    public function ensureDefaults(int $userId): void
    {
        if ($this->prefs->hasWidgetDefaultsSeeded($userId, 'stocks')) {
            return;
        }

        $order = 0;
        foreach (self::DEFAULT_SYMBOLS as $index => $symbol) {
            if ($this->repo->findBySymbol($userId, $symbol) !== null) {
                continue;
            }
            $this->repo->insert($userId, $symbol, $order, $index < self::widgetMaxSymbols());
            $order++;
        }

        $this->prefs->markWidgetDefaultsSeeded($userId, 'stocks');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $this->ensureDefaults($userId);

        return array_map(
            fn (array $row): array => $this->format($row),
            $this->repo->listForUser($userId, true),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function widgetForUser(int $userId): array
    {
        $all = $this->listForUser($userId);

        $pinned = array_values(array_filter(
            $all,
            static fn (array $row): bool => !empty($row['pin_to_dashboard']),
        ));

        if ($pinned !== []) {
            return array_slice($pinned, 0, self::widgetMaxSymbols());
        }

        return array_slice($all, 0, self::widgetMaxSymbols());
    }

    /**
     * @return array<string, mixed>
     */
    public function addSymbol(int $userId, string $symbol): array
    {
        $this->ensureDefaults($userId);

        $symbol = self::normalizeSymbol($symbol);
        if ($symbol === '') {
            throw new \InvalidArgumentException('Symbol is required.');
        }

        $existing = $this->repo->findBySymbol($userId, $symbol);
        if ($existing !== null) {
            return $this->format($existing);
        }

        if ($this->repo->countForUser($userId) >= self::MAX_SYMBOLS) {
            throw new \InvalidArgumentException('Maximum of ' . self::MAX_SYMBOLS . ' symbols allowed.');
        }

        $id = $this->repo->insert($userId, $symbol, $this->repo->nextSortOrder($userId));
        $row = $this->repo->findByIdForUser($id, $userId);

        if ($row === null) {
            throw new \RuntimeException('Failed to add symbol.');
        }

        return $this->format($row);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null
     */
    public function updateSymbol(int $id, int $userId, array $fields): ?array
    {
        $row = $this->repo->findByIdForUser($id, $userId);
        if ($row === null) {
            return null;
        }

        $update = [];

        if (array_key_exists('pin_to_dashboard', $fields)) {
            $wantsPin = !empty($fields['pin_to_dashboard']);
            if ($wantsPin) {
                $alreadyPinned = !empty($row['pin_to_dashboard']);
                if (!$alreadyPinned && $this->repo->countPinnedForUser($userId) >= self::widgetMaxSymbols()) {
                    throw new \InvalidArgumentException(
                        'Maximum of ' . self::widgetMaxSymbols() . ' symbols can appear on the dashboard.',
                    );
                }
            }
            $update['pin_to_dashboard'] = $wantsPin ? 1 : 0;
        }

        if ($update !== []) {
            $this->repo->update($id, $userId, $update);
        }

        $fresh = $this->repo->findByIdForUser($id, $userId);

        return $fresh !== null ? $this->format($fresh) : null;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->repo->delete($id, $userId);
    }

    public function fetchQuote(string $symbol): ?array
    {
        $result = $this->fetchQuoteWithMeta($symbol);

        return $result['status'] === 'ok' ? $result['quote'] : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{symbol: string, user_id: int, status: string, detail: string, used_api: bool}
     */
    public function refreshSymbol(array $row): array
    {
        $symbol = (string) $row['symbol'];
        $userId = (int) $row['user_id'];
        $result = $this->fetchQuoteWithMeta($symbol);

        if ($result['status'] === 'rate_limit') {
            return [
                'symbol'   => $symbol,
                'user_id'  => $userId,
                'status'   => 'rate_limit',
                'detail'   => $result['detail'],
                'used_api' => true,
            ];
        }

        if ($result['status'] === 'error') {
            return [
                'symbol'   => $symbol,
                'user_id'  => $userId,
                'status'   => 'error',
                'detail'   => $result['detail'],
                'used_api' => $result['used_api'],
            ];
        }

        $quote  = $result['quote'];
        $source = $result['source'];

        $this->repo->applyQuote(
            (int) $row['id'],
            $quote['price'],
            $quote['change'],
            $quote['change_percent'],
            json_encode($quote) ?: null,
        );

        return [
            'symbol'   => $symbol,
            'user_id'  => $userId,
            'status'   => $source === 'cache' ? 'cached' : 'ok',
            'detail'   => $this->formatQuoteDetail($quote),
            'used_api' => $source === 'api',
        ];
    }

    /**
     * @return array{
     *     updated: int,
     *     cached: int,
     *     failed: int,
     *     rate_limited: bool,
     *     entries: list<array{symbol: string, user_id: int, status: string, detail: string, used_api: bool}>
     * }
     */
    public function refreshAllActive(): array
    {
        $rows         = $this->repo->listAllActive();
        $updated      = 0;
        $cached       = 0;
        $failed       = 0;
        $rateLimited  = false;
        $entries      = [];
        $rowCount     = count($rows);

        foreach ($rows as $index => $row) {
            $entry     = $this->refreshSymbol($row);
            $entries[] = $entry;

            if ($entry['status'] === 'ok') {
                $updated++;
            } elseif ($entry['status'] === 'cached') {
                $cached++;
            } elseif ($entry['status'] === 'rate_limit') {
                $rateLimited = true;
                break;
            } else {
                $failed++;
            }

            if ($entry['used_api'] && !$rateLimited && $index < $rowCount - 1) {
                sleep(13);
            }
        }

        return [
            'updated'      => $updated,
            'cached'       => $cached,
            'failed'       => $failed,
            'rate_limited' => $rateLimited,
            'entries'      => $entries,
        ];
    }

    /**
     * @return array{status: 'ok', quote: array<string, mixed>, source: 'cache'|'api'}|array{status: 'error'|'rate_limit', detail: string, used_api: bool}
     */
    private function fetchQuoteWithMeta(string $symbol): array
    {
        $apiKey = Config::maybe('ALPHAVANTAGE_API_KEY');
        if ($apiKey === null) {
            return [
                'status'   => 'error',
                'detail'   => 'API key not configured',
                'used_api' => false,
            ];
        }

        $cacheFile = STORAGE_DIR . '/cache/stocks/' . $symbol . '.json';
        $cacheTtl  = Config::int('STOCK_CACHE_TTL', 14400);

        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return [
                    'status' => 'ok',
                    'quote'  => $cached,
                    'source' => 'cache',
                ];
            }
        }

        $url = 'https://www.alphavantage.co/query?' . http_build_query([
            'function' => 'GLOBAL_QUOTE',
            'symbol'   => $symbol,
            'apikey'   => $apiKey,
        ]);

        try {
            $response = $this->http->request('GET', $url, null, [], 12);
        } catch (\Throwable $e) {
            error_log('[Dashboard][Stocks] ' . $e->getMessage());

            return [
                'status'   => 'error',
                'detail'   => 'Request failed',
                'used_api' => true,
            ];
        }

        if ($response['status'] !== 200) {
            return [
                'status'   => 'error',
                'detail'   => 'HTTP ' . $response['status'],
                'used_api' => true,
            ];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['Global Quote'])) {
            if (is_array($data) && (isset($data['Note']) || isset($data['Information']))) {
                $message = (string) ($data['Note'] ?? $data['Information'] ?? 'Rate limited');
                error_log('[Dashboard][Stocks] Rate limit: ' . $message);

                return [
                    'status'   => 'rate_limit',
                    'detail'   => $message,
                    'used_api' => true,
                ];
            }

            return [
                'status'   => 'error',
                'detail'   => 'API returned no quote',
                'used_api' => true,
            ];
        }

        $q = $data['Global Quote'];
        $quote = [
            'symbol'         => $symbol,
            'price'          => (float) ($q['05. price'] ?? 0),
            'change'         => (float) ($q['09. change'] ?? 0),
            'change_percent' => (float) str_replace('%', '', (string) ($q['10. change percent'] ?? '0')),
            'fetched_at'     => date('c'),
        ];

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($cacheFile, json_encode($quote), LOCK_EX);

        return [
            'status' => 'ok',
            'quote'  => $quote,
            'source' => 'api',
        ];
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function formatQuoteDetail(array $quote): string
    {
        $pct  = (float) ($quote['change_percent'] ?? 0);
        $sign = $pct >= 0 ? '+' : '';

        return sprintf('$%.2f %s%.2f%%', (float) $quote['price'], $sign, $pct);
    }

    public static function normalizeSymbol(string $symbol): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9.\-]/', '', trim($symbol)) ?? '');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'symbol'           => (string) $row['symbol'],
            'is_active'        => (bool) $row['is_active'],
            'pin_to_dashboard' => !empty($row['pin_to_dashboard']),
            'price'            => $row['price'] !== null ? (float) $row['price'] : null,
            'change_amount'    => $row['change_amount'] !== null ? (float) $row['change_amount'] : null,
            'change_percent'   => $row['change_percent'] !== null ? (float) $row['change_percent'] : null,
            'last_refreshed'   => $row['last_refreshed'] ?? null,
        ];
    }
}
