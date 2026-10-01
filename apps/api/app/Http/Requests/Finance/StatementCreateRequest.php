<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class StatementCreateRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'account' => ['required', 'string', 'size:26'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'opening_balance' => ['required', 'string', 'max:21'],
            'closing_balance' => ['required', 'string', 'max:21'],
            'document' => ['required', 'string', 'size:26'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*' => ['array:occurred_on,amount,description,reference'],
            'lines.*.occurred_on' => ['required', 'date_format:Y-m-d'],
            'lines.*.amount' => ['required', 'string', 'max:21'],
            'lines.*.description' => ['required', 'string', 'max:191'],
            'lines.*.reference' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
