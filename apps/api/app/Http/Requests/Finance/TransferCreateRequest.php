<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class TransferCreateRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['origin_account' => ['required', 'string', 'size:26'], 'destination_unit' => ['required', 'string', 'size:26'], 'amount' => ['required', 'string', 'max:20'], 'purpose' => ['required', 'string', 'max:64'], 'document' => ['sometimes', 'nullable', 'string', 'size:26']];
    }
}
