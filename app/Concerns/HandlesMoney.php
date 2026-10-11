<?php

namespace App\Concerns;

trait HandlesMoney
{
    /**
     * Determine whether the amount is a positive value with at most two decimal places.
     */
    protected function isValidMoneyAmount(string $amount): bool
    {
        $trimmed = trim($amount);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return false;
        }

        $value = (float) $trimmed;

        if ($value <= 0 || ! is_finite($value)) {
            return false;
        }

        $decimalPosition = strpos($trimmed, '.');

        if ($decimalPosition !== false && strlen($trimmed) - $decimalPosition - 1 > 2) {
            return false;
        }

        return true;
    }

    /**
     * Normalize an amount to a two-decimal string.
     */
    protected function normalizeMoneyAmount(string $amount): string
    {
        return number_format((float) trim($amount), 2, '.', '');
    }

    /**
     * Convert a monetary string to whole cents for exact comparison.
     */
    protected function toCents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
