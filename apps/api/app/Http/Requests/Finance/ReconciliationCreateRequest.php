<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class ReconciliationCreateRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'account' => ['required', 'string', 'size:26'],
            'period' => ['required', 'string', 'size:7'],
            'statement' => ['required', 'string', 'size:26'],
        ];
    }
}
