<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class ReconciliationAdjustmentRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'statement_line' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'string', 'max:64'],
        ];
    }
}
