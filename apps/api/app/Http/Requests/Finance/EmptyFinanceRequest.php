<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class EmptyFinanceRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [];
    }
}
