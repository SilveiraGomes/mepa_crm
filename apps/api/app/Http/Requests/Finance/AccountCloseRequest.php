<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class AccountCloseRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'closed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'lock_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
