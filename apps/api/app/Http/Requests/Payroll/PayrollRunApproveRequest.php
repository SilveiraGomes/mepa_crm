<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2B (ADR 0021 D26-D29). Approve a CALCULATED run (approver != calculator, production enabled, input_hash recomputed under lock). Any other top-level field (a numeric *_id, a status, an amount, a total)
// is rejected with 422 by the closed-field-list base request.
final class PayrollRunApproveRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['lock_version' => ['sometimes', 'nullable', 'integer', 'min:0']];
    }
}
