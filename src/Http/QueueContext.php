<?php

namespace Sellissimo\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class QueueContext
{
    /** Keep listing filters separate from record inputs and the record's own pagination. */
    public static function read(Request $request, string $queue): array
    {
        $extra = match ($queue) {
            'drafts' => ['view' => 'nullable|in:open,ordered,cancelled'],
            'inventory' => ['view' => 'nullable|in:available,empty'],
            'customers' => ['view' => 'nullable|in:accounts,guests'],
            'returns' => ['status' => 'nullable|in:requested,approved,declined,received,exchanged', 'order' => 'nullable|uuid', 'case' => 'nullable|uuid'],
        };
        $values = $request->query('queue', []);
        if (!is_array($values)) {
            return [];
        }
        $validator = Validator::make($values, [
            'site' => 'nullable|alpha_dash|max:100', 'page' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:150', ...$extra,
        ]);
        return $validator->fails() ? [] : array_filter($validator->validated(), fn ($v) => $v !== null && $v !== '');
    }

    public static function orderOrigin(Request $request): array
    {
        $origin = $request->query('origin');
        if (!in_array($origin, ['drafts', 'returns'], true)) {
            return [];
        }
        return ['origin' => $origin, 'queue' => self::read($request, $origin)];
    }
}
