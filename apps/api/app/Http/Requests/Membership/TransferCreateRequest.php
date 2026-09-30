<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class TransferCreateRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['destination_public_id' => ['required', 'string', 'max:64'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'source_document' => ['sometimes', 'nullable', 'string', 'max:64']];
    }
}
