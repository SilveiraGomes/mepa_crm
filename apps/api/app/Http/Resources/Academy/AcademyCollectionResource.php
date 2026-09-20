<?php

declare(strict_types=1);

namespace App\Http\Resources\Academy;

use Illuminate\Http\Resources\Json\JsonResource;

final class AcademyCollectionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        $payload = (array) $this->resource;
        $items = $payload['items'] ?? (array_is_list($payload) ? $payload : []);
        $items = array_map(function ($item) use ($request) {
            $item = (array) $item;
            if (isset($item['instructor_id'], $item['id'])) {
                $item['assignment_id'] = (int) $item['id'];
            }
            return (new AcademyActionResource($item))->toArray($request);
        }, $items);
        if (!isset($payload['total'])) {
            return ['data' => $items, 'meta' => ['count' => count($items)], 'links' => []];
        }
        $page = (int) $payload['page'];
        $perPage = (int) $payload['per_page'];
        $last = max(1, (int) ceil(((int) $payload['total']) / max(1, $perPage)));
        return [
            'data' => $items,
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => (int) $payload['total'], 'last_page' => $last],
            'links' => ['prev' => $page > 1 ? $request->fullUrlWithQuery(['page' => $page - 1]) : null, 'next' => $page < $last ? $request->fullUrlWithQuery(['page' => $page + 1]) : null],
        ];
    }
}
