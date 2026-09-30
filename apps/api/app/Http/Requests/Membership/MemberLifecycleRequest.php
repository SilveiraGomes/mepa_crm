<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class MemberLifecycleRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'source_document' => ['sometimes', 'nullable', 'string', 'max:64']];
    }
}
