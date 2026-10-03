<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2B (ADR 0021 D26-D29). Post an APPROVED run to Finance (one aggregated PAYROLL_ACCRUAL entry); a posting date outside the service month needs a reason (D29). Any other top-level field (a numeric *_id, a status, an amount, a total)
// is rejected with 422 by the closed-field-list base request.
final class PayrollRunPostRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['entry_date' => ['sometimes', 'nullable', 'string', 'max:10'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'lock_version' => ['sometimes', 'nullable', 'integer', 'min:0']];
    }
}
