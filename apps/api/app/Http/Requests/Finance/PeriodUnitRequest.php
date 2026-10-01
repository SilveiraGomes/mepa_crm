<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class PeriodUnitRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'unit' => ['required', 'string', 'size:26'],
        ];
    }
}
