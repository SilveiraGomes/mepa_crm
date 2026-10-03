<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class ReportRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'unit' => ['required', 'string', 'max:26'],
            'view' => ['sometimes', 'string', 'in:OWN,CONSOLIDATED'],
            'period_kind' => ['sometimes', 'string', 'in:MONTH,QUARTER,SEMESTER,YEAR,RANGE'],
            'period' => ['sometimes', 'string', 'max:10'],
            'year' => ['sometimes', 'string', 'regex:/^\\d{4}$/'],
            'from' => ['sometimes', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'required_with:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
