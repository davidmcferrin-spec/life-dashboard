<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseNormalizeParamsTest extends TestCase
{
    public function testBooleansBecomeIntegers(): void
    {
        self::assertSame([1, 0, 'x', null, 5], Database::normalizeParams([true, false, 'x', null, 5]));
    }

    public function testNamedKeysArePreserved(): void
    {
        self::assertSame([':a' => 1, ':b' => 0], Database::normalizeParams([':a' => true, ':b' => false]));
    }

    public function testNonBooleanFalsyValuesAreUntouched(): void
    {
        self::assertSame(['', '0', 0, 0.0], Database::normalizeParams(['', '0', 0, 0.0]));
    }
}
