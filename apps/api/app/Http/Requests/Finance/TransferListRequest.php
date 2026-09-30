<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class TransferListRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['direction' => ['sometimes', 'string', 'in:sent,received,in_transit'], 'unit' => ['sometimes', 'nullable', 'string', 'max:26'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1']];
    }
}
