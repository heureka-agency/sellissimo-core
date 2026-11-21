<?php

namespace Sellissimo\Core\Http;

use Statamic\Dictionaries\Countries;

class StoreCountries
{
    public static function name(?string $code): ?string
    {
        return $code ? (\Locale::getDisplayRegion('und_'.$code, app()->getLocale()) ?: $code) : null;
    }

    public static function labels(bool $localized = false): array
    {
        return collect((new Countries)->optionItems())->mapWithKeys(fn ($country) => [$country['iso2'] => $localized ? self::name($country['iso2']) : $country['name']])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->all();
    }
}
