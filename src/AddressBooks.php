<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AddressBooks
{
    public function __construct(private Store $store) {}

    public static function scope(string $site, ?string $account, string $email = ''): string
    {
        return hash('sha256', json_encode([$site, $account === null ? 'guest' : 'account', $account ?? mb_strtolower(trim($email))], JSON_THROW_ON_ERROR));
    }

    public function read(string $scope): array
    {
        $row = $this->store->table('address_books')->where('scope', $scope)->first();

        return $row ? [...json_decode($row->addresses, true), 'revision' => (int) $row->revision] : ['entries' => [], 'billing' => null, 'shipping' => null, 'revision' => 0];
    }

    public function defaults(string $site, ?string $account, string $email = ''): array
    {
        $book = $this->read(self::scope($site, $account, $email));
        $entries = collect($book['entries'])->keyBy('id');

        return array_filter(['address' => $entries->get($book['billing'])['address'] ?? null,
            'shipping_address' => $entries->get($book['shipping'])['address'] ?? null]);
    }

    public function save(string $scope, array $input): void
    {
        if (isset($input['address'])) {
            $input['address'] = PostalRegions::address($input['address'], 'address.region');
        }
        $this->store->db()->transaction(function () use ($scope, $input) {
            $empty = ['entries' => [], 'billing' => null, 'shipping' => null];
            $this->store->table('address_books')->insertOrIgnore(['scope' => $scope, 'revision' => 0, 'addresses' => json_encode($empty)]);
            $record = $this->store->table('address_books')->where('scope', $scope)->lockForUpdate()->firstOrFail();
            if ((int) $record->revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'This address book changed. Refresh before saving.']);
            }
            $book = json_decode($record->addresses, true);
            $entries = collect($book['entries'])->keyBy('id');
            $id = $input['id'] ?? (string) Str::uuid();
            abort_if(isset($input['id']) && ! $entries->has($id), 404);
            if (($input['action'] ?? 'save') === 'delete') {
                $entries->forget($id);
            } else {
                abort_if(! $entries->has($id) && $entries->count() >= 40, 422, 'The address book is full.');
                $entries->put($id, ['id' => $id, 'label' => $input['label'], 'address' => array_filter($input['address'], fn ($value) => filled($value))]);
            }
            foreach (['billing', 'shipping'] as $type) {
                if ($entries->has($id) && ($input[$type] ?? false)) {
                    $book[$type] = $id;
                } elseif ($book[$type] === $id) {
                    $book[$type] = null;
                }
            }
            $book['entries'] = $entries->values()->all();
            $this->store->table('address_books')->where('scope', $scope)->update(['addresses' => json_encode($book, JSON_THROW_ON_ERROR), 'revision' => $record->revision + 1]);
        }, 3);
    }
}
