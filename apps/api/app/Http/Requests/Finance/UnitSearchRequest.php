<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class UnitSearchRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['search' => ['required', 'string', 'min:2', 'max:100']];
    }
}
