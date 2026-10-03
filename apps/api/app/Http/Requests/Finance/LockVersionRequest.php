<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class LockVersionRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'lock_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
