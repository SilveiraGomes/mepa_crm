<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class ReconciliationUnmatchRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'statement_line' => ['required', 'integer', 'min:1'],
            'entry' => ['required', 'string', 'size:26'],
            'entry_line' => ['required', 'integer', 'min:1'],
        ];
    }
}
