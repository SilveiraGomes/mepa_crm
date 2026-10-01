<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class BudgetLinesRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:200'],
            'lines.*' => ['array:category,requested_amount'],
            'lines.*.category' => ['required', 'string', 'max:64'],
            'lines.*.requested_amount' => ['required', 'string', 'max:20'],
            'lock_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
