<?php

declare(strict_types=1);

namespace App\Http\Resources\Academy;

use Illuminate\Http\Resources\Json\JsonResource;

final class AcademyReadCollectionResource extends JsonResource
{
    public static $wrap = null;
    public function toArray($request): array
    {
        $payload = (array) $this->resource;
        $items = array_map(fn ($row) => (new AcademyReadResource((array) $row))->toArray($request), $payload['items'] ?? []);
        $page = (int) ($payload['page'] ?? 1); $perPage = (int) ($payload['per_page'] ?? 50); $total = (int) ($payload['total'] ?? count($items));
        $last = max(1, (int) ceil($total / max(1, $perPage)));
        return ['data' => $items, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $last],
            'links' => ['prev' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null, 'next' => $page < $last ? $request->fullUrlWithQuery(['page' => $page + 1]) : null]];
    }
}
