<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class PropertyCreateRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['location_public_id' => ['required', 'string', 'max:64'], 'code' => ['required', 'string', 'max:64'], 'owner_person_public_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'owner_name_external' => ['sometimes', 'nullable', 'string', 'max:191'], 'ownership_status' => ['sometimes', 'string', 'max:64'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
