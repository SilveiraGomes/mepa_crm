<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class LegacyRevokeRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['source_system' => ['sometimes', 'string', 'in:MEPA_LEGACY_V1'], 'normalized_number' => ['required', 'string', 'max:191'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'lock_version' => ['sometimes', 'integer', 'min:0']];
    }
}
