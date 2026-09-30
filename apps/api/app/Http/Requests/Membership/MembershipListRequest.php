<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class MembershipListRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1'], 'search' => ['sometimes', 'nullable', 'string', 'max:100'], 'status' => ['sometimes', 'nullable', 'string', 'in:SUBMITTED,VALIDATED,REJECTED,WITHDRAWN,ACTIVE,INACTIVE,ENDED'], 'congregation_public_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'queue' => ['sometimes', 'boolean']];
    }
}
