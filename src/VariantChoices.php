<?php

namespace Sellissimo\Core;

use Illuminate\Support\Collection;

class VariantChoices
{
    /** Link attribute values to valid SKUs, preserving the buyer's other choices where possible. */
    public static function groups(Collection $variants, array $selected): array
    {
        $groups = [];
        $scores = [];
        foreach ($variants as $variant) {
            $score = count(array_intersect_assoc($selected['attributes'], $variant['attributes']));
            foreach ($variant['attributes'] as $attribute => $value) {
                if (! is_scalar($value) || trim((string) $value) === '' || ($scores[$attribute][$value] ?? -1) >= $score) {
                    continue;
                }
                $scores[$attribute][$value] = $score;
                $groups[$attribute][$value] = [...$variant, 'label' => $value,
                    'selected' => ($selected['attributes'][$attribute] ?? null) === $value];
            }
        }
        // A named choice keeps legacy variants and variants with identical attributes reachable.
        $signature = fn ($v) => collect($v['attributes'])->sortKeys()->toJson();
        $counts = $variants->countBy($signature);
        $named = $variants->filter(fn ($v) => ! $v['attributes'] || count($v['attributes']) < count($groups) || $counts[$signature($v)] > 1);
        if ($named->isNotEmpty()) {
            $label = __($groups ? 'sellissimo::shop.other_options' : 'sellissimo::shop.variant');
            $groups[$label] = [...($groups[$label] ?? []), ...$named->map(fn ($v) => [
                ...$v, 'label' => $v['variant'] ?: $v['sku'], 'selected' => $v['sku'] === $selected['sku'],
            ])->values()->all()];
        }
        return $groups;
    }
}
