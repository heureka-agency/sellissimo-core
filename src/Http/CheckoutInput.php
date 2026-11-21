<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;

class CheckoutInput
{
    public const ADDRESS_FIELDS = ['name', 'company', 'street', 'street2', 'city', 'region', 'postal_code', 'country', 'phone'];

    /** Fields still needed to leave the contact step, keyed to their form inputs. */
    public static function missingAddress(array $receipt, bool $separate): array
    {
        $destination = $separate ? 'shipping_address' : 'address';
        $required = ['address' => ['country', ...$receipt['missing_billing_fields']]];
        if (collect($receipt['lines'])->contains(fn ($line) => ! $line['digital'])) {
            $required[$destination] = [...($required[$destination] ?? []), ...\Sellissimo\Core\DeliveryFields::required($receipt[$destination] ?? []), ...$receipt['missing_shipping_fields']];
        }
        $missing = [];
        foreach ($required as $group => $fields) {
            foreach (array_unique($fields) as $field) {
                if (blank($receipt[$group][$field] ?? null)) {
                    $missing["$group.$field"] = __('sellissimo::shop.required_field');
                }
            }
        }
        return $missing;
    }

    public static function validate(Request $request, bool $checkout = false): array
    {
        $rules = [
            'checkout_steps' => 'sometimes|array|max:40',
            'checkout_steps.*' => 'array|max:40',
            'checkout_steps.*.*' => 'nullable|string|max:500',
            'order_fields' => 'sometimes|array|max:100', 'voucher' => 'nullable|string|max:80',
            'shipping_instructions' => 'nullable|string|max:500', 'shipping' => 'nullable|string|max:100', 'discount' => 'nullable|string|max:80',
            'email' => ($checkout ? 'required' : 'nullable').'|email|max:254',
            ...($checkout ? ['gateway' => 'nullable|string|max:80', 'quote_token' => 'nullable|regex:/^[a-f0-9]{64}$/D'] : []),
        ];
        foreach (['address', 'shipping_address'] as $key) {
            $rules[$key] = 'sometimes|array:'.implode(',', self::ADDRESS_FIELDS);
            $required = $checkout && ($key === 'address' || $request->has($key));
            $rules[$key.'.country'] = ($required ? 'required' : 'sometimes').'|regex:/^[A-Z]{2}$/D';
            foreach (['name' => 150, 'company' => 150, 'street' => 250, 'street2' => 250, 'city' => 100, 'region' => 100, 'postal_code' => 32, 'phone' => 50] as $field => $length) {
                $rules[$key.'.'.$field] = 'nullable|string|max:'.$length;
            }
        }

        $data = $request->validate($rules);
        foreach (['address', 'shipping_address'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = \Sellissimo\Core\PostalRegions::address($data[$key]);
            }
        }

        return $data;
    }
}
