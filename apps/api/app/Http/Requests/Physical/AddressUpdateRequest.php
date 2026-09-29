<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class AddressUpdateRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['country_code' => ['required', 'string', 'regex:/^[A-Za-z]{2,3}$/'], 'line1' => ['required', 'string', 'min:3', 'max:500'], 'locality' => ['sometimes', 'nullable', 'string', 'max:191'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
