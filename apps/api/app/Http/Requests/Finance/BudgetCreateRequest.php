<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class BudgetCreateRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'unit' => ['required', 'string', 'size:26'],
            'year' => ['required', 'string', 'size:4'],
            'lines' => ['sometimes', 'array', 'max:200'],
            'lines.*' => ['array:category,requested_amount'],
            'lines.*.category' => ['required', 'string', 'max:64'],
            'lines.*.requested_amount' => ['required', 'string', 'max:20'],
        ];
    }
}
