<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class MembershipPageRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1']];
    }
}
