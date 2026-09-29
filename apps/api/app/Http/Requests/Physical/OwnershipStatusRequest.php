<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class OwnershipStatusRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['ownership_status' => ['required', 'string', 'max:64'], 'reason' => ['required', 'string', 'min:3', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:0']];
    }
}
