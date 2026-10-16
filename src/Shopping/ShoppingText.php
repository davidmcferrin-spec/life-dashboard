<?php

declare(strict_types=1);

namespace Dashboard\Shopping;

use Dashboard\Support\ValidationException;

/**
 * Turns "2 lb chicken" into a quantity, a unit, and a name, and guesses an aisle.
 */
final class ShoppingText
{
    /** @var list<string> */
    public const CATEGORIES = [
        'Produce', 'Dairy', 'Meat', 'Bakery', 'Frozen', 'Pantry', 'Snacks', 'Beverages', 'Household', 'Other',
    ];

    /** @var array<string, list<string>> */
    private const KEYWORDS = [
        'Produce' => ['apple', 'banana', 'lettuce', 'tomato', 'onion', 'garlic', 'potato', 'avocado', 'berry', 'lemon', 'lime', 'pepper', 'carrot', 'broccoli', 'spinach', 'salad', 'fruit', 'vegetable', 'cilantro', 'herb', 'cucumber', 'grape'],
        'Dairy' => ['milk', 'cheese', 'yogurt', 'butter', 'egg', 'cream', 'sour cream'],
        'Meat' => ['chicken', 'beef', 'pork', 'steak', 'bacon', 'turkey', 'sausage', 'fish', 'salmon', 'shrimp'],
        'Bakery' => ['bagel', 'muffin', 'croissant', 'baguette'],
        'Frozen' => ['ice cream', 'frozen', 'popsicle'],
        'Pantry' => ['bread', 'rice', 'pasta', 'cereal', 'flour', 'sugar', 'oil', 'bean', 'sauce', 'spice', 'coffee', 'tea', 'oat', 'soup'],
        'Snacks' => ['chip', 'cracker', 'cookie', 'granola', 'pretzel'],
        'Beverages' => ['juice', 'soda', 'water', 'beer', 'wine', 'kombucha'],
        'Household' => ['soap', 'detergent', 'paper towel', 'toilet paper', 'trash bag', 'sponge', 'bleach', 'cleaner', 'foil', 'battery', 'batteries', 'bulb', 'shampoo'],
    ];

    /** @var array<string, string> */
    private const UNITS = [
        'lb' => 'lb', 'lbs' => 'lb', 'pound' => 'lb', 'pounds' => 'lb',
        'oz' => 'oz', 'ounce' => 'oz', 'ounces' => 'oz',
        'g' => 'g', 'gram' => 'g', 'grams' => 'g',
        'kg' => 'kg',
        'gal' => 'gal', 'gallon' => 'gal', 'gallons' => 'gal',
        'qt' => 'qt', 'quart' => 'qt', 'quarts' => 'qt',
        'pt' => 'pt', 'pint' => 'pt', 'pints' => 'pt',
        'ml' => 'ml',
        'l' => 'l', 'liter' => 'l', 'litre' => 'l', 'liters' => 'l',
        'ct' => 'ct', 'count' => 'ct',
        'pkg' => 'pkg', 'pack' => 'pkg', 'package' => 'pkg',
        'bag' => 'bag', 'box' => 'box', 'can' => 'can', 'bottle' => 'bottle',
        'bunch' => 'bunch', 'dozen' => 'dozen', 'dz' => 'dozen',
    ];

    /**
     * @return array{name: string, name_key: string, quantity: float, unit: ?string, unit_key: string, category: string}
     */
    public static function parse(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            throw ValidationException::withFields(['text' => 'Enter an item.']);
        }
        $quantity = 1.0;
        $rest = $text;
        if (preg_match('/^(\d+(?:\.\d+)?)(?:\s+|(?=[A-Za-z]))(.*)$/u', $text, $m) === 1) {
            $quantity = (float) $m[1];
            $rest = trim($m[2]);
        }
        if ($quantity <= 0 || $quantity > 9999) {
            throw ValidationException::withFields(['quantity' => 'Quantity must be greater than 0.']);
        }
        $unit = null;
        if (preg_match('/^([A-Za-z]+)\s+(.+)$/u', $rest, $u) === 1 && isset(self::UNITS[strtolower($u[1])])) {
            $unit = self::UNITS[strtolower($u[1])];
            $rest = trim($u[2]);
        }
        $name = self::name($rest);
        return [
            'name'     => $name,
            'name_key' => self::nameKey($name),
            'quantity' => round($quantity, 3),
            'unit'     => $unit,
            'unit_key' => $unit ?? '',
            'category' => self::category($name),
        ];
    }

    public static function name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw ValidationException::withFields(['name' => 'Enter an item name.']);
        }
        if (mb_strlen($name) > 160) {
            throw ValidationException::withFields(['name' => 'Name must be 160 characters or fewer.']);
        }
        return $name;
    }

    public static function nameKey(string $name): string
    {
        return mb_strtolower(self::name($name));
    }

    public static function unit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        $token = strtolower(trim($unit));
        if ($token === '') {
            return null;
        }
        if (!isset(self::UNITS[$token])) {
            throw ValidationException::withFields(['unit' => 'Unknown unit.']);
        }
        return self::UNITS[$token];
    }

    public static function categoryFor(?string $given, string $name): string
    {
        if ($given !== null && trim($given) !== '') {
            foreach (self::CATEGORIES as $category) {
                if (strcasecmp($category, trim($given)) === 0) {
                    return $category;
                }
            }
            throw ValidationException::withFields(['category' => 'Unknown aisle.']);
        }
        return self::category($name);
    }

    public static function category(string $name): string
    {
        $hay = strtolower($name);
        $best = 'Other';
        $bestLen = 0;
        foreach (self::KEYWORDS as $category => $words) {
            foreach ($words as $word) {
                if (strlen($word) <= $bestLen) {
                    continue;
                }
                $pattern = '/(?<![a-z])' . preg_quote(strtolower($word), '/') . 's?(?![a-z])/';
                if (preg_match($pattern, $hay) === 1) {
                    $best = $category;
                    $bestLen = strlen($word);
                }
            }
        }
        return $best;
    }
}
