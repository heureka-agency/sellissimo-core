<?php

namespace Sellissimo\Core;

class DeliveryFields
{
    public static function profiles(): array
    {
        static $countries;
        return $countries ??= json_decode(file_get_contents(__DIR__.'/../resources/address-requirements.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function required(array $address): array
    {
        $profile = self::profiles()[$address['country'] ?? ''] ?? ['street', 'city', 'postal_code'];
        return ['country', ...array_map(fn ($field) => $field === 'city_or_region'
            ? (filled($address['region'] ?? null) ? 'region' : 'city') : $field, $profile)];
    }
}
