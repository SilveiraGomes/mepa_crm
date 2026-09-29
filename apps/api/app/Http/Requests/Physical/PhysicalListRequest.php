<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class PhysicalListRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1'], 'search' => ['sometimes', 'nullable', 'string', 'max:100'], 'status' => ['sometimes', 'nullable', 'string', 'in:DRAFT,ACTIVE,CLOSED,ENDED,ALL'], 'unit_public_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'location_public_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'history' => ['sometimes', 'boolean']];
    }
}
