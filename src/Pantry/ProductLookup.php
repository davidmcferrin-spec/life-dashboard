<?php

declare(strict_types=1);

namespace Dashboard\Pantry;

use Dashboard\DB\Database;
use Dashboard\Support\HttpClient;
use Dashboard\Support\ValidationException;

/**
 * Local catalog first, then Open Food Facts, then the UPCitemdb trial lookup.
 * A suggestion is only filled from a response field. A miss stays blank.
 */
final class ProductLookup
{
    private const OFF = 'https://world.openfoodfacts.org/api/v2/product/';

    private const UPCITEMDB = 'https://api.upcitemdb.com/prod/trial/lookup?upc=';

    private const FOUND_TTL = 2592000;

    private const MISS_TTL = 86400;

    /** @var callable(string, string): array{status: int, body: string} */
    private $fetch;

    /**
     * @param callable(string, string): array{status: int, body: string}|null $fetch
     */
    public function __construct(private readonly Database $db, ?callable $fetch = null)
    {
        $this->fetch = $fetch ?? function (string $method, string $url): array {
            $client = new HttpClient();
            $response = $client->request($method, $url, null, [
                'User-Agent' => 'LifeDashboard pantry (personal)',
                'Accept' => 'application/json',
            ], 4);
            return ['status' => $response['status'], 'body' => $response['body']];
        };
    }

    /**
     * @return array{upc: string, suggestion: ?array{name: ?string, brand: ?string, size: ?string, image_url: ?string, source: string}, lookup_error: ?string}
     */
    public function external(string $upc): array
    {
        $upc = $this->requireUpc($upc);
        $cached = $this->cached($upc);
        if ($cached !== null) {
            return $cached;
        }
        $errors = [];
        $hit = $this->fromOpenFoodFacts($upc, $errors);
        if ($hit === null) {
            $hit = $this->fromUpcItemDb($upc, $errors);
        }
        if ($hit !== null) {
            $this->store($upc, true, $hit);
            return ['upc' => $upc, 'suggestion' => $hit, 'lookup_error' => null];
        }
        if ($errors === []) {
            $this->store($upc, false, null);
            return ['upc' => $upc, 'suggestion' => null, 'lookup_error' => null];
        }
        return ['upc' => $upc, 'suggestion' => null, 'lookup_error' => implode('; ', $errors)];
    }

    public static function requireUpc(string $upc): string
    {
        $digits = preg_replace('/\D/', '', $upc) ?? '';
        if (preg_match('/^\d{8,14}$/', $digits) !== 1) {
            throw ValidationException::withFields(['upc' => 'UPC must be 8 to 14 digits.']);
        }
        return $digits;
    }

    /**
     * @return list<string>
     */
    public static function variants(string $upc): array
    {
        $digits = self::requireUpc($upc);
        $out = [$digits];
        if (strlen($digits) === 13 && str_starts_with($digits, '0')) {
            $out[] = substr($digits, 1);
        }
        if (strlen($digits) === 12) {
            $out[] = '0' . $digits;
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $errors
     * @return array{name: ?string, brand: ?string, size: ?string, image_url: ?string, source: string}|null
     */
    private function fromOpenFoodFacts(string $upc, array &$errors): ?array
    {
        $payload = $this->get(self::OFF . rawurlencode($upc) . '.json', 'Open Food Facts', $errors);
        if ($payload === null) {
            return null;
        }
        if ((int) ($payload['status'] ?? 0) !== 1 || !is_array($payload['product'] ?? null)) {
            return null;
        }
        $product = $payload['product'];
        return [
            'name' => self::text($product['product_name'] ?? null, 200),
            'brand' => self::text($product['brands'] ?? null, 120),
            'size' => self::text($product['quantity'] ?? null, 80),
            'image_url' => self::https($product['image_url'] ?? null),
            'source' => 'openfoodfacts',
        ];
    }

    /**
     * @param list<string> $errors
     * @return array{name: ?string, brand: ?string, size: ?string, image_url: ?string, source: string}|null
     */
    private function fromUpcItemDb(string $upc, array &$errors): ?array
    {
        $payload = $this->get(self::UPCITEMDB . rawurlencode($upc), 'UPCitemdb', $errors);
        if ($payload === null) {
            return null;
        }
        $code = strtoupper((string) ($payload['code'] ?? ''));
        if ($code !== 'OK' || !is_array($payload['items'] ?? null) || $payload['items'] === []) {
            return null;
        }
        $item = $payload['items'][0];
        if (!is_array($item)) {
            return null;
        }
        $image = null;
        if (is_array($item['images'] ?? null) && isset($item['images'][0]) && is_string($item['images'][0])) {
            $image = self::https($item['images'][0]);
        }
        return [
            'name' => self::text($item['title'] ?? null, 200),
            'brand' => self::text($item['brand'] ?? null, 120),
            'size' => self::text($item['size'] ?? null, 80),
            'image_url' => $image,
            'source' => 'upcitemdb',
        ];
    }

    /**
     * @param list<string> $errors
     * @return array<string, mixed>|null
     */
    private function get(string $url, string $source, array &$errors): ?array
    {
        try {
            $response = ($this->fetch)('GET', $url);
        } catch (\Throwable) {
            $errors[] = $source . ' lookup failed';
            return null;
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            $errors[] = $source . ' lookup failed (' . $status . ')';
            return null;
        }
        $body = (string) ($response['body'] ?? '');
        if (strlen($body) > 1000000) {
            $errors[] = $source . ' lookup failed';
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $errors[] = $source . ' lookup failed';
            return null;
        }
        return $decoded;
    }

    /**
     * @return array{upc: string, suggestion: ?array{name: ?string, brand: ?string, size: ?string, image_url: ?string, source: string}, lookup_error: ?string}|null
     */
    private function cached(string $upc): ?array
    {
        $row = $this->db->fetchOne('SELECT found, name, brand, size, image_url, source, fetched_at FROM pantry_lookup_cache WHERE upc = ?', [$upc]);
        if ($row === null) {
            return null;
        }
        $age = time() - strtotime((string) $row['fetched_at'] . ' UTC');
        $ttl = (int) $row['found'] === 1 ? self::FOUND_TTL : self::MISS_TTL;
        if ($age < 0 || $age >= $ttl) {
            return null;
        }
        if ((int) $row['found'] !== 1) {
            return ['upc' => $upc, 'suggestion' => null, 'lookup_error' => null];
        }
        return [
            'upc' => $upc,
            'suggestion' => [
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
                'brand' => $row['brand'] !== null ? (string) $row['brand'] : null,
                'size' => $row['size'] !== null ? (string) $row['size'] : null,
                'image_url' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
                'source' => (string) ($row['source'] ?? 'cache'),
            ],
            'lookup_error' => null,
        ];
    }

    /**
     * @param array{name: ?string, brand: ?string, size: ?string, image_url: ?string, source: string}|null $hit
     */
    private function store(string $upc, bool $found, ?array $hit): void
    {
        $this->db->execute(
            'INSERT INTO pantry_lookup_cache (upc, found, name, brand, size, image_url, source, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, (now() AT TIME ZONE \'UTC\'))
             ON CONFLICT (upc) DO UPDATE SET
                found = EXCLUDED.found,
                name = EXCLUDED.name,
                brand = EXCLUDED.brand,
                size = EXCLUDED.size,
                image_url = EXCLUDED.image_url,
                source = EXCLUDED.source,
                fetched_at = EXCLUDED.fetched_at',
            [
                $upc,
                $found ? 1 : 0,
                $hit['name'] ?? null,
                $hit['brand'] ?? null,
                $hit['size'] ?? null,
                $hit['image_url'] ?? null,
                $hit['source'] ?? null,
            ]
        );
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }

    private static function https(mixed $value): ?string
    {
        $text = self::text($value, 500);
        if ($text === null || !str_starts_with($text, 'https://')) {
            return null;
        }
        return $text;
    }
}
