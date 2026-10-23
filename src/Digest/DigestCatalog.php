<?php

declare(strict_types=1);

namespace Dashboard\Digest;

use Dashboard\Support\ValidationException;

/** Product names and the seed list shared with the Python worker. */
final class DigestCatalog
{
    public const SEED_FLAG = 'defaults_seeded_digest';

    /** @var array<string, string> */
    public const PRODUCTS = [
        'cursor_ide' => 'Cursor IDE',
        'grok_bot'   => 'Grok Bot',
        'grok'       => 'Grok',
        'grok_build' => 'Grok Build',
    ];

    /**
     * @return list<array{product: string, label: string, url: string}>
     */
    public static function seeds(): array
    {
        $path = dirname(__DIR__, 2) . '/services/lifedash/digest_seeds.json';
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $seeds = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $product = (string) ($row['product'] ?? '');
            $label = (string) ($row['label'] ?? '');
            $url = (string) ($row['url'] ?? '');
            if (!isset(self::PRODUCTS[$product]) || $label === '' || $url === '') {
                continue;
            }
            $seeds[] = ['product' => $product, 'label' => $label, 'url' => $url];
        }
        return $seeds;
    }

    public static function product(string $value): string
    {
        $value = strtolower(trim($value));
        if (!isset(self::PRODUCTS[$value])) {
            throw ValidationException::withFields(['product' => 'Choose Cursor IDE, Grok Bot, Grok, or Grok Build.']);
        }
        return $value;
    }
}
