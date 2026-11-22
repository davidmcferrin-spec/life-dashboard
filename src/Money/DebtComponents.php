<?php

declare(strict_types=1);

namespace Dashboard\Money;

/**
 * Component amounts sit beside the debt balance. Unapplied funds reduce
 * the sum. The balance column is not changed when the sum disagrees.
 */
final class DebtComponents
{
    /** @var list<string> */
    public const KINDS = ['principal', 'unapplied_funds', 'late_charges', 'fees', 'interest'];

    public static function sum(?float $principal, ?float $unapplied, ?float $late, ?float $fees, ?float $interest): ?float
    {
        if ($principal === null && $unapplied === null && $late === null && $fees === null && $interest === null) {
            return null;
        }

        return round(($principal ?? 0) + ($late ?? 0) + ($fees ?? 0) + ($interest ?? 0) - ($unapplied ?? 0), 2);
    }

    public static function mismatches(float $balance, ?float $sum): bool
    {
        if ($sum === null) {
            return false;
        }

        return abs(round($balance, 2) - $sum) > 0.009;
    }
}
