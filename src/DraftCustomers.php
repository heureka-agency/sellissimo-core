<?php

namespace Sellissimo\Core;

use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use Sellissimo\Core\Http\CheckoutInput;
use Sellissimo\Core\Http\OrderListing;
use Statamic\Facades\User;

class DraftCustomers
{
    public function __construct(private Store $store) {}

    public function eligible($user, string $site): bool
    {
        // User profiles are installation-wide. Site-limited staff may look up a profile
        // they may view natively or an account which has purchased on this site.
        return $user && (request()->user()?->can('view', $user) || (StaffSites::storewide() && ! $user->can('access cp')) || $this->store->table('orders')
            ->where('site', $site)->where('customer', (string) $user->id())->exists());
    }

    public function confirm(string $id, string $site, ?string $email, bool $selecting = true): string
    {
        $user = User::find($id);
        if (! $user || ($selecting && ! $this->eligible($user, $site)) || strcasecmp($user->email(), (string) $email) !== 0) {
            throw ValidationException::withMessages(['customer_id' => 'This customer account changed. Select the customer again or enter guest details.']);
        }

        return (string) $user->id();
    }

    public function accounts(string $site, string $term): LazyCollection
    {
        return LazyCollection::make(function () use ($site, $term) {
            $query = User::query()->when($term !== '', fn ($q) => $q->where('email', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'))->orderBy('email');
            for ($page = 1; ; $page++) {
                $batch = (clone $query)->forPage($page, 64)->get();
                foreach ($batch as $user) {
                    if ($this->eligible($user, $site)) {
                        yield $user;
                    }
                }
                if ($batch->count() < 64) {
                    return;
                }
            }
        });
    }

    public function search(string $site, string $term): array
    {
        $matches = $this->accounts($site, $term)->take(8)->collect()->map(function ($user) use ($site) {
            $last = $this->store->table('orders')->where('site', $site)->where('customer', (string) $user->id())
                ->latest('created_at')->orderByDesc('id')->value('receipt');
            $receipt = json_decode($last ?? '{}', true);
            foreach (['address' => 'sellissimo_billing_address', 'shipping_address' => 'sellissimo_shipping_address'] as $key => $field) {
                if (is_array($profile = $user->get($field)) && $profile !== []) {
                    $receipt[$key] = $profile;
                }
            }

            return $this->contact((string) $user->id(), $user->email(), array_replace($receipt, app(AddressBooks::class)->defaults($site, (string) $user->id())), $user->name());
        })->values();
        $previous = app(OrderListing::class)->query(['q' => $term])->where('site', $site)->whereNull('customer')
            ->latest('created_at')->orderByDesc('id')->limit(50)->get(['email', 'receipt'])->unique('email')
            ->take(8)->map(fn ($row) => $this->contact(null, $row->email, array_replace(json_decode($row->receipt, true), app(AddressBooks::class)->defaults($site, null, $row->email))));

        return $matches->concat($previous)->unique(fn ($row) => mb_strtolower($row['email']))->values()->all();
    }

    private function contact(?string $id, string $email, array $receipt, ?string $name = null): array
    {
        $address = Arr::only($receipt['address'] ?? [], CheckoutInput::ADDRESS_FIELDS);
        if ($name && empty($address['name'])) {
            $address['name'] = $name;
        }

        return ['customer_id' => $id, 'email' => $email, 'address' => (object) $address,
            'shipping_address' => isset($receipt['shipping_address']) ? Arr::only($receipt['shipping_address'], CheckoutInput::ADDRESS_FIELDS) : null];
    }
}
