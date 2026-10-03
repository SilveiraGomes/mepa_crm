<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class PageRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
