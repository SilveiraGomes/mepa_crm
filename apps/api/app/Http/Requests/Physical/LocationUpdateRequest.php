<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class LocationUpdateRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:191'], 'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'], 'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
