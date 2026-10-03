<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class TransferReceiveRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['lock_version' => ['sometimes', 'nullable', 'integer', 'min:0'], 'destination_account' => ['required', 'string', 'size:26'], 'received_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'amount' => ['sometimes', 'string', 'max:20'], 'document' => ['sometimes', 'nullable', 'string', 'size:26']];
    }
}
