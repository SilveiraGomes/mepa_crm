<?php

declare(strict_types=1);

namespace App\Http\People;

use App\Domain\People\PeopleError;
use App\Domain\People\PeopleReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Last line of defence for every People response: an internal numeric key, a foreign key, member
// number, ciphertext, blind index or key version must never leave the API. Services already project
// public identifiers only; a forbidden key reaching this point is a server bug and fails closed.
final class PeopleOutput
{
    private const FORBIDDEN = ['id', 'member_number', 'value_ciphertext', 'value_blind_index', 'line1_ciphertext', 'number_ciphertext', 'number_blind_index', 'key_version', 'merged_into_id', 'status_id', 'unit_id'];

    public static function item(array $data, int $status = 200): JsonResponse
    {
        self::assertSafe($data);
        return response()->json(['data' => $data], $status);
    }

    public static function page(Request $request, array $payload): JsonResponse
    {
        $items = $payload['items'] ?? [];
        self::assertSafe($items);
        $page = (int) ($payload['page'] ?? 1);
        $perPage = (int) ($payload['per_page'] ?? 50);
        $total = (int) ($payload['total'] ?? count($items));
        $last = max(1, (int) ceil($total / max(1, $perPage)));
        return response()->json([
            'data' => $items,
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $last],
            'links' => [
                'prev' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
                'next' => $page < $last ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
            ],
        ]);
    }

    public static function assertSafe(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && (in_array($key, self::FORBIDDEN, true) || (str_ends_with($key, '_id') && $key !== 'public_id'))) {
                throw new PeopleError(PeopleReason::INVARIANT_VIOLATION, ['reason' => 'internal_field_in_response']);
            }
            if (is_array($value)) {
                self::assertSafe($value);
            }
        }
    }
}
