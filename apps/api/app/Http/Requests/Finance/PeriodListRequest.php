<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class PeriodListRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'unit' => ['required', 'string', 'max:26'],
            'year' => ['sometimes', 'nullable', 'string', 'size:4'],
        ];
    }
}
