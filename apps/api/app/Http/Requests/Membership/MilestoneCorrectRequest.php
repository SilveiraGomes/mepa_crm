<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class MilestoneCorrectRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['occurred_on' => ['sometimes', 'nullable', 'string', 'max:10'], 'date_precision' => ['required', 'string', 'in:EXACT,MONTH,YEAR,UNKNOWN'], 'unit_public_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'source_document' => ['sometimes', 'nullable', 'string', 'max:64'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:0']];
    }
}
