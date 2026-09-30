<?php

declare(strict_types=1);

namespace App\Http\Finance;

use App\Domain\Finance\FinanceError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Last line of defence for every Finance response: no primary key (`id`, `*_id` other than a public id) ever leaves the
// API (D20); collections are always paginated and bounded.
final class FinanceOutput
{
    public static function item(array $data, int $status = 200): JsonResponse
    {
        self::assertSafe($data);
        return response()->json(['data' => $data], $status, ['Cache-Control' => 'no-store, private']);
    }

    public static function page(Request $request, array $p): JsonResponse
    {
        self::assertSafe($p['items'] ?? []);
        $page = (int) ($p['page'] ?? 1);
        $per = (int) ($p['per_page'] ?? 50);
        $total = (int) ($p['total'] ?? 0);
        $last = max(1, (int) ceil($total / max(1, $per)));
        $extra = array_diff_key($p, array_flip(['items', 'page', 'per_page', 'total']));
        self::assertSafe($extra);
        return response()->json(['data' => $p['items'] ?? [], 'meta' => ['current_page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => $last] + $extra, 'links' => [
            'prev' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
            'next' => $page < $last ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    public static function assertSafe(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && ($key === 'id' || (str_ends_with($key, '_id') && !str_ends_with($key, 'public_id')))) {
                throw new FinanceError('INVARIANT_VIOLATION', [], ['reason' => 'internal_field_in_response', 'key' => $key]);
            }
            if (is_array($value)) {
                self::assertSafe($value);
            }
        }
    }
}
