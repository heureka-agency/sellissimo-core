<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;
use Normalizer;

class PostalRegions
{
    public static function countries(): array
    {
        static $countries;

        return $countries ??= json_decode(file_get_contents(__DIR__.'/../resources/postal-subdivisions.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function options(string $country): array
    {
        return self::countries()[strtoupper($country)] ?? [];
    }

    private static function key(string $value): string
    {
        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', Normalizer::normalize(trim($value), Normalizer::FORM_D)));
    }

    public static function canonical(string $country, string $value): ?string
    {
        static $indexes = [];
        $country = strtoupper(trim($country));
        if (! isset($indexes[$country])) {
            $indexes[$country] = [];
            foreach (self::options($country) as $row) {
                foreach ($row as $alias) {
                    $indexes[$country][self::key($alias)] = $row[0];
                }
            }
        }
        $key = self::key($value);
        $prefix = strtolower($country).'-';

        return $indexes[$country][$key] ?? (str_starts_with($key, $prefix) ? ($indexes[$country][substr($key, strlen($prefix))] ?? null) : null);
    }

    public static function address(array $address, ?string $field = null): array
    {
        $country = $address['country'] ?? '';
        $region = trim($address['region'] ?? '');
        if ($country && $region !== '' && self::options($country)) {
            $code = self::canonical($country, $region);
            if ($code !== null) {
                $address['region'] = $code;
            } elseif ($field !== null) {
                throw ValidationException::withMessages([$field => __('sellissimo::shop.choose_known_region')]);
            }
        }

        return $address;
    }

    public static function matches(string $country, string $rule, string $value): bool
    {
        return self::key(self::canonical($country, $rule) ?? $rule) === self::key(self::canonical($country, $value) ?? $value);
    }

    public static function postcode(string|array $patterns, string $value): bool
    {
        $value = mb_strtoupper(trim($value));
        if ($value === '') {
            return false;
        }
        foreach ((array) $patterns as $pattern) {
            $regex = str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote(mb_strtoupper(trim($pattern)), '/'));
            if (preg_match('/^'.$regex.'$/uD', $value)) {
                return true;
            }
        }

        return false;
    }
}
