<?php

namespace Sellissimo\Core;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Store
{
    public function db(): Connection
    {
        return DB::connection(config('sellissimo.connection'));
    }

    public function table(string $name): Builder
    {
        return $this->db()->table('sellissimo_'.$name);
    }

    public function hasTable(string $name): bool
    {
        $connection = $this->db();
        $path = $connection->getConfig('database');
        if ($connection->getConfig('driver') === 'sqlite' && $path !== ':memory:' && ! is_file($path)) {
            return false;
        }

        return $connection->getSchemaBuilder()->hasTable('sellissimo_'.$name);
    }

    public function recordActivity(string $orderId, string $kind, string $message, ?string $author = null, ?string $authorId = null, ?string $requestId = null): void
    {
        $this->table('order_activity')->insert([
            'order_id' => $orderId, 'kind' => $kind, 'message' => $message, 'created_at' => now(),
            'author' => $author, 'author_id' => $authorId, 'request_id' => $requestId,
        ]);
        if (in_array($kind, ['payment', 'refund', 'return', 'cancellation', 'amendment', 'import'], true)) {
            $this->db()->afterCommit(fn () => app(PurchaseTrends::class)->orderChanged($orderId));
        }
    }
}
