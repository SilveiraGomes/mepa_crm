<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2B (ADR 0021 D26-D29). Create a payroll run (DRAFT) for a unit and a service month; REGULAR only in V1 (other kinds need a policy). Any other top-level field (a numeric *_id, a status, an amount, a total)
// is rejected with 422 by the closed-field-list base request.
final class PayrollRunCreateRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['unit' => ['required', 'string', 'max:26'], 'period' => ['required', 'string', 'max:7'], 'run_kind' => ['sometimes', 'nullable', 'string', 'max:32']];
    }
}
