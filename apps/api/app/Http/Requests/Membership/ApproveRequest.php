<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class ApproveRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:0'], 'admitted_on' => ['sometimes', 'nullable', 'string', 'max:10'], 'admitted_on_precision' => ['sometimes', 'nullable', 'string', 'in:EXACT,MONTH,YEAR,UNKNOWN'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'source_document' => ['sometimes', 'nullable', 'string', 'max:64']];
    }
}
