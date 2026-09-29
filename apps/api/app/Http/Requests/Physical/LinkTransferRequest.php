<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class LinkTransferRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['to_unit_public_id' => ['required', 'string', 'max:64'], 'occupation_type_code' => ['sometimes', 'string', 'max:64'], 'property_public_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'is_primary' => ['sometimes', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:0']];
    }
}
