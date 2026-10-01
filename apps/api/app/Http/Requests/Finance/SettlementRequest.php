<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class SettlementRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'account' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'string', 'max:20'],
            'settled_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'document' => ['sometimes', 'nullable', 'string', 'size:26'],
            'lock_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
