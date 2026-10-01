<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class AccountListRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'unit' => ['sometimes', 'nullable', 'string', 'max:26'],
            'status' => ['sometimes', 'nullable', 'string', 'in:OPEN,CLOSED'],
            'kind' => ['sometimes', 'nullable', 'string', 'in:CASH,BANK'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
