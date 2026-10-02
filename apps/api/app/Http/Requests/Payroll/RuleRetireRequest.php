<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2A (ADR 0021 D23-D28). Retire a rule version (kept as history). Any other top-level field (a numeric *_id, a raw unit / person id, a status, an amount
// where none is taken) is rejected with 422 by the closed-field-list base request.
final class RuleRetireRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
