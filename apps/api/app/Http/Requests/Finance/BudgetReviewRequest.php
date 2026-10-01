<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class BudgetReviewRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'approved_lines' => ['sometimes', 'array', 'max:200'],
            'approved_lines.*' => ['array:category,approved_amount'],
            'approved_lines.*.category' => ['required', 'string', 'max:64'],
            'approved_lines.*.approved_amount' => ['required', 'string', 'max:20'],
            'lock_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
