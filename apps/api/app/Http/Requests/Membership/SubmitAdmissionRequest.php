<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class SubmitAdmissionRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['person_public_id' => ['required', 'string', 'max:64'], 'congregation_public_id' => ['required', 'string', 'max:64'], 'origin' => ['sometimes', 'string', 'in:ADMISSION,LEGACY_IMPORT'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'source_document' => ['sometimes', 'nullable', 'string', 'max:64'], 'lock_version' => ['sometimes', 'integer', 'min:0']];
    }
}
