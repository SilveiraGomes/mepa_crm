<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class ActualVsBudgetRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
