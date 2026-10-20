<?php

declare(strict_types=1);

namespace Dashboard\Pantry;

use Dashboard\Shopping\ShoppingText;
use Dashboard\Support\ValidationException;

/**
 * Category days are reasonable defaults in the style of USDA FoodKeeper.
 * They are not a copy of that database. Produce rows are the same kind of estimate.
 */
final class PantryCatalog
{
    /** @var array<string, array{pantry: ?int, fridge: ?int, freezer: ?int, opened: ?int}> */
    public const CATEGORIES = [
        'Produce'    => ['pantry' => 7, 'fridge' => 7, 'freezer' => 240, 'opened' => null],
        'Dairy'      => ['pantry' => null, 'fridge' => 21, 'freezer' => 90, 'opened' => 7],
        'Meat'       => ['pantry' => null, 'fridge' => 3, 'freezer' => 120, 'opened' => 2],
        'Bakery'     => ['pantry' => 7, 'fridge' => 7, 'freezer' => 90, 'opened' => 3],
        'Frozen'     => ['pantry' => null, 'fridge' => 2, 'freezer' => 180, 'opened' => 3],
        'Pantry'     => ['pantry' => 365, 'fridge' => 180, 'freezer' => 365, 'opened' => 180],
        'Snacks'     => ['pantry' => 180, 'fridge' => null, 'freezer' => null, 'opened' => 30],
        'Beverages'  => ['pantry' => 180, 'fridge' => 7, 'freezer' => null, 'opened' => 3],
        'Household'  => ['pantry' => null, 'fridge' => null, 'freezer' => null, 'opened' => null],
        'Other'      => ['pantry' => 30, 'fridge' => 30, 'freezer' => 90, 'opened' => 7],
    ];

    /**
     * Rough counter, fridge, and freezer lives for produce with no UPC.
     *
     * @var list<array{name: string, unit: string, pantry: ?int, fridge: ?int, freezer: ?int, opened: ?int}>
     */
    public const PRODUCE = [
        ['name' => 'Onion', 'unit' => 'each', 'pantry' => 30, 'fridge' => 60, 'freezer' => 240, 'opened' => null],
        ['name' => 'Bell pepper', 'unit' => 'each', 'pantry' => 5, 'fridge' => 7, 'freezer' => 240, 'opened' => null],
        ['name' => 'Broccoli', 'unit' => 'head', 'pantry' => 2, 'fridge' => 5, 'freezer' => 300, 'opened' => null],
        ['name' => 'Potatoes', 'unit' => 'lb', 'pantry' => 30, 'fridge' => 14, 'freezer' => 300, 'opened' => null],
        ['name' => 'Bananas', 'unit' => 'each', 'pantry' => 5, 'fridge' => 7, 'freezer' => 60, 'opened' => null],
        ['name' => 'Apples', 'unit' => 'each', 'pantry' => 7, 'fridge' => 42, 'freezer' => 240, 'opened' => null],
        ['name' => 'Lettuce', 'unit' => 'head', 'pantry' => 1, 'fridge' => 7, 'freezer' => null, 'opened' => null],
        ['name' => 'Tomatoes', 'unit' => 'each', 'pantry' => 5, 'fridge' => 7, 'freezer' => 180, 'opened' => null],
        ['name' => 'Carrots', 'unit' => 'lb', 'pantry' => 7, 'fridge' => 21, 'freezer' => 300, 'opened' => null],
        ['name' => 'Garlic', 'unit' => 'head', 'pantry' => 60, 'fridge' => 14, 'freezer' => 300, 'opened' => null],
        ['name' => 'Lemon', 'unit' => 'each', 'pantry' => 14, 'fridge' => 30, 'freezer' => 180, 'opened' => 3],
        ['name' => 'Lime', 'unit' => 'each', 'pantry' => 14, 'fridge' => 30, 'freezer' => 180, 'opened' => 3],
        ['name' => 'Avocado', 'unit' => 'each', 'pantry' => 4, 'fridge' => 5, 'freezer' => 180, 'opened' => 1],
        ['name' => 'Cucumber', 'unit' => 'each', 'pantry' => 3, 'fridge' => 7, 'freezer' => null, 'opened' => null],
        ['name' => 'Spinach', 'unit' => 'bag', 'pantry' => 1, 'fridge' => 5, 'freezer' => 300, 'opened' => null],
        ['name' => 'Mushrooms', 'unit' => 'pkg', 'pantry' => 1, 'fridge' => 5, 'freezer' => 300, 'opened' => null],
        ['name' => 'Celery', 'unit' => 'bunch', 'pantry' => 2, 'fridge' => 14, 'freezer' => 300, 'opened' => null],
        ['name' => 'Grapes', 'unit' => 'lb', 'pantry' => 2, 'fridge' => 7, 'freezer' => 180, 'opened' => null],
        ['name' => 'Berries', 'unit' => 'pkg', 'pantry' => 1, 'fridge' => 3, 'freezer' => 300, 'opened' => null],
        ['name' => 'Oranges', 'unit' => 'each', 'pantry' => 7, 'fridge' => 21, 'freezer' => 180, 'opened' => null],
        ['name' => 'Ginger', 'unit' => 'each', 'pantry' => 14, 'fridge' => 21, 'freezer' => 180, 'opened' => null],
        ['name' => 'Cilantro', 'unit' => 'bunch', 'pantry' => 1, 'fridge' => 7, 'freezer' => null, 'opened' => null],
        ['name' => 'Green onion', 'unit' => 'bunch', 'pantry' => 2, 'fridge' => 7, 'freezer' => null, 'opened' => null],
        ['name' => 'Zucchini', 'unit' => 'each', 'pantry' => 3, 'fridge' => 7, 'freezer' => 300, 'opened' => null],
        ['name' => 'Sweet potato', 'unit' => 'each', 'pantry' => 21, 'fridge' => 14, 'freezer' => 300, 'opened' => null],
        ['name' => 'Cabbage', 'unit' => 'head', 'pantry' => 7, 'fridge' => 21, 'freezer' => 300, 'opened' => null],
        ['name' => 'Corn', 'unit' => 'each', 'pantry' => 1, 'fridge' => 3, 'freezer' => 300, 'opened' => null],
    ];

    /**
     * Rows from a markdown table that has a UPC column and a name column.
     * Blank or non-numeric UPCs are skipped. Nothing is invented for a missing cell.
     *
     * @return list<array{upc: string, name: string, brand: ?string, category: ?string, default_size: ?string, default_unit: ?string, notes: ?string}>
     */
    public static function productsFromMarkdown(string $body): array
    {
        $headers = null;
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|')) {
                $headers = null;
                continue;
            }
            $cells = self::cells($line);
            if (self::isSeparator($cells)) {
                continue;
            }
            if ($headers === null) {
                $mapped = self::headerMap($cells);
                $headers = isset($mapped['upc'], $mapped['name']) ? $mapped : null;
                continue;
            }
            $upc = self::digits($cells[$headers['upc']] ?? '');
            $name = trim($cells[$headers['name']] ?? '');
            if ($upc === null || $name === '') {
                continue;
            }
            $out[] = [
                'upc' => $upc,
                'name' => mb_substr($name, 0, 200),
                'brand' => self::optional($cells, $headers, 'brand', 120),
                'category' => self::optional($cells, $headers, 'category', 24),
                'default_size' => self::optional($cells, $headers, 'size', 80),
                'default_unit' => self::optional($cells, $headers, 'unit', 16),
                'notes' => self::optional($cells, $headers, 'notes', 500),
            ];
        }
        return $out;
    }

    public static function categoryName(?string $given, string $productName): string
    {
        if ($given !== null && trim($given) !== '') {
            try {
                return ShoppingText::categoryFor($given, $productName);
            } catch (ValidationException) {
                return ShoppingText::category($productName);
            }
        }
        return ShoppingText::category($productName);
    }

    /**
     * @param list<string> $cells
     */
    private static function isSeparator(array $cells): bool
    {
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (preg_match('/^:?-+:?$/', trim($cell)) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $line = trim($line, '|');
        $parts = explode('|', $line);
        return array_map(static fn (string $cell): string => trim($cell), $parts);
    }

    /**
     * @param list<string> $cells
     * @return array<string, int>
     */
    private static function headerMap(array $cells): array
    {
        $aliases = [
            'upc' => ['upc', 'barcode', 'ean', 'gtin'],
            'name' => ['name', 'product', 'item', 'description'],
            'brand' => ['brand'],
            'category' => ['category', 'aisle'],
            'size' => ['size', 'default_size'],
            'unit' => ['unit'],
            'notes' => ['notes', 'note'],
        ];
        $map = [];
        foreach ($cells as $i => $cell) {
            $key = strtolower(trim($cell));
            $key = str_replace([' ', '-'], '_', $key);
            foreach ($aliases as $name => $words) {
                if (in_array($key, $words, true) && !isset($map[$name])) {
                    $map[$name] = $i;
                }
            }
        }
        return $map;
    }

    private static function digits(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (preg_match('/^\d{8,14}$/', $digits) !== 1) {
            return null;
        }
        return $digits;
    }

    /**
     * @param list<string> $cells
     * @param array<string, int> $headers
     */
    private static function optional(array $cells, array $headers, string $key, int $max): ?string
    {
        if (!isset($headers[$key])) {
            return null;
        }
        $value = trim($cells[$headers[$key]] ?? '');
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }
}
