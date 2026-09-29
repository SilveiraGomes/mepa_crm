<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class LocationCreateRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['unit_public_id' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:191'], 'address' => ['required', 'array'], 'address.country_code' => ['required', 'string', 'regex:/^[A-Za-z]{2,3}$/'], 'address.line1' => ['required', 'string', 'min:3', 'max:500'], 'address.locality' => ['sometimes', 'nullable', 'string', 'max:191'], 'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'], 'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'], 'occupation_type_code' => ['required', 'string', 'max:64'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
