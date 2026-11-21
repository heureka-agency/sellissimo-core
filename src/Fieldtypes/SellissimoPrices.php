<?php

namespace Sellissimo\Core\Fieldtypes;

use Illuminate\Validation\ValidationException;
use Sellissimo\Core\Money;
use Statamic\Fields\Fieldtype;

class SellissimoPrices extends Fieldtype
{
    protected static $title = 'Sellissimo prices';

    public function augment($value)
    {
        return $this->config('internal', false) ? null : $value;
    }

    public function rules(): array
    {
        return ['nullable', 'array'];
    }

    public function preload()
    {
        $currencies = \Sellissimo\Core\SellingCurrencies::editable();
        return ['currencies' => $currencies, 'digits' => array_combine($currencies, array_map(Money::fractionDigits(...), $currencies))];
    }

    public function preProcess($data)
    {
        $prices = [];
        foreach ($data ?? [] as $currency => $amount) {
            $minor = filter_var($amount, FILTER_VALIDATE_INT);
            $prices[$currency] = $minor !== false && $minor >= 0 && preg_match('/^[A-Z]{3}$/D', $currency)
                ? Money::decimal($minor, $currency) : $amount;
        }
        return $prices;
    }

    public function process($data)
    {
        $prices = [];
        $field = $this->field()?->handle() ?? 'prices';
        if ($data !== null && ! is_array($data)) {
            throw ValidationException::withMessages([$field => 'Enter one amount per currency.']);
        }
        foreach ($data ?? [] as $currency => $amount) {
            if ($amount === null || $amount === '') {
                continue;
            }
            if (! preg_match('/^[A-Z]{3}$/D', $currency) || ! is_scalar($amount)) {
                throw ValidationException::withMessages([$field => 'Enter a price for a valid currency.']);
            }
            $prices[$currency] = Money::parseDecimal((string) $amount, Money::fractionDigits($currency), $field);
            if ($prices[$currency] > 99999999) {
                throw ValidationException::withMessages([$field => "The $currency price exceeds the supported maximum."]);
            }
        }
        return $prices;
    }
}
