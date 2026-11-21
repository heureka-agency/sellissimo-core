<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;
use NumberFormatter;

final class Money
{
    public static function fractionDigits(string $currency): int
    {
        $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        return $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    public static function display(int $amount, string $currency, ?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        $digits = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
        if ($locale !== null) {
            // The storefront locale controls punctuation, while stored minor units retain their runtime scale.
            $digits = self::fractionDigits($currency);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $digits);
        }
        return $formatter->formatCurrency($amount / 10 ** $digits, $currency);
    }

    public static function parseDecimal(string $value, int $digits, string $field): int
    {
        $fraction = $digits ? '(?:\\.([0-9]{1,'.$digits.'}))?' : '';
        if (! preg_match('/^([0-9]{1,12})'.$fraction.'$/D', $value, $parts)) {
            throw ValidationException::withMessages([$field => 'Enter a non-negative amount with the correct number of decimal places.']);
        }

        return (int) ($parts[1].str_pad($parts[2] ?? '', $digits, '0'));
    }

    public static function decimal(int $amount, string $currency): string
    {
        return self::decimalWithDigits($amount, self::fractionDigits($currency));
    }

    public static function decimalWithDigits(int $amount, int $digits): string
    {
        $absolute = str_pad(ltrim((string) $amount, '-'), $digits + 1, '0', STR_PAD_LEFT);
        $decimal = $digits ? substr($absolute, 0, -$digits).'.'.substr($absolute, -$digits) : $absolute;

        return $amount < 0 ? '-'.$decimal : $decimal;
    }
}
