<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class PeriodReopenRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'unit' => ['required', 'string', 'size:26'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
