<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class PropertyUpdateRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['code' => ['sometimes', 'string', 'max:64'], 'owner_person_public_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'owner_name_external' => ['sometimes', 'nullable', 'string', 'max:191'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
