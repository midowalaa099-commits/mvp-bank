<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_decimal_strings_convert_exactly(string $amount, int $precision, int $expected): void
    {
        $this->assertSame($expected, Money::toMinor($amount, $precision));
    }

    public static function validAmounts(): array
    {
        return [
            ['10.25', 2, 1025], ['10', 2, 1000], ['10.2', 2, 1020], ['0.01', 2, 1],
            ['10', 0, 10], ['1.234', 3, 1234], ['92233720368547758.07', 2, PHP_INT_MAX],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMinor($amount, 2);
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn (string $amount): array => [$amount], [
            '0', '0.00', '-1', '+1', '01.00', '1e2', '1,000', '10.001', '.50', '1.',
            '', ' 1 ', '١٠', '92233720368547758.08', '999999999999999999999999999999999999',
        ]);
    }

    public function test_display_preserves_zero_precision_and_large_values(): void
    {
        $this->assertSame('0.00', Money::format(0, 2));
        $this->assertSame('0.01', Money::format(1, 2));
        $this->assertSame('92233720368547758.07', Money::format(PHP_INT_MAX, 2));
        $this->assertSame('123', Money::format(123, 0));
        $this->assertSame('1.234', Money::format(1234, 3));
    }
}
