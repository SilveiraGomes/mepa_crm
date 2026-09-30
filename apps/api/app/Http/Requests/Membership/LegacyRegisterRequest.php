<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class LegacyRegisterRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['source_system' => ['sometimes', 'string', 'in:MEPA_LEGACY_V1'], 'raw_number' => ['required', 'string', 'max:191'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
