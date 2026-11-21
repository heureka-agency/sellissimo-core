<?php

namespace Sellissimo\Core;

use Illuminate\Validation\ValidationException;

class OperationalFields
{
    public function __construct(private CommerceFields $fields, private Store $store) {}

    public function controls(): array
    {
        return array_filter($this->fields->controls('order', true), fn ($field) => $field['internal']);
    }

    /** Corrections overlay the original staff fields for the merchant view only. */
    public function current(object $order, array $receipt): array
    {
        return array_filter(array_replace($receipt['order_fields'] ?? [], json_decode($order->operational_fields ?? '{}', true)));
    }

    public function inputs(array $current, array $controls): array
    {
        $result = [];
        foreach (array_intersect_key($current, $controls) as $handle => $field) {
            $options = $controls[$handle]['options'];
            $key = array_search($field['value'], $options, true);
            $result[$handle] = $field['raw'] ?? $field['file_id'] ?? ($key === false ? $field['value'] : $key);
        }

        return $result;
    }

    public function update(string $id, int $revision, array $patch, $user): void
    {
        $controls = $this->controls();
        if (array_diff_key($patch, $controls)) {
            throw ValidationException::withMessages(['fields' => __('Choose a staff-only order field from the current blueprint.')]);
        }
        $this->store->db()->transaction(function () use ($id, $patch, $revision, $user, $controls) {
            $orders = $this->store->table('orders')->where('id', $id);
            $order = $orders->lockForUpdate()->firstOrFail();
            abort_unless((int) $order->fields_revision === $revision, 409, __('The order fields changed. Reload the order.'));
            $values = $this->fields->values('order', $patch, $id, true, true, array_keys($patch));
            $snapshot = $this->fields->snapshot('order', $values);
            $overrides = json_decode($order->operational_fields ?? '{}', true);
            foreach ($patch as $key => $value) {
                $overrides[$key] = isset($snapshot[$key]) ? [...$snapshot[$key], 'raw' => $values[$key]] : null;
            }
            $orders->update(['operational_fields' => json_encode($overrides, JSON_THROW_ON_ERROR), 'fields_revision' => $revision + 1]);
            app(OrderQueueFields::class)->refresh($id);
            $names = array_column(array_intersect_key($controls, $patch), 'label');
            $this->store->recordActivity($id, 'fields', __('Updated staff order fields: :fields', ['fields' => implode(', ', $names)]),
                $user->name() ?: $user->email(), (string) $user->id());
        }, 3);
    }
}
