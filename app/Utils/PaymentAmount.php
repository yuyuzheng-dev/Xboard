<?php

namespace App\Utils;

/**
 * Converts a decimal currency amount into integer cents without float rounding.
 */
final class PaymentAmount
{
    public static function toCents(mixed $amount): ?int
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            return null;
        }
        if (is_float($amount) && !is_finite($amount)) {
            return null;
        }
        $value = (string) $amount;
        if (!preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D', $value, $parts)) {
            return null;
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }
}
