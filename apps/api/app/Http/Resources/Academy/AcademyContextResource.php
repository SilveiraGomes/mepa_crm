<?php

declare(strict_types=1);

namespace App\Http\Resources\Academy;

use Illuminate\Http\Resources\Json\JsonResource;

final class AcademyContextResource extends JsonResource
{
    public static $wrap = 'data';

    public function toArray($request): array
    {
        $context = (array) $this->resource;
        $vocabulary = (array) ($context['vocabulary'] ?? []);
        return [
            'permissions' => array_values(array_filter((array) ($context['permissions'] ?? []), 'is_string')),
            'vocabulary' => [
                'transitions' => (array) ($vocabulary['transitions'] ?? []),
                'attendance_statuses' => array_values(array_filter((array) ($vocabulary['attendance_statuses'] ?? []), 'is_string')),
            ],
        ];
    }
}
