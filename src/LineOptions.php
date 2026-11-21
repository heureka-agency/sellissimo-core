<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LineOptions
{
    public static function withHandles(array $fields): array
    {
        $taken = [];
        foreach ($fields as $index => $field) {
            $key = $field['handle'] ?? '';
            if ($key === '') {
                continue;
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) || isset($taken[$key])) {
                throw ValidationException::withMessages(["sellissimo_options.$index.handle" => 'Use a unique identifier of up to 40 lowercase letters, digits or underscores, starting with a letter.']);
            }
            $taken[$key] = true;
        }
        foreach ($fields as $index => $field) {
            if (filled($field['handle'] ?? null)) {
                continue;
            }
            $slug = Str::slug($field['label'] ?? '', '_');
            $base = substr(preg_match('/^[a-z]/', $slug) ? $slug : 'field_'.($slug ?: 'value'), 0, 40);
            $key = $base;
            for ($number = 2; isset($taken[$key]); $number++) {
                $suffix = '_'.$number;
                $key = substr($base, 0, 40 - strlen($suffix)).$suffix;
            }
            $fields[$index]['handle'] = $key;
            $taken[$key] = true;
        }

        return $fields;
    }

    public static function definitions(array $fields): array
    {
        $definitions = [];
        foreach ($fields as $field) {
            $handle = $field['handle'] ?? '';
            throw_unless(preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $handle) && ! isset($definitions[$handle]), 'Invalid product option handle.');
            $definitions[$handle] = [
                'label' => $field['label'] ?? $handle, 'required' => (bool) ($field['required'] ?? false),
                'max_length' => max(1, min(250, (int) ($field['max_length'] ?? 100))),
            ];
        }

        return $definitions;
    }

    public static function validate(array $definitions, array $values): array
    {
        if (array_diff_key($values, $definitions)) {
            throw ValidationException::withMessages(['options' => 'This product does not accept one of the selected options.']);
        }
        $selected = [];
        foreach ($definitions as $handle => $field) {
            $value = $values[$handle] ?? '';
            if (! is_string($value) || mb_strlen(trim($value)) > $field['max_length'] || ($field['required'] && trim($value) === '')) {
                throw ValidationException::withMessages(["options.$handle" => 'Enter a valid value for '.$field['label'].' (up to '.$field['max_length'].' characters).']);
            }
            if (trim($value) !== '') {
                $selected[$handle] = trim($value);
            }
        }
        ksort($selected);

        return $selected;
    }

    public static function key(string $sku, array $options, array $fields = []): string
    {
        if ($fields) {
            return 'p:'.hash('sha256', json_encode([$sku, $options, $fields], JSON_THROW_ON_ERROR));
        }
        return $options ? 'p:'.hash('sha256', json_encode([$sku, $options], JSON_THROW_ON_ERROR)) : $sku;
    }

    public static function find(array $lines, string $key): ?array
    {
        return collect($lines)->first(fn ($line) => ($line['key'] ?? $line['sku']) === $key);
    }
}
