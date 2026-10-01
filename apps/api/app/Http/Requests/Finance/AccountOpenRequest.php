<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class AccountOpenRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'unit' => ['required', 'string', 'size:26'],
            'kind' => ['required', 'string', 'in:CASH,BANK'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:191'],
            'opened_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'opening_balance' => ['sometimes', 'nullable', 'string', 'max:20'],
            'custodian' => ['sometimes', 'string', 'size:26'],
            'bank_name' => ['sometimes', 'string', 'max:191'],
            'account_number' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
