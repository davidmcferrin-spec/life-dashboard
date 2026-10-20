<?php

declare(strict_types=1);

namespace Dashboard\Pantry;

use Dashboard\Support\ValidationException;

/**
 * One list of pantry units. Aliases fold plurals onto the stored name.
 */
final class PantryUnits
{
    /** @var list<string> */
    public const CANONICAL = [
        'each', 'ct', 'head', 'bunch', 'lb', 'oz', 'g', 'kg',
        'gal', 'qt', 'pt', 'ml', 'l', 'pkg', 'bag', 'box', 'can',
        'bottle', 'dozen', 'slice', 'piece', 'serving',
    ];

    /**
     * A count of these means packages, so a pack size expands them.
     *
     * @var list<string>
     */
    public const PACKAGE = ['pkg', 'box', 'bag', 'each'];

    /** @var array<string, string> */
    private const ALIASES = [
        'each' => 'each',
        'ct' => 'ct', 'count' => 'ct',
        'head' => 'head', 'heads' => 'head',
        'bunch' => 'bunch', 'bunches' => 'bunch',
        'lb' => 'lb', 'lbs' => 'lb',
        'oz' => 'oz',
        'g' => 'g',
        'kg' => 'kg',
        'gal' => 'gal',
        'qt' => 'qt',
        'pt' => 'pt',
        'ml' => 'ml',
        'l' => 'l',
        'pkg' => 'pkg', 'pack' => 'pkg', 'packs' => 'pkg',
        'bag' => 'bag', 'bags' => 'bag',
        'box' => 'box', 'boxes' => 'box',
        'can' => 'can', 'cans' => 'can',
        'bottle' => 'bottle', 'bottles' => 'bottle',
        'dozen' => 'dozen', 'dozens' => 'dozen',
        'slice' => 'slice', 'slices' => 'slice',
        'piece' => 'piece', 'pieces' => 'piece',
        'serving' => 'serving', 'servings' => 'serving',
    ];

    /**
     * @return list<string>
     */
    public static function canonical(): array
    {
        return self::CANONICAL;
    }

    public static function normalize(string $value): string
    {
        $key = strtolower(trim($value));
        if (!isset(self::ALIASES[$key])) {
            throw ValidationException::withFields(['unit' => 'Unknown unit.']);
        }
        return self::ALIASES[$key];
    }
}
