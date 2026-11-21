<?php

namespace Sellissimo\Core;

use Sellissimo\Core\Http\StoreCountries;
use Statamic\Facades\Site;

final class StoreIdentity
{
    public static function fields(string $section, string $site): array
    {
        $fields = match ($section) {
            'details' => [
                'trading_name' => ['Trading name', 'text', 'nullable|string|max:160', Site::all()->get($site)?->name() ?: config('app.name')],
                'contact_email' => ['Customer contact email', 'text', 'nullable|email|max:254', null],
                'address_line' => ['Street address', 'text', 'nullable|string|max:200', null],
                'address_city' => ['City or town', 'text', 'nullable|string|max:120', null],
                'address_region' => ['State, province or region', 'text', 'nullable|string|max:120', null],
                'address_postcode' => ['Postal code', 'text', 'nullable|string|max:30', null],
                'address_country' => ['Country', 'select', ['nullable', \Illuminate\Validation\Rule::in(array_keys(StoreCountries::labels()))], null],
            ],
            'emails' => [
                'sender_name' => ['Sender name', 'text', 'nullable|string|max:160', config('mail.from.name')],
                'sender_email' => ['Sender email', 'text', 'nullable|email|max:254', config('mail.from.address')],
                'reply_to' => ['Reply-to email', 'text', 'nullable|email|max:254', config('mail.reply_to.address')],
            ],
            default => [],
        };
        $definitions = [];
        foreach ($fields as $key => [$label, $type, $rules, $default]) {
            $field = ['type' => $type, 'display' => __($label)];
            if ($key === 'address_country') {
                $field += ['options' => StoreCountries::labels(true), 'searchable' => true, 'clearable' => true];
            }
            if ($key === 'sender_email') {
                $field['instructions'] = __('Use an address your mail service allows you to send from.');
            }
            $definitions[$key] = ['field' => $field, 'rules' => $rules, 'default' => $default];
        }

        return $definitions;
    }

    /** Store only merchant-provided identity on the order so a renamed shop can reissue its receipts. */
    public static function snapshot(string $site): array
    {
        $identity = [];
        foreach (['trading_name', 'contact_email', 'address_line', 'address_city', 'address_region', 'address_postcode', 'address_country'] as $key) {
            if (filled($value = StoreSettings::forSection($site, 'details', $key))) {
                $identity[$key] = $value;
            }
        }

        if ($identity && empty($identity['trading_name'])) {
            $identity['trading_name'] = Site::all()->get($site)?->name() ?: config('app.name');
        }

        return $identity;
    }
}
