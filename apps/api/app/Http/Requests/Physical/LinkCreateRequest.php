<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class LinkCreateRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['unit_public_id' => ['required', 'string', 'max:64'], 'occupation_type_code' => ['required', 'string', 'max:64'], 'property_public_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'is_primary' => ['sometimes', 'boolean'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
