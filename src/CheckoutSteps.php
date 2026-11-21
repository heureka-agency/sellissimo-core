<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CheckoutSteps
{
    public function __construct(private Registry $providers) {}

    public function order(?string $site = null): array
    {
        $order = config('sellissimo.checkout_steps', ['contact', 'delivery', 'review']);
        if ($site !== null) {
            $order = StoreSettings::forSection($site, 'checkout', 'checkout_steps', $order);
        }
        if (!array_is_list($order) || count(array_unique($order)) !== count($order) || reset($order) !== 'contact'
            || end($order) !== 'review' || !in_array('delivery', $order, true)) {
            throw new InvalidArgumentException('Checkout steps must begin with contact, include delivery, and end with review. Use each handle once.');
        }
        return $order;
    }

    public function definitions(array $quote, string $site): array
    {
        $public = Pricing::publicReceipt($quote);
        unset($public['checkout_steps']);
        $definitions = [];
        foreach ($this->order($site) as $handle) {
            if (in_array($handle, ['contact', 'delivery', 'review'], true)) {
                if ($handle !== 'delivery' || collect($quote['lines'])->contains(fn ($line) => !$line['digital'])) {
                    $definitions[$handle] = ['label' => __('sellissimo::shop.'.$handle)];
                }
                continue;
            }
            $step = $this->providers->registered('checkout_steps', $handle);
            if (!$step->applies($public, $site)) {
                continue;
            }
            $fields = $step->fields($public, $site);
            foreach ($fields as $key => &$field) {
                if (!preg_match('/^[a-z][a-z0-9_]*$/D', $key) || blank($field['label'] ?? null)
                    || !in_array($field['type'] ?? 'text', ['text', 'select', 'date'], true)
                    || (($field['type'] ?? '') === 'select' && empty($field['options']))) {
                    throw new InvalidArgumentException("Invalid field on checkout step $handle: $key");
                }
                $field = [...$field, 'type' => $field['type'] ?? 'text', 'required' => (bool) ($field['required'] ?? false)];
            }
            unset($field);
            $definitions[$handle] = ['label' => $step->label(), 'fields' => $fields];
        }
        return $definitions;
    }

    public function publicDefinitions(array $definitions): array
    {
        foreach ($definitions as &$definition) {
            if (isset($definition['fields'])) {
                $definition['fields'] = array_map(fn ($field) => array_intersect_key($field, array_flip(['type', 'label', 'required', 'options'])), $definition['fields']);
            }
        }
        return $definitions;
    }

    /** Snapshot buyer-visible labels and values only after validation against the current quote. */
    public function snapshot(array $definitions, array $answers, bool $required, bool $preview): array
    {
        $snapshots = [];
        foreach ($definitions as $handle => $definition) {
            if (!isset($definition['fields']) || (!$required && !array_key_exists($handle, $answers))) {
                continue;
            }
            $fields = $definition['fields'];
            $prefix = "checkout_steps.$handle";
            $rules = [$prefix => ['array', 'max:40']];
            $values = [];
            $attributes = [];
            foreach ($fields as $key => $field) {
                $path = "$prefix.$key";
                $rules[$path] = [$field['required'] ? 'required' : 'nullable', 'string', 'max:500'];
                if ($field['type'] === 'select') {
                    $rules[$path][] = Rule::in(array_keys($field['options']));
                } elseif ($field['type'] === 'date') {
                    $rules[$path][] = 'date_format:Y-m-d';
                }
                array_push($rules[$path], ...($field['rules'] ?? []));
                $attributes[$path] = $field['label'];
            }
            $validator = Validator::make(['checkout_steps' => $answers], $rules, [], $attributes);
            if ($validator->fails()) {
                if ($preview) {
                    continue;
                }
                throw new ValidationException($validator);
            }
            foreach ($fields as $key => $field) {
                $value = $answers[$handle][$key] ?? null;
                if (filled($value)) {
                    $values[$key] = ['label' => $field['label'], 'value' => $value,
                        'display' => $field['type'] === 'select' ? $field['options'][$value] : $value];
                }
            }
            if ($values) {
                $snapshots[$handle] = ['label' => $definition['label'], 'fields' => $values];
            }
        }
        return $snapshots;
    }

    public function firstIncomplete(array $definitions, array $receipt, ?string $email, string $target): ?string
    {
        foreach ($definitions as $handle => $definition) {
            if ($handle === $target) {
                break;
            }
            $incomplete = match ($handle) {
                'contact' => !$email || !empty($receipt['country_errors']) || Http\CheckoutInput::missingAddress($receipt, isset($receipt['shipping_address'])),
                'delivery' => !$receipt['delivery_ready'],
                'review' => false,
                default => !isset($receipt['checkout_steps'][$handle]) && collect($definition['fields'])->contains('required', true),
            };
            if ($incomplete) {
                return $handle;
            }
        }
        return null;
    }
}
