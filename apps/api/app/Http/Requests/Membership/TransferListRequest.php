<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

final class TransferListRequest extends MembershipRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1'], 'direction' => ['sometimes', 'string', 'in:incoming,outgoing,all'], 'state' => ['sometimes', 'string', 'in:open,closed,all']];
    }
}
