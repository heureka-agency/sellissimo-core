<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Sellissimo\Core\Contracts\ConfiguresDiscountRule;

class DiscountExtensions
{
    public function definitions(): array
    {
        $definitions = [];
        foreach (app(Registry::class)->enabled('discount_rules') as $handle => $rule) {
            $configurable = $rule instanceof ConfiguresDiscountRule;
            $definitions['extension:'.$handle] = [
                'label' => $configurable ? $rule->label() : Str::headline($handle),
                'fields' => $configurable ? $rule->fields() : [],
            ];
        }
        return $definitions;
    }

    public function settings(array $fields, mixed $settings): array
    {
        $keys = array_keys($fields);
        $rules = ['rule_settings' => $keys ? ['array:'.implode(',', $keys)] : ['array', 'size:0']];
        if (is_array($settings)) {
            foreach ($fields as $key => $field) {
                if (!array_key_exists($key, $settings) && array_key_exists('default', $field)) {
                    $settings[$key] = $field['default'];
                }
            }
        }
        foreach ($fields as $key => $field) {
            $type = $field['type'];
            $rule = match ($type) {
                'integer' => ['integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 2147483647)],
                'select' => ['string', Rule::in(array_keys($field['options']))],
                'toggle' => ['boolean'],
                'text' => ['string', 'max:'.($field['max'] ?? 255)],
            };
            $rules['rule_settings.'.$key] = [($field['required'] ?? false) ? 'required' : 'nullable', ...$rule];
        }
        $validated = Validator::make(['rule_settings' => $settings], $rules)->validate()['rule_settings'];
        foreach ($validated as $key => $value) {
            if ($value !== null) {
                $validated[$key] = match ($fields[$key]['type']) {
                    'integer' => (int) $value, 'toggle' => (bool) $value, default => $value,
                };
            }
        }
        return $validated;
    }
}
