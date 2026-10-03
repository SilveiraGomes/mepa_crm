<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class PeriodRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1']];
    }
}
