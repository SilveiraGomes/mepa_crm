<?php

declare(strict_types=1);

namespace App\Http\Physical;

use App\Domain\Physical\PhysicalError;
use App\Domain\Physical\PhysicalReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Last line of defence for every Physical response: no numeric key (`id`, `*_id` other than a public id) and none
// of the stored secrets ever leaves the API.
final class PhysicalOutput
{
    private const FORBIDDEN = ['id', 'line1_ciphertext', 'key_version', 'source_document_id', 'owner_name_external', 'address_id', 'owner_person_id', 'unit_id', 'location_id', 'property_id', 'occupation_type_id'];

    public static function item(array $data, int $status = 200): JsonResponse
    {
        self::assertSafe($data);
        return response()->json(['data' => $data], $status);
    }

    public static function page(Request $request, array $p): JsonResponse
    {
        self::assertSafe($p['items'] ?? []);
        $page = (int) ($p['page'] ?? 1);
        $per = (int) ($p['per_page'] ?? 50);
        $total = (int) ($p['total'] ?? 0);
        $last = max(1, (int) ceil($total / max(1, $per)));
        $meta = ['current_page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => $last];
        if (array_key_exists('other_active_links', $p)) {
            $meta['other_active_links'] = (int) $p['other_active_links'];
        }
        return response()->json(['data' => $p['items'] ?? [], 'meta' => $meta, 'links' => [
            'prev' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
            'next' => $page < $last ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
        ]]);
    }

    public static function assertSafe(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && (in_array($key, self::FORBIDDEN, true) || (str_ends_with($key, '_id') && !str_ends_with($key, 'public_id')))) {
                throw new PhysicalError(PhysicalReason::INVARIANT_VIOLATION, ['reason' => 'internal_field_in_response']);
            }
            if (is_array($value)) {
                self::assertSafe($value);
            }
        }
    }
}
