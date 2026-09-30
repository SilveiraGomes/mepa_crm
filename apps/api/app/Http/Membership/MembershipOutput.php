<?php

declare(strict_types=1);

namespace App\Http\Membership;

use App\Domain\Membership\MembershipError;
use App\Domain\Membership\MembershipReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Last line of defence for every Membership response: no primary key (`id`, `*_id` other than a public id) ever leaves
// the API; the official number is a value, never a route key.
final class MembershipOutput
{
    private const FORBIDDEN = ['id', 'person_id', 'membership_id', 'congregation_id', 'status_id', 'source_document_id', 'approved_by', 'unit_id', 'workflow_instance_id', 'origin_unit_id', 'destination_unit_id', 'milestone_type_id', 'import_record_id', 'sequence_value'];

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
        return response()->json(['data' => $p['items'] ?? [], 'meta' => ['current_page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => $last], 'links' => [
            'prev' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null,
            'next' => $page < $last ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    public static function assertSafe(array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && (in_array($key, self::FORBIDDEN, true) || (str_ends_with($key, '_id') && !str_ends_with($key, 'public_id')))) {
                throw new MembershipError(MembershipReason::INVARIANT_VIOLATION, ['reason' => 'internal_field_in_response', 'key' => $key]);
            }
            if (is_array($value)) {
                self::assertSafe($value);
            }
        }
    }
}
