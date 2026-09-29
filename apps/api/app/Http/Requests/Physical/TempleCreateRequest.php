<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class TempleCreateRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['location_public_id' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:191'], 'capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
