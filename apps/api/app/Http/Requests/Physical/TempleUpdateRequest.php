<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class TempleUpdateRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:191'], 'capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
