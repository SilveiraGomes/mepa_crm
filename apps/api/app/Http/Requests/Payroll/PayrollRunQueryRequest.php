<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2B (ADR 0021 D26-D29). Query parameters of the payroll run list (bounded paging, public ids only). Any other top-level field (a numeric *_id, a status, an amount, a total)
// is rejected with 422 by the closed-field-list base request.
final class PayrollRunQueryRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['unit' => ['sometimes', 'nullable', 'string', 'max:26'], 'period' => ['sometimes', 'nullable', 'string', 'max:7'], 'status' => ['sometimes', 'nullable', 'string', 'max:16'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
