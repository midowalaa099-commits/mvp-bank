<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function toMinor(string $amount, int $precision): int
    {
        if (! preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $amount)) {
            throw new InvalidArgumentException('The amount must be a positive decimal string without signs or exponents.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        if (strlen($fraction) > $precision) {
            throw new InvalidArgumentException('The amount has too many decimal places for this currency.');
        }

        $minor = ltrim($whole.str_pad($fraction, $precision, '0'), '0');
        $maximum = (string) PHP_INT_MAX;

        if ($minor === '') {
            throw new InvalidArgumentException('The amount must be greater than zero.');
        }

        if (strlen($minor) > strlen($maximum)
            || (strlen($minor) === strlen($maximum) && strcmp($minor, $maximum) > 0)) {
            throw new InvalidArgumentException('The amount exceeds the supported limit.');
        }

        return (int) $minor;
    }

    public static function format(int $minor, int $precision): string
    {
        if ($precision === 0) {
            return (string) $minor;
        }

        $digits = str_pad((string) $minor, $precision + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$precision).'.'.substr($digits, -$precision);
    }
}
