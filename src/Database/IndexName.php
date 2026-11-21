<?php

namespace Sellissimo\Core\Database;

final class IndexName
{
    public static function forStore(string $prefix, string $name): string
    {
        $identifier = $prefix.$name;

        // PostgreSQL limits identifiers to 63 bytes; preserve the store distinction at that limit.
        return strlen($identifier) <= 63 ? $identifier : 'ss_'.substr(hash('sha256', $identifier), 0, 40);
    }
}
