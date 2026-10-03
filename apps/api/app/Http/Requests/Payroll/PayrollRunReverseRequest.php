<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2B (ADR 0021 D26-D29). Reverse a POSTED (not PAID) run: PAYROLL_REVERSAL, exact inverse of the accrual, reason required. Any other top-level field (a numeric *_id, a status, an amount, a total)
// is rejected with 422 by the closed-field-list base request.
final class PayrollRunReverseRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000'], 'entry_date' => ['sometimes', 'nullable', 'string', 'max:10'], 'lock_version' => ['sometimes', 'nullable', 'integer', 'min:0']];
    }
}
