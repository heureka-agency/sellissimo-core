<?php

namespace Sellissimo\Core;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Traits\Localizable;
use Statamic\Facades\Site;

class StoreMail
{
    use Localizable;

    public function send(string $type, string $site, string $email, string $url, array $data = []): void
    {
        $storefront = Site::all()->get($site);
        $this->withLocale($storefront?->shortLocale() ?? config('app.locale'), function () use ($type, $site, $storefront, $email, $url, $data) {
            $views = ['html' => 'sellissimo::mail.'.$type, 'text' => 'sellissimo::mail.text'];
            $identity = isset($data['order']->receipt)
                ? (json_decode($data['order']->receipt, true)['store_identity'] ?? [])
                : StoreIdentity::snapshot($site);
            $data += ['identity' => $identity, 'type' => $type, 'brand' => $identity['trading_name'] ?? ($storefront?->name() ?: config('app.name')), 'url' => $url];
            if (in_array($type, ['receipt', 'accepted', 'receipt_link'], true) && isset($data['order']->total, $data['order']->currency)) {
                $data['orderTotal'] = Money::display((int) $data['order']->total, $data['order']->currency);
            }
            if (isset($data['payableBalance'])) {
                $balance = $data['payableBalance'];
                $formatted = Money::display($balance['amount'], $balance['currency']);
                $data['formattedBalance'] = str_contains($formatted, $balance['currency']) ? $formatted : $formatted.' '.$balance['currency'];
            }
            Mail::send($views, $data, function ($message) use ($email, $type, $site) {
                $address = StoreSettings::forSection($site, 'emails', 'sender_email');
                $name = StoreSettings::forSection($site, 'emails', 'sender_name');
                if (($address || $name) && ($from = $address ?: config('mail.from.address'))) {
                    $message->from($from, $name ?: config('mail.from.name'));
                }
                if ($reply = StoreSettings::forSection($site, 'emails', 'reply_to')) {
                    $message->replyTo([$reply]);
                }
                $message->to($email)->subject(__("sellissimo::mail.$type.subject"));
            });
        });
    }
}
