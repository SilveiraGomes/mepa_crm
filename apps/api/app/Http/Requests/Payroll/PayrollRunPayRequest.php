<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2B (ADR 0021 D26-D29). Pay the POSTED net payable of a run from one financial account (public id) of the employing unit; the amount is never sent. Any other top-level field (a numeric *_id, a status, an amount, a total)
// is rejected with 422 by the closed-field-list base request.
final class PayrollRunPayRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['account' => ['required', 'string', 'max:26'], 'paid_on' => ['sometimes', 'nullable', 'string', 'max:10'], 'lock_version' => ['sometimes', 'nullable', 'integer', 'min:0']];
    }
}
