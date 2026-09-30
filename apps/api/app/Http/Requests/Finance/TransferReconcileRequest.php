<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class TransferReconcileRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['lock_version' => ['sometimes', 'nullable', 'integer', 'min:0']];
    }
}
