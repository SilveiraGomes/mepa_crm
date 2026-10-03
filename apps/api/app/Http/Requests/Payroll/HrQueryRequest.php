<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2A (ADR 0021 D23-D28). Query parameters of the HR read routes (bounded paging, public ids only). Any other top-level field (a numeric *_id, a raw unit / person id, a status, an amount
// where none is taken) is rejected with 422 by the closed-field-list base request.
final class HrQueryRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['unit' => ['sometimes', 'nullable', 'string', 'max:26'], 'status' => ['sometimes', 'nullable', 'string', 'max:16'], 'period' => ['sometimes', 'nullable', 'string', 'max:7'], 'as_of' => ['sometimes', 'nullable', 'string', 'max:10'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
