<?php

namespace Sellissimo\Core;

use Illuminate\Support\Str;

class OrderQueueFields
{
    public function __construct(private Store $store, private OperationalFields $fields) {}

    public function definitions(): array
    {
        return array_filter(app(CommerceFields::class)->controls('order', true), fn ($field) => $field['type'] !== 'assets');
    }

    public function values(object $order, array $receipt): array
    {
        $values = [];
        foreach ($this->fields->current($order, $receipt) as $handle => $field) {
            if (strlen($handle) <= 150 && ! isset($field['file_id']) && is_scalar($field['value'] ?? null)) {
                $values[$handle] = is_bool($field['value']) ? (string) (int) $field['value'] : (string) $field['value'];
            }
        }

        return $values;
    }

    public function refresh(string $id): void
    {
        $order = $this->store->table('orders')->where('id', $id)->firstOrFail(['id', 'receipt', 'operational_fields']);
        $records = [];
        foreach ($this->values($order, json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR)) as $handle => $value) {
            $records[] = ['order_id' => $id, 'handle' => $handle, 'term' => hash('sha256', Str::lower(trim($value)))];
        }
        $this->store->table('queue_fields')->where('order_id', $id)->delete();
        if ($records) {
            $this->store->table('queue_fields')->insert($records);
        }
    }
}
