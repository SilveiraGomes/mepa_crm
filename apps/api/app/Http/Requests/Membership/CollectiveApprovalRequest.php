<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class CollectiveApprovalRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['memberships' => ['required', 'array', 'min:1', 'max:200'], 'memberships.*' => ['required', 'string', 'max:64', 'distinct'], 'admitted_on' => ['sometimes', 'nullable', 'string', 'max:10'], 'admitted_on_precision' => ['sometimes', 'nullable', 'string', 'in:EXACT,MONTH,YEAR,UNKNOWN'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'source_document' => ['sometimes', 'nullable', 'string', 'max:64']];
    }
}
